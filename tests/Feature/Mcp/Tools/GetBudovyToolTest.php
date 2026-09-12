<?php

use App\Mcp\Servers\StagMcpServer;
use App\Mcp\Tools\GetBudovyTool;
use App\Models\User;
use Illuminate\Support\Facades\Http;

const STAG_GET_BUDOVY = 'stag-ws.zcu.cz/ws/services/rest2/mistnost/getBudovy*';

/**
 * One row shaped exactly as STAG returns it, Czech keys and all.
 *
 * @return array<string, mixed>
 */
function stagBudova(array $overrides = []): array
{
    return array_merge([
        'zkrBudovy' => 'UL',
        'url' => 'https://www.openstreetmap.org/directions?from=&to=49.7248378%2C13.3509269',
        'lokalita' => 'X',
        'identifikator' => null,
        'ulice' => 'Univerzitní',
        'cisloUlice' => '2762/22',
        'obec' => 'Plzeň',
        'gpsBudovaX' => 13.3509269,
        'gpsBudovaY' => 49.7248378,
        'gpsAdresniMistoX' => 49.72483780000001,
        'gpsAdresniMistoY' => 13.3509269,
        'provozOd' => ['value' => '1.1.1990'],
        'provozDo' => null,
    ], $overrides);
}

it('works without a STAG token', function () {
    Http::fake([STAG_GET_BUDOVY => Http::response(['items' => [stagBudova()]])]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetBudovyTool::class)
        ->assertOk()
        ->assertHasNoErrors();
});

it('maps the GPS axes explicitly rather than by position', function () {
    Http::fake([STAG_GET_BUDOVY => Http::response(['items' => [stagBudova()]])]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetBudovyTool::class)
        ->assertOk()
        ->assertSee('"latitude":49.7248378')
        ->assertSee('"longitude":13.3509269');
});

it('reshapes the STAG payload into compact english fields', function () {
    Http::fake([STAG_GET_BUDOVY => Http::response(['items' => [stagBudova()]])]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetBudovyTool::class)
        ->assertOk()
        ->assertSee('"count":1')
        ->assertSee('"code":"UL"')
        ->assertSee('"campus":"X"')
        ->assertSee('"address":{"city":"Plzeň"');
});

it('nests address and coordinates under their own keys', function () {
    Http::fake([STAG_GET_BUDOVY => Http::response(['items' => [stagBudova()]])]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetBudovyTool::class)
        ->assertOk()
        ->assertSee('"address":{"city":"Plzeň","street":"Univerzitní","house_number":"2762/22"}')
        ->assertSee('"coordinates":{"latitude":49.7248378,"longitude":13.3509269}');
});

it('returns an empty list without erroring', function () {
    Http::fake([STAG_GET_BUDOVY => Http::response(['items' => []])]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetBudovyTool::class)
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"count":0');
});

it('errors when there is no authenticated user', function () {
    Http::fake();

    StagMcpServer::tool(GetBudovyTool::class)
        ->assertHasErrors()
        ->assertSee('must send a bearer token');

    Http::assertNothingSent();
});
