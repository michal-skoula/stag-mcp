<?php

namespace Tests\Fixtures;

/**
 * Shared fixtures for `App\Services\Programmes` and the programme tools.
 *
 * Rows follow live `programy` responses (fetched 2026-11-22), trimmed to the
 * fields that carry data. The guarantor is a made-up person.
 */
final class ProgrammeFixtures
{
    public const string PROGRAMY_URL = 'stag-ws.zcu.cz/ws/services/rest2/programy/getStudijniProgramy*';

    public const string INFO_URL = 'stag-ws.zcu.cz/ws/services/rest2/programy/getStudijniProgramInfo*';

    public const string OBLASTI_URL = 'stag-ws.zcu.cz/ws/services/rest2/programy/getOblastiVzdelavaniProgramu*';

    public const string OBORY_URL = 'stag-ws.zcu.cz/ws/services/rest2/programy/getOboryStudijnihoProgramu*';

    public const string PLANY_OBORU_URL = 'stag-ws.zcu.cz/ws/services/rest2/programy/getPlanyOboru*';

    /**
     * One `programInfo` row. The list and the detail endpoint share the shape.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function programme(array $overrides = []): array
    {
        return array_merge([
            'stprIdno' => 2209,
            'nazev' => 'Informatika',
            'nazevCz' => 'Informatika',
            'nazevAn' => 'Informatics',
            'kod' => 'B0613A140036',
            'titul' => 'Bakalář',
            'titulZkr' => 'Bc.',
            'titulRigo' => null,
            'typ' => 'Bakalářský',
            'forma' => 'Prezenční',
            'fakulta' => 'FAV',
            'stdDelka' => 3.0,
            'maxDelka' => 5.0,
            'kredity' => 180,
            'vykazovan' => 'A',
            'platnyOd' => '2023',
            'neplatnyOd' => null,
            'cile' => 'Studijní program poskytuje širokou teoretickou a praktickou základnu.',
            'garant' => 'Novák Jan, prof. Ing. Ph.D.',
            'garantUcitIdno' => 12345,
            'jazyk' => 'Čeština',
            'profil' => 'akademický',
            'akreditacePoNovele' => 'A',
            'akreditaceDoDate' => ['value' => '17.3.2033'],
            'akreditaceCislo' => 'ZCU004505/2023',
            'koefEkonomickeNarocnosti' => 1.0,
            'profilAbsolventaCz' => 'Absolvent může pokračovat v navazujícím magisterském studiu.',
            'profilAbsolventaAn' => '*********',
            'pozadavkyNaPrijetiCz' => 'Splnění podmínek přijímacího řízení',
            'pozadavkyNaPrijetiAn' => '**********',
            'pozadavkyNaPrijetiUrl' => 'www.fav.zcu.cz',
            'predpokladUplatitelnostiCz' => 'Absolventi naleznou uplatnění v širokém spektru firem.',
            'moznePracovniPoziceCz' => '- programátor, analytik',
            'moznePracovniPoziceAn' => '***********',
            'pocetPrijimanych' => null,
            'typickaTemataVskp' => null,
        ], $overrides);
    }

    /**
     * A valid-programmes list in the shapes the search has to tell apart.
     *
     * @return list<array<string, mixed>>
     */
    public static function programmes(): array
    {
        return [
            self::programme(['stprIdno' => 1, 'nazev' => 'Aplikovaná informatika', 'nazevAn' => 'Applied Informatics', 'kod' => 'B0613P140001', 'fakulta' => 'FPR']),
            self::programme(),
            self::programme([
                'stprIdno' => 2, 'nazev' => 'Informatika a její specializace', 'nazevAn' => 'Computer Science and its Specializations',
                'kod' => 'N0613A140034', 'typ' => 'Navazující', 'titulZkr' => 'Ing.', 'stdDelka' => 2.0, 'kredity' => 120,
            ]),
            self::programme([
                'stprIdno' => 3, 'nazev' => 'Computer Science and Engineering', 'nazevAn' => null, 'kod' => 'P0613D140031',
                'typ' => 'Doktorský', 'forma' => 'Kombinovaná', 'jazyk' => 'Angličtina', 'titulZkr' => 'Ph.D.', 'stdDelka' => 4.0, 'kredity' => 240,
            ]),
            self::programme([
                'stprIdno' => 4, 'nazev' => 'Ekonomie a finance', 'nazevAn' => 'Economics and Finance', 'kod' => 'B0311A050001',
                'fakulta' => 'FEK', 'neplatnyOd' => '2028', 'cile' => '-',
            ]),
            self::programme([
                'stprIdno' => 5, 'nazev' => 'Certifikátové programy', 'nazevAn' => null, 'kod' => '9999',
                'typ' => 'Ostatní', 'titulZkr' => null, 'stdDelka' => 0.8, 'kredity' => 0,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function obor(int $id, string $name, string $nameEn, string $code): array
    {
        return [
            'oborIdno' => $id, 'nazev' => $name, 'nazevCz' => $name, 'nazevAn' => $nameEn,
            'cisloOboru' => 'B0613A140036S01', 'typ' => 'Bakalářský', 'forma' => 'Prezenční', 'jazyk' => 'CZ',
            'fakulta' => 'FAV', 'platnyOd' => '2023', 'neplatnyOd' => null, 'anotace' => null,
            'limitKreditu' => 180, 'pocetEtap' => 1, 'stprIdno' => 2209, 'zkratka' => $code,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function plan(int $id, int $oborIdno, string $verze): array
    {
        return [
            'stplIdno' => $id, 'oborIdno' => $oborIdno, 'nazev' => 'Počítačové vědy', 'kreditne' => 'A',
            'limitKreditu' => 180, 'etapa' => 1, 'pocetSemestru' => 6, 'rokPlatnosti' => '2026', 'verze' => $verze,
            'poznamka' => null, 'specializace' => null,
        ];
    }
}
