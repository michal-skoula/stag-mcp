<?php

use App\Mcp\Servers\StagMcpServer;
use App\Mcp\Tools\SearchProgramyTool;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\ProgrammeFixtures as F;

/**
 * Calls the tool against the fixture programme list, unless the test faked STAG itself.
 */
function searchProgramy(array $arguments = [], bool $fakeStag = true): mixed
{
    if ($fakeStag) {
        Http::fake([F::PROGRAMY_URL => Http::response(['programInfo' => F::programmes()])]);
    }

    return StagMcpServer::actingAs(User::factory()->create())
        ->tool(SearchProgramyTool::class, $arguments);
}

it('lists valid programmes without a STAG ticket, leaving certificates out', function () {
    searchProgramy()
        ->assertOk()
        ->assertSee('"total":5')
        ->assertDontSee('Certifikátové programy');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'getStudijniProgramy')
        && $request['pouzePlatne'] === 'true'
        && ! isset($request['fakulta']));
});

it('shapes a programme row', function () {
    searchProgramy(['name' => 'Informatika'])
        ->assertOk()
        ->assertSee('{"programme_id":2209,"code":"B0613A140036","name":"Informatika","name_en":"Informatics","faculty":"FAV","level":"bachelor","form":"full-time","language":"cs","degree":"Bc.","standard_years":3,"credits":180,"valid_from":"2023","valid_until":null,"certificate":false}')
        ->assertDontSee('garant')
        ->assertDontSee('akreditace');
});

it('matches every word against Czech and English names and the code, ignoring diacritics and case', function (string $query, array $ids) {
    $programmes = searchProgramy(['name' => $query])->assertOk();

    foreach ([1, 2209, 2, 3, 4] as $id) {
        in_array($id, $ids, true)
            ? $programmes->assertSee('"programme_id":'.$id.',')
            : $programmes->assertDontSee('"programme_id":'.$id.',');
    }
})->with([
    'czech without diacritics' => ['aplikovana', [1]],
    'english name' => ['computer science', [2, 3]],
    'two words, any order' => ['specializace informatika', [2]],
    'code' => ['b0311a', [4]],
]);

it('ranks the exact name first, then names starting with the query', function () {
    /* 2209 "Informatika", then 2 "Informatika a její specializace", then 1 "Aplikovaná informatika". */
    searchProgramy(['name' => 'informatika'])
        ->assertOk()
        ->assertSee('"total":3')
        ->assertSee('"programmes":[{"programme_id":2209,')
        ->assertSee('"certificate":false},{"programme_id":2,')
        ->assertSee('"certificate":false},{"programme_id":1,');
});

it('filters by level, form and language', function () {
    searchProgramy(['level' => 'doctoral', 'form' => 'combined', 'language' => 'en'])
        ->assertOk()
        ->assertSee('"total":1')
        ->assertSee('"programme_id":3,"code":"P0613D140031","name":"Computer Science and Engineering","name_en":null,"faculty":"FAV","level":"doctoral","form":"combined","language":"en","degree":"Ph.D."');
});

it('passes the faculty to STAG upper-cased', function () {
    searchProgramy(['faculty' => ' fav '])->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'getStudijniProgramy') && $request['fakulta'] === 'FAV');
});

it('includes certificates and expired programmes only when asked', function () {
    searchProgramy(['include_certificates' => true, 'include_expired' => true])
        ->assertOk()
        ->assertSee('"programme_id":5,"code":"9999","name":"Certifikátové programy","name_en":null,"faculty":"FAV","level":"other","form":"full-time","language":"cs","degree":null,"standard_years":0.8,"credits":null')
        ->assertSee('"certificate":true');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'getStudijniProgramy') && ! isset($request['pouzePlatne']));
});

it('reports a programme being phased out', function () {
    searchProgramy(['name' => 'ekonomie'])
        ->assertOk()
        ->assertSee('"valid_until":"2028"');
});

it('pages the results', function () {
    searchProgramy(['count' => 2, 'offset' => 2])
        ->assertOk()
        ->assertSee('"total":5,"offset":2,"count":2');
});

it('returns an empty list when nothing matches', function () {
    searchProgramy(['name' => 'zzz neexistuje'])
        ->assertOk()
        ->assertSee('"total":0,"offset":0,"count":0,"programmes":[]');
});

it('rejects an unknown level or an over-max count before calling STAG', function (array $arguments) {
    Http::fake();

    searchProgramy($arguments, fakeStag: false)->assertHasErrors();

    Http::assertNothingSent();
})->with([
    'level' => [['level' => 'masters']],
    'count' => [['count' => 301]],
]);

it('errors when there is no authenticated user', function () {
    Http::fake();

    StagMcpServer::tool(SearchProgramyTool::class)
        ->assertHasErrors()
        ->assertSee('must send a bearer token');

    Http::assertNothingSent();
});

it('reports a STAG failure', function () {
    Http::fake([F::PROGRAMY_URL => Http::response('Internal Server Error', 500)]);

    searchProgramy(fakeStag: false)->assertHasErrors(['unexpected HTTP 500']);
});
