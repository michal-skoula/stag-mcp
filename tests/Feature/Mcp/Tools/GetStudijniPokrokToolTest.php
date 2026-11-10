<?php

use App\Mcp\Servers\StagMcpServer;
use App\Mcp\Tools\GetStudijniPokrokTool;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\StudyPlanFixtures as F;

function getPokrok(array $arguments = []): mixed
{
    return StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetStudijniPokrokTool::class, $arguments);
}

it('refuses a caller with no STAG ticket', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetStudijniPokrokTool::class)
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('audits the caller\'s own plan', function () {
    F::fakeStag();

    getPokrok()
        ->assertOk()
        ->assertSee('"plan":{"stpl_id":25230,"name":"Softwarové inženýrství","own_plan":true,"semesters":6}')
        ->assertSee('"programme":{"credits_required":180,"credits_earned":42,"credits_recognised":0,"year_of_study":2}')
        ->assertSee('"name":"Povinné předměty","kind":"required","min_credits":15,"min_subjects":3,"satisfied":false,"credits_earned":11,"subjects_earned":2,"credits_in_progress":4,"credits_next_year":0,"remaining_credits":4,"remaining_subjects":1,"earned":["KIV/PPA","KIV/DB1"],"in_progress":["KMA/DMA"]')
        ->assertSee('"eu_track":{"credits_earned":0,"credits_in_progress":0,"level1_credits_required":6,"level1_met":false}')
        ->assertSee('"unmatched":[{"code":"KIV/OKS","credits":4,"state":"earned","year":"2026"}]');
});

it('lists what is missing in all-required blocks and what is left to pick elsewhere', function () {
    F::fakeStag();

    getPokrok()
        ->assertOk()
        ->assertSee('"name":"Státní závěrečná zkouška"')
        ->assertSee('"missing":[{"code":"KIV/BZSWI","name":"Předmět BZSWI","credits":0,"exam_type":"state exam (státní závěrečná zkouška)","recommended_year":3,"recommended_semester":"ZS"}],"options":null')
        ->assertSee('"missing":null,"options":[{"code":"UJP/NEP6"')
        ->assertSee('"missing":null,"options":[{"code":"KIV/PRO"');
});

it('drops leftovers of a satisfied pick-some block and the subjects of optional ones', function () {
    F::fakeStag();

    getPokrok()
        ->assertOk()
        ->assertSee('"name":"Tělesná výchova","kind":"elective","min_credits":2,"min_subjects":null,"satisfied":true')
        ->assertSee('"earned":["UTS/TV","UTS/TV"],"in_progress":[],"next_year":[],"missing":null,"options":null,"untaken_count":1')
        ->assertDontSee('REK/CHIN1');
});

it('never reports the osCislo it had to send', function () {
    F::fakeStag();

    getPokrok()->assertOk()->assertDontSee(F::FAKE_OS_CISLO);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'vsp/getStudent') && $request['osCislo'] === F::FAKE_OS_CISLO);
});

it('measures progress towards a certificate without claiming it is awarded', function () {
    F::fakeStag();

    getPokrok(['certificate_id' => 4825])
        ->assertOk()
        ->assertSee('"plan":{"stpl_id":25020,"name":"Podnikání v IT","own_plan":false,"semesters":4}')
        ->assertSee('"programme":null')
        ->assertSee('"eu_track":null')
        ->assertSee('"unmatched":[]')
        ->assertSee('"code":"KIV/PIZZ","name":"Předmět PIZZ","credits":0,"exam_type":"final exam (závěrečná zkouška)"')
        ->assertSee('not that the certificate has been issued');
});

it('errors for an account with no student role', function () {
    F::fakeStag([F::USER_LIST_URL => Http::response(['stagUserInfo' => [['userName' => 'teacher', 'role' => 'VY']]])]);

    getPokrok()
        ->assertHasErrors(['holds no student role']);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'vsp/getStudent'));
});

it('errors on an unknown plan', function () {
    F::fakeStag([F::PLAN_URL => Http::response('', 204)]);

    getPokrok(['stpl_id' => 99999999])
        ->assertHasErrors(['No study plan found with stpl_id 99999999']);
});

it('reports a broken student document as a STAG fault', function () {
    F::fakeStag([F::STUDENT_URL => Http::response('<vspStudent><rokyStudia>')]);

    getPokrok()
        ->assertHasErrors(['empty XML document']);
});
