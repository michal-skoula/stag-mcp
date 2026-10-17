<?php

namespace Tests\Fixtures;

use Illuminate\Support\Facades\Http;

/**
 * `ucitel/getUciteleKatedry` rows shaped exactly as captured from live STAG on
 * 1 Oct 2026, Czech keys and nulls included.
 */
final class UcitelFixtures
{
    public const string URL = 'stag-ws.zcu.cz/ws/services/rest2/ucitel/getUciteleKatedry*';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function ucitel(array $overrides = []): array
    {
        return array_merge([
            'ucitIdno' => 301402,
            'jmeno' => 'Denisa',
            'prijmeni' => 'Šperlová',
            'titulPred' => 'Bc.',
            'titulZa' => null,
            'platnost' => 'A',
            'zamestnanec' => 'A',
            'katedra' => 'KIV',
            'pracovisteDalsi' => 'KIV',
            'email' => 'sperld@fav.zcu.cz',
            'telefon' => null,
            'telefon2' => null,
            'url' => null,
            'phdSkolitel' => 'N',
            'zkrBudovy' => null,
            'cisloMistnosti' => null,
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public static function fake(array $rows): void
    {
        Http::fake([self::URL => Http::response(['ucitel' => $rows])]);
    }
}
