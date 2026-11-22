<?php

namespace App\Services\Programmes;

use App\Contracts\StagClient;
use App\Exceptions\StagException;
use App\Services\Teachers\TeacherSearch;

/**
 * Study programmes from the `programy` namespace, the catalogue side only:
 * what a programme is, what it leads to and which plans it runs.
 *
 * Every endpoint used here answers anonymously in ~0.02-0.05s, so nothing is
 * cached. The list endpoint returns the full 87-field row for every
 * programme (1.3 MB for the 264 valid ones), so rows are cut down to a
 * summary straight away.
 *
 * Free text in this namespace has placeholders in place of nothing: the
 * `*An` prose fields hold literal `"*********"`, and some `cile` hold `-` or
 * `N/A`. {@see text()} reads all of them as empty.
 *
 * @phpstan-type Summary array{
 *     programme_id: int,
 *     code: ?string,
 *     name: ?string,
 *     name_en: ?string,
 *     faculty: ?string,
 *     level: ?string,
 *     form: ?string,
 *     language: ?string,
 *     degree: ?string,
 *     standard_years: int|float|null,
 *     credits: ?int,
 *     valid_from: ?string,
 *     valid_until: ?string,
 *     certificate: bool,
 * }
 */
final readonly class ProgrammeCatalogue
{
    /**
     * STAG's `typ` labels, in English. `Ostatní` covers certificate
     * programmes and other non-degree study.
     */
    public const array LEVELS = [
        'Bakalářský' => 'bachelor',
        'Navazující' => 'follow-up master',
        'Magisterský' => 'master',
        'Doktorský' => 'doctoral',
        'Celoživotní' => 'lifelong learning',
        'Rigorózní' => 'rigorosum',
        'Ostatní' => 'other',
    ];

    /** STAG's `forma` labels, in English. */
    public const array FORMS = [
        'Prezenční' => 'full-time',
        'Kombinovaná' => 'combined',
        'Distanční' => 'distance',
    ];

    /** STAG's `jazyk` labels on programmes, as language codes. */
    public const array LANGUAGES = [
        'Čeština' => 'cs',
        'Angličtina' => 'en',
    ];

    /**
     * Above this many specialisations the plans are not fetched, one call
     * each. REK's "Mimořádné studium" has 160; real degrees have at most 6.
     */
    public const int MAX_SPECIALISATIONS_WITH_PLANS = 12;

    public function __construct(private StagClient $stag) {}

    /**
     * @return list<Summary>
     *
     * @throws StagException
     */
    public function programmes(?string $faculty, bool $includeExpired): array
    {
        $query = array_filter([
            'fakulta' => $faculty,
            'pouzePlatne' => $includeExpired ? null : 'true',
        ], fn (?string $value) => $value !== null && $value !== '');

        $rows = $this->stag->get('programy/getStudijniProgramy', $query)['programInfo'] ?? [];

        return array_values(array_map(self::summary(...), array_filter($rows, 'is_array')));
    }

    /**
     * One programme with its prose, areas, guarantor and specialisations,
     * or null when STAG does not know the id (it answers with no body).
     *
     * @return array<string, mixed>|null
     *
     * @throws StagException
     */
    public function programme(int $programmeId): ?array
    {
        $info = $this->stag->get('programy/getStudijniProgramInfo', ['stprIdno' => $programmeId]);

        if (! isset($info['stprIdno'])) {
            return null;
        }

        return [
            ...self::summary($info),
            'max_years' => self::number($info['maxDelka'] ?? null),
            'profile' => self::text($info['profil'] ?? null),
            'accredited_until' => self::text($info['akreditaceDoDate']['value'] ?? null),
            'areas' => $this->areas($programmeId),
            'guarantor' => isset($info['garantUcitIdno']) ? [
                'name' => self::text($info['garant'] ?? null),
                'teacher_id' => (int) $info['garantUcitIdno'],
            ] : null,
            'about' => [
                'goals' => self::text($info['cile'] ?? null),
                'graduate_profile' => self::text($info['profilAbsolventaCz'] ?? null),
                'employability' => self::text($info['predpokladUplatitelnostiCz'] ?? null),
                'job_positions' => self::text($info['moznePracovniPoziceCz'] ?? null),
                'admission_requirements' => self::text($info['pozadavkyNaPrijetiCz'] ?? null),
                'admission_url' => self::text($info['pozadavkyNaPrijetiUrl'] ?? null),
                'intake' => is_numeric($info['pocetPrijimanych'] ?? null) ? (int) $info['pocetPrijimanych'] : null,
                'health_requirements' => self::text($info['pozadovanaZdrZpusobilostCz'] ?? null),
                'follow_up_study' => self::text($info['navaznostNaDalsiSpCz'] ?? null),
                'practice' => self::text($info['odbornaPraxe'] ?? null),
                'thesis_topics' => self::text($info['typickaTemataVskp'] ?? null),
            ],
            'specialisations' => $this->specialisations($programmeId),
        ];
    }

    /**
     * @param  array<string, mixed>  $row  a `programInfo` row
     * @return Summary
     */
    public static function summary(array $row): array
    {
        $level = self::text($row['typ'] ?? null);
        $form = self::text($row['forma'] ?? null);
        $language = self::text($row['jazyk'] ?? null);
        $code = self::text($row['kod'] ?? null);

        return [
            'programme_id' => (int) ($row['stprIdno'] ?? 0),
            'code' => $code,
            'name' => self::text($row['nazev'] ?? null),
            'name_en' => self::text($row['nazevAn'] ?? null),
            'faculty' => self::text($row['fakulta'] ?? null),
            'level' => self::LEVELS[$level] ?? $level,
            'form' => self::FORMS[$form] ?? $form,
            'language' => self::LANGUAGES[$language] ?? $language,
            'degree' => self::text($row['titulZkr'] ?? null),
            'standard_years' => self::number($row['stdDelka'] ?? null),
            'credits' => is_numeric($row['kredity'] ?? null) && (int) $row['kredity'] > 0 ? (int) $row['kredity'] : null,
            'valid_from' => self::text((string) ($row['platnyOd'] ?? '')),
            'valid_until' => self::text((string) ($row['neplatnyOd'] ?? '')),
            /* Certificate programmes are coded 9999 or 9999-XXX, see list-certifikaty. */
            'certificate' => $code !== null && str_starts_with($code, '9999'),
        ];
    }

    /**
     * The search key a query is matched against: names in both languages
     * and the code, folded the same way teacher names are.
     *
     * @param  Summary  $programme
     */
    public static function searchKey(array $programme): string
    {
        return TeacherSearch::normalize(implode(' ', array_filter([$programme['name'], $programme['name_en'], $programme['code']])));
    }

    /**
     * Blank, asterisks-only (`"*********"`), `-` and `N/A` all mean nothing.
     */
    public static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || preg_match('/^[*\s]+$/', $trimmed) === 1 || in_array(mb_strtoupper($trimmed), ['-', 'N/A'], true)) {
            return null;
        }

        return $trimmed;
    }

    /**
     * Lengths come as floats (`3.0`, `0.8` for a short course). Whole years
     * are returned as integers so they read as "3", not "3.0".
     */
    private static function number(mixed $value): int|float|null
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            return null;
        }

        $number = (float) $value;

        return floor($number) === $number ? (int) $number : $number;
    }

    /**
     * Fields of education, e.g. "Informatika (100%)". A refusal degrades to
     * null (unknown) rather than failing the whole programme.
     *
     * @return list<string>|null
     */
    private function areas(int $programmeId): ?array
    {
        try {
            $items = $this->stag->get('programy/getOblastiVzdelavaniProgramu', ['stprIdno' => $programmeId])['item'] ?? [];
        } catch (StagException) {
            return null;
        }

        return array_values(array_filter(array_map(
            fn (mixed $item) => is_array($item) ? self::text($item['oblastVzdelavaniText'] ?? null) : null,
            $items,
        )));
    }

    /**
     * The programme's obory with this year's plans. Plans are re-issued every
     * year in one version per intake year (`verze`), so each specialisation
     * lists several, newest first.
     *
     * @return list<array<string, mixed>>
     *
     * @throws StagException
     */
    private function specialisations(int $programmeId): array
    {
        $obory = array_values(array_filter(
            $this->stag->get('programy/getOboryStudijnihoProgramu', ['stprIdno' => $programmeId])['oborInfo'] ?? [],
            'is_array',
        ));
        $withPlans = count($obory) <= self::MAX_SPECIALISATIONS_WITH_PLANS;

        return array_map(fn (array $obor) => [
            'specialisation_id' => (int) ($obor['oborIdno'] ?? 0),
            'name' => self::text($obor['nazev'] ?? null),
            'name_en' => self::text($obor['nazevAn'] ?? null),
            'code' => self::text($obor['zkratka'] ?? null),
            'credits' => is_numeric($obor['limitKreditu'] ?? null) && (int) $obor['limitKreditu'] > 0 ? (int) $obor['limitKreditu'] : null,
            'plans' => $withPlans ? $this->plans((int) ($obor['oborIdno'] ?? 0)) : null,
        ], $obory);
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function plans(int $oborIdno): ?array
    {
        try {
            $plans = $this->stag->get('programy/getPlanyOboru', ['oborIdno' => $oborIdno])['planInfo'] ?? [];
        } catch (StagException) {
            return null;
        }

        $plans = array_values(array_filter($plans, 'is_array'));

        usort($plans, fn (array $a, array $b) => [(string) ($b['rokPlatnosti'] ?? ''), (string) ($b['verze'] ?? '')]
            <=> [(string) ($a['rokPlatnosti'] ?? ''), (string) ($a['verze'] ?? '')]);

        return array_map(fn (array $plan) => [
            'stpl_id' => (int) ($plan['stplIdno'] ?? 0),
            'version' => self::text((string) ($plan['verze'] ?? '')),
            'valid_for' => self::text((string) ($plan['rokPlatnosti'] ?? '')),
            'semesters' => is_numeric($plan['pocetSemestru'] ?? null) && (int) $plan['pocetSemestru'] > 0 ? (int) $plan['pocetSemestru'] : null,
            'credits' => is_numeric($plan['limitKreditu'] ?? null) && (int) $plan['limitKreditu'] > 0 ? (int) $plan['limitKreditu'] : null,
        ], $plans);
    }
}
