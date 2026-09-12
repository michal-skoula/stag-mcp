<?php

namespace Tests\Fixtures;

use Illuminate\Support\Facades\Http;

/**
 * Shared fixtures for everything under `app/Services/Grades` and
 * `GetZnamkyTool`. Centralised as static methods (rather than the usual
 * per-file free functions) because several test files need `stagZnamka()`-
 * shaped rows, and PHP fatals on redeclaring a same-named global function
 * once two such files load in the same run.
 */
final class GradesFixtures
{
    public const string STAG_USER_LIST_URL = 'stag-ws.zcu.cz/ws/services/rest2/help/getStagUserListForActualUser*';

    public const string BY_STUDENT_URL = 'stag-ws.zcu.cz/ws/services/rest2/znamky/getZnamkyByStudent*';

    public const string TYPY_HODNOCENI_URL = 'stag-ws.zcu.cz/ws/services/rest2/znamky/typyHodnoceni*';

    public const string ABSOLVOVAL_URL = 'stag-ws.zcu.cz/ws/services/rest2/student/getStudentPredmetyAbsolvoval*';

    /**
     * @return array<string, mixed>
     */
    public static function userList(array $overrides = []): array
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
     * One enrolment shaped exactly as znamky/getZnamkyByStudent returns it,
     * Czech keys, empty strings where STAG sends empty strings.
     *
     * @return array<string, mixed>
     */
    public static function znamka(array $overrides = []): array
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
     * An enrolment with no exam recorded: every zk_* value is the empty
     * string or null, which is how STAG represents a subject that was never
     * assessed.
     *
     * @return array<string, mixed>
     */
    public static function znamkaUngraded(array $overrides = []): array
    {
        return self::znamka(array_merge([
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
    public static function absolvoval(array $overrides = []): array
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
    public static function typyHodnoceni(): array
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
     * The dev account's real first year, the set the printed FAV transcript
     * covers. Eight numeric-scale subjects and six zápočet-only ones, three
     * of them never completed. This is the one fixture that must not drift:
     * it is what proves the GPA formula against 84/41 = 2,05, not just
     * internal consistency.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    public static function firstYear(): array
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
            $base = $hodnidno === null ? self::znamkaUngraded() : self::znamka();

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

            $absolvoval[] = self::absolvoval([
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

    /**
     * Keys a flat list of absolvoval() rows into the rok|semestr|katedra|zkratka
     * map that StudyRecord::build()'s catalogue parameter expects, mirroring
     * StudyRecordService::catalogue()'s own keying — lets a unit test call
     * StudyRecord::build() / Subject::fromRow() directly, without going
     * through the service.
     *
     * @param  list<array<string, mixed>>  $absolvovalRows
     * @return array<string, array<string, mixed>>
     */
    public static function catalogue(array $absolvovalRows): array
    {
        $byKey = [];

        foreach ($absolvovalRows as $row) {
            $key = implode('|', [$row['rok'] ?? '', $row['semestr'] ?? '', $row['katedra'] ?? '', $row['zkratka'] ?? '']);
            $byKey[$key] = $row;
        }

        return $byKey;
    }

    /**
     * Fakes all three upstream calls at once.
     *
     * @param  list<array<string, mixed>>  $znamky
     * @param  list<array<string, mixed>>  $absolvoval
     */
    public static function fake(array $znamky, array $absolvoval = []): void
    {
        Http::fake([
            self::STAG_USER_LIST_URL => Http::response(self::userList()),
            self::BY_STUDENT_URL => Http::response(['student_na_predmetu' => $znamky]),
            self::TYPY_HODNOCENI_URL => Http::response(self::typyHodnoceni()),
            self::ABSOLVOVAL_URL => Http::response(['predmetAbsolvoval' => $absolvoval]),
        ]);
    }
}
