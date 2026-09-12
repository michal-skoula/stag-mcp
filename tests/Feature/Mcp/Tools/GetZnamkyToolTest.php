<?php

use App\Mcp\Servers\StagMcpServer;
use App\Mcp\Tools\GetZnamkyTool;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\Fluent\AssertableJson;

const STAG_ZNAMKY_STAG_USER_LIST = 'stag-ws.zcu.cz/ws/services/rest2/help/getStagUserListForActualUser*';
const STAG_ZNAMKY_BY_STUDENT = 'stag-ws.zcu.cz/ws/services/rest2/znamky/getZnamkyByStudent*';
const STAG_ZNAMKY_TYPY_HODNOCENI = 'stag-ws.zcu.cz/ws/services/rest2/znamky/typyHodnoceni*';
const STAG_ZNAMKY_ABSOLVOVAL = 'stag-ws.zcu.cz/ws/services/rest2/student/getStudentPredmetyAbsolvoval*';

/**
 * @return array<string, mixed>
 */
function stagZnamkyUserList(array $overrides = []): array
{
    return ['stagUserInfo' => [array_merge([
        'userName' => 'A25B0093P',
        'role' => 'ST',
        'roleNazev' => 'Student',
        'fakulta' => 'FAV',
        'katedra' => null,
        'ucitIdno' => null,
        'osCislo' => 'A25B0093P',
        'email' => 'skoulam@students.zcu.cz',
    ], $overrides)]];
}

/**
 * One enrolment shaped exactly as znamky/getZnamkyByStudent returns it, Czech
 * keys, empty strings where STAG sends empty strings.
 *
 * @return array<string, mixed>
 */
function stagZnamka(array $overrides = []): array
{
    return array_merge([
        'katedra' => 'KIV',
        'zkratka' => 'DB1',
        'rok' => '2025',
        'semestr' => 'ZS',
        'os_cislo' => 'A25B0093P',
        'jmeno' => 'Michal',
        'prijmeni' => 'ŠKOULA',
        'titul' => '',
        'nesplnene_prerekvizity' => '',
        'predmetUznany' => 'N',
        'zk_tyhoidno' => 1,
        'zk_hodnidno' => 1,
        'zk_typ_hodnoceni' => '1|2|3|4',
        'zk_datum' => '19.01.2026',
        'zk_hodnoceni' => '1',
        'zk_body' => '98.00',
        'zk_pokus' => '1',
        'zk_ucit_idno' => '17895',
        'zk_jazyk' => 'CZ',
        'zk_ucit_jmeno' => 'Zíma Martin',
        'zppzk_tyhoidno' => 2,
        'zppzk_hodnidno' => 5,
        'zppzk_typ_hodnoceni' => '',
        'zppzk_datum' => '19.12.2025',
        'zppzk_hodnoceni' => 'S',
        'zppzk_pokus' => '1',
        'zppzk_ucit_idno' => '241975',
        'zppzk_ucit_jmeno' => 'Prantl Martin',
        'zppzk_uznan' => 'N',
        'stavAbsolvovani' => 'A',
    ], $overrides);
}

/**
 * An enrolment with no exam recorded: every zk_* value is the empty string or
 * null, which is how STAG represents a subject that was never assessed.
 *
 * @return array<string, mixed>
 */
function stagZnamkaUngraded(array $overrides = []): array
{
    return stagZnamka(array_merge([
        'zk_hodnidno' => null,
        'zk_datum' => '',
        'zk_hodnoceni' => '',
        'zk_body' => '',
        'zk_pokus' => '0',
        'zk_ucit_idno' => '',
        'zk_jazyk' => '',
        'zk_ucit_jmeno' => '',
        'zppzk_tyhoidno' => null,
        'zppzk_hodnidno' => null,
        'zppzk_typ_hodnoceni' => null,
        'zppzk_datum' => null,
        'zppzk_hodnoceni' => null,
        'zppzk_pokus' => null,
        'zppzk_ucit_idno' => null,
        'zppzk_ucit_jmeno' => null,
        'zppzk_uznan' => null,
    ], $overrides));
}

/**
 * @return array<string, mixed>
 */
function stagAbsolvoval(array $overrides = []): array
{
    return array_merge([
        'osCislo' => 'A25B0093P',
        'semestr' => 'ZS',
        'rok' => '2025',
        'katedra' => 'KIV',
        'zkratka' => 'DB1',
        'datum' => ['value' => '19.1.2026'],
        'absolvoval' => 'A',
        'znamka' => '1',
        'nazevPredmetu' => 'Databázové systémy 1',
        'pocetKreditu' => 6,
    ], $overrides);
}

/**
 * The two grading scales, shaped as znamky/typyHodnoceni returns them.
 * nevyplnenoHodnotaDoPrumeru is what makes an unfinished subject score a 4.
 *
 * @return list<array<string, mixed>>
 */
function stagTypyHodnoceni(): array
{
    return [
        [
            'tyhoidno' => 1,
            'zkratkaCs' => '1|2|3|4',
            'nazevCs' => '1|2|3|4',
            'urcenoPro' => 'ZJ',
            'nevyplnenoHodnotaDoPrumeru' => 4.0,
            'hodnoceni' => [
                ['hodnidno' => 1, 'tyhoidno' => 1, 'zkratkaCs' => '1', 'nazevCs' => 'Výborně', 'nazevEn' => 'Excellent', 'jeToUspech' => true, 'hodnotaDoPrumeru' => 1.0, 'doPrumeru' => true],
                ['hodnidno' => 2, 'tyhoidno' => 1, 'zkratkaCs' => '2', 'nazevCs' => 'Velmi dobře', 'nazevEn' => 'Very Good', 'jeToUspech' => true, 'hodnotaDoPrumeru' => 2.0, 'doPrumeru' => true],
                ['hodnidno' => 3, 'tyhoidno' => 1, 'zkratkaCs' => '3', 'nazevCs' => 'Dobře', 'nazevEn' => 'Good', 'jeToUspech' => true, 'hodnotaDoPrumeru' => 3.0, 'doPrumeru' => true],
                ['hodnidno' => 4, 'tyhoidno' => 1, 'zkratkaCs' => '4', 'nazevCs' => 'Nevyhověl', 'nazevEn' => 'Unsatisfactory (Fail)', 'jeToUspech' => false, 'hodnotaDoPrumeru' => 4.0, 'doPrumeru' => true],
            ],
        ],
        [
            'tyhoidno' => 2,
            'zkratkaCs' => 'S|N',
            'nazevCs' => 'S|N',
            'urcenoPro' => 'ZPJ',
            'nevyplnenoHodnotaDoPrumeru' => null,
            'hodnoceni' => [
                ['hodnidno' => 5, 'tyhoidno' => 2, 'zkratkaCs' => 'S', 'nazevCs' => 'Splněno', 'nazevEn' => 'Passed', 'jeToUspech' => true, 'hodnotaDoPrumeru' => 0.0, 'doPrumeru' => false],
                ['hodnidno' => 6, 'tyhoidno' => 2, 'zkratkaCs' => 'N', 'nazevCs' => 'Nesplněno', 'nazevEn' => 'Failed', 'jeToUspech' => false, 'hodnotaDoPrumeru' => 0.0, 'doPrumeru' => false],
            ],
        ],
    ];
}

/**
 * Fakes all three upstream calls at once.
 *
 * @param  list<array<string, mixed>>  $znamky
 * @param  list<array<string, mixed>>  $absolvoval
 */
function fakeZnamky(array $znamky, array $absolvoval = []): void
{
    Http::fake([
        STAG_ZNAMKY_STAG_USER_LIST => Http::response(stagZnamkyUserList()),
        STAG_ZNAMKY_BY_STUDENT => Http::response(['student_na_predmetu' => $znamky]),
        STAG_ZNAMKY_TYPY_HODNOCENI => Http::response(stagTypyHodnoceni()),
        STAG_ZNAMKY_ABSOLVOVAL => Http::response(['predmetAbsolvoval' => $absolvoval]),
    ]);
}

/**
 * The dev account's real first year, the set the printed FAV transcript covers.
 * Eight numeric-scale subjects and six zápočet-only ones, three of them never
 * completed.
 *
 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
 */
function stagFirstYear(): array
{
    $spec = [
        // [katedra, zkratka, semestr, credits, name, stav, hodnidno, tyhoidno, grade]
        ['KIV', 'DB1', 'ZS', 6, 'Databázové systémy 1', 'A', 1, 1, '1'],
        ['KIV', 'LNX', 'ZS', 4, 'Úvod do Linuxu', 'A', 5, 2, 'S'],
        ['KIV', 'PPA', 'ZS', 5, 'Počítače a programování', 'A', 2, 1, '2'],
        ['KIV', 'UVSI', 'ZS', 2, 'Úvod do studia informatiky', 'A', 5, 2, 'S'],
        ['KMA', 'LAA', 'ZS', 5, 'Lineární algebra', 'NX', null, 1, ''],
        ['KMA', 'MA1', 'ZS', 6, 'Matematická analýza 1', 'NX', null, 1, ''],
        ['KMA', 'SMP', 'ZS', 2, 'Seminář - maticový počet', 'NX', null, 2, ''],
        ['UTS', 'TV', 'ZS', 1, 'Tělesná výchova', 'A', 5, 2, 'S'],
        ['KIV', 'ADT', 'LS', 5, 'Aplikace datových struktur', 'A', 1, 1, '1'],
        ['KIV', 'IDT', 'LS', 5, 'Implementace datových struktur', 'A', 2, 1, '2'],
        ['KIV', 'PCT', 'LS', 5, 'Počítačová technika', 'A', 1, 1, '1'],
        ['KIV', 'UUR', 'LS', 4, 'Úvod do uživatelských rozhraní', 'A', 1, 1, '1'],
        ['KIV', 'ZPP', 'LS', 3, 'Základy programátorské praxe', 'A', 5, 2, 'S'],
        ['UTS', 'ZLK', 'LS', 2, 'Základní letní kurz', 'A', 5, 2, 'S'],
    ];

    $znamky = [];
    $absolvoval = [];

    foreach ($spec as [$katedra, $zkratka, $semestr, $credits, $name, $stav, $hodnidno, $tyhoidno, $grade]) {
        $base = $hodnidno === null ? stagZnamkaUngraded() : stagZnamka();

        $znamky[] = array_merge($base, [
            'katedra' => $katedra,
            'zkratka' => $zkratka,
            'semestr' => $semestr,
            'rok' => '2025',
            'stavAbsolvovani' => $stav,
            'zk_tyhoidno' => $tyhoidno,
            'zk_hodnidno' => $hodnidno,
            'zk_typ_hodnoceni' => $tyhoidno === 1 ? '1|2|3|4' : 'S|N',
            'zk_hodnoceni' => $grade,
        ]);

        $absolvoval[] = stagAbsolvoval([
            'katedra' => $katedra,
            'zkratka' => $zkratka,
            'semestr' => $semestr,
            'rok' => '2025',
            'nazevPredmetu' => $name,
            'pocetKreditu' => $credits,
            'absolvoval' => $stav === 'A' ? 'A' : 'N',
            'znamka' => $grade,
        ]);
    }

    return [$znamky, $absolvoval];
}

beforeEach(function () {
    /*
     * The default rok/semestr come from the current date, so pin it:
     * without this the suite changes behavior in February.
     */
    Carbon::setTestNow(Carbon::parse('2026-09-12'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('merges names and credits from the student namespace into the grade rows', function () {
    fakeZnamky([stagZnamka()], [stagAbsolvoval()]);

    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['rok' => '2025', 'semestr' => 'ZS']);

    $response->assertOk()
        ->assertSee('"nazev":"Databázové systémy 1"')
        ->assertSee('"kredity":6')
        ->assertSee('"zkratka":"DB1"');
});

it('decodes the grade through the codebook rather than echoing the raw code', function () {
    fakeZnamky([stagZnamka()], [stagAbsolvoval()]);

    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['rok' => '2025', 'semestr' => 'ZS']);

    $response->assertOk()
        ->assertSee('"grade_name":"Výborně"')
        ->assertSee('"is_pass":true')
        ->assertSee('"scale":"1|2|3|4"');
});

it('reads status from the completion code and keeps the raw code', function () {
    fakeZnamky([stagZnamka(['stavAbsolvovani' => 'NZ'])], [stagAbsolvoval()]);

    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['rok' => '2025', 'semestr' => 'ZS']);

    $response->assertOk()
        ->assertSee('"status":{"type":"failed","code":"NZ"')
        ->assertSee('nezískal zápočet');
});

it('falls back to unknown for a completion code outside the codebook', function () {
    fakeZnamky([stagZnamka(['stavAbsolvovani' => 'ZZZ'])], [stagAbsolvoval()]);

    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['rok' => '2025', 'semestr' => 'ZS']);

    $response->assertOk()
        ->assertSee('"status":{"type":"unknown","code":"ZZZ","label":null,"superseded_by":null}');
});

it('normalises empty strings to null and parses the padded date format', function () {
    fakeZnamky([stagZnamka()], [stagAbsolvoval()]);

    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['rok' => '2025', 'semestr' => 'ZS']);

    // zppzk_typ_hodnoceni is the empty string upstream, and zk_datum is padded.
    $response->assertOk()
        ->assertSee('"date":"2026-01-19"')
        ->assertDontSee('"scale":""');
});

it('omits the zapocet block entirely when the subject has no zapocet', function () {
    fakeZnamky([stagZnamka([
        'zppzk_tyhoidno' => null,
        'zppzk_hodnidno' => null,
        'zppzk_typ_hodnoceni' => null,
        'zppzk_datum' => null,
        'zppzk_hodnoceni' => null,
        'zppzk_pokus' => null,
        'zppzk_ucit_idno' => null,
        'zppzk_ucit_jmeno' => null,
        'zppzk_uznan' => null,
    ])], [stagAbsolvoval()]);

    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['rok' => '2025', 'semestr' => 'ZS']);

    $response->assertOk()->assertSee('"zapocet":null');
});

it("reproduces the printed transcript's weighted average of 2.05", function () {
    [$znamky, $absolvoval] = stagFirstYear();
    fakeZnamky($znamky, $absolvoval);

    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['rok' => '2025', 'semestr' => '%']);

    // 84 weighted points over 41 credits, exactly as the FAV transcript states.
    $response->assertOk()
        ->assertSee('"gpa_official":{"value":2.05,"credits":41,"subjects":8');
});

it('reports a higher passed-only average over fewer credits', function () {
    [$znamky, $absolvoval] = stagFirstYear();
    fakeZnamky($znamky, $absolvoval);

    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['rok' => '2025', 'semestr' => '%']);

    // 40 weighted points over 30 credits: the three unfinished subjects drop out.
    $response->assertOk()
        ->assertSee('"gpa_passed_only":{"value":1.33,"credits":30,"subjects":6');
});

it('keeps a failed zapocet-only subject out of both averages', function () {
    [$znamky, $absolvoval] = stagFirstYear();
    fakeZnamky($znamky, $absolvoval);

    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['rok' => '2025', 'semestr' => '%']);

    /* KMA/SMP was not completed, but its scale says doPrumeru is false. Were it
     * counted, the denominator would be 43 rather than 41. */
    $response->assertOk()
        ->assertSee('"credits":41')
        ->assertDontSee('"credits":43');
});

it('counts credits earned separately from credits enrolled', function () {
    [$znamky, $absolvoval] = stagFirstYear();
    fakeZnamky($znamky, $absolvoval);

    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['rok' => '2025', 'semestr' => '%']);

    // Transcript: Dosaženo 42, Kredity plán 55.
    $response->assertOk()
        ->assertSee('"credits_earned":42')
        ->assertSee('"credits_enrolled":55');
});

it('drops a failed enrolment from the official average once it is retaken and passed', function () {
    [$znamky, $absolvoval] = stagFirstYear();

    $znamky[] = stagZnamka([
        'katedra' => 'KMA', 'zkratka' => 'MA1', 'rok' => '2026', 'semestr' => 'ZS',
        'zk_tyhoidno' => 1, 'zk_hodnidno' => 2, 'zk_hodnoceni' => '2', 'stavAbsolvovani' => 'A',
    ]);
    $absolvoval[] = stagAbsolvoval([
        'katedra' => 'KMA', 'zkratka' => 'MA1', 'rok' => '2026', 'semestr' => 'ZS',
        'nazevPredmetu' => 'Matematická analýza 1', 'pocetKreditu' => 6, 'znamka' => '2',
    ]);

    fakeZnamky($znamky, $absolvoval);

    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['all_years' => true]);

    /* Without supersession MA1 would score both a 4 and a 2: (84+12)/47 = 2.04.
     * Dropping the 2025 failure gives (84-24+12)/(41-6+6) = 72/41 = 1.76. */
    $response->assertOk()
        ->assertSee('"superseded_by":"2026/ZS"')
        ->assertSee('"retaken":1')
        ->assertSee('"gpa_official":{"value":1.76');
});

it('leaves an unpassed retake enrolment as a failure that still counts', function () {
    [$znamky, $absolvoval] = stagFirstYear();

    $znamky[] = stagZnamkaUngraded([
        'katedra' => 'KMA', 'zkratka' => 'MA1', 'rok' => '2026', 'semestr' => 'ZS',
        'zk_tyhoidno' => 1, 'stavAbsolvovani' => 'S',
    ]);
    $absolvoval[] = stagAbsolvoval([
        'katedra' => 'KMA', 'zkratka' => 'MA1', 'rok' => '2026', 'semestr' => 'ZS',
        'nazevPredmetu' => 'Matematická analýza 1', 'pocetKreditu' => 6, 'absolvoval' => 'N', 'znamka' => '',
    ]);

    fakeZnamky($znamky, $absolvoval);

    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['all_years' => true]);

    // Being enrolled again is not the same as having passed: 2.05 still stands.
    $response->assertOk()
        ->assertSee('"retaken":0')
        ->assertSee('"gpa_official":{"value":2.05');
});

it('computes the summary over the full history even when the list is filtered', function () {
    [$znamky, $absolvoval] = stagFirstYear();

    $znamky[] = stagZnamka([
        'katedra' => 'KMA', 'zkratka' => 'MA1', 'rok' => '2026', 'semestr' => 'ZS',
        'zk_tyhoidno' => 1, 'zk_hodnidno' => 2, 'zk_hodnoceni' => '2', 'stavAbsolvovani' => 'A',
    ]);
    $absolvoval[] = stagAbsolvoval([
        'katedra' => 'KMA', 'zkratka' => 'MA1', 'rok' => '2026', 'semestr' => 'ZS',
        'nazevPredmetu' => 'Matematická analýza 1', 'pocetKreditu' => 6, 'znamka' => '2',
    ]);

    fakeZnamky($znamky, $absolvoval);

    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['rok' => '2025', 'semestr' => 'ZS']);

    // Asking about 2025 alone must still see the 2026 pass that supersedes MA1.
    $response->assertOk()->assertSee('"superseded_by":"2026/ZS"');
});

it('defaults to the current academic year and semester', function () {
    [$znamky, $absolvoval] = stagFirstYear();

    $znamky[] = stagZnamkaUngraded([
        'katedra' => 'KIV', 'zkratka' => 'WEB', 'rok' => '2026', 'semestr' => 'ZS',
        'zk_tyhoidno' => 1, 'stavAbsolvovani' => 'S',
    ]);
    $absolvoval[] = stagAbsolvoval([
        'katedra' => 'KIV', 'zkratka' => 'WEB', 'rok' => '2026', 'semestr' => 'ZS',
        'nazevPredmetu' => 'Webové aplikace', 'pocetKreditu' => 4, 'absolvoval' => 'N', 'znamka' => '',
    ]);

    fakeZnamky($znamky, $absolvoval);

    // Test time is 12 September 2026, which is 2026/ZS.
    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, []);

    $response->assertOk()
        ->assertSee('"rok":"2026"')
        ->assertSee('"total":1')
        ->assertSee('Webové aplikace')
        ->assertDontSee('Databázové systémy 1');
});

it('returns the whole history when all_years is set', function () {
    [$znamky, $absolvoval] = stagFirstYear();
    fakeZnamky($znamky, $absolvoval);

    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['all_years' => true]);

    $response->assertOk()
        ->assertSee('"total":14')
        ->assertSee('"rok":null')
        ->assertSee('"all_years":true');
});

it('filters katedra and zkratka as case-insensitive substrings', function () {
    [$znamky, $absolvoval] = stagFirstYear();
    fakeZnamky($znamky, $absolvoval);

    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['all_years' => true, 'katedra' => 'kma']);

    $response->assertOk()
        ->assertSee('"total":3')
        ->assertSee('Lineární algebra')
        ->assertDontSee('Databázové systémy 1');
});

it('never sends the filters to STAG, which matches them case-sensitively', function () {
    [$znamky, $absolvoval] = stagFirstYear();
    fakeZnamky($znamky, $absolvoval);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['all_years' => true, 'katedra' => 'kma', 'zkratka' => 'ma1']);

    /* pracZkr/zkrPredm are exact-match and case-sensitive upstream, so "kma"
     * would return nothing. The whole history is fetched and filtered locally. */
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'getZnamkyByStudent')) {
            return true;
        }

        return ! str_contains($request->url(), 'pracZkr')
            && ! str_contains($request->url(), 'zkrPredm')
            && str_contains($request->url(), 'osCislo=A25B0093P');
    });
});

it('returns the whole matching set without truncation', function () {
    /*
     * A whole degree is a couple hundred rows at most, well within a
     * token-safe response, so there is no count/offset to page with — every
     * matching subject comes back in one call.
     */
    [$znamky, $absolvoval] = stagFirstYear();
    fakeZnamky($znamky, $absolvoval);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['all_years' => true])
        ->assertOk()
        ->assertSee('"total":14')
        ->assertDontSee('"offset"')
        ->assertDontSee('"count"');
});

it('lists subjects newest period first', function () {
    [$znamky, $absolvoval] = stagFirstYear();
    fakeZnamky($znamky, $absolvoval);

    /*
     * KIV/ADT is a 2025/LS subject; KIV/DB1 is 2025/ZS. LS comes later in the
     * year, so all six LS subjects (alphabetical within the period) precede
     * the eight ZS ones, with ADT first overall and DB1 first among the ZS run.
     */
    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['all_years' => true])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('subjects.0.zkratka', 'ADT')
            ->where('subjects.6.zkratka', 'DB1')
            ->etc()
        );
});

it('accepts a blank semestr as an alias for the wildcard', function () {
    [$znamky, $absolvoval] = stagFirstYear();
    fakeZnamky($znamky, $absolvoval);

    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['rok' => '2025', 'semestr' => '']);

    $response->assertOk()->assertSee('"total":14');
});

it('rejects a semestr that is not ZS, LS, %, or blank', function () {
    fakeZnamky([stagZnamka()], [stagAbsolvoval()]);

    /* Upstream, a garbage semestr silently returns zero rows rather than an
     * error, which would otherwise read as "you have no grades this term". */
    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['semestr' => 'XX']);

    $response->assertHasErrors();
    Http::assertNothingSent();
});

it('explains an empty result rather than returning an empty list', function () {
    fakeZnamky([], []);

    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['os_cislo' => 'A24B0001P']);

    /* A foreign osCislo comes back empty upstream instead of 403, so the tool
     * has to say so rather than implying the student has no grades. */
    $response->assertHasErrors();
});

it('still returns a subject when the student namespace does not list it', function () {
    fakeZnamky([stagZnamka()], []);

    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['rok' => '2025', 'semestr' => 'ZS']);

    $response->assertOk()
        ->assertSee('"nazev":null')
        ->assertSee('"kredity":null')
        ->assertSee('"zkratka":"DB1"');
});

it('errors without an authenticated user', function () {
    fakeZnamky([stagZnamka()], [stagAbsolvoval()]);

    StagMcpServer::tool(GetZnamkyTool::class, [])->assertHasErrors();
    Http::assertNothingSent();
});

it('errors when the user has no stag token', function () {
    fakeZnamky([stagZnamka()], [stagAbsolvoval()]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetZnamkyTool::class, [])
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('accepts the academic year as a number as well as a string', function (int|string $rok) {
    [$znamky, $absolvoval] = stagFirstYear();
    fakeZnamky($znamky, $absolvoval);

    // Clients send 2025 as readily as "2025" for a field that reads numeric.
    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['rok' => $rok, 'semestr' => '%']);

    $response->assertOk()
        ->assertSee('"rok":"2025"')
        ->assertSee('"total":14');
})->with([2025, '2025']);

it('rejects a year that cannot be one instead of reporting an empty record', function (mixed $rok) {
    fakeZnamky([stagZnamka()], [stagAbsolvoval()]);

    /* Upstream, a malformed rok returns zero rows rather than an error, which
     * would otherwise read as "you have no grades". */
    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['rok' => $rok])
        ->assertHasErrors();
})->with(['2025/2026', 'abcd', 1800]);
