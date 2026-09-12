<?php

namespace App\Services\Grades;

/**
 * STAG's grading scales, from `znamky/typyHodnoceni`.
 *
 * Every rule about what a grade means and how it weighs into an average.
 *
 * Two scales are in use: the numeric 1|2|3|4 exam scale (tyhoidno 1)
 * and the S|N pass/fail zápočet scale (tyhoidno 2), which declares
 * `doPrumeru: false` and therefore keeps its subjects out of averages entirely.
 */
final readonly class GradeScales
{
    /**
     * @param  array<int, array<string, mixed>>  $grades  keyed by hodnidno
     * @param  array<int, float|null>  $unfilled  keyed by tyhoidno
     */
    private function __construct(
        private array $grades,
        private array $unfilled,
    ) {}

    /**
     * @param  mixed  $payload  the raw typyHodnoceni response
     */
    public static function fromPayload(mixed $payload): self
    {
        $grades = [];
        $unfilled = [];

        foreach (is_array($payload) ? $payload : [] as $type) {
            if (isset($type['tyhoidno'])) {
                $unfilled[$type['tyhoidno']] = $type['nevyplnenoHodnotaDoPrumeru'] ?? null;
            }

            foreach ($type['hodnoceni'] ?? [] as $grade) {
                if (isset($grade['hodnidno'])) {
                    $grades[$grade['hodnidno']] = $grade;
                }
            }
        }

        return new self($grades, $unfilled);
    }

    /**
     * What a grade means in Czech, e.g. "Výborně".
     */
    public function nameOfGrade(?int $hodnIdno): ?string
    {
        return $this->grade($hodnIdno)['nazevCs'] ?? null;
    }

    /**
     * Whether a grade is a pass, per STAG rather than inferred from its value.
     */
    public function isPass(?int $hodnIdno): ?bool
    {
        return $this->grade($hodnIdno)['jeToUspech'] ?? null;
    }

    /**
     * What one assessment contributes to a weighted average, or null when it
     * falls outside averages altogether.
     *
     * A recorded grade contributes its own `hodnotaDoPrumeru`, unless its scale
     * says `doPrumeru` is false. A missing grade contributes the scale's
     * `nevyplnenoHodnotaDoPrumeru`, STAG's stated "value to use when nothing is
     * filled in", which is 4.0 on the numeric scale and null on the pass/fail
     * one. That single field is what makes an unfinished subject score a 4 while
     * an unfinished zápočet costs nothing.
     */
    public function assessmentContributionToWeightedAverage(?int $hodnIdno, ?int $tyhoIdno): ?float
    {
        if ($hodnIdno !== null) {
            $grade = $this->grade($hodnIdno);

            if ($grade === null) {
                return null;
            }

            return ($grade['doPrumeru'] ?? false) ? (float) $grade['hodnotaDoPrumeru'] : null;
        }

        $unfilled = $tyhoIdno !== null ? ($this->unfilled[$tyhoIdno] ?? null) : null;

        return $unfilled !== null ? (float) $unfilled : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function grade(?int $hodnIdno): ?array
    {
        return $hodnIdno !== null ? ($this->grades[$hodnIdno] ?? null) : null;
    }
}
