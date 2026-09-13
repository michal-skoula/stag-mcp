<?php

use App\Mcp\Servers\StagMcpServer;
use App\Mcp\Tools\UserInfoTool;
use App\Models\User;
use Illuminate\Support\Facades\Http;

const STAG_USER_LIST_V2 = 'stag-ws.zcu.cz/ws/services/rest2/help/getStagUserListForActualUserV2*';
const STAG_GET_STUDENT_INFO = 'stag-ws.zcu.cz/ws/services/rest2/student/getStudentInfo*';
const STAG_GET_UCITEL_INFO = 'stag-ws.zcu.cz/ws/services/rest2/ucitel/getUcitelInfo*';

/**
 * One student role row, shaped exactly as getStagUserListForActualUserV2
 * returns it.
 *
 * @return array<string, mixed>
 */
function whoAmIStudentRole(array $overrides = []): array
{
    return array_merge([
        'userName' => 'A25B0093P',
        'role' => 'ST',
        'roleNazev' => 'Student',
        'fakulta' => 'FAV',
        'katedra' => null,
        'ucitIdno' => null,
        'osCislo' => 'A25B0093P',
        'email' => 'skoulam@students.zcu.cz',
    ], $overrides);
}

/**
 * The teaching half of a dual-role account: ucitIdno set, osCislo null.
 *
 * @return array<string, mixed>
 */
function whoAmITeacherRole(array $overrides = []): array
{
    return array_merge([
        'userName' => 'CHUPAC',
        'role' => 'VY',
        'roleNazev' => 'Vyučující',
        'fakulta' => 'FPR',
        'katedra' => 'KPO',
        'ucitIdno' => 283755,
        'osCislo' => null,
        'email' => 'chupac@fpr.zcu.cz',
    ], $overrides);
}

/**
 * @param  array<int, array<string, mixed>>  $roles
 * @return array<string, mixed>
 */
function whoAmIUserList(array $roles, array $overrides = []): array
{
    return array_merge([
        'jmeno' => 'Michal',
        'prijmeni' => 'ŠKOULA',
        'titulPred' => null,
        'titulZa' => null,
        'email' => 'skoulam@students.zcu.cz',
        'stagUserInfo' => $roles,
    ], $overrides);
}

/**
 * One student/getStudentInfo payload, Czech keys and STAG's own string-quoted
 * numbers included.
 *
 * @return array<string, mixed>
 */
function whoAmIStudentInfo(array $overrides = []): array
{
    return array_merge([
        'osCislo' => 'A25B0093P',
        'jmeno' => 'Michal',
        'prijmeni' => 'ŠKOULA',
        'titulPred' => null,
        'titulZa' => null,
        'stav' => 'S',
        'userName' => 'skoulam',
        'stprIdno' => '2210',
        'nazevSp' => 'Softwarové inženýrství',
        'fakultaSp' => 'FAV',
        'kodSp' => 'B0613A140037',
        'formaSp' => 'P',
        'typSp' => 'B',
        'typSpKey' => '7',
        'mistoVyuky' => 'P',
        'rocnik' => '2',
        'financovani' => '1',
        'oborKomb' => 'SWI23bp',
        'oborIdnos' => '4610',
        'email' => 'skoulam@students.zcu.cz',
        'maxDobaDatum' => null,
        'simsP58' => null,
        'simsP59' => null,
        'cisloKarty' => '042b15b2b07a80',
        'pohlavi' => 'M',
        'rozvrhovyKrouzek' => null,
        'studijniKruh' => null,
        'evidovanBankovniUcet' => 'N',
        'studReferentkaUsername' => 'SUTNEROVA',
        'studReferentkaUcitidno' => '54602',
        'studReferentkaEmail' => 'sutnerov@fav.zcu.cz',
        'studReferentkaTelefon' => '377632010',
        'studReferentkaPrijmeniJmeno' => 'Sutnerová Petra',
        'planovaneOdevzdaniVSKP' => null,
        'planovaneOdevzdaniVSKPText' => null,
    ], $overrides);
}

/**
 * @return array<string, mixed>
 */
function whoAmIUcitelInfo(array $overrides = []): array
{
    return array_merge([
        'ucitIdno' => 283755,
        'jmeno' => 'Miroslav',
        'prijmeni' => 'Chupáč',
        'titulPred' => 'JUDr.',
        'titulZa' => null,
        'platnost' => 'A',
        'zamestnanec' => 'A',
        'katedra' => 'KPO',
        'pracovisteDalsi' => 'KPO',
        'email' => 'chupac@fpr.zcu.cz',
        'telefon' => null,
        'telefon2' => null,
        'url' => null,
        'phdSkolitel' => 'N',
        'zkrBudovy' => null,
        'cisloMistnosti' => null,
    ], $overrides);
}

it('refuses a caller with no STAG ticket', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(UserInfoTool::class)
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('returns the name and the study detail behind a student ticket', function () {
    Http::fake([
        STAG_USER_LIST_V2 => Http::response(whoAmIUserList([whoAmIStudentRole()])),
        STAG_GET_STUDENT_INFO => Http::response(whoAmIStudentInfo()),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(UserInfoTool::class)
        ->assertOk()
        ->assertSee('"full":"Michal ŠKOULA"')
        ->assertSee('"os_cislo":"A25B0093P"')
        ->assertSee('"state":"studying"')
        ->assertSee('"form":"full-time"')
        ->assertSee('"type":"bachelor"')
        ->assertSee('"rocnik":"2"')
        ->assertSee('"name":"Softwarové inženýrství"')
        ->assertSee('"teacher":null');
});

it('fetches the student detail for the role row osCislo', function () {
    Http::fake([
        STAG_USER_LIST_V2 => Http::response(whoAmIUserList([whoAmIStudentRole()])),
        STAG_GET_STUDENT_INFO => Http::response(whoAmIStudentInfo()),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(UserInfoTool::class)
        ->assertOk();

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'getStudentInfo')
        && $request['osCislo'] === 'A25B0093P');
});

it('drops the card number and the other fields STAG volunteers', function () {
    Http::fake([
        STAG_USER_LIST_V2 => Http::response(whoAmIUserList([whoAmIStudentRole()])),
        STAG_GET_STUDENT_INFO => Http::response(whoAmIStudentInfo()),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(UserInfoTool::class)
        ->assertOk()
        ->assertDontSee('042b15b2b07a80')
        ->assertDontSee('cisloKarty')
        ->assertDontSee('pohlavi')
        ->assertDontSee('evidovanBankovniUcet')
        ->assertDontSee('typSpKey')
        ->assertDontSee('study_advisor')
        ->assertDontSee('Sutnerová Petra');
});

it('never asks STAG for the personal data endpoint', function () {
    Http::fake([
        STAG_USER_LIST_V2 => Http::response(whoAmIUserList([whoAmIStudentRole()])),
        STAG_GET_STUDENT_INFO => Http::response(whoAmIStudentInfo()),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(UserInfoTool::class)
        ->assertOk()
        ->assertDontSee('rodneCislo');

    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'getStudentOsobniUdaje'));
});

it('describes both halves of a dual-role account', function () {
    Http::fake([
        STAG_USER_LIST_V2 => Http::response(whoAmIUserList(
            [whoAmITeacherRole(), whoAmIStudentRole(['userName' => 'R21P0002P', 'osCislo' => 'R21P0002P', 'fakulta' => 'FPR'])],
            ['jmeno' => 'Miroslav', 'prijmeni' => 'Chupáč', 'titulPred' => 'JUDr.', 'email' => 'chupac@fpr.zcu.cz'],
        )),
        STAG_GET_STUDENT_INFO => Http::response(whoAmIStudentInfo(['osCislo' => 'R21P0002P', 'typSp' => 'D', 'rocnik' => '6'])),
        STAG_GET_UCITEL_INFO => Http::response(whoAmIUcitelInfo()),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(UserInfoTool::class)
        ->assertOk()
        ->assertSee('"full":"JUDr. Miroslav Chupáč"')
        ->assertSee('"ucit_idno":283755')
        ->assertSee('"platnost":true')
        ->assertSee('"phd_skolitel":false')
        ->assertSee('"type":"doctoral"');

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'getUcitelInfo')
        && (string) $request['ucitIdno'] === '283755');
});

it('keeps the role row when the teacher detail comes back empty', function () {
    Http::fake([
        STAG_USER_LIST_V2 => Http::response(whoAmIUserList([whoAmITeacherRole()])),
        STAG_GET_UCITEL_INFO => Http::response([]),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(UserInfoTool::class)
        ->assertOk()
        ->assertSee('"role":"VY"')
        ->assertSee('"katedra":"KPO"')
        ->assertSee('"teacher":null');
});

it('keeps the role row when the detail call is refused', function () {
    Http::fake([
        STAG_USER_LIST_V2 => Http::response(whoAmIUserList([whoAmIStudentRole()])),
        STAG_GET_STUDENT_INFO => Http::response('Role rejected', 403),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(UserInfoTool::class)
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"os_cislo":"A25B0093P"')
        ->assertSee('"student":null');
});

it('falls back to the raw code when STAG sends an unknown one', function () {
    Http::fake([
        STAG_USER_LIST_V2 => Http::response(whoAmIUserList([whoAmIStudentRole()])),
        STAG_GET_STUDENT_INFO => Http::response(whoAmIStudentInfo(['stav' => 'X'])),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(UserInfoTool::class)
        ->assertOk()
        ->assertSee('"state":"X"');
});

it('handles an account with no roles at all', function () {
    Http::fake([STAG_USER_LIST_V2 => Http::response(whoAmIUserList([]))]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(UserInfoTool::class)
        ->assertOk()
        ->assertSee('"roles":[]');
});
