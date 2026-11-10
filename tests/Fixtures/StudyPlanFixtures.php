<?php

namespace Tests\Fixtures;

use Illuminate\Support\Facades\Http;

/**
 * Shared fixtures for `app/Services/StudyPlans` and the study plan tools.
 *
 * Plans are shaped as `vsp/getStudijniPlan` returned plan 25230 (Softwarové
 * inženýrství, 2026) and 25020 (Podnikání v IT), trimmed to a few subjects
 * per block. The student XML follows a live `vsp/getStudent` document, with
 * the identity fields replaced by fake values.
 */
final class StudyPlanFixtures
{
    public const string PLAN_URL = 'stag-ws.zcu.cz/ws/services/rest2/vsp/getStudijniPlan*';

    public const string PLAN_INFO_URL = 'stag-ws.zcu.cz/ws/services/rest2/programy/getPlanInfo*';

    public const string STUDENT_URL = 'stag-ws.zcu.cz/ws/services/rest2/vsp/getStudent*';

    public const string USER_LIST_URL = 'stag-ws.zcu.cz/ws/services/rest2/help/getStagUserListForActualUserV2*';

    public const string PLANY_OBORU_URL = 'stag-ws.zcu.cz/ws/services/rest2/programy/getPlanyOboru*';

    public const string PROGRAMY_URL = 'stag-ws.zcu.cz/ws/services/rest2/programy/getStudijniProgramy*';

    public const string OBORY_URL = 'stag-ws.zcu.cz/ws/services/rest2/programy/getOboryStudijnihoProgramu*';

    /** Never a real student's number; asserted absent from every output. */
    public const string FAKE_OS_CISLO = 'Z99B0001P';

    /**
     * One plan subject row, an embedded predmetVarianta as STAG nests it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function slot(string $code, int $credits, array $overrides = []): array
    {
        [$pracZkr, $zkrPredm] = explode('/', $code);
        static $id = 1216000;

        return array_merge([
            'key' => ['pracZkr' => $pracZkr, 'zkrPredm' => $zkrPredm, 'rokVarianty' => '2026'],
            'pracZkr' => $pracZkr,
            'zkrPredm' => $zkrPredm,
            'statut' => 'A',
            'rokVarianty' => '2026',
            'zkratkaProZobrazeni' => "{$pracZkr}<span>/</span>{$zkrPredm}",
            'statnice' => false,
            'id' => ++$id,
            'doporucenyRocnik' => 1,
            'doporucenySemestr' => 'ZS',
            'vyznamPredmetu' => 'B',
            'kredity' => $credits,
            'predmet' => [
                'nazev' => ['valueCs' => "Předmět {$zkrPredm}", 'valueEn' => "Předmět {$zkrPredm}"],
                'zkratkaProZobrazeni' => "{$pracZkr}<span>/</span>{$zkrPredm}",
                'rozsah' => '2+2+0',
                'kredity' => $credits,
                'typZk' => '1',
                'statnice' => false,
                'nahrazovanePredmety' => [],
                'vyloucenePredmety' => [],
                'podminujiciPredmety' => [],
                'doporucenePredmety' => [],
                'pracZkr' => $pracZkr,
                'zkrPredm' => $zkrPredm,
            ],
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $slots
     * @return array<string, mixed>
     */
    public static function block(int $id, string $name, string $statut, ?int $minKred, ?int $minPocet, array $slots): array
    {
        return [
            'nazev' => ['valueCs' => $name, 'valueEn' => $name],
            'statutA' => $statut === 'A',
            'statutB' => $statut === 'B',
            'statutC' => $statut === 'C',
            'statutX' => false,
            'blokIdno' => $id,
            'statut' => $statut,
            'minKred' => $minKred,
            'maxKred' => null,
            'minPocetPredmetu' => $minPocet,
            'poznamka' => null,
            'predmety' => $slots,
        ];
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $segments  segment name => blocks
     * @return array<string, mixed>
     */
    public static function plan(array $segments, int $id = 25230): array
    {
        return [
            'emptyPlan' => false,
            'stplIdno' => $id,
            'oborIdno' => 4610,
            'kreditne' => true,
            'popisPlanuUrl' => 'https://www.kiv.zcu.cz/cs/Education/bc-sw.html',
            'rokPlatnosti' => '2026',
            'verze' => '2025',
            'pocetSemestru' => 0,
            'poznamkaVerejna' => ['valueCs' => 'Poznámka k plánu', 'valueEn' => 'Poznámka k plánu'],
            'skupinySegmentu' => [[
                'stplIdno' => $id,
                'segmenty' => array_map(fn (string $name, array $blocks) => [
                    'nazev' => ['valueCs' => $name, 'valueEn' => $name],
                    'popis' => $name,
                    'bloky' => $blocks,
                ], array_keys($segments), $segments),
            ]],
        ];
    }

    /**
     * The bachelor plan most tests run against:
     * - an all-required A block, and a state exam block;
     * - "Cizí jazyk", an A block where 1 of 2 is enough;
     * - a B block holding KIV/PRO, which C "Skupina 6" lists too;
     * - "Tělesná výchova", with UTS/TV twice (two semesters, 1 credit each);
     * - the EU Track C block, with its rule missing as on STAG.
     *
     * @return array<string, mixed>
     */
    public static function bachelorPlan(): array
    {
        return self::plan([
            'Softwarové inženýrství' => [
                self::block(1, 'Povinné předměty', 'A', 15, 3, [
                    self::slot('KIV/PPA', 5),
                    self::slot('KIV/DB1', 6, ['predmet' => [...self::slot('KIV/DB1', 6)['predmet'], 'vyloucenePredmety' => [['zkratkaProZobrazeni' => 'KIV<span>/</span>DB1-E', 'pracZkr' => 'KIV', 'zkrPredm' => 'DB1-E']]]]),
                    self::slot('KMA/DMA', 4, ['doporucenySemestr' => 'LS']),
                ]),
                self::block(2, 'Státní závěrečná zkouška', 'A', null, 1, [
                    self::slot('KIV/BZSWI', 0, ['statnice' => true, 'doporucenyRocnik' => 3, 'predmet' => [...self::slot('KIV/BZSWI', 0)['predmet'], 'typZk' => '10']]),
                ]),
                self::block(3, 'Skupina 2 - Odborné předměty', 'B', 10, null, [
                    self::slot('KIV/LNX', 4),
                    self::slot('KIV/UUR', 4),
                    self::slot('KIV/PRO', 5, ['doporucenyRocnik' => 3]),
                ]),
                self::block(4, 'Skupina 6 - Doporučené předměty', 'C', 0, null, [
                    self::slot('KIV/PRO', 5, ['statut' => 'C']),
                    self::slot('KFI/ZPDH', 3, ['statut' => 'C', 'doporucenyRocnik' => null]),
                ]),
            ],
            'FAV bc. - společná neoborová výuka' => [
                self::block(5, 'Cizí jazyk - povinný', 'A', 4, 1, [
                    self::slot('UJP/AEP6', 4),
                    self::slot('UJP/NEP6', 4),
                ]),
                self::block(6, 'Tělesná výchova', 'B', 2, null, [
                    self::slot('UTS/TV', 1),
                    self::slot('UTS/TV', 1),
                    self::slot('UTS/ZLK', 2),
                ]),
            ],
            'EU Track-Level1' => [
                self::block(7, 'EU Track-Level1', 'C', 0, null, [
                    self::slot('REK/CHIN1', 2, ['statut' => 'C']),
                    self::slot('REK/ICPD', 3, ['statut' => 'C']),
                    self::slot('REK/BH', 3, ['statut' => 'C']),
                ]),
            ],
        ]);
    }

    /**
     * Podnikání v IT: four required subjects closing with a 0-credit final
     * exam, and a B block of options.
     *
     * @return array<string, mixed>
     */
    public static function certificatePlan(): array
    {
        return self::plan([
            'Certifikátový program Podnikání v IT' => [
                self::block(10, 'Povinné předměty', 'A', 13, 4, [
                    self::slot('KIV/ASWI', 6),
                    self::slot('KPM/PPP', 4),
                    self::slot('KPO/ZPIT2', 3),
                    self::slot('KIV/PIZZ', 0, ['predmet' => [...self::slot('KIV/PIZZ', 0)['predmet'], 'typZk' => '5']]),
                ]),
                self::block(11, 'Povinně volitelné předměty', 'B', 10, null, [
                    self::slot('KPM/ZAP', 4),
                    self::slot('KIV/TSI', 2),
                    self::slot('KIV/EITM', 6),
                ]),
            ],
        ], 25020);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function planInfo(array $overrides = []): array
    {
        return array_merge([
            'stplIdno' => 25230,
            'oborIdno' => 4610,
            'nazev' => 'Softwarové inženýrství',
            'kreditne' => 'A',
            'limitKreditu' => 180,
            'pocetSemestru' => 6,
            'rokPlatnosti' => '2026',
            'verze' => '2025',
        ], $overrides);
    }

    /**
     * One `predmetStudenta` row. `$state` is earned, failed, in_progress,
     * next_year or recognised.
     *
     * @return array<string, string>
     */
    public static function row(string $code, float $credits, string $statut, string $state, string $semestr = 'ZS'): array
    {
        [$pracZkr, $zkrPredm] = explode('/', $code);

        return [
            'kredity' => number_format($credits, 1, '.', ''),
            'pracZkr' => $pracZkr,
            'zkrPredm' => $zkrPredm,
            'semestr' => $semestr,
            'statut' => $statut,
            'stavAbsolvovani' => match ($state) {
                'earned', 'recognised' => 'A',
                'failed' => 'NX',
                default => 'S',
            },
            'uspesneAbsolvovany' => $state === 'earned' ? 'true' : 'false',
            'uznano' => $state === 'recognised' ? 'true' : 'false',
            'praveStudovan' => $state === 'in_progress' ? 'true' : 'false',
            'zapsanProPristiRok' => $state === 'next_year' ? 'true' : 'false',
            'znamkaHodnoceni' => $state === 'earned' ? '1' : '',
            'znamkaPokus' => '1',
            'typPredmetu' => 'ZNAMKY',
        ];
    }

    /**
     * A `vsp/getStudent` document. Each year: [rokPlatnosti, rocnik, stplIdno, rows].
     *
     * @param  list<array{0: string, 1: int, 2: int, 3: list<array<string, string>>}>  $years
     */
    public static function studentXml(array $years): string
    {
        $leaf = fn (string $name, string $value) => $value === '' ? "<{$name}/>" : "<{$name}>".htmlspecialchars($value, ENT_XML1)."</{$name}>";
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><ns2:vspStudent xmlns:ns2="http://stag-ws.zcu.cz/">'
            .$leaf('email', 'fake@students.zcu.cz').$leaf('fakulta', 'FAV').$leaf('kredityNutnoZiskatProgram', '180')
            .$leaf('kredityUznaneCelkem', '0.0').$leaf('kredityZiskaneCelkem', '42.0')
            .$leaf('osCislo', self::FAKE_OS_CISLO).$leaf('osobIdno', '999999').$leaf('stprIdno', '2210');

        foreach ($years as [$year, $rocnik, $planId, $rows]) {
            $xml .= '<rokyStudia>'.$leaf('kredityZbyvaAbsolvovat', '138.0').$leaf('rocnik', (string) $rocnik).$leaf('rokPlatnosti', $year)
                .'<obory>'.$leaf('oborIdno', '4610').$leaf('stplIdno', (string) $planId);

            foreach ($rows as $row) {
                $xml .= '<predmety>';

                foreach ($row as $name => $value) {
                    $xml .= $leaf($name, $value);
                }

                $xml .= $leaf('rokPlatnosti', $year).$leaf('stplIdno', (string) $planId)
                    .'<predmetVarianta>'.$leaf('pracZkr', $row['pracZkr']).$leaf('zkrPredm', $row['zkrPredm']).$leaf('kredity', '9').'</predmetVarianta>'
                    .'</predmety>';
            }

            $xml .= '</obory></rokyStudia>';
        }

        return $xml.'</ns2:vspStudent>';
    }

    /**
     * A second-year student on the bachelor plan:
     * - 2025: PPA, DB1, LNX, TV passed; DMA failed;
     * - 2026: DMA retaken (in progress), TV passed again, AEP6 in progress,
     *   KIV/OKS passed (not in the plan), UUR booked for next year.
     */
    public static function bachelorStudentXml(): string
    {
        return self::studentXml([
            ['2025', 1, 24735, [
                self::row('KIV/PPA', 5, 'A', 'earned'),
                self::row('KIV/DB1', 6, 'A', 'earned'),
                self::row('KMA/DMA', 4, 'A', 'failed', 'LS'),
                self::row('KIV/LNX', 4, 'B', 'earned'),
                self::row('UTS/TV', 1, 'B', 'earned'),
            ]],
            ['2026', 2, 25230, [
                self::row('KMA/DMA', 4, 'A', 'in_progress', 'LS'),
                self::row('UTS/TV', 1, 'B', 'earned'),
                self::row('UJP/AEP6', 4, 'A', 'in_progress', 'LS'),
                self::row('KIV/OKS', 4, 'A', 'earned'),
                self::row('KIV/UUR', 4, 'B', 'next_year', 'LS'),
            ]],
        ]);
    }

    public static function fakeIdentity(): array
    {
        return ['stagUserInfo' => [['userName' => 'fake', 'role' => 'ST', 'osCislo' => self::FAKE_OS_CISLO]]];
    }

    /**
     * STAG for the bachelor student and plan, with optional replacements.
     *
     * @param  array<string, mixed>  $overrides  url pattern => response
     */
    public static function fakeStag(array $overrides = []): void
    {
        Http::fake(array_merge([
            self::USER_LIST_URL => Http::response(self::fakeIdentity()),
            self::STUDENT_URL => Http::response(self::bachelorStudentXml(), 200, ['Content-Type' => 'application/xml']),
            self::PLAN_URL => fn ($request) => Http::response((string) $request['stplIdno'] === '25020' ? self::certificatePlan() : self::bachelorPlan()),
            self::PLAN_INFO_URL => fn ($request) => Http::response((string) $request['stplIdno'] === '25020'
                ? self::planInfo(['stplIdno' => 25020, 'nazev' => 'Podnikání v IT', 'pocetSemestru' => 4, 'limitKreditu' => 23])
                : self::planInfo()),
            self::PLANY_OBORU_URL => Http::response(['planInfo' => [
                self::planInfo(['stplIdno' => 24990, 'nazev' => 'Podnikání v IT', 'rokPlatnosti' => '2025', 'verze' => '25-']),
                self::planInfo(['stplIdno' => 25020, 'nazev' => 'Podnikání v IT', 'rokPlatnosti' => '2026', 'verze' => '25-']),
            ]]),
        ], $overrides));
    }
}
