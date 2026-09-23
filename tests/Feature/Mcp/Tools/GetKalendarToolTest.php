<?php

use App\Mcp\Servers\StagMcpServer;
use App\Mcp\Tools\GetKalendarTool;
use App\Models\User;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

const STAG_GET_STAG_USER_LIST = 'stag-ws.zcu.cz/ws/services/rest2/help/getStagUserListForActualUser*';
const STAG_GET_ROZVRH_BY_STUDENT = 'stag-ws.zcu.cz/ws/services/rest2/rozvrhy/getRozvrhByStudent*';
const STAG_GET_KALENDAR_ROKU = 'stag-ws.zcu.cz/ws/services/rest2/kalendar/getKalendarRoku*';

/**
 * @return array<string, mixed>
 */
function stagUserList(array $overrides = []): array
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
 * One class occurrence shaped exactly as STAG's getRozvrhByStudent returns it,
 * Czech keys and all.
 *
 * @return array<string, mixed>
 */
function stagRozvrhAkce(array $overrides = []): array
{
    return array_merge([
        'roakIdno' => 643817,
        'nazev' => 'Matematická analýza 1',
        'katedra' => 'KMA',
        'predmet' => 'MA1',
        'statut' => 'A',
        'ucitIdno' => 241911,
        'ucitel' => [
            'ucitIdno' => 241911,
            'jmeno' => 'Jonáš',
            'prijmeni' => 'Volek',
            'titulPred' => 'RNDr.',
            'titulZa' => 'Ph.D.',
            'platnost' => 'A',
            'zamestnanec' => 'A',
            'podilNaVyuce' => 100,
        ],
        'rok' => '2026',
        'budova' => 'US',
        'mistnost' => '217',
        'kapacitaMistnosti' => 248,
        'planObsazeni' => 390,
        'obsazeni' => 383,
        'typAkce' => 'Přednáška',
        'typAkceZkr' => 'Př',
        'semestr' => 'ZS',
        'platnost' => 'A',
        'den' => 'Pondělí',
        'denZkr' => 'Po',
        'vyucJazyk' => null,
        'hodinaOd' => 3,
        'hodinaDo' => 4,
        'pocetVyucHodin' => 2,
        'hodinaSkutOd' => ['value' => '09:20'],
        'hodinaSkutDo' => ['value' => '11:00'],
        'tydenOd' => 39,
        'tydenDo' => 52,
        'tyden' => 'Každý',
        'tydenZkr' => 'K',
        'grupIdno' => 47921,
        'jeNadrazena' => 'N',
        'maNadrazenou' => 'N',
        'kontakt' => null,
        'krouzky' => 'A1A1a',
        'casovaRada' => 'ZCU',
        'datum' => ['value' => '9.11.2026'],
        'datumOd' => null,
        'datumDo' => null,
        'druhAkce' => 'R',
        'vsichniUciteleUcitIdno' => '241911',
        'vsichniUciteleJmenaTituly' => 'RNDr. Jonáš Volek, Ph.D.',
        'vsichniUciteleJmenaTitulySPodily' => "'RNDr. Jonáš Volek, Ph.D.' (100)",
        'vsichniUcitelePrijmeni' => 'Volek',
        'referencedIdno' => 643817,
        'poznamkaRozvrhare' => null,
        'nekonaSe' => null,
        'owner' => 'VYRUTR',
        'zakazaneAkce' => null,
    ], $overrides);
}

/**
 * A real exam occurrence (druhAkce Z), which drops roakIdno/statut and uses
 * referencedIdno for the term id instead.
 *
 * @return array<string, mixed>
 */
function stagRozvrhAkceExam(array $overrides = []): array
{
    return stagRozvrhAkce(array_merge([
        'roakIdno' => null,
        'nazev' => 'KIV/DB1',
        'katedra' => 'KIV',
        'predmet' => 'DB1',
        'statut' => null,
        'ucitIdno' => 17895,
        'ucitel' => [
            'ucitIdno' => 17895,
            'jmeno' => 'Martin',
            'prijmeni' => 'Zíma',
            'titulPred' => 'Ing.',
            'titulZa' => 'Ph.D.',
            'platnost' => 'A',
            'zamestnanec' => 'A',
            'podilNaVyuce' => null,
        ],
        'rok' => '2025',
        'budova' => 'UU',
        'mistnost' => '108',
        'kapacitaMistnosti' => 80,
        'planObsazeni' => 40,
        'obsazeni' => 20,
        'typAkce' => 'Zkouška',
        'typAkceZkr' => 'Zkouška',
        'hodinaOd' => null,
        'hodinaDo' => null,
        'pocetVyucHodin' => null,
        'hodinaSkutOd' => ['value' => '13:00'],
        'hodinaSkutDo' => ['value' => '15:30'],
        'tydenOd' => 4,
        'tydenDo' => 4,
        'tyden' => 'Jiný',
        'tydenZkr' => 'J',
        'grupIdno' => null,
        'jeNadrazena' => null,
        'maNadrazenou' => null,
        'krouzky' => null,
        'casovaRada' => null,
        'datum' => ['value' => '19.1.2026'],
        'datumOd' => ['value' => '19.1.2026'],
        'datumDo' => ['value' => '19.1.2026'],
        'druhAkce' => 'Z',
        'vsichniUciteleUcitIdno' => '17895',
        'vsichniUciteleJmenaTituly' => 'Ing. Martin Zíma, Ph.D.',
        'vsichniUciteleJmenaTitulySPodily' => "'Ing. Martin Zíma, Ph.D.'",
        'vsichniUcitelePrijmeni' => 'Zíma',
        'referencedIdno' => 1212874,
        'owner' => 'DSLECHT_KIV',
    ], $overrides));
}

/**
 * The real 28.11.2025 row: a positive nekonaSe override ("Koná se") on a
 * multi-teacher occurrence, used to test both note handling and teacher
 * name splitting in one authentic fixture.
 *
 * @return array<string, mixed>
 */
function stagRozvrhAkceWithNote(array $overrides = []): array
{
    return stagRozvrhAkce(array_merge([
        'roakIdno' => 638563,
        'nazev' => 'Úvod do Linuxu',
        'katedra' => 'KIV',
        'predmet' => 'LNX',
        'statut' => 'B',
        'rok' => '2025',
        'kapacitaMistnosti' => 248,
        'planObsazeni' => 95,
        'obsazeni' => 94,
        'typAkce' => 'Cvičení',
        'typAkceZkr' => 'Cv',
        'den' => 'Pátek',
        'denZkr' => 'Pá',
        'hodinaOd' => 5,
        'hodinaDo' => 6,
        'pocetVyucHodin' => null,
        'hodinaSkutOd' => ['value' => '11:10'],
        'hodinaSkutDo' => ['value' => '12:50'],
        'tydenOd' => 38,
        'tydenDo' => 50,
        'grupIdno' => null,
        'krouzky' => null,
        'datum' => ['value' => '28.11.2025'],
        'vsichniUciteleUcitIdno' => '54539, 294708',
        'vsichniUciteleJmenaTituly' => 'Ing. Ladislav Pešička, Ph.D., Daniel Rössner',
        'vsichniUciteleJmenaTitulySPodily' => "'Ing. Ladislav Pešička, Ph.D.' (100), 'Daniel Rössner' (100)",
        'vsichniUcitelePrijmeni' => 'Pešička, Rössner',
        'referencedIdno' => 638563,
        'nekonaSe' => '28.11.2025: Koná se (proběhne v EP120)',
        'owner' => 'KOBEDA',
    ], $overrides));
}

/**
 * @return array<string, mixed>
 */
function stagKalendarItem(string $datum, string $rozvrhDen, string $typRozvrhDne, string $typTydne, int $cisloTydne): array
{
    return [
        'datum' => ['value' => $datum],
        'rokPlatnosti' => '2026',
        'rozvrhDen' => $rozvrhDen,
        'rozvrhDenStr' => $rozvrhDen,
        'typRozvrhDne' => $typRozvrhDne,
        'typTydne' => $typTydne,
        'cisloTydne' => $cisloTydne,
    ];
}

/**
 * Real getKalendarRoku rows for 9.-13.11.2026: an ordinary Monday, a
 * Rektorský den with no recoverable weekday, and three ordinary weekdays.
 *
 * @return list<array<string, mixed>>
 */
function stagKalendarWeek46(): array
{
    return [
        stagKalendarItem('9.11.2026', 'Po', 'ZS', 'S', 46),
        stagKalendarItem('10.11.2026', 'Rd', 'ZS', 'S', 46),
        stagKalendarItem('11.11.2026', 'St', 'ZS', 'S', 46),
        stagKalendarItem('12.11.2026', 'Čt', 'ZS', 'S', 46),
        stagKalendarItem('13.11.2026', 'Pá', 'ZS', 'S', 46),
    ];
}

it('refuses a caller with no STAG ticket', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetKalendarTool::class)
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('errors when there is no authenticated user', function () {
    Http::fake();

    StagMcpServer::tool(GetKalendarTool::class)
        ->assertHasErrors()
        ->assertSee('must send a bearer token');

    Http::assertNothingSent();
});

it('resolves osCislo via getStagUserListForActualUser when not given', function () {
    Http::fake([
        STAG_GET_STAG_USER_LIST => Http::response(stagUserList()),
        STAG_GET_ROZVRH_BY_STUDENT => Http::response(['rozvrhovaAkce' => []]),
        STAG_GET_KALENDAR_ROKU => Http::response(['kalendarItem' => stagKalendarWeek46()]),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetKalendarTool::class, ['date_from' => '2026-11-09', 'date_to' => '2026-11-13'])
        ->assertOk();

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'getStagUserListForActualUser'));
    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'getRozvrhByStudent')
        && $request['osCislo'] === 'A25B0093P');
});

it('skips osCislo resolution when os_cislo is given explicitly', function () {
    Http::fake([
        STAG_GET_ROZVRH_BY_STUDENT => Http::response(['rozvrhovaAkce' => []]),
        STAG_GET_KALENDAR_ROKU => Http::response(['kalendarItem' => stagKalendarWeek46()]),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetKalendarTool::class, [
            'date_from' => '2026-11-09',
            'date_to' => '2026-11-13',
            'os_cislo' => 'B20B9999P',
        ])
        ->assertOk();

    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'getStagUserListForActualUser'));
    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'getRozvrhByStudent')
        && $request['osCislo'] === 'B20B9999P');
});

it('errors when no osCislo can be resolved and none was given', function () {
    Http::fake([
        STAG_GET_STAG_USER_LIST => Http::response(['stagUserInfo' => []]),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetKalendarTool::class)
        ->assertHasErrors()
        ->assertSee('Could not resolve an osCislo');

    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'getRozvrhByStudent'));
});

it('sends datumOd and datumDo as d.n.Y, not ISO', function () {
    Http::fake([
        STAG_GET_STAG_USER_LIST => Http::response(stagUserList()),
        STAG_GET_ROZVRH_BY_STUDENT => Http::response(['rozvrhovaAkce' => []]),
        STAG_GET_KALENDAR_ROKU => Http::response(['kalendarItem' => stagKalendarWeek46()]),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetKalendarTool::class, ['date_from' => '2026-11-09', 'date_to' => '2026-11-13'])
        ->assertOk();

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'getRozvrhByStudent')
        && $request['datumOd'] === '9.11.2026'
        && $request['datumDo'] === '13.11.2026'
        && $request['vsechnyAkce'] === 'true');
});

it('keeps a Svátek day\'s real weekday and nulls timetable_day', function () {
    Http::fake([
        STAG_GET_STAG_USER_LIST => Http::response(stagUserList()),
        STAG_GET_ROZVRH_BY_STUDENT => Http::response(['rozvrhovaAkce' => []]),
        STAG_GET_KALENDAR_ROKU => Http::response(['kalendarItem' => [
            stagKalendarItem('28.10.2026', 'Sv', 'ZS', 'S', 44),
        ]]),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetKalendarTool::class, ['date_from' => '2026-10-28', 'date_to' => '2026-10-28'])
        ->assertOk()
        ->assertSee('"weekday":"Wednesday"')
        ->assertSee('"timetable_day":null')
        ->assertSee('"non_teaching_reason":"Public holiday"');
});

it('marks a Rektorský den as non-teaching with no events, even without include_empty_days', function () {
    Http::fake([
        STAG_GET_STAG_USER_LIST => Http::response(stagUserList()),
        STAG_GET_ROZVRH_BY_STUDENT => Http::response(['rozvrhovaAkce' => [
            stagRozvrhAkce(['datum' => ['value' => '9.11.2026']]),
        ]]),
        STAG_GET_KALENDAR_ROKU => Http::response(['kalendarItem' => stagKalendarWeek46()]),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetKalendarTool::class, ['date_from' => '2026-11-09', 'date_to' => '2026-11-13'])
        ->assertOk()
        ->assertSee('"date":"2026-11-10"')
        ->assertSee('"teaching":false')
        ->assertSee('"non_teaching_reason":"Rector\'s day"');
});

it('reads timetable_week per day rather than deriving it from week_number', function () {
    Http::fake([
        STAG_GET_STAG_USER_LIST => Http::response(stagUserList()),
        STAG_GET_ROZVRH_BY_STUDENT => Http::response(['rozvrhovaAkce' => []]),
        STAG_GET_KALENDAR_ROKU => Http::response(['kalendarItem' => [
            stagKalendarItem('21.12.2026', 'Po', 'ZS', 'S', 52),
            stagKalendarItem('22.12.2026', 'Út', 'ZS', 'L', 52),
            stagKalendarItem('23.12.2026', 'St', 'ZS', 'S', 52),
        ]]),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetKalendarTool::class, [
            'date_from' => '2026-12-21',
            'date_to' => '2026-12-23',
            'include_empty_days' => true,
        ])
        ->assertOk()
        ->assertSee('"date":"2026-12-21","weekday":"Monday","timetable_day":"Monday","timetable_week":"even"')
        ->assertSee('"date":"2026-12-22","weekday":"Tuesday","timetable_day":"Tuesday","timetable_week":"odd"')
        ->assertSee('"date":"2026-12-23","weekday":"Wednesday","timetable_day":"Wednesday","timetable_week":"even"');
});

it('maps exam occurrences to kind exam', function () {
    Http::fake([
        STAG_GET_STAG_USER_LIST => Http::response(stagUserList()),
        STAG_GET_ROZVRH_BY_STUDENT => Http::response(['rozvrhovaAkce' => [stagRozvrhAkceExam()]]),
        STAG_GET_KALENDAR_ROKU => Http::response(['kalendarItem' => [
            stagKalendarItem('19.1.2026', 'Po', 'ZZ', 'J', 4),
        ]]),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetKalendarTool::class, ['date_from' => '2026-01-19', 'date_to' => '2026-01-19'])
        ->assertOk()
        ->assertSee('"kind":"exam"')
        ->assertSee('"type":"Zkouška"')
        ->assertSee('"katedra":"KIV","zkratka":"DB1"');
});

it('splits a multi-teacher list without breaking on a comma inside a title', function () {
    Http::fake([
        STAG_GET_STAG_USER_LIST => Http::response(stagUserList()),
        STAG_GET_ROZVRH_BY_STUDENT => Http::response(['rozvrhovaAkce' => [stagRozvrhAkceWithNote()]]),
        STAG_GET_KALENDAR_ROKU => Http::response(['kalendarItem' => [
            stagKalendarItem('28.11.2025', 'Pá', 'ZS', 'K', 38),
        ]]),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetKalendarTool::class, ['date_from' => '2025-11-28', 'date_to' => '2025-11-28'])
        ->assertOk()
        ->assertSee('"teachers":[{"ucit_idno":54539,"name":"Ing. Ladislav Pešička, Ph.D."},{"ucit_idno":294708,"name":"Daniel Rössner"}]');
});

it('strips the leading date from nekonaSe, keeps the structured room even when the note names another, and never emits cancelled', function () {
    Http::fake([
        STAG_GET_STAG_USER_LIST => Http::response(stagUserList()),
        STAG_GET_ROZVRH_BY_STUDENT => Http::response(['rozvrhovaAkce' => [stagRozvrhAkceWithNote()]]),
        STAG_GET_KALENDAR_ROKU => Http::response(['kalendarItem' => [
            stagKalendarItem('28.11.2025', 'Pá', 'ZS', 'K', 38),
        ]]),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetKalendarTool::class, ['date_from' => '2025-11-28', 'date_to' => '2025-11-28'])
        ->assertOk()
        ->assertSee('"note":"Koná se (proběhne v EP120)"')
        ->assertSee('"room":"217"')
        ->assertDontSee('"28.11.2025: Kon')
        ->assertDontSee('cancelled');
});

it('fetches getKalendarRoku once per academic year the range touches', function () {
    Http::fake([
        STAG_GET_STAG_USER_LIST => Http::response(stagUserList()),
        STAG_GET_ROZVRH_BY_STUDENT => Http::response(['rozvrhovaAkce' => []]),
        STAG_GET_KALENDAR_ROKU => Http::response(['kalendarItem' => []]),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetKalendarTool::class, ['date_from' => '2027-08-28', 'date_to' => '2027-09-03'])
        ->assertOk();

    $kalendarRequests = Http::recorded(fn ($request) => str_contains((string) $request->url(), 'getKalendarRoku'));

    expect($kalendarRequests)->toHaveCount(2);

    $years = $kalendarRequests->map(fn ($pair) => $pair[0]['rok'])->sort()->values()->all();
    expect($years)->toBe(['2026', '2027']);
});

it('defaults to today through today plus 13 days', function () {
    Carbon::setTestNow('2026-11-09');

    Http::fake([
        STAG_GET_STAG_USER_LIST => Http::response(stagUserList()),
        STAG_GET_ROZVRH_BY_STUDENT => Http::response(['rozvrhovaAkce' => []]),
        STAG_GET_KALENDAR_ROKU => Http::response(['kalendarItem' => []]),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetKalendarTool::class)
        ->assertOk()
        ->assertSee('"date_from":"2026-11-09"')
        ->assertSee('"date_to":"2026-11-22"');

    Carbon::setTestNow();
});

it('omits an ordinary teaching day with no events unless include_empty_days is set', function () {
    Http::fake([
        STAG_GET_STAG_USER_LIST => Http::response(stagUserList()),
        STAG_GET_ROZVRH_BY_STUDENT => Http::response(['rozvrhovaAkce' => []]),
        STAG_GET_KALENDAR_ROKU => Http::response(['kalendarItem' => [
            stagKalendarItem('11.11.2026', 'St', 'ZS', 'S', 46),
        ]]),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetKalendarTool::class, ['date_from' => '2026-11-11', 'date_to' => '2026-11-11'])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"total":0')
        ->assertSee('"days":[]');
});

it('includes an ordinary teaching day with no events when include_empty_days is true', function () {
    Http::fake([
        STAG_GET_STAG_USER_LIST => Http::response(stagUserList()),
        STAG_GET_ROZVRH_BY_STUDENT => Http::response(['rozvrhovaAkce' => []]),
        STAG_GET_KALENDAR_ROKU => Http::response(['kalendarItem' => [
            stagKalendarItem('11.11.2026', 'St', 'ZS', 'S', 46),
        ]]),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetKalendarTool::class, [
            'date_from' => '2026-11-11',
            'date_to' => '2026-11-11',
            'include_empty_days' => true,
        ])
        ->assertOk()
        ->assertSee('"total":1')
        ->assertSee('"teaching":true')
        ->assertSee('"events":[]');
});

it('defaults to the first 100 days over a long, fully-teaching range', function () {
    $kalendarItems = collect(range(0, 149))
        ->map(fn (int $i) => stagKalendarItem(
            Carbon::parse('2026-09-01')->addDays($i)->format('j.n.Y'),
            'Po', 'ZS', 'K', 36
        ))
        ->all();

    Http::fake([
        STAG_GET_STAG_USER_LIST => Http::response(stagUserList()),
        STAG_GET_ROZVRH_BY_STUDENT => Http::response(['rozvrhovaAkce' => []]),
        STAG_GET_KALENDAR_ROKU => Http::response(['kalendarItem' => $kalendarItems]),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetKalendarTool::class, [
            'date_from' => '2026-09-01',
            'date_to' => '2027-01-28',
            'include_empty_days' => true,
        ])
        ->assertOk()
        ->assertSee('"total":150')
        ->assertSee('"offset":0')
        ->assertSee('"count":100');
});

it('honours a custom count and offset over days', function () {
    Carbon::setTestNow('2026-09-01');

    $kalendarItems = collect(range(0, 9))
        ->map(fn (int $i) => stagKalendarItem(
            Carbon::parse('2026-09-01')->addDays($i)->format('j.n.Y'),
            'Po', 'ZS', 'K', 36
        ))
        ->all();

    Http::fake([
        STAG_GET_STAG_USER_LIST => Http::response(stagUserList()),
        STAG_GET_ROZVRH_BY_STUDENT => Http::response(['rozvrhovaAkce' => []]),
        STAG_GET_KALENDAR_ROKU => Http::response(['kalendarItem' => $kalendarItems]),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetKalendarTool::class, [
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-10',
            'include_empty_days' => true,
            'count' => 3,
            'offset' => 8,
        ])
        ->assertOk()
        ->assertSee('"total":10')
        ->assertSee('"offset":8')
        ->assertSee('"count":2')
        ->assertSee('"date":"2026-09-10"');

    Carbon::setTestNow();
});

it('rejects a count above the maximum without calling STAG', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetKalendarTool::class, ['count' => 501])
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('rejects a range spanning more than the maximum days without calling STAG', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetKalendarTool::class, ['date_from' => '2026-01-01', 'date_to' => '2027-06-01'])
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('rejects date_from after date_to without calling STAG', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetKalendarTool::class, ['date_from' => '2026-11-13', 'date_to' => '2026-11-09'])
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('handles an empty result without erroring', function () {
    Http::fake([
        STAG_GET_STAG_USER_LIST => Http::response(stagUserList()),
        STAG_GET_ROZVRH_BY_STUDENT => Http::response(['rozvrhovaAkce' => []]),
        STAG_GET_KALENDAR_ROKU => Http::response(['kalendarItem' => [
            stagKalendarItem('11.11.2026', 'St', 'ZS', 'S', 46),
        ]]),
    ]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetKalendarTool::class, ['date_from' => '2026-11-11', 'date_to' => '2026-11-11'])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"total":0')
        ->assertSee('"count":0');
});

it('names days in the pagination descriptions', function () {
    $tool = new GetKalendarTool;
    $input = $tool->schema(new JsonSchemaTypeFactory);
    $output = $tool->outputSchema(new JsonSchemaTypeFactory);

    expect($input['count']->toArray()['description'])->toContain('days')
        ->and($input['offset']->toArray()['description'])->toContain('days')
        ->and($output['total']->toArray()['description'])->toContain('days')
        ->and($output['count']->toArray()['description'])->toContain('days');
});
