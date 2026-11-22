<?php

use App\Mcp\Servers\StagMcpServer;
use App\Mcp\Tools\GetSubjectTool;
use App\Models\User;
use Illuminate\Support\Facades\Http;

const STAG_GET_PREDMET_INFO = 'stag-ws.zcu.cz/ws/services/rest2/predmety/getPredmetInfo*';

/**
 * One subject shaped exactly as STAG's info endpoint returns it, Czech keys and all.
 *
 * @return array<string, mixed>
 */
function stagPredmetInfo(array $overrides = []): array
{
    return array_merge([
        'katedra' => 'KIV',
        'zkratka' => 'UPA',
        'rok' => '2026',
        'nazev' => 'Úvod do počítačových architektur',
        'nazevDlouhy' => 'Úvod do počítačových architektur',
        'maVyuku' => 'A',
        'vyukaZS' => 'A',
        'vyukaLS' => 'N',
        'jakCastoJeNabizen' => 'K',
        'jakCastoJeNabizenUpresneni' => null,
        'kreditu' => 6,
        'viceZapis' => 'NE',
        'minObsazeni' => 10,
        'garanti' => "'Dr. Ing. Karel Dudáček'",
        'garantiSPodily' => "'Dr. Ing. Karel Dudáček'",
        'garantiUcitIdno' => '53378',
        'prednasejici' => "'doc. Ing. Vlastimil Vavřička, CSc.'",
        'prednasejiciSPodily' => "'doc. Ing. Vlastimil Vavřička, CSc.' (100)",
        'prednasejiciUcitIdno' => '17809',
        'cvicici' => "'Dr. Ing. Karel Dudáček', 'Ing. Tomáš Mainzer, Ph.D.', 'doc. Ing. Vlastimil Vavřička, CSc.'",
        'cviciciSPodily' => "'Dr. Ing. Karel Dudáček' (100), 'Ing. Tomáš Mainzer, Ph.D.' (100), 'doc. Ing. Vlastimil Vavřička, CSc.' (100)",
        'cviciciUcitIdno' => '53378, 247089, 17809',
        'seminarici' => '',
        'seminariciSPodily' => '',
        'seminariciUcitIdno' => '',
        'schvalujiciUznani' => '',
        'schvalujiciUznaniUcitIdno' => '',
        'examinatori' => '',
        'examinatoriUcitIdno' => '',
        'podminujiciPredmety' => '',
        'vylucujiciPredmety' => 'KIV/UPA-E',
        'podminujePredmety' => '',
        'literatura' => "'Patterson, David A. Computer organization and design. 4th ed. 2009.',\n'Tanenbaum, Andrew S. Structured Computer Organization. 2012.'",
        'nahrazPredmety' => '',
        'metodyVyucovaci' => 'U tohoto předmětu se již používá nový QRAM.',
        'metodyHodnotici' => 'U tohoto předmětu se již používá nový QRAM.',
        'akreditovan' => 'A',
        'jednotekPrednasek' => 3,
        'jednotkaPrednasky' => 'HOD/TYD',
        'jednotekCviceni' => 2,
        'jednotkaCviceni' => 'HOD/TYD',
        'jednotekSeminare' => 0,
        'jednotkaSeminare' => 'HOD/TYD',
        'anotace' => 'Cílem předmětu je seznámit studenty se základními typy architektur.',
        'typZkousky' => 'Zkouška',
        'maZapocetPredZk' => 'ANO',
        'formaZkousky' => 'Kombinovaná',
        'pozadavky' => 'Podmínky pro získání zápočtu.',
        'prehledLatky' => '1. Klasifikace výpočetních systémů.',
        'predpoklady' => 'Základní znalosti z fyziky.',
        'ziskaneZpusobilosti' => 'U tohoto předmětu se již používá nový QRAM.',
        'casovaNarocnost' => 'Kontaktní výuka=65',
        'predmetUrl' => null,
        'vyucovaciJazyky' => 'Čeština',
        'poznamka' => null,
        'ectsZobrazit' => 'A',
        'ectsAkreditace' => 'A',
        'ectsNabizetUPrijezdu' => 'N',
        'poznamkaVerejna' => null,
        'skupinaAkreditace' => 'nezařazeno',
        'skupinaAkreditaceKey' => '0',
        'zarazenDoPrezencnihoStudia' => 'A',
        'zarazenDoKombinovanehoStudia' => 'N',
        'studijniOpory' => null,
        'praxePocetDnu' => '0',
        'urovenNastavena' => null,
        'urovenVypoctena' => 'Bc.',
        'automatickyUznavatZppZk' => 'N',
        'hodZaSemKombForma' => null,
    ], $overrides);
}

it('works without a STAG token', function () {
    Http::fake([STAG_GET_PREDMET_INFO => Http::response(stagPredmetInfo())]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetSubjectTool::class, ['department' => 'KIV', 'code' => 'UPA'])
        ->assertOk()
        ->assertHasNoErrors();
});

it('sends the exact-match filters STAG expects', function () {
    Http::fake([STAG_GET_PREDMET_INFO => Http::response(stagPredmetInfo())]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetSubjectTool::class, ['department' => 'KIV', 'code' => 'UPA', 'year' => 2026])
        ->assertOk();

    Http::assertSent(fn ($request) => $request['katedra'] === 'KIV'
        && $request['zkratka'] === 'UPA'
        && $request['rok'] === '2026');
});

it('maps identity, credits, and exam fields', function () {
    Http::fake([STAG_GET_PREDMET_INFO => Http::response(stagPredmetInfo())]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetSubjectTool::class, ['department' => 'KIV', 'code' => 'UPA'])
        ->assertOk()
        ->assertSee('"department":"KIV"')
        ->assertSee('"code":"UPA"')
        ->assertSee('"name":"Ú') // "Úvod..." — just confirm name made it through
        ->assertSee('"accredited":true')
        ->assertSee('"level":"Bc."')
        ->assertSee('"credits":6')
        ->assertSee('"taught_in_winter":true')
        ->assertSee('"taught_in_summer":false')
        ->assertSee('"type":"Zkouška"')
        ->assertSee('"zapocet_before_exam":true');
});

it('splits quoted name lists without breaking on commas inside a name', function () {
    Http::fake([STAG_GET_PREDMET_INFO => Http::response(stagPredmetInfo())]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetSubjectTool::class, ['department' => 'KIV', 'code' => 'UPA'])
        ->assertOk()
        ->assertSee('"guarantors":["Dr. Ing. Karel Dudáček"]')
        ->assertSee('"instructor_teacher_ids":[53378,247089,17809]')
        ->assertSee('"seminar_leaders":[]');
});

it('splits plain comma-separated relation codes', function () {
    Http::fake([STAG_GET_PREDMET_INFO => Http::response(stagPredmetInfo())]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetSubjectTool::class, ['department' => 'KIV', 'code' => 'UPA'])
        ->assertOk()
        ->assertSee('"excludes":["KIV/UPA-E"]')
        ->assertSee('"prerequisites":[]');
});

it('returns a clear error when STAG has no such subject', function () {
    Http::fake([STAG_GET_PREDMET_INFO => Http::response([])]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetSubjectTool::class, ['department' => 'ZZZ', 'code' => 'NOPE'])
        ->assertHasErrors()
        ->assertSee('No subject found');
});

it('errors when there is no authenticated user', function () {
    Http::fake();

    StagMcpServer::tool(GetSubjectTool::class, ['department' => 'KIV', 'code' => 'UPA'])
        ->assertHasErrors()
        ->assertSee('must send a bearer token');

    Http::assertNothingSent();
});

it('names the missing argument and what it accepts', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(GetSubjectTool::class, ['department' => 'KIV'])
        ->assertHasErrors(['code is required and must be the subject code within the department, e.g. "UPA".']);

    Http::assertNothingSent();
});
