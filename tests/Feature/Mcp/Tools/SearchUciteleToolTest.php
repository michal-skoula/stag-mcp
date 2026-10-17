<?php

use App\Mcp\Servers\StagMcpServer;
use App\Mcp\Tools\SearchUciteleTool;
use App\Models\User;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\UcitelFixtures;

it('refuses a caller with no STAG ticket', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(SearchUciteleTool::class, ['name' => 'novak'])
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('requires a name or a department', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(SearchUciteleTool::class)
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('rejects a one-character name', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(SearchUciteleTool::class, ['name' => 'a'])
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('rejects a name with nothing to search by', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(SearchUciteleTool::class, ['name' => '..,'])
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('searches the whole university by name', function () {
    UcitelFixtures::fake([UcitelFixtures::ucitel()]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(SearchUciteleTool::class, ['name' => 'sperlova'])
        ->assertOk()
        ->assertSee('"total":1')
        ->assertSee('"teacher_id":301402')
        ->assertSee('"name":"Bc. Denisa Šperlová"');

    Http::assertSent(fn ($request) => $request['katedra'] === 'REK');
});

it('sends the department upper-cased instead of REK', function () {
    UcitelFixtures::fake([UcitelFixtures::ucitel()]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(SearchUciteleTool::class, ['department' => 'kiv'])
        ->assertOk()
        ->assertSee('"total":1');

    Http::assertSent(fn ($request) => $request['katedra'] === 'KIV');
});

it('accepts hyphenated and Czech department codes', function (string $code) {
    UcitelFixtures::fake([]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(SearchUciteleTool::class, ['department' => $code])
        ->assertOk();

    Http::assertSent(fn ($request) => $request['katedra'] === mb_strtoupper($code));
})->with(['PR-P', 'ujp-s', 'KČJ']);

it('rejects a department code that is not alphanumeric', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(SearchUciteleTool::class, ['department' => 'KIV&katedra=REK'])
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('maps rows into the narrowed field set', function () {
    UcitelFixtures::fake([UcitelFixtures::ucitel([
        'titulPred' => 'doc. Ing.',
        'titulZa' => 'Ph.D.',
        'telefon' => '2304',
        'url' => 'https://example.zcu.cz',
    ])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(SearchUciteleTool::class, ['name' => 'sperlova'])
        ->assertOk()
        ->assertSee('"name":"doc. Ing. Denisa Šperlová, Ph.D."')
        ->assertSee('"titles_after":"Ph.D."')
        ->assertSee('"department":"KIV"')
        ->assertSee('"email":"sperld@fav.zcu.cz"')
        ->assertSee('"active":true')
        ->assertSee('"employee":true')
        ->assertDontSee('telefon')
        ->assertDontSee('2304')
        ->assertDontSee('example.zcu.cz')
        ->assertDontSee('"key"');
});

it('returns inactive people by default, flagged as such', function () {
    UcitelFixtures::fake([UcitelFixtures::ucitel(['platnost' => 'N'])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(SearchUciteleTool::class, ['name' => 'sperlova'])
        ->assertOk()
        ->assertSee('"total":1')
        ->assertSee('"active":false');
});

it('drops inactive people with active_only', function () {
    UcitelFixtures::fake([UcitelFixtures::ucitel(['platnost' => 'N'])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(SearchUciteleTool::class, ['name' => 'sperlova', 'active_only' => true])
        ->assertOk()
        ->assertSee('"total":0');
});

it('drops external teachers with employees_only', function () {
    UcitelFixtures::fake([UcitelFixtures::ucitel(['zamestnanec' => 'N'])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(SearchUciteleTool::class, ['name' => 'sperlova', 'employees_only' => true])
        ->assertOk()
        ->assertSee('"total":0');
});

it('defaults to the first 25 teachers', function () {
    $rows = array_map(fn (int $i) => UcitelFixtures::ucitel(['ucitIdno' => $i]), range(1, 40));
    UcitelFixtures::fake($rows);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(SearchUciteleTool::class, ['department' => 'KIV'])
        ->assertOk()
        ->assertSee('"total":40')
        ->assertSee('"count":25');
});

it('pages through results with offset', function () {
    $rows = array_map(fn (int $i) => UcitelFixtures::ucitel(['ucitIdno' => $i]), range(1, 10));
    UcitelFixtures::fake($rows);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(SearchUciteleTool::class, ['department' => 'KIV', 'count' => 3, 'offset' => 9])
        ->assertOk()
        ->assertSee('"total":10')
        ->assertSee('"offset":9')
        ->assertSee('"count":1');
});

it('rejects a count above the maximum', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(SearchUciteleTool::class, ['name' => 'novak', 'count' => 201])
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('returns an empty list without erroring', function () {
    UcitelFixtures::fake([]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(SearchUciteleTool::class, ['department' => 'XYZ'])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"total":0')
        ->assertSee('"count":0');
});

it('names teachers in the pagination descriptions', function () {
    $tool = new SearchUciteleTool;
    $input = $tool->schema(new JsonSchemaTypeFactory);
    $output = $tool->outputSchema(new JsonSchemaTypeFactory);

    expect($input['count']->toArray()['description'])->toContain('teachers')
        ->and($output['total']->toArray()['description'])->toContain('teachers');
});
