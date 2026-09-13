<?php

use App\Mcp\Servers\StagMcpServer;
use App\Mcp\Tools\StudyAdvisorTool;
use App\Models\User;
use Illuminate\Support\Facades\Http;

const STAG_ADVISOR_USER_LIST = 'stag-ws.zcu.cz/ws/services/rest2/help/getStagUserListForActualUserV2*';
const STAG_ADVISOR_STUDENT_INFO = 'stag-ws.zcu.cz/ws/services/rest2/student/getStudentInfo*';
const STAG_ADVISOR_VIZITKA = 'stag-ws.zcu.cz/ws/services/rest2/vizitka/getVizitkaByUcitidno*';

/**
 * @return array<string, mixed>
 */
function advisorUserList(array $overrides = []): array
{
    return [
        'jmeno' => 'Michal',
        'prijmeni' => 'ŠKOULA',
        'titulPred' => null,
        'titulZa' => null,
        'email' => 'skoulam@students.zcu.cz',
        'stagUserInfo' => [array_merge([
            'userName' => 'A25B0093P',
            'role' => 'ST',
            'roleNazev' => 'Student',
            'fakulta' => 'FAV',
            'katedra' => null,
            'ucitIdno' => null,
            'osCislo' => 'A25B0093P',
            'email' => 'skoulam@students.zcu.cz',
        ], $overrides)],
    ];
}

/**
 * Only the referentka block matters here; the rest of getStudentInfo is
 * exercised by UserInfoToolTest.
 *
 * @return array<string, mixed>
 */
function advisorStudentInfo(array $overrides = []): array
{
    return array_merge([
        'osCislo' => 'A25B0093P',
        'jmeno' => 'Michal',
        'prijmeni' => 'ŠKOULA',
        'stav' => 'S',
        'rocnik' => '2',
        'cisloKarty' => '042b15b2b07a80',
        'studReferentkaUsername' => 'SUTNEROVA',
        'studReferentkaUcitidno' => '54602',
        'studReferentkaEmail' => 'sutnerov@fav.zcu.cz',
        'studReferentkaTelefon' => '377632010',
        'studReferentkaPrijmeniJmeno' => 'Sutnerová Petra',
    ], $overrides);
}

/**
 * One business card, shaped as vizitka/getVizitkaByUcitidno returns it. The two
 * tsLinky rows are real: STAG repeats the room once per phone line.
 *
 * @return array<string, mixed>
 */
function advisorVizitka(array $overrides = []): array
{
    return array_merge([
        'info' => [
            'dalsiInfo' => null,
            'www' => null,
            'email' => 'sutnerov@fav.zcu.cz',
            'researcherid' => null,
            'orcid' => null,
            'preferovanaLinka' => '2010',
            'update' => '2014-09-29',
            'showKontakt' => 'A',
            'showKonzultace' => 'N',
            'showMistnost' => 'A',
        ],
        'uredniHodiny' => null,
        'psLinky' => ['linky' => []],
        'tsLinky' => ['linky' => [
            ['cislo' => '2010', 'login' => null, 'telefon' => 'A', 'fax' => 'N', 'mobil' => null,
                'budova' => 'UC', 'mistnost' => '157', 'zkratkaPracoviste' => 'DAV', 'kodPracoviste' => '52800'],
            ['cislo' => '2021', 'login' => null, 'telefon' => 'A', 'fax' => 'N', 'mobil' => null,
                'budova' => 'UC', 'mistnost' => '157', 'zkratkaPracoviste' => 'DAV', 'kodPracoviste' => '52800'],
        ]],
        'wsOsobaZivotopis' => [],
    ], $overrides);
}

function fakeAdvisor(array $studentInfo = [], array $vizitka = []): void
{
    Http::fake([
        STAG_ADVISOR_USER_LIST => Http::response(advisorUserList()),
        STAG_ADVISOR_STUDENT_INFO => Http::response(advisorStudentInfo($studentInfo)),
        STAG_ADVISOR_VIZITKA => Http::response(advisorVizitka($vizitka)),
    ]);
}

it('refuses a caller with no STAG ticket', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(StudyAdvisorTool::class)
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('returns the advisor with their office', function () {
    fakeAdvisor();

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(StudyAdvisorTool::class)
        ->assertOk()
        ->assertSee('"name":"Sutnerová Petra"')
        ->assertSee('"email":"sutnerov@fav.zcu.cz"')
        ->assertSee('"phone":"377632010"')
        ->assertSee('"offices":[{"building":"UC","room":"157","workplace":"DAV"}]')
        ->assertSee('"card_updated":"2014-09-29"');
});

it('collapses the repeated directory rows into one office', function () {
    fakeAdvisor();

    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(StudyAdvisorTool::class)
        ->assertOk();

    /* Two tsLinky rows for one room must not become two offices. */
    $response->assertSee('"offices":[{"building":"UC","room":"157","workplace":"DAV"}],');
});

it('drops the phone extensions and the login STAG volunteers', function () {
    fakeAdvisor();

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(StudyAdvisorTool::class)
        ->assertOk()
        ->assertDontSee('preferovanaLinka')
        ->assertDontSee('preferred_extension')
        ->assertDontSee('phone_extensions')
        ->assertDontSee('stag_login')
        ->assertDontSee('teacher_id')
        ->assertDontSee('SUTNEROVA')
        ->assertDontSee('2021');
});

it('always points at the faculty website for office hours', function () {
    fakeAdvisor();

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(StudyAdvisorTool::class)
        ->assertOk()
        /* Faculty site comes from the advisor's own email domain. */
        ->assertSee('Look on fav.zcu.cz')
        ->assertSee('úřední hodiny');
});

it('never returns office hours from STAG, even when STAG has them', function () {
    fakeAdvisor(vizitka: ['uredniHodiny' => ['wsUredniHodinaItem' => [[
        'hodinaOd' => '13:00', 'hodinaDo' => '14:00', 'den' => '1', 'tyden' => 'k',
        'denS' => 'Pondělí', 'tydenS' => 'Každý', 'poznamka' => null,
    ]]]]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(StudyAdvisorTool::class)
        ->assertOk()
        ->assertDontSee('13:00')
        ->assertDontSee('Pondělí')
        ->assertDontSee('"office_hours"')
        ->assertSee('Look on fav.zcu.cz');
});

it('still guides the reader when the advisor has no email to derive a site from', function () {
    fakeAdvisor(studentInfo: ['studReferentkaEmail' => null]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(StudyAdvisorTool::class)
        ->assertOk()
        ->assertSee('studijní oddělení')
        ->assertDontSee('Look on ');
});

it('looks the business card up by the advisor teacher id', function () {
    fakeAdvisor();

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(StudyAdvisorTool::class)
        ->assertOk();

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'getVizitkaByUcitidno')
        && (string) $request['ucitidno'] === '54602');
});

it('keeps the contact details when no business card is published', function () {
    Http::fake([
        STAG_ADVISOR_USER_LIST => Http::response(advisorUserList()),
        STAG_ADVISOR_STUDENT_INFO => Http::response(advisorStudentInfo()),
        STAG_ADVISOR_VIZITKA => Http::response([]),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(StudyAdvisorTool::class)
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"phone":"377632010"')
        ->assertSee('"offices":[]')
        ->assertSee('Look on fav.zcu.cz');
});

it('keeps the contact details when the business card is refused', function () {
    Http::fake([
        STAG_ADVISOR_USER_LIST => Http::response(advisorUserList()),
        STAG_ADVISOR_STUDENT_INFO => Http::response(advisorStudentInfo()),
        STAG_ADVISOR_VIZITKA => Http::response('Role rejected', 403),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(StudyAdvisorTool::class)
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"name":"Sutnerová Petra"')
        ->assertSee('"offices":[]');
});

it('returns a null advisor when the study has none recorded', function () {
    fakeAdvisor(studentInfo: [
        'studReferentkaPrijmeniJmeno' => null,
        'studReferentkaEmail' => null,
        'studReferentkaTelefon' => null,
        'studReferentkaUcitidno' => null,
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(StudyAdvisorTool::class)
        ->assertOk()
        ->assertSee('"advisor":null');

    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'getVizitkaByUcitidno'));
});

it('uses an explicit os_cislo without resolving identity', function () {
    fakeAdvisor(studentInfo: ['osCislo' => 'R21P0002P']);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(StudyAdvisorTool::class, ['os_cislo' => 'R21P0002P'])
        ->assertOk()
        ->assertSee('"os_cislo":"R21P0002P"');

    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'getStagUserListForActualUser'));
});

it('errors when no osCislo can be resolved', function () {
    Http::fake([
        STAG_ADVISOR_USER_LIST => Http::response(advisorUserList(['osCislo' => null])),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(StudyAdvisorTool::class)
        ->assertHasErrors();

    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'getStudentInfo'));
});
