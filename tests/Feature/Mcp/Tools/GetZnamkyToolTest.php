<?php

use App\Mcp\Servers\StagMcpServer;
use App\Mcp\Tools\GetZnamkyTool;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\GradesFixtures;

/*
 * Business logic — grade decoding, status mapping, null/date normalisation,
 * GPA math, retake supersession, sort order, katedra/zkratka filtering — is
 * tested directly against app/Services/Grades in tests/Unit/Services/Grades
 * and tests/Feature/Services/Grades, without the HTTP/MCP/schema layers in
 * between. This file only covers what actually belongs to the tool: schema
 * validation, defaulting, auth, param wiring to STAG, and error wording.
 */

beforeEach(function () {
    /* The default rok/semestr come from the current date, so pin it:
     * without this the suite changes behavior in February. */
    Carbon::setTestNow(Carbon::parse('2026-09-12'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('defaults to the current academic year and semester', function () {
    [$znamky, $absolvoval] = GradesFixtures::firstYear();

    $znamky[] = GradesFixtures::znamkaUngraded([
        'katedra' => 'KIV', 'zkratka' => 'WEB', 'rok' => '2026', 'semestr' => 'ZS',
        'zk_tyhoidno' => 1, 'stavAbsolvovani' => 'S',
    ]);
    $absolvoval[] = GradesFixtures::absolvoval([
        'katedra' => 'KIV', 'zkratka' => 'WEB', 'rok' => '2026', 'semestr' => 'ZS',
        'nazevPredmetu' => 'Webové aplikace', 'pocetKreditu' => 4, 'absolvoval' => 'N', 'znamka' => '',
    ]);

    GradesFixtures::fake($znamky, $absolvoval);

    // Test time is 12 September 2026, which is 2026/ZS.
    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, []);

    $response->assertOk()
        ->assertSee('"rok":"2026"')
        ->assertSee('"total":1')
        ->assertSee('Webové aplikace')
        ->assertDontSee('Databázové systémy 1');
});

it('returns the whole history when all_years is set, ignoring rok/semestr', function () {
    [$znamky, $absolvoval] = GradesFixtures::firstYear();
    GradesFixtures::fake($znamky, $absolvoval);

    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['all_years' => true]);

    $response->assertOk()
        ->assertSee('"total":14')
        ->assertSee('"rok":null')
        ->assertSee('"all_years":true')
        ->assertSee('"uncredited":0');
});

it('never sends katedra/zkratka filters to STAG, which matches them case-sensitively', function () {
    [$znamky, $absolvoval] = GradesFixtures::firstYear();
    GradesFixtures::fake($znamky, $absolvoval);

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

it('returns the whole matching set with no pagination keys', function () {
    /*
     * A whole degree is a couple hundred rows at most, well within a
     * token-safe response, so there is no count/offset to page with — every
     * matching subject comes back in one call.
     */
    [$znamky, $absolvoval] = GradesFixtures::firstYear();
    GradesFixtures::fake($znamky, $absolvoval);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['all_years' => true])
        ->assertOk()
        ->assertSee('"total":14')
        ->assertDontSee('"offset"')
        ->assertDontSee('"count"');
});

it('accepts a blank semestr as an alias for the wildcard', function () {
    [$znamky, $absolvoval] = GradesFixtures::firstYear();
    GradesFixtures::fake($znamky, $absolvoval);

    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['rok' => '2025', 'semestr' => '']);

    $response->assertOk()->assertSee('"total":14');
});

it('rejects a semestr that is not ZS, LS, %, or blank', function () {
    GradesFixtures::fake([GradesFixtures::znamka()], [GradesFixtures::absolvoval()]);

    /* Upstream, a garbage semestr silently returns zero rows rather than an
     * error, which would otherwise read as "you have no grades this term". */
    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['semestr' => 'XX']);

    $response->assertHasErrors();
    Http::assertNothingSent();
});

it('accepts the academic year as a number as well as a string', function (int|string $rok) {
    [$znamky, $absolvoval] = GradesFixtures::firstYear();
    GradesFixtures::fake($znamky, $absolvoval);

    // Clients send 2025 as readily as "2025" for a field that reads numeric.
    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['rok' => $rok, 'semestr' => '%']);

    $response->assertOk()
        ->assertSee('"rok":"2025"')
        ->assertSee('"total":14');
})->with([2025, '2025']);

it('rejects a year that cannot be one instead of reporting an empty record', function (mixed $rok) {
    GradesFixtures::fake([GradesFixtures::znamka()], [GradesFixtures::absolvoval()]);

    /* Upstream, a malformed rok returns zero rows rather than an error, which
     * would otherwise read as "you have no grades". */
    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['rok' => $rok])
        ->assertHasErrors();
})->with(['2025/2026', 'abcd', 1800]);

it('explains an empty result rather than returning an empty list', function () {
    GradesFixtures::fake([], []);

    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetZnamkyTool::class, ['os_cislo' => 'A24B0001P']);

    /* A foreign osCislo comes back empty upstream instead of 403, so the tool
     * has to say so rather than implying the student has no grades. */
    $response->assertHasErrors();
});

it('errors without an authenticated user', function () {
    GradesFixtures::fake([GradesFixtures::znamka()], [GradesFixtures::absolvoval()]);

    StagMcpServer::tool(GetZnamkyTool::class, [])->assertHasErrors();
    Http::assertNothingSent();
});

it('errors when the user has no stag token', function () {
    GradesFixtures::fake([GradesFixtures::znamka()], [GradesFixtures::absolvoval()]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetZnamkyTool::class, [])
        ->assertHasErrors();

    Http::assertNothingSent();
});
