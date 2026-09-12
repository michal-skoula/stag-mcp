<?php

use App\Mcp\Enums\Location;
use App\Mcp\Servers\StagMcpServer;
use App\Mcp\Tools\GetBudovyTool;
use App\Models\User;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
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
        ->assertSee('"campus":"Není známa"')
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

it('filters by city, case-insensitively', function () {
    Http::fake([STAG_GET_BUDOVY => Http::response(['items' => [
        stagBudova(['zkrBudovy' => 'UL', 'obec' => 'Plzeň']),
        stagBudova(['zkrBudovy' => 'CD', 'obec' => 'Cheb']),
    ]])]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetBudovyTool::class, ['city' => 'cheb'])
        ->assertOk()
        ->assertSee('"count":1')
        ->assertSee('"code":"CD"')
        ->assertDontSee('"code":"UL"');
});

it('accepts a city STAG has not been seen with yet', function () {
    Http::fake([STAG_GET_BUDOVY => Http::response(['items' => [
        stagBudova(['zkrBudovy' => 'UL', 'obec' => 'Plzeň']),
    ]])]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetBudovyTool::class, ['city' => 'Ostrava'])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"count":0');
});

it('filters by a raw campus code', function () {
    Http::fake([STAG_GET_BUDOVY => Http::response(['items' => [
        stagBudova(['zkrBudovy' => 'UL', 'lokalita' => 'B']),
        stagBudova(['zkrBudovy' => 'AM', 'lokalita' => 'S']),
    ]])]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetBudovyTool::class, ['campus' => 'b'])
        ->assertOk()
        ->assertSee('"count":1')
        ->assertSee('"code":"UL"');
});

it('filters by a campus label the same way as its raw code', function () {
    Http::fake([STAG_GET_BUDOVY => Http::response(['items' => [
        stagBudova(['zkrBudovy' => 'UL', 'lokalita' => 'B']),
        stagBudova(['zkrBudovy' => 'AM', 'lokalita' => 'S']),
    ]])]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetBudovyTool::class, ['campus' => Location::Univerzitni->label()])
        ->assertOk()
        ->assertSee('"count":1')
        ->assertSee('"code":"UL"');
});

it('filters by an address substring across street, house number, and city', function () {
    Http::fake([STAG_GET_BUDOVY => Http::response(['items' => [
        stagBudova(['zkrBudovy' => 'UL', 'ulice' => 'Univerzitní', 'cisloUlice' => '2762/22']),
        stagBudova(['zkrBudovy' => 'AM', 'ulice' => 'Americká', 'cisloUlice' => '2222/42']),
    ]])]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetBudovyTool::class, ['address' => '2762'])
        ->assertOk()
        ->assertSee('"count":1')
        ->assertSee('"code":"UL"');
});

it('defaults the city filter to the user\'s saved preference', function () {
    $user = User::factory()->create();
    $user->preferences()->create(['city' => 'Cheb']);

    Http::fake([STAG_GET_BUDOVY => Http::response(['items' => [
        stagBudova(['zkrBudovy' => 'UL', 'obec' => 'Plzeň']),
        stagBudova(['zkrBudovy' => 'CD', 'obec' => 'Cheb']),
    ]])]);

    StagMcpServer::actingAs($user)
        ->tool(GetBudovyTool::class)
        ->assertOk()
        ->assertSee('"count":1')
        ->assertSee('"code":"CD"')
        ->assertDontSee('"code":"UL"');
});

it('lets an explicit city filter override the saved preference', function () {
    $user = User::factory()->create();
    $user->preferences()->create(['city' => 'Cheb']);

    Http::fake([STAG_GET_BUDOVY => Http::response(['items' => [
        stagBudova(['zkrBudovy' => 'UL', 'obec' => 'Plzeň']),
        stagBudova(['zkrBudovy' => 'CD', 'obec' => 'Cheb']),
    ]])]);

    StagMcpServer::actingAs($user)
        ->tool(GetBudovyTool::class, ['city' => 'Plzeň'])
        ->assertOk()
        ->assertSee('"count":1')
        ->assertSee('"code":"UL"')
        ->assertDontSee('"code":"CD"');
});

it('mentions the saved preference in the schema description when one exists', function () {
    $user = User::factory()->create();
    $user->preferences()->create(['city' => 'Cheb']);

    $this->actingAs($user, 'sanctum');

    $schema = (new GetBudovyTool)->schema(new JsonSchemaTypeFactory);

    expect($schema['city']->toArray()['description'])->toContain("Defaults to 'Cheb' from user preference");
});

it('does not mention a default city in the schema description without a saved preference', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');

    $schema = (new GetBudovyTool)->schema(new JsonSchemaTypeFactory);

    expect($schema['city']->toArray()['description'])->not->toContain('Defaults to');
});

it('errors when there is no authenticated user', function () {
    Http::fake();

    StagMcpServer::tool(GetBudovyTool::class)
        ->assertHasErrors()
        ->assertSee('must send a bearer token');

    Http::assertNothingSent();
});
