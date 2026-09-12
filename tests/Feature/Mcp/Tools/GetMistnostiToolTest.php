<?php

use App\Mcp\Servers\StagMcpServer;
use App\Mcp\Tools\GetMistnostiTool;
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
        ->tool(GetMistnostiTool::class)
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('sends the exact-match filters STAG expects', function () {
    Http::fake([STAG_GET_MISTNOSTI_INFO => Http::response(['mistnostInfo' => []])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetMistnostiTool::class, [
            'zkr_budovy' => 'UL',
            'cislo_mistnosti' => '103',
            'pracoviste' => 'Katedra konstruování strojů',
            'typ' => 'Učebna',
        ])
        ->assertOk();

    Http::assertSent(fn ($request) => $request['zkrBudovy'] === 'UL'
        && $request['cisloMistnosti'] === '103'
        && $request['pracoviste'] === 'Katedra konstruování strojů'
        && $request['typ'] === 'Učebna');
});

it('defaults jen_platne to true', function () {
    Http::fake([STAG_GET_MISTNOSTI_INFO => Http::response(['mistnostInfo' => []])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetMistnostiTool::class)
        ->assertOk();

    Http::assertSent(fn ($request) => $request['jenPlatne'] === 'true');
});

it('sends jen_platne as false when asked to include invalid rooms', function () {
    Http::fake([STAG_GET_MISTNOSTI_INFO => Http::response(['mistnostInfo' => []])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetMistnostiTool::class, ['jen_platne' => false])
        ->assertOk();

    Http::assertSent(fn ($request) => $request['jenPlatne'] === 'false');
});

it('maps rooms into the narrowed field set with nested coordinates', function () {
    Http::fake([STAG_GET_MISTNOSTI_INFO => Http::response(['mistnostInfo' => [stagMistnost()]])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetMistnostiTool::class)
        ->assertOk()
        ->assertSee('"total":1')
        ->assertSee('"offset":0')
        ->assertSee('"count":1')
        ->assertSee('"zkr_budovy":"UL"')
        ->assertSee('"cislo_mistnosti":"103"')
        ->assertSee('"coordinates":{"latitude":49.7253292,"longitude":13.3507753}')
        ->assertDontSee('spolecny_fond')
        ->assertDontSee('plocha')
        ->assertDontSee('cislo_popisne')
        ->assertDontSee('url_budova');
});

it('always includes poznamka', function () {
    Http::fake([STAG_GET_MISTNOSTI_INFO => Http::response([
        'mistnostInfo' => [stagMistnost(['poznamka' => 'černá tabule, projektor'])],
    ])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetMistnostiTool::class)
        ->assertOk()
        ->assertSee('"poznamka":"černá tabule, projektor"');
});

it('defaults to the first 100 rooms', function () {
    $rows = array_map(
        fn (int $i) => stagMistnost(['cisloMistnosti' => (string) $i]),
        range(1, 150)
    );

    Http::fake([STAG_GET_MISTNOSTI_INFO => Http::response(['mistnostInfo' => $rows])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetMistnostiTool::class)
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
        ->tool(GetMistnostiTool::class, ['count' => 3])
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
        ->tool(GetMistnostiTool::class, ['count' => 3, 'offset' => 9])
        ->assertOk()
        ->assertSee('"total":10')
        ->assertSee('"offset":9')
        ->assertSee('"count":1')
        ->assertSee('"cislo_mistnosti":"10"');
});

it('rejects a count above the maximum', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetMistnostiTool::class, ['count' => 501])
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('returns an empty list without erroring', function () {
    Http::fake([STAG_GET_MISTNOSTI_INFO => Http::response(['mistnostInfo' => []])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetMistnostiTool::class)
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"total":0')
        ->assertSee('"count":0');
});

it('rejects a typ outside the known set', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetMistnostiTool::class, ['typ' => 'Kuchyň'])
        ->assertHasErrors();

    Http::assertNothingSent();
});
