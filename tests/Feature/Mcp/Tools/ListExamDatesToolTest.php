<?php

use App\Mcp\Servers\StagMcpServer;
use App\Mcp\Tools\ListExamDatesTool;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../../Fixtures/ExamTerms.php';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-22 12:00:00', 'Europe/Prague'));
});

afterEach(function () {
    /* The terminy writes are GETs too, so guard every test against reaching one. */
    Http::assertNotSent(fn (HttpRequest $request) => preg_match('#/terminy/(zapis|odhlas)#', $request->url()) === 1);
});

/**
 * Fakes the three reads the tool makes. getTerminyZkousek answers both the
 * search and the student's registered-terms lookup, told apart by osCislo.
 *
 * @param  list<array<string, mixed>>  $search
 * @param  list<array<string, mixed>>  $student
 * @param  list<array<string, mixed>>  $registered
 */
function fakeTerminy(array $search = [], array $student = [], array $registered = [], ?string $osCislo = TERMINY_OS_CISLO, mixed $studentResponse = null): void
{
    $fake = new FakeExamStag;
    $fake->search = $search;
    $fake->student = $student;
    $fake->registered = $registered;
    $fake->osCislo = $osCislo;
    $fake->studentResponse = $studentResponse;
    $fake->install();
}

function listExamDates(array $arguments = []): mixed
{
    return StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(ListExamDatesTool::class, $arguments);
}

it('refuses a caller with no STAG ticket', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(ListExamDatesTool::class, ['katedra' => 'KIV'])
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('rejects arguments STAG would silently answer with nothing', function (array $arguments) {
    Http::fake();

    listExamDates($arguments)->assertHasErrors();

    Http::assertNothingSent();
})->with([
    'semester' => [['katedra' => 'KIV', 'semestr' => 'XX']],
    'year as a range' => [['katedra' => 'KIV', 'rok' => '2025/2026']],
    'wildcard' => [['katedra' => 'KI%']],
    'count over max' => [['katedra' => 'KIV', 'count' => 201]],
]);

it('forwards a subject search to STAG without room bookings', function () {
    fakeTerminy(search: [terminyRow()]);

    listExamDates(['katedra' => 'KIV', 'zkratka' => 'DB1', 'rok' => 2026, 'semestr' => 'ZS'])->assertOk();

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'getTerminyZkousek')
        && ! isset($request->data()['osCislo'])
        && $request->data() == ['katedra' => 'KIV', 'zkratka' => 'DB1', 'rok' => '2026', 'semestr' => 'ZS', 'zobrazitBlokovane' => 'false']);
});

it('maps a term into dates, times, capacity and deadlines', function () {
    fakeTerminy(search: [terminyRow()]);

    listExamDates(['katedra' => 'KIV', 'zkratka' => 'DB1'])
        ->assertOk()
        ->assertSee('"term_id":1239020')
        ->assertSee('"subject":{"katedra":"KIV","zkratka":"DB1","rok":"2026","semestr":"ZS"}')
        ->assertSee('"type":"exam","type_label":"Zkouška","status":"valid"')
        ->assertSee('"date":"2027-01-11","time":{"from":"13:00","to":"15:30"}')
        ->assertSee('"location":{"building":"UU","room":"108"}')
        ->assertSee('"examiner":{"teacher_id":10001,"name":"Ing. Jan Novák, Ph.D."}')
        ->assertSee('"capacity":{"registered":19,"limit":40,"free":21}')
        ->assertSee('"group":null')
        ->assertSee('"attempts_allowed":"first_attempt_only"')
        ->assertSee('"register_by":"2027-01-11 12:00","register_by_passed":false,"unregister_by":"2027-01-10 13:00","unregister_by_passed":false')
        ->assertDontSee('novak@example.com');
});

it('reports an unlimited term and a capacity group', function () {
    fakeTerminy(search: [terminyRow([
        'limit' => null,
        'grteIdno' => 283531,
        'skupinaObsazeni' => '11',
        'nadSkupinaObsazeni' => '20',
        'typTerminu' => 'Zápočet',
        'opravny' => 'N',
    ])]);

    listExamDates(['katedra' => 'KIV'])
        ->assertOk()
        ->assertSee('"capacity":{"registered":19,"limit":null,"free":null}')
        ->assertSee('"group":{"id":283531,"registered":11,"limit":null,"parent_registered":20,"parent_limit":null}')
        ->assertSee('"type":"credit"')
        ->assertSee('"attempts_allowed":"all"');
});

it('drops room bookings even if STAG sends them', function () {
    fakeTerminy(search: [
        terminyRow(),
        terminyRow(['termIdno' => 2, 'platnost' => 'B', 'poznamka' => 'SZZ Bc. - I. termín']),
    ]);

    listExamDates(['katedra' => 'KIV'])
        ->assertOk()
        ->assertSee('"total":1')
        ->assertDontSee('SZZ Bc.');
});

it('hides past and cancelled terms unless asked', function () {
    fakeTerminy(search: [
        terminyRow(),
        terminyRow(['termIdno' => 2, 'datum' => ['value' => '12.10.2026']]),
        terminyRow(['termIdno' => 3, 'platnost' => 'N']),
    ]);

    listExamDates(['katedra' => 'KIV'])->assertSee('"total":1');
    listExamDates(['katedra' => 'KIV', 'include_past' => true])->assertSee('"total":2');
    listExamDates(['katedra' => 'KIV', 'include_cancelled' => true])->assertSee('"total":2')->assertSee('"status":"cancelled"');
});

it('keeps a term happening today', function () {
    fakeTerminy(search: [terminyRow(['datum' => ['value' => '22.11.2026'], 'casOd' => '08:00:00'])]);

    listExamDates(['katedra' => 'KIV'])->assertSee('"total":1');
});

it('keeps a cancelled term the caller is still registered on', function () {
    fakeTerminy(
        search: [terminyRow(['platnost' => 'N'])],
        registered: [terminyRow(['platnost' => 'N'])],
    );

    listExamDates(['katedra' => 'KIV'])
        ->assertSee('"total":1')
        ->assertSee('"status":"cancelled"');
});

it('marks the caller\'s registration state on search results', function () {
    fakeTerminy(
        search: [
            terminyRow(['termIdno' => 1]),
            terminyRow(['termIdno' => 2]),
            terminyRow(['termIdno' => 3]),
        ],
        student: [terminyStudentRow([
            'termIdno' => 1,
            'lzeZapsatOdepsat' => false,
            'kodDuvoduProcNelzeZapsatOdepsat' => 'X1',
            'textDuvoduProcNelzeZapsatOdepsat' => 'Termín je plně obsazen.',
        ])],
        registered: [terminyRow(['termIdno' => 2])],
    );

    listExamDates(['katedra' => 'KIV'])
        ->assertOk()
        ->assertSee('"os_cislo":"'.TERMINY_OS_CISLO.'"')
        ->assertSee('"registration":{"registered":false,"can_change":false,"reason_code":"X1","reason":"Termín je plně obsazen.","reason_detail":null,"queue":{"registered":false,"can_change":false,"reason":null}}')
        ->assertSee('"term_id":2')
        ->assertSee('"registration":{"registered":true,"can_change":null')
        ->assertSee('"registration":{"registered":false,"can_change":null');

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'getTerminyProStudenta')
        && $request->data() == ['osCislo' => TERMINY_OS_CISLO, 'stagUser' => TERMINY_OS_CISLO]);
});

it('filters a search to the terms the caller is registered on', function () {
    fakeTerminy(
        search: [terminyRow(['termIdno' => 1]), terminyRow(['termIdno' => 2])],
        registered: [terminyRow(['termIdno' => 2])],
    );

    listExamDates(['katedra' => 'KIV', 'registered_only' => true])
        ->assertSee('"total":1')
        ->assertSee('"term_id":2');
});

it('returns search results without registration for a caller with no student role', function () {
    fakeTerminy(search: [terminyRow()], osCislo: null);

    listExamDates(['katedra' => 'KIV'])
        ->assertOk()
        ->assertSee('"os_cislo":null')
        ->assertSee('"registration":null');

    Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), 'getTerminyProStudenta'));
});

it('still returns search results when STAG refuses the registration lookup', function () {
    fakeTerminy(search: [terminyRow()], studentResponse: Http::response('Forbidden', 403));

    listExamDates(['katedra' => 'KIV'])
        ->assertOk()
        ->assertSee('"total":1')
        ->assertSee('"registration":null');
});

it('lists the caller\'s own terms by merging both student sources', function () {
    fakeTerminy(
        student: [
            terminyStudentRow(['termIdno' => 1, 'zapsan' => true]),
            terminyStudentRow(['termIdno' => 2, 'predmet' => 'PPA2', 'datum' => ['value' => '20.1.2027']]),
        ],
        registered: [
            terminyRow(['termIdno' => 1]),
            terminyRow(['termIdno' => 3, 'predmet' => 'OKS', 'datum' => ['value' => '5.1.2027']]),
        ],
    );

    listExamDates()
        ->assertOk()
        ->assertSee('"source":"student"')
        ->assertSee('"total":3')
        ->assertSee('"registration":{"registered":true,"can_change":true');

    Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), 'getTerminyZkousek')
        && ! isset($request->data()['osCislo']));
});

it('orders terms by date and start time', function () {
    fakeTerminy(search: [
        terminyRow(['termIdno' => 3, 'datum' => ['value' => '20.1.2027']]),
        terminyRow(['termIdno' => 2, 'datum' => ['value' => '5.1.2027'], 'casOd' => '13:00:00']),
        terminyRow(['termIdno' => 1, 'datum' => ['value' => '5.1.2027'], 'casOd' => '08:00:00']),
    ]);

    $ids = listExamDates(['katedra' => 'KIV'])->assertOk();

    $ids->assertSee('"terms":[{"term_id":1,');
});

it('reads an earlier year\'s registrations by rok', function () {
    fakeTerminy(
        student: [terminyStudentRow(['termIdno' => 9])],
        registered: [terminyRow(['termIdno' => 5, 'rok' => '2025', 'datum' => ['value' => '22.1.2026']])],
    );

    listExamDates(['rok' => 2025, 'include_past' => true])
        ->assertOk()
        ->assertSee('"total":1')
        ->assertSee('"term_id":5')
        ->assertDontSee('"term_id":9');

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'getTerminyZkousek')
        && ($request->data()['osCislo'] ?? null) === TERMINY_OS_CISLO
        && ($request->data()['rok'] ?? null) === '2025');
});

it('filters the caller\'s own terms by semester', function () {
    fakeTerminy(student: [
        terminyStudentRow(['termIdno' => 1, 'semestr' => 'ZS']),
        terminyStudentRow(['termIdno' => 2, 'semestr' => 'LS']),
    ]);

    listExamDates(['semestr' => 'LS'])
        ->assertSee('"total":1')
        ->assertSee('"term_id":2');
});

it('errors when listing own terms without a student role', function () {
    fakeTerminy(osCislo: null);

    listExamDates()->assertHasErrors(['no student role']);
});

it('pages through the results', function () {
    fakeTerminy(search: array_map(
        fn (int $i) => terminyRow(['termIdno' => $i, 'datum' => ['value' => sprintf('%d.1.2027', $i)]]),
        range(1, 30),
    ));

    listExamDates(['katedra' => 'KIV', 'count' => 10, 'offset' => 20])
        ->assertSee('"total":30,"offset":20,"count":10')
        ->assertSee('"terms":[{"term_id":21,');
});
