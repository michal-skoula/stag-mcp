<?php

use App\Mcp\Servers\StagMcpServer;
use App\Mcp\Tools\GetStudijniPlanTool;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\StudyPlanFixtures as F;

function getPlan(array $arguments = [], bool $withTicket = true): mixed
{
    $user = $withTicket ? User::factory()->withStagToken()->create() : User::factory()->create();

    return StagMcpServer::actingAs($user)->tool(GetStudijniPlanTool::class, $arguments);
}

it('errors when there is no authenticated user', function () {
    Http::fake();

    StagMcpServer::tool(GetStudijniPlanTool::class)
        ->assertHasErrors()
        ->assertSee('must send a bearer token');

    Http::assertNothingSent();
});

it('defaults to the caller\'s own current plan', function () {
    F::fakeStag();

    getPlan()
        ->assertOk()
        ->assertSee('"stpl_id":25230')
        ->assertSee('"name":"Softwarové inženýrství"')
        ->assertSee('"semesters":6')
        ->assertSee('"credit_limit":180')
        ->assertDontSee(F::FAKE_OS_CISLO);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'vsp/getStudijniPlan') && (string) $request['stplIdno'] === '25230');
});

it('asks a caller with no ticket to authorize or pass an id, without calling STAG', function () {
    Http::fake();

    getPlan(withTicket: false)
        ->assertHasErrors(['has not authorized IS-STAG'])
        ->assertSee('stpl_id');

    Http::assertNothingSent();
});

it('shows any plan by id without a ticket', function () {
    F::fakeStag();

    getPlan(['stpl_id' => 25230], withTicket: false)
        ->assertOk()
        ->assertSee('"stpl_id":25230');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'vsp/getStudent'));
    Http::assertSent(fn ($request) => ! $request->hasHeader('Cookie'));
});

it('resolves a certificate to its newest plan', function () {
    F::fakeStag();

    getPlan(['certificate_id' => 4825], withTicket: false)
        ->assertOk()
        ->assertSee('"stpl_id":25020')
        ->assertSee('"name":"Podnikání v IT"')
        ->assertSee('"code":"KIV/PIZZ"')
        ->assertSee('"exam_type":"final exam (závěrečná zkouška)"');
});

it('describes blocks and subjects', function () {
    F::fakeStag();

    getPlan(['stpl_id' => 25230])
        ->assertOk()
        ->assertSee('"segment":"Softwarové inženýrství","name":"Povinné předměty","kind":"required","min_credits":15,"min_subjects":3,"all_required":true,"subject_count":3')
        ->assertSee('"name":"Cizí jazyk - povinný","kind":"required","min_credits":4,"min_subjects":1,"all_required":false')
        ->assertSee('"code":"KIV/DB1","name":"Předmět DB1","credits":6,"exam_type":"exam (zkouška)","recommended_year":1,"recommended_semester":"ZS","hours":"2+2+0","state_exam":false,"excludes":["KIV/DB1-E"]')
        ->assertSee('"exam_type":"state exam (státní závěrečná zkouška)"')
        ->assertDontSee('<span>')
        ->assertDontSee('Poznámka k plánu');
});

it('collapses optional blocks to a count unless asked', function () {
    F::fakeStag();

    getPlan(['stpl_id' => 25230])
        ->assertOk()
        ->assertSee('"name":"EU Track-Level1","kind":"optional","min_credits":null,"min_subjects":null,"all_required":false,"subject_count":3,"subjects":null')
        ->assertDontSee('REK/CHIN1');

    getPlan(['stpl_id' => 25230, 'include_optional' => true])
        ->assertOk()
        ->assertSee('"code":"REK/CHIN1"');
});

it('errors on an unknown plan', function () {
    Http::fake([F::PLAN_URL => Http::response('', 204)]);

    getPlan(['stpl_id' => 99999999])
        ->assertHasErrors(['No study plan found with stpl_id 99999999']);
});

it('errors on a certificate with no plan', function () {
    Http::fake([F::PLANY_OBORU_URL => Http::response(['planInfo' => []])]);

    getPlan(['certificate_id' => 1])
        ->assertHasErrors(['No plan found for certificate_id 1']);
});

it('refuses both ids at once and invalid ids, before calling STAG', function (array $arguments) {
    Http::fake();

    getPlan($arguments)->assertHasErrors();

    Http::assertNothingSent();
})->with([
    'both' => [['stpl_id' => 25230, 'certificate_id' => 4825]],
    'zero' => [['stpl_id' => 0]],
    'not a number' => [['certificate_id' => 'podnikani']],
]);
