<?php

use App\Mcp\Servers\StagMcpServer;
use App\Mcp\Tools\ConfirmExamRegistrationTool;
use App\Mcp\Tools\PrepareExamRegistrationTool;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\Fluent\AssertableJson;

require_once __DIR__.'/../../../Fixtures/ExamTerms.php';

const CONFIRM_RESTATED = ['subject' => 'KIV/DB1', 'date' => '2027-01-11', 'time_from' => '13:00', 'room' => 'UU 108'];

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(Carbon::parse('2026-11-22 12:00:00', 'Europe/Prague'));
    config(['services.stag.exam_registration' => true]);

    $this->stag = new FakeExamStag;
    $this->stag->search = [terminyRow()];
    $this->stag->student = [terminyStudentRow()];
    $this->stag->install();

    $this->user = User::factory()->withStagToken()->create();
});

/**
 * Runs prepare-exam-registration and returns its token.
 */
function prepareToken(User $user, string $action = 'register'): string
{
    $token = null;

    StagMcpServer::actingAs($user, 'sanctum')
        ->tool(PrepareExamRegistrationTool::class, ['term_id' => 1239020, 'action' => $action])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use (&$token) {
            $token = $json->toArray()['confirmation']['token'];
            $json->etc();
        });

    return $token;
}

function confirmExam(User $user, string $token, array $restated = CONFIRM_RESTATED): mixed
{
    return StagMcpServer::actingAs($user, 'sanctum')
        ->tool(ConfirmExamRegistrationTool::class, ['confirmation_token' => $token, ...$restated]);
}

function writesSent(): int
{
    return Http::recorded(fn (HttpRequest $request) => preg_match('#/terminy/(zapis|odhlas)#', $request->url()) === 1)->count();
}

it('is not available until the owner switches exam registration on', function () {
    $token = prepareToken($this->user);
    config(['services.stag.exam_registration' => false]);

    confirmExam($this->user, $token)->assertHasErrors();

    expect(writesSent())->toBe(0);
});

it('registers once the restated term matches', function () {
    $token = prepareToken($this->user);
    $this->stag->student = [terminyStudentRow(['zapsan' => true, 'obsazeni' => '20'])];

    confirmExam($this->user, $token)
        ->assertOk()
        ->assertSee('"action":"register","stag_message":"OK"')
        ->assertSee('"registration":{"registered":true');

    expect(writesSent())->toBe(1);
    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'terminy/zapisStudentaNaTermin')
        && $request->data() == ['osCislo' => TERMINY_OS_CISLO, 'stagUser' => TERMINY_OS_CISLO, 'termIdno' => '1239020']
        && $request->hasHeader('Accept', 'text/plain'));
});

it('calls the endpoint matching each action', function (string $action, array $studentState, string $endpoint) {
    $this->stag->student = [terminyStudentRow($studentState)];
    $token = prepareToken($this->user, $action);

    confirmExam($this->user, $token)->assertOk();

    expect(writesSent())->toBe(1);
    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), "terminy/{$endpoint}"));
})->with([
    'register' => ['register', [], 'zapisStudentaNaTermin'],
    'unregister' => ['unregister', ['zapsan' => true], 'odhlasStudentaZTerminu'],
    'join queue' => ['join_queue', [], 'zapisStudentaDoFronty'],
    'leave queue' => ['leave_queue', ['frontaZapsan' => true], 'odhlasStudentaZFronty'],
]);

it('forgives case, spacing and a missing leading zero in the restatement', function () {
    $this->stag->search = [terminyRow(['casOd' => '09:00:00'])];
    $this->stag->student = [terminyStudentRow(['casOd' => '09:00:00'])];
    $token = prepareToken($this->user);

    confirmExam($this->user, $token, ['subject' => 'kiv/db1', 'date' => '2027-01-11', 'time_from' => '9:00', 'room' => 'uu108'])
        ->assertOk();

    expect(writesSent())->toBe(1);
});

it('refuses a restatement naming a different term, and spends the token', function (array $restated, string $field) {
    $token = prepareToken($this->user);

    confirmExam($this->user, $token, [...CONFIRM_RESTATED, ...$restated])
        ->assertHasErrors(["{$field} \"", 'Nothing was changed']);
    confirmExam($this->user, $token)->assertHasErrors(['unknown, expired or already used']);

    expect(writesSent())->toBe(0);
})->with([
    'subject' => [['subject' => 'KIV/DB2'], 'subject'],
    'date' => [['date' => '2027-01-12'], 'date'],
    'time' => [['time_from' => '14:00'], 'time_from'],
    'room' => [['room' => 'UU 208'], 'room'],
]);

it('accepts a token only once', function () {
    $token = prepareToken($this->user);

    confirmExam($this->user, $token)->assertOk();
    confirmExam($this->user, $token)->assertHasErrors(['already used']);

    expect(writesSent())->toBe(1);
});

it('refuses a token prepared by someone else', function () {
    $token = prepareToken(User::factory()->withStagToken()->create());

    confirmExam($this->user, $token)->assertHasErrors(['unknown, expired or already used']);

    expect(writesSent())->toBe(0);
});

it('refuses a token after five minutes', function () {
    $token = prepareToken($this->user);
    $this->travel(301)->seconds();

    confirmExam($this->user, $token)->assertHasErrors(['unknown, expired or already used']);

    expect(writesSent())->toBe(0);
});

it('refuses when the term moved since it was prepared', function () {
    $token = prepareToken($this->user);
    $this->stag->search = [terminyRow(['mistnost' => '208'])];

    confirmExam($this->user, $token)
        ->assertHasErrors(['changed in STAG since it was prepared', 'UU 208']);

    expect(writesSent())->toBe(0);
});

it('refuses when the term disappeared since it was prepared', function () {
    $token = prepareToken($this->user);
    $this->stag->search = [];

    confirmExam($this->user, $token)->assertHasErrors(['no longer exists']);

    expect(writesSent())->toBe(0);
});

it('reports STAG\'s reason when it answers anything but OK', function () {
    $token = prepareToken($this->user);
    $this->stag->writeResponse = Http::response('Termín je již plně obsazen.', 200, ['Content-Type' => 'text/plain']);

    confirmExam($this->user, $token)
        ->assertHasErrors(['STAG did not make the change: Termín je již plně obsazen.']);
});

it('passes on the body of an HTTP error from STAG', function () {
    $token = prepareToken($this->user);
    $this->stag->writeResponse = Http::response('Pri volani studentem musi byt parametry stagUser a osCislo stejne!', 500);

    confirmExam($this->user, $token)
        ->assertHasErrors(['STAG said: Pri volani studentem', 'Check list-exam-dates']);
});

it('says the outcome is unknown when STAG does not answer the write', function () {
    $token = prepareToken($this->user);
    $this->stag->writeResponse = fn () => throw new ConnectionException('timed out');

    confirmExam($this->user, $token)
        ->assertHasErrors(['whether the change went through is unknown']);
});

it('still reports success when the state cannot be read back', function () {
    $token = prepareToken($this->user);
    $this->stag->studentResponse = Http::response('Forbidden', 403);

    confirmExam($this->user, $token)
        ->assertOk()
        ->assertSee('"stag_message":"OK","term":null');
});

it('rejects a call missing any restated fact before touching STAG', function (string $missing) {
    $token = prepareToken($this->user);
    $restated = CONFIRM_RESTATED;
    unset($restated[$missing]);

    confirmExam($this->user, $token, $restated)->assertHasErrors();

    expect(writesSent())->toBe(0);
})->with(['subject', 'date', 'time_from', 'room']);
