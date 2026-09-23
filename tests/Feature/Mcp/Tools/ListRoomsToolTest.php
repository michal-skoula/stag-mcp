<?php

use App\Mcp\Servers\StagMcpServer;
use App\Mcp\Tools\ListRoomsTool;
use App\Models\User;
use Illuminate\Support\Facades\Http;

const STAG_GET_MISTNOSTI_INFO = 'stag-ws.zcu.cz/ws/services/rest2/mistnost/getMistnostiInfo*';

/**
 * One row shaped exactly as STAG returns it, Czech keys and all.
 *
 * @return array<string, mixed>
 */
function stagMistnost(array $overrides = []): array
{
    return array_merge([
        'zkrBudovy' => 'UL',
        'cisloMistnosti' => '103',
        'katedra' => 'KKS',
        'pracoviste' => 'Katedra konstruování strojů',
        'typCiselne' => '2',
        'typ' => 'Učebna',
        'kapacita' => 32,
        'spolecnyFond' => 'ne',
        'provozOd' => ['value' => '1.1.1990'],
        'provozDo' => null,
        'poznamka' => null,
        'plocha' => 52.11,
        'vyskaMistnosti' => null,
        'dvereCislo' => null,
        'podlazi' => '1',
        'obec' => 'Plzeň',
        'ulice' => 'Univerzitní 22',
        'cisloPopisne' => ' 2762',
        'adresaBudovy' => 'Univerzitní 22, areál Bory, laboratorní objekt,2762, Plzeň',
        'serial' => null,
        'urlMistnost' => null,
        'urlBudova' => 'https://www.openstreetmap.org/directions?from=&to=49.7248378%2C13.3509269',
        'identifikatorMistnost' => null,
        'identifikatorBudova' => null,
        'budovaGPSX' => 13.3507753,
        'budovaGPSY' => 49.7253292,
    ], $overrides);
}

it('refuses a caller with no STAG ticket', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(ListRoomsTool::class)
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('sends the exact-match filters STAG expects', function () {
    Http::fake([STAG_GET_MISTNOSTI_INFO => Http::response(['mistnostInfo' => []])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(ListRoomsTool::class, [
            'building_shortcode' => 'UL',
            'room_number' => '103',
            'department' => 'KKS',
            'room_type' => 'Učebna',
        ])
        ->assertOk();

    Http::assertSent(fn ($request) => $request['zkrBudovy'] === 'UL'
        && $request['cisloMistnosti'] === '103'
        && $request['pracoviste'] === 'KKS');
});

it('translates the room type label into the numeric code STAG filters on', function () {
    Http::fake([STAG_GET_MISTNOSTI_INFO => Http::response(['mistnostInfo' => []])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(ListRoomsTool::class, ['room_type' => 'Laboratoř'])
        ->assertOk();

    Http::assertSent(fn ($request) => $request['typ'] === '4');
});

it('defaults only_valid to true', function () {
    Http::fake([STAG_GET_MISTNOSTI_INFO => Http::response(['mistnostInfo' => []])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(ListRoomsTool::class)
        ->assertOk();

    Http::assertSent(fn ($request) => $request['jenPlatne'] === 'true');
});

it('sends only_valid as false when asked to include decommissioned rooms', function () {
    Http::fake([STAG_GET_MISTNOSTI_INFO => Http::response(['mistnostInfo' => []])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(ListRoomsTool::class, ['only_valid' => false])
        ->assertOk();

    Http::assertSent(fn ($request) => $request['jenPlatne'] === 'false');
});

it('maps rooms into the narrowed field set with nested coordinates', function () {
    Http::fake([STAG_GET_MISTNOSTI_INFO => Http::response(['mistnostInfo' => [stagMistnost()]])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(ListRoomsTool::class)
        ->assertOk()
        ->assertSee('"total":1')
        ->assertSee('"offset":0')
        ->assertSee('"count":1')
        ->assertSee('"building_shortcode":"UL"')
        ->assertSee('"room_number":"103"')
        ->assertSee('"coordinates":{"latitude":49.7253292,"longitude":13.3507753}')
        ->assertDontSee('spolecny_fond')
        ->assertDontSee('plocha')
        ->assertDontSee('cislo_popisne')
        ->assertDontSee('url_budova');
});

it('always includes the note', function () {
    Http::fake([STAG_GET_MISTNOSTI_INFO => Http::response([
        'mistnostInfo' => [stagMistnost(['poznamka' => 'černá tabule, projektor'])],
    ])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(ListRoomsTool::class)
        ->assertOk()
        ->assertSee('"note":"černá tabule, projektor"');
});

it('defaults to the first 100 rooms', function () {
    $rows = array_map(
        fn (int $i) => stagMistnost(['cisloMistnosti' => (string) $i]),
        range(1, 150)
    );

    Http::fake([STAG_GET_MISTNOSTI_INFO => Http::response(['mistnostInfo' => $rows])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(ListRoomsTool::class)
        ->assertOk()
        ->assertSee('"total":150')
        ->assertSee('"offset":0')
        ->assertSee('"count":100');
});

it('honours a custom count', function () {
    $rows = array_map(
        fn (int $i) => stagMistnost(['cisloMistnosti' => (string) $i]),
        range(1, 10)
    );

    Http::fake([STAG_GET_MISTNOSTI_INFO => Http::response(['mistnostInfo' => $rows])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(ListRoomsTool::class, ['count' => 3])
        ->assertOk()
        ->assertSee('"total":10')
        ->assertSee('"count":3');
});

it('pages through results with offset', function () {
    $rows = array_map(
        fn (int $i) => stagMistnost(['cisloMistnosti' => (string) $i]),
        range(1, 10)
    );

    Http::fake([STAG_GET_MISTNOSTI_INFO => Http::response(['mistnostInfo' => $rows])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(ListRoomsTool::class, ['count' => 3, 'offset' => 9])
        ->assertOk()
        ->assertSee('"total":10')
        ->assertSee('"offset":9')
        ->assertSee('"count":1')
        ->assertSee('"room_number":"10"');
});

it('rejects a count above the maximum', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(ListRoomsTool::class, ['count' => 501])
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('returns an empty list without erroring', function () {
    Http::fake([STAG_GET_MISTNOSTI_INFO => Http::response(['mistnostInfo' => []])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(ListRoomsTool::class)
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"total":0')
        ->assertSee('"count":0');
});

it('rejects a room type outside the known set', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(ListRoomsTool::class, ['room_type' => 'Kuchyň'])
        ->assertHasErrors();

    Http::assertNothingSent();
});
