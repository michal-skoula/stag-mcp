<?php

use App\Mcp\Servers\StagMcpServer;
use App\Mcp\Tools\GetProgramInfoTool;
use App\Models\User;
use App\Services\Programmes\ProgrammeCatalogue;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\ProgrammeFixtures as F;

function getProgramInfo(array $arguments = ['programme_id' => 2209]): mixed
{
    return StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetProgramInfoTool::class, $arguments);
}

/**
 * @param  array<string, mixed>  $overrides  url pattern => response
 */
function fakeProgramme(array $overrides = []): void
{
    Http::fake(array_merge([
        F::INFO_URL => Http::response(F::programme()),
        F::OBLASTI_URL => Http::response(['item' => [['stprIdno' => 2209, 'oblastVzdelavani' => 'Inf', 'oblastVzdelavaniText' => 'Informatika (100%)', 'tematickeOkruhy' => null]]]),
        F::OBORY_URL => Http::response(['oborInfo' => [
            F::obor(4608, 'Počítačové vědy', 'Computer Sciences', 'PV23bp'),
            F::obor(4609, 'Výpočetní technika', 'Computer Science and Engineering', 'VT23bp'),
        ]]),
        F::PLANY_OBORU_URL => fn ($request) => Http::response(['planInfo' => (string) $request['oborIdno'] === '4608'
            ? [F::plan(25225, 4608, '2024'), F::plan(25289, 4608, '2026'), F::plan(25224, 4608, '2025')]
            : [F::plan(25292, 4609, '2026')]]),
    ], $overrides));
}

it('describes one programme without a STAG ticket', function () {
    fakeProgramme();

    getProgramInfo()
        ->assertOk()
        ->assertSee('"programme_id":2209,"code":"B0613A140036","name":"Informatika","name_en":"Informatics","faculty":"FAV","level":"bachelor","form":"full-time","language":"cs","degree":"Bc.","standard_years":3,"credits":180')
        ->assertSee('"max_years":5,"profile":"akademický","accredited_until":"17.3.2033","areas":["Informatika (100%)"]')
        ->assertSee('"guarantor":{"name":"Novák Jan, prof. Ing. Ph.D.","teacher_id":12345}');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'getStudijniProgramInfo') && (string) $request['stprIdno'] === '2209');
});

it('keeps the Czech prose and drops placeholders and accreditation detail', function () {
    fakeProgramme();

    getProgramInfo()
        ->assertOk()
        ->assertSee('"graduate_profile":"Absolvent může pokračovat v navazujícím magisterském studiu."')
        ->assertSee('"job_positions":"- programátor, analytik"')
        ->assertSee('"admission_url":"www.fav.zcu.cz","intake":null')
        ->assertDontSee('***')
        ->assertDontSee('ZCU004505')
        ->assertDontSee('koef');
});

it('reads a lone dash as no text', function () {
    fakeProgramme([F::INFO_URL => Http::response(F::programme(['cile' => ' - ']))]);

    getProgramInfo()->assertOk()->assertSee('"about":{"goals":null,');
});

it('lists specialisations with this year\'s plans, newest version first', function () {
    fakeProgramme();

    getProgramInfo()
        ->assertOk()
        ->assertSee('{"specialisation_id":4608,"name":"Počítačové vědy","name_en":"Computer Sciences","code":"PV23bp","credits":180,"plans":[{"stpl_id":25289,"version":"2026","valid_for":"2026","semesters":6,"credits":180},{"stpl_id":25224,"version":"2025"')
        ->assertSee('{"stpl_id":25225,"version":"2024"')
        ->assertSee('"specialisation_id":4609');
});

it('skips the plans of a programme with too many specialisations', function () {
    $obory = array_map(fn (int $i) => F::obor(5000 + $i, "Kurz {$i}", "Course {$i}", "K{$i}"), range(1, ProgrammeCatalogue::MAX_SPECIALISATIONS_WITH_PLANS + 1));
    fakeProgramme([F::OBORY_URL => Http::response(['oborInfo' => $obory])]);

    getProgramInfo()
        ->assertOk()
        ->assertSee('"code":"K13","credits":180,"plans":null');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'getPlanyOboru'));
});

it('degrades a refused area or plan lookup to null', function () {
    fakeProgramme([
        F::OBLASTI_URL => Http::response('Internal Server Error', 500),
        F::PLANY_OBORU_URL => Http::response('Internal Server Error', 500),
    ]);

    getProgramInfo()
        ->assertOk()
        ->assertSee('"areas":null')
        ->assertSee('"code":"PV23bp","credits":180,"plans":null');
});

it('errors on an unknown programme', function () {
    fakeProgramme([F::INFO_URL => Http::response('', 204)]);

    getProgramInfo(['programme_id' => 99999999])
        ->assertHasErrors(['No study programme found with programme_id 99999999']);
});

it('requires a valid programme_id before calling STAG', function (array $arguments) {
    Http::fake();

    getProgramInfo($arguments)->assertHasErrors();

    Http::assertNothingSent();
})->with([
    'missing' => [[]],
    'zero' => [['programme_id' => 0]],
    'text' => [['programme_id' => 'abc']],
]);

it('errors when there is no authenticated user', function () {
    Http::fake();

    StagMcpServer::tool(GetProgramInfoTool::class, ['programme_id' => 2209])
        ->assertHasErrors()
        ->assertSee('must send a bearer token');

    Http::assertNothingSent();
});

it('reports a STAG failure on the programme itself', function () {
    fakeProgramme([F::INFO_URL => Http::response('Internal Server Error', 500)]);

    getProgramInfo()->assertHasErrors(['unexpected HTTP 500']);
});
