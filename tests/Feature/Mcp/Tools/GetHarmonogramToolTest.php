<?php

use App\Mcp\Servers\StagMcpServer;
use App\Mcp\Tools\GetHarmonogramTool;
use App\Models\User;
use App\Services\Stag\ReferenceData;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\Fluent\AssertableJson;

const STAG_GET_AKTUALNI_OBDOBI_INFO = 'stag-ws.zcu.cz/ws/services/rest2/kalendar/getAktualniObdobiInfo*';
const STAG_GET_HARMONOGRAM_ROKU = 'stag-ws.zcu.cz/ws/services/rest2/kalendar/getHarmonogramRoku*';

/**
 * Shaped exactly as STAG's getAktualniObdobiInfo returns it, Czech keys and all.
 *
 * @return array<string, mixed>
 */
function stagObdobi(array $overrides = []): array
{
    return array_merge([
        'obdobi' => 'ZR',
        'akademRok' => '2026',
        'semestrInteligentne' => 'ZS',
        'akademRokInteligentne' => '2026',
        'posledniVyucovaciDenRoku' => ['value' => '18.5.2027'],
        'posledniDenSemestruInteligentne' => ['value' => '27.12.2026'],
        'posledniDenZimnihoZkouskoveho' => ['value' => '14.2.2027'],
        'posledniDenLetnihoZkouskoveho' => ['value' => '31.8.2027'],
        'prvniDenStavajicihoAkademickehoRoku' => ['value' => '1.9.2026'],
        'posledniDenStavajicihoAkademickehoRoku' => ['value' => '31.8.2027'],
    ], $overrides);
}

/**
 * @return array<string, mixed>
 */
function stagHarmonogramItem(string $datumOd, string $popis): array
{
    return ['datumOd' => ['value' => $datumOd], 'datumDo' => null, 'popis' => $popis];
}

it('works without a STAG token', function () {
    Http::fake([
        STAG_GET_AKTUALNI_OBDOBI_INFO => Http::response(stagObdobi()),
        STAG_GET_HARMONOGRAM_ROKU => Http::response(['harmonogramItem' => []]),
    ]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetHarmonogramTool::class)
        ->assertOk()
        ->assertHasNoErrors();
});

it('errors when there is no authenticated user', function () {
    Http::fake();

    StagMcpServer::tool(GetHarmonogramTool::class)
        ->assertHasErrors()
        ->assertSee('must send a bearer token');

    Http::assertNothingSent();
});

it('forwards year as rok to getHarmonogramRoku only', function () {
    Http::fake([
        STAG_GET_AKTUALNI_OBDOBI_INFO => Http::response(stagObdobi()),
        STAG_GET_HARMONOGRAM_ROKU => Http::response(['harmonogramItem' => []]),
    ]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetHarmonogramTool::class, ['year' => '2027'])
        ->assertOk();

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'getHarmonogramRoku')
        ? $request['rok'] === '2027'
        : true);
});

it('merges the harmonogram rows with the six dated getAktualniObdobiInfo fields', function () {
    Http::fake([
        STAG_GET_AKTUALNI_OBDOBI_INFO => Http::response(stagObdobi()),
        STAG_GET_HARMONOGRAM_ROKU => Http::response(['harmonogramItem' => [
            stagHarmonogramItem('21.9.2026', 'Začíná: Zimní semestr'),
        ]]),
    ]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetHarmonogramTool::class)
        ->assertOk()
        ->assertSee('Začíná: Zimní semestr')
        ->assertSee('Last teaching day of the year')
        ->assertSee('Last day of the current semester')
        ->assertSee('Last day of the winter exam period')
        ->assertSee('Last day of the summer exam period')
        ->assertSee('Start of the academic year')
        ->assertSee('End of the academic year');
});

it('sorts entries ascending by date_from', function () {
    Http::fake([
        STAG_GET_AKTUALNI_OBDOBI_INFO => Http::response(stagObdobi([
            // No collision with the harmonogram rows below, so all six derived
            // dates are added on top of the two harmonogram rows: 8 entries.
            'posledniVyucovaciDenRoku' => ['value' => '18.5.2027'],
            'posledniDenSemestruInteligentne' => ['value' => '27.12.2026'],
            'posledniDenZimnihoZkouskoveho' => ['value' => '14.2.2027'],
            'posledniDenLetnihoZkouskoveho' => ['value' => '31.8.2027'],
            'prvniDenStavajicihoAkademickehoRoku' => ['value' => '2.9.2026'],
            'posledniDenStavajicihoAkademickehoRoku' => ['value' => '30.8.2027'],
        ])),
        STAG_GET_HARMONOGRAM_ROKU => Http::response(['harmonogramItem' => [
            stagHarmonogramItem('21.9.2026', 'Začíná: Zimní semestr'),
            stagHarmonogramItem('1.9.2026', 'Začátek ak. roku'),
        ]]),
    ]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetHarmonogramTool::class)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('total', 8)
            ->where('entries.0.date_from', '2026-09-01')
            ->where('entries.1.date_from', '2026-09-02')
            ->where('entries.2.date_from', '2026-09-21')
            ->where('entries.7.date_from', '2027-08-31')
            ->etc()
        );
});

it('does not dedupe the academic-year-boundary entries against a harmonogram row on the same date', function () {
    Http::fake([
        STAG_GET_AKTUALNI_OBDOBI_INFO => Http::response(stagObdobi([
            'prvniDenStavajicihoAkademickehoRoku' => ['value' => '1.9.2026'],
            'posledniDenStavajicihoAkademickehoRoku' => ['value' => '31.8.2027'],
        ])),
        STAG_GET_HARMONOGRAM_ROKU => Http::response(['harmonogramItem' => [
            stagHarmonogramItem('1.9.2026', 'Začátek ak. roku'),
            stagHarmonogramItem('31.8.2027', 'Konec ak. roku'),
        ]]),
    ]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetHarmonogramTool::class)
        ->assertOk()
        ->assertSee('Začátek ak. roku')
        ->assertSee('Start of the academic year')
        ->assertSee('Konec ak. roku')
        ->assertSee('End of the academic year');
});

it('includes the academic-year-boundary entries when the harmonogram does not cover that date', function () {
    Http::fake([
        STAG_GET_AKTUALNI_OBDOBI_INFO => Http::response(stagObdobi([
            'prvniDenStavajicihoAkademickehoRoku' => ['value' => '1.9.2026'],
            'posledniDenStavajicihoAkademickehoRoku' => ['value' => '31.8.2027'],
        ])),
        STAG_GET_HARMONOGRAM_ROKU => Http::response(['harmonogramItem' => []]),
    ]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetHarmonogramTool::class)
        ->assertOk()
        ->assertSee('Start of the academic year')
        ->assertSee('End of the academic year');
});

it('outputs dates as ISO', function () {
    Http::fake([
        STAG_GET_AKTUALNI_OBDOBI_INFO => Http::response(stagObdobi()),
        STAG_GET_HARMONOGRAM_ROKU => Http::response(['harmonogramItem' => [
            stagHarmonogramItem('21.9.2026', 'Začíná: Zimní semestr'),
        ]]),
    ]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetHarmonogramTool::class)
        ->assertOk()
        ->assertSee('"date_from":"2026-09-21"')
        ->assertDontSee('21.9.2026');
});

it('returns an empty list without erroring for a bogus year', function () {
    Http::fake([
        STAG_GET_AKTUALNI_OBDOBI_INFO => Http::response([
            'obdobi' => 'ZR',
            'akademRok' => '2026',
            'semestrInteligentne' => 'ZS',
            'akademRokInteligentne' => '2026',
        ]),
        STAG_GET_HARMONOGRAM_ROKU => Http::response(['harmonogramItem' => []]),
    ]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetHarmonogramTool::class, ['year' => '1900'])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"total":0')
        ->assertSee('"entries":[]');
});

it('sends the current academic year explicitly when no year is given', function () {
    Http::fake([
        STAG_GET_AKTUALNI_OBDOBI_INFO => Http::response(stagObdobi(['akademRok' => '2026'])),
        STAG_GET_HARMONOGRAM_ROKU => Http::response(['harmonogramItem' => []]),
    ]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetHarmonogramTool::class)
        ->assertOk();

    Http::assertSent(fn ($request) => ! str_contains((string) $request->url(), 'getHarmonogramRoku') || $request['rok'] === '2026');
});

it('rejects a year that is not four digits without calling STAG', function (string $year) {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetHarmonogramTool::class, ['year' => $year])
        ->assertHasErrors();

    Http::assertNothingSent();
})->with(['letters' => ['abcd'], 'academic-year range' => ['2026/2027'], 'two digits' => ['26']]);

it('caches the harmonogram per year but always asks STAG for the current period', function () {
    Http::fake([
        STAG_GET_AKTUALNI_OBDOBI_INFO => Http::response(stagObdobi()),
        STAG_GET_HARMONOGRAM_ROKU => Http::response(['harmonogramItem' => [stagHarmonogramItem('1.9.2026', 'Začátek ak. roku')]]),
    ]);
    $user = User::factory()->create();

    StagMcpServer::actingAs($user)->tool(GetHarmonogramTool::class)->assertOk();
    StagMcpServer::actingAs($user)->tool(GetHarmonogramTool::class)->assertOk()->assertSee('"using_outdated_data":false');
    StagMcpServer::actingAs($user)->tool(GetHarmonogramTool::class, ['year' => '2027'])->assertOk();

    expect(Http::recorded(fn ($request) => str_contains((string) $request->url(), 'getHarmonogramRoku')))->toHaveCount(2)
        ->and(Http::recorded(fn ($request) => str_contains((string) $request->url(), 'getAktualniObdobiInfo')))->toHaveCount(3);
});

it('does not keep an empty harmonogram, so a year published later is picked up', function () {
    Http::fake([
        STAG_GET_AKTUALNI_OBDOBI_INFO => Http::response(stagObdobi()),
        STAG_GET_HARMONOGRAM_ROKU => Http::sequence()
            ->push(['harmonogramItem' => []])
            ->push(['harmonogramItem' => [stagHarmonogramItem('1.9.2027', 'Začátek ak. roku')]]),
    ]);
    $user = User::factory()->create();

    StagMcpServer::actingAs($user)->tool(GetHarmonogramTool::class, ['year' => '2027'])->assertOk();
    StagMcpServer::actingAs($user)->tool(GetHarmonogramTool::class, ['year' => '2027'])->assertOk()->assertSee('2027-09-01');
});

it('serves the old harmonogram and flags it when STAG fails after expiry', function () {
    Http::fake([
        STAG_GET_AKTUALNI_OBDOBI_INFO => Http::response(stagObdobi()),
        STAG_GET_HARMONOGRAM_ROKU => Http::sequence()
            ->push(['harmonogramItem' => [stagHarmonogramItem('21.9.2026', 'Začíná: Zimní semestr')]])
            ->push('Service Unavailable', 503),
    ]);
    $user = User::factory()->create();

    StagMcpServer::actingAs($user)->tool(GetHarmonogramTool::class)->assertOk();
    $this->travel(ReferenceData::CALENDAR_TTL_SECONDS + 1)->seconds();

    StagMcpServer::actingAs($user)->tool(GetHarmonogramTool::class)
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('Začíná: Zimní semestr')
        ->assertSee('"using_outdated_data":true');
});
