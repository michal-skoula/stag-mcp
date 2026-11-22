<?php

/*
 * Exam term fixtures shared by the terminy tool tests. Rows are shaped as
 * STAG returns them (captured 22.11.2026), with names and contact details
 * replaced by placeholders.
 */

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

const TERMINY_OS_CISLO = 'X26B0001P';

/**
 * One getTerminyZkousek row, shaped as STAG returns it (captured 22.11.2026,
 * names and contact details replaced).
 *
 * @return array<string, mixed>
 */
function terminyRow(array $overrides = []): array
{
    return array_merge([
        'termIdno' => 1239020,
        'ucitIdno' => 10001,
        'ucitel' => [
            'ucitIdno' => 10001,
            'jmeno' => 'Jan',
            'prijmeni' => 'Novák',
            'titulPred' => 'Ing.',
            'titulZa' => 'Ph.D.',
            'platnost' => 'A',
            'zamestnanec' => 'A',
            'katedra' => 'KIV',
            'pracovisteDalsi' => null,
            'email' => 'novak@example.com',
            'telefon' => null,
            'telefon2' => null,
            'url' => null,
            'phdSkolitel' => 'N',
            'zkrBudovy' => 'UC',
            'cisloMistnosti' => '304',
        ],
        'predmet' => 'DB1',
        'katedra' => 'KIV',
        'rok' => '2026',
        'semestr' => 'ZS',
        'datum' => ['value' => '11.1.2027'],
        'obsazeni' => '19',
        'limit' => '40',
        'skupinaObsazeni' => null,
        'skupinaLimit' => null,
        'nadSkupinaObsazeni' => null,
        'nadSkupinaLimit' => null,
        'casOd' => '13:00:00',
        'casDo' => '15:30:00',
        'budova' => 'UU',
        'mistnost' => '108',
        'kontakt' => null,
        'poznamka' => null,
        'opravny' => '1',
        'deadlineDatumOdhlaseni' => ['value' => '10.1.2027 13:00'],
        'deadlineDatumPrihlaseni' => ['value' => '11.1.2027 12:00'],
        'zacatekPrihlasovani' => null,
        'platnost' => 'A',
        'typTerminu' => 'Zkouška',
        'grteIdno' => null,
    ], $overrides);
}

/**
 * A getTerminyProStudenta row: the same term plus the student's state, as
 * the WADL's terminProStudentaType declares it.
 *
 * @return array<string, mixed>
 */
function terminyStudentRow(array $overrides = []): array
{
    return array_merge(terminyRow(), [
        'osCislo' => TERMINY_OS_CISLO,
        'zapsan' => false,
        'lzeZapsatOdepsat' => true,
        'kodDuvoduProcNelzeZapsatOdepsat' => null,
        'textDuvoduProcNelzeZapsatOdepsat' => null,
        'popisDuvoduProcNelzeZapsatOdepsat' => null,
        'frontaZapsan' => false,
        'frontaLzeZapsatOdepsat' => false,
        'frontaKodDuvoduProcNelzeZapsatOdepsat' => null,
        'frontaTextDuvoduProcNelzeZapsatOdepsat' => null,
        'frontaPopisDuvoduProcNelzeZapsatOdepsat' => null,
    ], $overrides);
}

/**
 * Stands in for STAG across the terminy reads and writes. Properties are read
 * at request time, so a test can change what STAG answers between two calls
 * (e.g. move a term between prepare and confirm).
 */
final class FakeExamStag
{
    /** @var list<array<string, mixed>> getTerminyZkousek rows for a search or a termIdno lookup. */
    public array $search = [];

    /** @var list<array<string, mixed>> getTerminyProStudenta rows. */
    public array $student = [];

    /** @var list<array<string, mixed>> getTerminyZkousek?osCislo= rows. */
    public array $registered = [];

    public ?string $osCislo = TERMINY_OS_CISLO;

    public mixed $studentResponse = null;

    /** @var mixed Response for any write endpoint; "OK" when null. */
    public mixed $writeResponse = null;

    public function install(): void
    {
        Http::fake(fn (HttpRequest $request) => $this->answer($request));
    }

    private function answer(HttpRequest $request): mixed
    {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $query = $request->data();

        return match (true) {
            str_ends_with($path, 'help/getStagUserListForActualUserV2') => Http::response([
                'jmeno' => 'Jana',
                'prijmeni' => 'Testová',
                'stagUserInfo' => [[
                    'userName' => $this->osCislo ?? 'TESTOVA',
                    'role' => $this->osCislo !== null ? 'ST' : 'VY',
                    'osCislo' => $this->osCislo,
                    'ucitIdno' => $this->osCislo !== null ? null : 10002,
                ]],
            ]),
            str_ends_with($path, 'terminy/getTerminyProStudenta') => $this->studentResponse ?? Http::response(['termin' => $this->student]),
            str_ends_with($path, 'terminy/getTerminyZkousek') => Http::response(['termin' => match (true) {
                isset($query['osCislo']) => $this->registered,
                isset($query['termIdno']) => array_values(array_filter($this->search, fn (array $row) => (string) $row['termIdno'] === (string) $query['termIdno'])),
                default => $this->search,
            }]),
            preg_match('#/terminy/(zapis|odhlas)#', $path) === 1 => $this->writeResponse ?? Http::response('OK', 200, ['Content-Type' => 'text/plain']),
            default => Http::response('unexpected '.$path, 599),
        };
    }
}
