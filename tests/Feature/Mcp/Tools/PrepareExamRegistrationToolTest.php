<?php

use App\Mcp\Servers\StagMcpServer;
use App\Mcp\Tools\PrepareExamRegistrationTool;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../../Fixtures/ExamTerms.php';

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(Carbon::parse('2026-11-22 12:00:00', 'Europe/Prague'));
    config(['services.stag.exam_registration' => true]);
});

afterEach(function () {
    /* Preparing must never reach a write endpoint. */
    Http::assertNotSent(fn (HttpRequest $request) => preg_match('#/terminy/(zapis|odhlas)#', $request->url()) === 1);
});

function prepareStag(array $search = [], array $student = [], array $registered = []): FakeExamStag
{
    $fake = new FakeExamStag;
    $fake->search = $search === [] ? [terminyRow()] : $search;
    $fake->student = $student;
    $fake->registered = $registered;
    $fake->install();

    return $fake;
}

function prepareExam(array $arguments = ['term_id' => 1239020, 'action' => 'register']): mixed
{
    return StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(PrepareExamRegistrationTool::class, $arguments);
}

it('is not available until the owner switches exam registration on', function () {
    config(['services.stag.exam_registration' => false]);
    Http::fake();

    prepareExam()->assertHasErrors();

    Http::assertNothingSent();
});

it('refuses a caller with no STAG ticket', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(PrepareExamRegistrationTool::class, ['term_id' => 1239020, 'action' => 'register'])
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('rejects a missing term or an unknown action before calling STAG', function (array $arguments) {
    Http::fake();

    prepareExam($arguments)->assertHasErrors();

    Http::assertNothingSent();
})->with([
    'no term' => [['action' => 'register']],
    'no action' => [['term_id' => 1239020]],
    'unknown action' => [['term_id' => 1239020, 'action' => 'enrol']],
]);

it('returns the term, the facts to restate and a token', function () {
    prepareStag(student: [terminyStudentRow()]);

    prepareExam()
        ->assertOk()
        ->assertSee('"action":"register"')
        ->assertSee('"term_id":1239020')
        ->assertSee('"restate":{"subject":"KIV/DB1","date":"2027-01-11","time_from":"13:00","room":"UU 108"}')
        ->assertSee('"expires_at":"2026-11-22 12:05:00"')
        ->assertSee('"warnings":[]')
        ->assertSee('"token":"');
});

it('reads the term by id and the caller\'s state', function () {
    prepareStag(student: [terminyStudentRow()]);

    prepareExam()->assertOk();

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'getTerminyZkousek')
        && $request->data() == ['termIdno' => '1239020', 'zobrazitBlokovane' => 'false']);
    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'getTerminyProStudenta'));
});

it('errors on an unknown term', function () {
    prepareStag();

    prepareExam(['term_id' => 42, 'action' => 'register'])
        ->assertHasErrors(['No exam term with term_id 42']);
});

it('refuses a change the caller\'s state rules out', function (string $action, array $studentOverrides, array $termOverrides, string $error) {
    prepareStag(
        search: [terminyRow($termOverrides)],
        student: [terminyStudentRow([...$termOverrides, ...$studentOverrides])],
    );

    prepareExam(['term_id' => 1239020, 'action' => $action])
        ->assertHasErrors([$error]);
})->with([
    'register twice' => ['register', ['zapsan' => true], [], 'already registered'],
    'unregister when not on it' => ['unregister', [], [], 'not registered'],
    'register on a cancelled term' => ['register', [], ['platnost' => 'N'], 'is cancelled'],
    'join the queue twice' => ['join_queue', ['frontaZapsan' => true], ['limit' => '19'], 'already in the waiting queue'],
    'queue while registered' => ['join_queue', ['zapsan' => true], [], 'already registered'],
    'leave a queue not joined' => ['leave_queue', [], [], 'not in the waiting queue'],
]);

it('passes on STAG\'s reason when it reports the change as not allowed', function () {
    prepareStag(student: [terminyStudentRow([
        'lzeZapsatOdepsat' => false,
        'textDuvoduProcNelzeZapsatOdepsat' => 'Nemáte splněný zápočet.',
    ])]);

    prepareExam()
        ->assertOk()
        ->assertSee('STAG currently reports this change as not allowed: Nemáte splněný zápočet.');
});

it('warns but still prepares an unregister after the deadline', function () {
    prepareStag(
        search: [terminyRow(['deadlineDatumOdhlaseni' => ['value' => '21.11.2026 13:00']])],
        student: [terminyStudentRow(['zapsan' => true, 'deadlineDatumOdhlaseni' => ['value' => '21.11.2026 13:00']])],
    );

    prepareExam(['term_id' => 1239020, 'action' => 'unregister'])
        ->assertOk()
        ->assertSee('The deadline to unregister from this term passed on 2026-11-21 13:00. STAG decides whether it still accepts the change.')
        ->assertSee('"token":"');
});

it('points a full term at the waiting queue', function () {
    prepareStag(
        search: [terminyRow(['obsazeni' => '40'])],
        student: [terminyStudentRow(['obsazeni' => '40'])],
    );

    prepareExam()
        ->assertOk()
        ->assertSee('The term is full. join_queue');
});

it('warns when STAG\'s student view does not list the term', function () {
    prepareStag();

    prepareExam()
        ->assertOk()
        ->assertSee('whether the change is allowed is unknown until STAG answers');
});

it('errors for a caller with no student role', function () {
    $fake = prepareStag();
    $fake->osCislo = null;

    prepareExam()->assertHasErrors(['no student role']);
});
