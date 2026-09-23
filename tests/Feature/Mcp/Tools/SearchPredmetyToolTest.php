<?php

use App\Mcp\Servers\StagMcpServer;
use App\Mcp\Tools\SearchPredmetyTool;
use App\Models\User;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Http;

const STAG_GET_PREDMETY_BY_FAKULTA = 'stag-ws.zcu.cz/ws/services/rest2/predmety/getPredmetyByFakulta*';

/**
 * One row shaped exactly as STAG's getPredmetyByFakulta returns it, Czech
 * keys and all.
 *
 * @return array<string, mixed>
 */
function stagPredmetByFakultaRow(array $overrides = []): array
{
    return array_merge([
        'katedra' => 'KIV',
        'zkratka' => 'UPA',
        'rok' => '2026',
        'nazev' => 'Úvod do počítačových architektur',
        'semestr' => null,
        'maVyuku' => 'A',
        'vyukaZS' => 'A',
        'vyukaLS' => 'N',
        'jazyk1' => 'CZ',
        'jazyk2' => null,
        'jazyk3' => null,
        'jazyk4' => null,
        'nabizetPrijezdyEcts' => 'N',
        'pocetStudentu' => null,
        'aMax' => null,
        'bMax' => null,
        'cMax' => null,
        'aSkut' => null,
        'bSkut' => null,
        'cSkut' => null,
    ], $overrides);
}

it('refuses a caller with no STAG ticket', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(SearchPredmetyTool::class)
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('works with no filters, paging through the whole catalog', function () {
    Http::fake([STAG_GET_PREDMETY_BY_FAKULTA => Http::response(['predmetKatedry' => [stagPredmetByFakultaRow()]])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(SearchPredmetyTool::class)
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"total":1');
});

it('sends only fakulta, rok, and lang to STAG', function () {
    Http::fake([STAG_GET_PREDMETY_BY_FAKULTA => Http::response(['predmetKatedry' => []])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(SearchPredmetyTool::class, [
            'nazev' => 'architektur',
            'katedra' => 'KIV',
            'zkratka' => 'UPA',
            'fakulta' => 'FAV',
            'rok' => '2026',
            'lang' => 'en',
        ])
        ->assertOk();

    Http::assertSent(fn ($request) => $request->data() === ['fakulta' => 'FAV', 'rok' => '2026', 'lang' => 'en']);
});

it('matches nazev as a case-insensitive substring', function () {
    Http::fake([STAG_GET_PREDMETY_BY_FAKULTA => Http::response(['predmetKatedry' => [
        stagPredmetByFakultaRow(['zkratka' => 'UPA', 'nazev' => 'Úvod do počítačových architektur']),
        stagPredmetByFakultaRow(['zkratka' => 'PRO1', 'nazev' => 'Programování 1']),
    ]])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(SearchPredmetyTool::class, ['nazev' => 'POČÍTAČOVÝCH'])
        ->assertOk()
        ->assertSee('"total":1')
        ->assertSee('"zkratka":"UPA"');
});

it('matches katedra and zkratka as substrings too', function () {
    Http::fake([STAG_GET_PREDMETY_BY_FAKULTA => Http::response(['predmetKatedry' => [
        stagPredmetByFakultaRow(['katedra' => 'KIV', 'zkratka' => 'UPA']),
        stagPredmetByFakultaRow(['katedra' => 'KMA', 'zkratka' => 'MAT1']),
    ]])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(SearchPredmetyTool::class, ['katedra' => 'iv'])
        ->assertOk()
        ->assertSee('"total":1')
        ->assertSee('"katedra":"KIV"');

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(SearchPredmetyTool::class, ['zkratka' => 'mat'])
        ->assertOk()
        ->assertSee('"total":1')
        ->assertSee('"zkratka":"MAT1"');
});

it('maps rows into the narrowed field set with populated teaching flags', function () {
    Http::fake([STAG_GET_PREDMETY_BY_FAKULTA => Http::response(['predmetKatedry' => [
        stagPredmetByFakultaRow(['jazyk1' => 'CZ', 'jazyk2' => 'AN']),
    ]])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(SearchPredmetyTool::class)
        ->assertOk()
        ->assertSee('"katedra":"KIV"')
        ->assertSee('"zkratka":"UPA"')
        ->assertSee('"ma_vyuku":true')
        ->assertSee('"vyuka_zs":true')
        ->assertSee('"vyuka_ls":false')
        ->assertSee('"nabizi_prijezdy_ects":false')
        ->assertSee('"jazyky":["CZ","AN"]')
        ->assertDontSee('pocetStudentu')
        ->assertDontSee('aMax');
});

it('defaults to the first 100 subjects', function () {
    $rows = array_map(
        fn (int $i) => stagPredmetByFakultaRow(['zkratka' => "SUB{$i}"]),
        range(1, 150)
    );

    Http::fake([STAG_GET_PREDMETY_BY_FAKULTA => Http::response(['predmetKatedry' => $rows])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(SearchPredmetyTool::class)
        ->assertOk()
        ->assertSee('"total":150')
        ->assertSee('"offset":0')
        ->assertSee('"count":100');
});

it('honours a custom count', function () {
    $rows = array_map(
        fn (int $i) => stagPredmetByFakultaRow(['zkratka' => "SUB{$i}"]),
        range(1, 10)
    );

    Http::fake([STAG_GET_PREDMETY_BY_FAKULTA => Http::response(['predmetKatedry' => $rows])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(SearchPredmetyTool::class, ['count' => 3])
        ->assertOk()
        ->assertSee('"total":10')
        ->assertSee('"count":3');
});

it('pages through results with offset', function () {
    $rows = array_map(
        fn (int $i) => stagPredmetByFakultaRow(['zkratka' => "SUB{$i}"]),
        range(1, 10)
    );

    Http::fake([STAG_GET_PREDMETY_BY_FAKULTA => Http::response(['predmetKatedry' => $rows])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(SearchPredmetyTool::class, ['count' => 3, 'offset' => 9])
        ->assertOk()
        ->assertSee('"total":10')
        ->assertSee('"offset":9')
        ->assertSee('"count":1')
        ->assertSee('"zkratka":"SUB10"');
});

it('rejects a count above the maximum', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(SearchPredmetyTool::class, ['count' => 501])
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('returns an empty list without erroring', function () {
    Http::fake([STAG_GET_PREDMETY_BY_FAKULTA => Http::response(['predmetKatedry' => []])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(SearchPredmetyTool::class)
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"total":0')
        ->assertSee('"count":0');
});

it('errors when there is no authenticated user', function () {
    Http::fake();

    StagMcpServer::tool(SearchPredmetyTool::class)
        ->assertHasErrors()
        ->assertSee('must send a bearer token');

    Http::assertNothingSent();
});

it('names subjects in the pagination descriptions', function () {
    $tool = new SearchPredmetyTool;
    $input = $tool->schema(new JsonSchemaTypeFactory);
    $output = $tool->outputSchema(new JsonSchemaTypeFactory);

    expect($input['count']->toArray()['description'])->toContain('subjects')
        ->and($input['offset']->toArray()['description'])->toContain('subjects')
        ->and($output['total']->toArray()['description'])->toContain('subjects')
        ->and($output['count']->toArray()['description'])->toContain('subjects');
});
