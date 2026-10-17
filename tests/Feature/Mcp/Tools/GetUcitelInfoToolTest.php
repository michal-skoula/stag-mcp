<?php

use App\Mcp\Servers\StagMcpServer;
use App\Mcp\Tools\GetUcitelInfoTool;
use App\Models\User;
use Illuminate\Support\Facades\Http;

const STAG_UCITEL_INFO = 'stag-ws.zcu.cz/ws/services/rest2/ucitel/getUcitelInfo*';
const STAG_UCITEL_VIZITKA = 'stag-ws.zcu.cz/ws/services/rest2/vizitka/getVizitkaByUcitidno*';
const STAG_UCITEL_GARANT = 'stag-ws.zcu.cz/ws/services/rest2/ucitel/getGarant_ProgramOborByUcitIdno*';

/**
 * Shaped as `ucitel/getUcitelInfo` returned it for ucitIdno 17098 on 17 Oct 2026.
 *
 * @return array<string, mixed>
 */
function ucitelInfo(array $overrides = []): array
{
    return array_merge([
        'ucitIdno' => 17098,
        'jmeno' => 'Pavel',
        'prijmeni' => 'Herout',
        'titulPred' => 'doc. Ing.',
        'titulZa' => 'Ph.D.',
        'platnost' => 'A',
        'zamestnanec' => 'A',
        'katedra' => 'KIV',
        'pracovisteDalsi' => 'PC',
        'email' => 'herout@fav.zcu.cz',
        'telefon' => null,
        'telefon2' => null,
        'url' => null,
        'phdSkolitel' => 'N',
        'zkrBudovy' => 'UC',
        'cisloMistnosti' => '304',
    ], $overrides);
}

/**
 * The same person's business card, trimmed of the visibility flags nothing
 * reads. The second tsLinky row is added to cover STAG repeating the room once
 * per phone line.
 *
 * @return array<string, mixed>
 */
function ucitelVizitka(array $info = [], array $overrides = []): array
{
    return array_merge([
        'info' => array_merge([
            'dalsiInfo' => '<p>&#160;</p>',
            'dalsiInfoEn' => null,
            'www' => 'http://www.kiv.zcu.cz/~herout/',
            'email' => 'herout@fav.zcu.cz',
            'researcherid' => 'AAR-6493-2020',
            'orcid' => '0000-0002-2813-0611',
            'preferovanaLinka' => '2427',
            'update' => '2026-09-04',
            'showKontakt' => 'N',
            'showKonzultace' => 'A',
            'showMistnost' => 'A',
        ], $info),
        'uredniHodiny' => ['wsUredniHodinaItem' => [[
            'jmeno' => null, 'prijmeni' => null, 'titul_za' => null, 'titul_pred' => null, 'login' => null,
            'hodinaOd' => '10:00', 'hodinaDo' => '11:00', 'poznamka' => 'UC304',
            'den' => '3', 'denS' => 'Středa', 'tyden' => 'k', 'tydenS' => 'Každý',
        ]]],
        'psLinky' => ['linky' => []],
        'tsLinky' => ['linky' => [
            ['cislo' => '2427', 'login' => null, 'telefon' => 'A', 'fax' => 'N', 'mobil' => null,
                'budova' => 'UC', 'mistnost' => '304', 'zkratkaPracoviste' => 'KIV', 'kodPracoviste' => '52150',
                'popis' => null, 'funkce' => null, 'insert' => null, 'update' => null],
            ['cislo' => '2428', 'login' => null, 'telefon' => 'A', 'fax' => 'N', 'mobil' => null,
                'budova' => 'UC', 'mistnost' => '304', 'zkratkaPracoviste' => 'KIV', 'kodPracoviste' => '52150',
                'popis' => null, 'funkce' => null, 'insert' => null, 'update' => null],
        ]],
        'wsOsobaZivotopis' => [],
    ], $overrides);
}

/**
 * @return list<array<string, mixed>>
 */
function ucitelGaranti(): array
{
    return [
        ['typ' => 'O', 'stprIdno' => 1821, 'oborIdno' => 4068, 'fakulta' => 'FAV',
            'nazevProgramu' => 'Informatika a výpočetní technika', 'kodProgramu' => 'B0613A140015',
            'nazevOboru' => 'Informatika', 'zkratkaOboru' => 'INF18bp',
            'cisloOboru' => 'B0613A140015S01', 'cisloSpecializace' => '01'],
        ['typ' => 'P', 'stprIdno' => 1821, 'oborIdno' => 0, 'fakulta' => 'FAV',
            'nazevProgramu' => 'Informatika a výpočetní technika', 'kodProgramu' => 'B0613A140015',
            'nazevOboru' => null, 'zkratkaOboru' => null, 'cisloOboru' => null, 'cisloSpecializace' => null],
    ];
}

function fakeUcitel(array $info = [], mixed $vizitka = null, mixed $garanti = null): void
{
    Http::fake([
        STAG_UCITEL_INFO => Http::response(ucitelInfo($info)),
        STAG_UCITEL_VIZITKA => $vizitka ?? Http::response(ucitelVizitka()),
        STAG_UCITEL_GARANT => $garanti ?? Http::response(ucitelGaranti()),
    ]);
}

function getUcitel(array $arguments = ['teacher_id' => 17098]): mixed
{
    return StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(GetUcitelInfoTool::class, $arguments);
}

it('refuses a caller with no STAG ticket', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetUcitelInfoTool::class, ['teacher_id' => 17098])
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('rejects a missing or invalid teacher_id before calling STAG', function (array $arguments) {
    Http::fake();

    getUcitel($arguments)->assertHasErrors();

    Http::assertNothingSent();
})->with([
    'missing' => [[]],
    'zero' => [['teacher_id' => 0]],
    'not a number' => [['teacher_id' => 'herout']],
]);

it('returns the teacher with their office, web page and guarantees', function () {
    fakeUcitel();

    getUcitel()
        ->assertOk()
        ->assertSee('"teacher_id":17098')
        ->assertSee('"name":"doc. Ing. Pavel Herout, Ph.D."')
        ->assertSee('"department":"KIV"')
        ->assertSee('"other_department":"PC"')
        ->assertSee('"active":true')
        ->assertSee('"employee":true')
        ->assertSee('"email":"herout@fav.zcu.cz"')
        ->assertSee('"website":"http://www.kiv.zcu.cz/~herout/"')
        ->assertSee('"card_updated":"2026-09-04"')
        ->assertSee('"role":"specialisation","faculty":"FAV","programme_code":"B0613A140015","programme_name":"Informatika a výpočetní technika","specialisation_code":"INF18bp","specialisation_name":"Informatika"')
        ->assertSee('"role":"programme","faculty":"FAV","programme_code":"B0613A140015","programme_name":"Informatika a výpočetní technika","specialisation_code":null,"specialisation_name":null');
});

it('asks each endpoint for the requested id, in the casing each expects', function () {
    fakeUcitel();

    getUcitel()->assertOk();

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'getUcitelInfo')
        && (string) $request['ucitIdno'] === '17098');
    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'getVizitkaByUcitidno')
        && (string) $request['ucitidno'] === '17098');
    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'getGarant_ProgramOborByUcitIdno')
        && (string) $request['ucitIdno'] === '17098');
});

it('collapses the repeated directory rows into one office', function () {
    fakeUcitel();

    getUcitel()
        ->assertOk()
        ->assertSee('"offices":[{"building":"UC","room":"304","workplace":"KIV"}],');
});

it('errors on an unknown teacher_id without fetching anything else', function () {
    Http::fake([STAG_UCITEL_INFO => Http::response([])]);

    getUcitel(['teacher_id' => 99999999])
        ->assertHasErrors(['No teacher found with teacher_id 99999999']);

    Http::assertSentCount(1);
});

it('answers for a student teacher, who has no business card', function () {
    /* Bc. Denisa Šperlová, 301402: STAG answers [] for both the card and the guarantees. */
    fakeUcitel(
        info: ['ucitIdno' => 301402, 'jmeno' => 'Denisa', 'prijmeni' => 'Šperlová', 'titulPred' => 'Bc.', 'titulZa' => null,
            'pracovisteDalsi' => 'KIV', 'email' => 'sperld@fav.zcu.cz', 'zkrBudovy' => null, 'cisloMistnosti' => null],
        vizitka: Http::response([]),
        garanti: Http::response([]),
    );

    getUcitel(['teacher_id' => 301402])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"name":"Bc. Denisa Šperlová"')
        ->assertSee('"email":"sperld@fav.zcu.cz"')
        ->assertSee('"offices":[]')
        ->assertSee('"website":null')
        ->assertSee('"card_updated":null')
        ->assertSee('"guarantees":[]');
});

it('falls back to the room on the teacher record when there is no business card', function () {
    fakeUcitel(vizitka: Http::response([]));

    getUcitel()
        ->assertOk()
        ->assertSee('"offices":[{"building":"UC","room":"304","workplace":"KIV"}]');
});

it('hides the room when the person hid it on their card', function () {
    fakeUcitel(vizitka: Http::response(ucitelVizitka(['showMistnost' => 'N'])));

    /* The teacher record's room must not leak back in through the fallback either. */
    getUcitel()
        ->assertOk()
        ->assertSee('"offices":[]');
});

it('keeps everything else when the business card is refused', function () {
    fakeUcitel(vizitka: Http::response('Role rejected', 403));

    getUcitel()
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"email":"herout@fav.zcu.cz"')
        ->assertSee('"offices":[{"building":"UC","room":"304","workplace":"KIV"}]');
});

it('reports guarantees as unknown rather than none when STAG refuses them', function () {
    fakeUcitel(garanti: Http::response('Internal Server Error', 500));

    getUcitel()
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"guarantees":null');
});

it('passes both phone fields through verbatim', function () {
    fakeUcitel(info: ['telefon' => '377636200', 'telefon2' => '+420 736 510 529']);

    getUcitel()
        ->assertOk()
        ->assertSee('"phones":["377636200","+420 736 510 529"]');
});

it('returns no phones when STAG has none, rather than the card extensions', function () {
    fakeUcitel(info: ['telefon' => ' ', 'telefon2' => null]);

    getUcitel()
        ->assertOk()
        ->assertSee('"phones":[]')
        ->assertDontSee('2427')
        ->assertDontSee('2428');
});

it('falls back to the teacher record url when the card has no web page', function () {
    fakeUcitel(info: ['url' => 'armakolas.weebly.com'], vizitka: Http::response(ucitelVizitka(['www' => null])));

    getUcitel()
        ->assertOk()
        ->assertSee('"website":"armakolas.weebly.com"');
});

it('never returns office hours from STAG, even when the card has them', function () {
    fakeUcitel();

    getUcitel()
        ->assertOk()
        ->assertDontSee('10:00')
        ->assertDontSee('Středa')
        ->assertDontSee('"office_hours"')
        ->assertSee('konzultační hodiny')
        ->assertSee('Check their web page (http://www.kiv.zcu.cz/~herout/)');
});

it('points at the email domain for office hours when there is no web page', function () {
    fakeUcitel(vizitka: Http::response([]));

    getUcitel()
        ->assertOk()
        ->assertSee('under fav.zcu.cz');
});

it('drops identifiers and flags nobody asked for', function () {
    fakeUcitel();

    getUcitel()
        ->assertOk()
        ->assertDontSee('orcid')
        ->assertDontSee('0000-0002-2813-0611')
        ->assertDontSee('phdSkolitel')
        ->assertDontSee('stprIdno')
        ->assertDontSee('1821')
        ->assertDontSee('showKontakt');
});
