<?php

namespace App\Services\StudyPlans;

/**
 * One study plan, from `vsp/getStudijniPlan` plus `programy/getPlanInfo`.
 *
 * vsp is the only place the subjects satisfying a block are listed, but its
 * own `pocetSemestru` is 0 on every plan and it has no plan name, so those
 * come from `getPlanInfo`. The raw payload is ~276 KB for a bachelor plan;
 * this keeps only what an audit or a reader needs, as plain arrays so the
 * repository can cache it.
 *
 * Each subject row is a slot with its own plan id rather than a subject code:
 * the same subject can sit twice in one block (`UTS/TV`, taken in two
 * semesters) and once in two blocks (`KIV/PRO`), so neither deduplicating
 * nor keying by code is safe.
 *
 * @phpstan-type Slot array{
 *     slot_id: int,
 *     code: string,
 *     name: ?string,
 *     credits: int,
 *     exam_type: ?string,
 *     hours: ?string,
 *     recommended_year: ?int,
 *     recommended_semester: ?string,
 *     state_exam: bool,
 *     prerequisites: list<string>,
 *     excludes: list<string>,
 *     replaces: list<string>,
 *     recommended_first: list<string>,
 * }
 * @phpstan-type Block array{
 *     id: int,
 *     segment: ?string,
 *     name: ?string,
 *     statut: string,
 *     min_credits: ?int,
 *     min_subjects: ?int,
 *     all_required: bool,
 *     eu_track: bool,
 *     slots: list<Slot>,
 * }
 * @phpstan-type Plan array{
 *     id: int,
 *     name: ?string,
 *     semesters: ?int,
 *     credit_limit: ?int,
 *     valid_for: ?string,
 *     version: ?string,
 *     description_url: ?string,
 *     blocks: list<Block>,
 * }
 */
final readonly class StudyPlan
{
    /**
     * @param  Plan  $plan
     */
    private function __construct(private array $plan) {}

    /**
     * @param  array<string, mixed>  $plan  `vsp/getStudijniPlan` as returned
     * @param  array<string, mixed>  $planInfo  `programy/getPlanInfo` as returned, or [] when refused
     */
    public static function fromStag(array $plan, array $planInfo): self
    {
        $blocks = [];

        foreach ($plan['skupinySegmentu'] ?? [] as $group) {
            foreach ($group['segmenty'] ?? [] as $segment) {
                $segmentName = self::text($segment['nazev'] ?? null) ?? self::nullIfBlank($segment['popis'] ?? null);

                foreach ($segment['bloky'] ?? [] as $block) {
                    $blocks[] = self::toBlock($block, $segmentName);
                }
            }
        }

        return new self([
            'id' => (int) ($plan['stplIdno'] ?? $planInfo['stplIdno'] ?? 0),
            'name' => self::nullIfBlank($planInfo['nazev'] ?? null),
            'semesters' => self::positiveInt($planInfo['pocetSemestru'] ?? null),
            'credit_limit' => self::positiveInt($planInfo['limitKreditu'] ?? null),
            'valid_for' => self::nullIfBlank((string) ($plan['rokPlatnosti'] ?? $planInfo['rokPlatnosti'] ?? '')),
            'version' => self::nullIfBlank((string) ($plan['verze'] ?? $planInfo['verze'] ?? '')),
            'description_url' => self::nullIfBlank($plan['popisPlanuUrl'] ?? null),
            'blocks' => $blocks,
        ]);
    }

    /**
     * @param  Plan  $plan  as produced by {@see toArray()}
     */
    public static function fromArray(array $plan): self
    {
        return new self($plan);
    }

    /**
     * @return Plan
     */
    public function toArray(): array
    {
        return $this->plan;
    }

    public function id(): int
    {
        return $this->plan['id'];
    }

    public function name(): ?string
    {
        return $this->plan['name'];
    }

    /**
     * @return list<Block>
     */
    public function blocks(): array
    {
        return $this->plan['blocks'];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return Block
     */
    private static function toBlock(array $block, ?string $segmentName): array
    {
        $statut = strtoupper((string) ($block['statut'] ?? 'C'));
        $name = self::text($block['nazev'] ?? null);
        $minSubjects = self::positiveInt($block['minPocetPredmetu'] ?? null);
        $slots = array_map(self::toSlot(...), $block['predmety'] ?? []);

        return [
            'id' => (int) ($block['blokIdno'] ?? 0),
            'segment' => $segmentName,
            'name' => $name,
            'statut' => $statut,
            'min_credits' => self::positiveInt($block['minKred'] ?? null),
            'min_subjects' => $minSubjects,
            /*
             * A count rule below the number of slots makes it a pick-some block
             * ("Cizí jazyk - povinný" is A, 1 of several). Without a count rule
             * an A block means every subject in it.
             */
            'all_required' => $minSubjects !== null ? $minSubjects >= count($slots) : $statut === 'A',
            'eu_track' => preg_match('/EU\s*Track/i', ($name ?? '').' '.($segmentName ?? '')) === 1,
            'slots' => $slots,
        ];
    }

    /**
     * `podminujiciPredmety` is mapped even though it is empty at ZČU on every
     * plan sampled: the school abolished prerequisites, the field still works,
     * and another STAG school may fill it.
     *
     * @param  array<string, mixed>  $row
     * @return Slot
     */
    private static function toSlot(array $row): array
    {
        $subject = $row['predmet'] ?? [];

        return [
            'slot_id' => (int) ($row['id'] ?? 0),
            'code' => self::code($row),
            'name' => self::text($subject['nazev'] ?? null),
            'credits' => (int) ($row['kredity'] ?? $subject['kredity'] ?? 0),
            'exam_type' => self::nullIfBlank($subject['typZk'] ?? null),
            'hours' => self::nullIfBlank($subject['rozsah'] ?? null),
            'recommended_year' => self::positiveInt($row['doporucenyRocnik'] ?? null),
            'recommended_semester' => self::nullIfBlank($row['doporucenySemestr'] ?? null),
            'state_exam' => (bool) ($row['statnice'] ?? $subject['statnice'] ?? false),
            'prerequisites' => self::codes($subject['podminujiciPredmety'] ?? []),
            'excludes' => self::codes($subject['vyloucenePredmety'] ?? []),
            'replaces' => self::codes($subject['nahrazovanePredmety'] ?? []),
            'recommended_first' => self::codes($subject['doporucenePredmety'] ?? []),
        ];
    }

    /**
     * Built from `pracZkr`/`zkrPredm`, never from `zkratkaProZobrazeni`, which
     * carries presentation markup (`KIV<span>/</span>DB1`).
     *
     * @param  array<string, mixed>  $row
     */
    public static function code(array $row): string
    {
        return trim((string) ($row['pracZkr'] ?? '')).'/'.trim((string) ($row['zkrPredm'] ?? ''));
    }

    /**
     * @return list<string>
     */
    private static function codes(mixed $rows): array
    {
        return is_array($rows) ? array_values(array_unique(array_map(self::code(...), $rows))) : [];
    }

    /**
     * Free text comes as `{valueCs, valueEn}`. Czech is used, matching the
     * rest of the server; `valueEn` falls back to Czech on untranslated plans
     * anyway.
     */
    private static function text(mixed $value): ?string
    {
        return is_array($value) ? self::nullIfBlank($value['valueCs'] ?? null) : null;
    }

    private static function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function nullIfBlank(?string $value): ?string
    {
        $trimmed = $value === null ? '' : trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
