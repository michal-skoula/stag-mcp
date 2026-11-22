<?php

use App\Models\User;
use App\Services\Stag\ReferenceData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const STAG_SCHOOLS = 'stag-ws.zcu.cz/ws/services/rest2/zakaznici/getSeznamProvozovanychWS*';

/**
 * One deployment as STAG lists it, with the shape of a real row.
 *
 * @return array<string, mixed>
 */
function stagSchool(array $overrides = []): array
{
    return array_merge([
        'nazevAplikace' => 'ws',
        'nazevSkoly' => 'TUL',
        'celyNazevSkoly' => 'Technická univerzita v Liberci',
        'zkratkaServeru' => 'ws',
        'provozniServer' => 'A',
        'pristupnyBezOmezeni' => 'A',
        'url' => 'https://stag-ws.tul.cz/ws',
    ], $overrides);
}

/**
 * @param  list<array<string, mixed>>  $schools
 */
function fakeSchools(array $schools): void
{
    Http::fake([STAG_SCHOOLS => Http::response(['nasazeniAplikace' => $schools])]);
}

it('works before the user has linked STAG', function () {
    fakeSchools([stagSchool()]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('onboarding.schools'))
        ->assertOk()
        ->assertJsonPath('schools.0.short_name', 'TUL');
});

it('reshapes a deployment into what a picker needs', function () {
    fakeSchools([stagSchool()]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('onboarding.schools'))
        ->assertJsonPath('schools.0', [
            'short_name' => 'TUL',
            'name' => 'Technická univerzita v Liberci',
            'url' => 'https://stag-ws.tul.cz/ws',
            'supported' => false,
        ])
        ->assertJsonPath('using_outdated_data', false);
});

it('hides the demo server and a school with restricted access', function () {
    fakeSchools([
        stagSchool(['nazevSkoly' => 'ZČU', 'zkratkaServeru' => 'demo', 'provozniServer' => 'N', 'url' => 'https://stag-demo.zcu.cz/ws']),
        stagSchool(['nazevSkoly' => 'UPOL', 'pristupnyBezOmezeni' => 'N']),
        stagSchool(['nazevSkoly' => 'UTB']),
    ]);

    $response = $this->actingAs(User::factory()->create())->getJson(route('onboarding.schools'));

    expect(array_column($response->json('schools'), 'short_name'))->toBe(['UTB']);
});

it('hides a row whose flags or url are missing', function () {
    fakeSchools([
        stagSchool(['nazevSkoly' => 'A1', 'provozniServer' => null]),
        stagSchool(['nazevSkoly' => 'A2', 'pristupnyBezOmezeni' => null]),
        stagSchool(['nazevSkoly' => 'A3', 'url' => null]),
        stagSchool(['nazevSkoly' => 'OK']),
    ]);

    $response = $this->actingAs(User::factory()->create())->getJson(route('onboarding.schools'));

    expect(array_column($response->json('schools'), 'short_name'))->toBe(['OK']);
});

it('puts the supported school first and sorts the rest in Czech order', function () {
    fakeSchools([
        stagSchool(['nazevSkoly' => 'UTB']),
        stagSchool(['nazevSkoly' => 'ČVUT', 'url' => 'https://stag.example.cz/ws']),
        stagSchool(['nazevSkoly' => 'ZČU', 'url' => 'https://stag-ws.zcu.cz/ws']),
        stagSchool(['nazevSkoly' => 'JČU', 'url' => 'https://stag.example.cz/jcu/ws']),
        stagSchool(['nazevSkoly' => 'AVU', 'url' => 'https://stag.example.cz/avu/ws']),
        stagSchool(['nazevSkoly' => 'UHK', 'url' => 'https://stag.example.cz/uhk/ws']),
    ]);

    $schools = $this->actingAs(User::factory()->create())->getJson(route('onboarding.schools'))->json('schools');

    expect(array_column($schools, 'short_name'))->toBe(['ZČU', 'AVU', 'ČVUT', 'JČU', 'UHK', 'UTB'])
        ->and($schools[0]['supported'])->toBeTrue()
        ->and(collect($schools)->skip(1)->every(fn ($school) => $school['supported'] === false))->toBeTrue();
});

it('treats a trailing slash on the url the same', function () {
    fakeSchools([stagSchool(['nazevSkoly' => 'ZČU', 'url' => 'https://stag-ws.zcu.cz/ws/'])]);

    $school = $this->actingAs(User::factory()->create())->getJson(route('onboarding.schools'))->json('schools.0');

    expect($school['url'])->toBe('https://stag-ws.zcu.cz/ws')->and($school['supported'])->toBeTrue();
});

it('answers from the cache and calls STAG once', function () {
    fakeSchools([stagSchool()]);
    $user = User::factory()->create();

    $this->actingAs($user)->getJson(route('onboarding.schools'))->assertOk();
    $this->actingAs($user)->getJson(route('onboarding.schools'))->assertOk();

    Http::assertSentCount(1);
});

it('caches the raw list, so the hiding rule can change without a flush', function () {
    fakeSchools([stagSchool(['nazevSkoly' => 'UPOL', 'pristupnyBezOmezeni' => 'N']), stagSchool()]);

    $this->actingAs(User::factory()->create())->getJson(route('onboarding.schools'))->assertOk();

    $cached = Cache::get('stag_ref:schools');

    expect(array_column($cached['data'], 'nazevSkoly'))->toBe(['UPOL', 'TUL']);
});

it('keeps serving the last list, flagged outdated, when STAG is down', function () {
    Http::fake([STAG_SCHOOLS => Http::sequence()
        ->push(['nasazeniAplikace' => [stagSchool()]])
        ->push('', 500)]);
    $user = User::factory()->create();
    $this->actingAs($user)->getJson(route('onboarding.schools'))->assertOk();

    $this->travel(ReferenceData::SCHOOLS_TTL_SECONDS + 1)->seconds();

    $this->actingAs($user)->getJson(route('onboarding.schools'))
        ->assertOk()
        ->assertJsonPath('using_outdated_data', true)
        ->assertJsonPath('schools.0.short_name', 'TUL');
});

it('does not cache an empty list and reports it as a failure', function () {
    Http::fake([STAG_SCHOOLS => Http::response(['nasazeniAplikace' => []])]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('onboarding.schools'))
        ->assertStatus(503)
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'empty school list'));

    expect(Cache::get('stag_ref:schools'))->toBeNull();
});

it('reports STAG being down when there is nothing cached', function () {
    Http::fake([STAG_SCHOOLS => Http::response('', 500)]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('onboarding.schools'))
        ->assertStatus(503);
});

it('turns guests away', function () {
    $this->getJson(route('onboarding.schools'))->assertUnauthorized();
});
