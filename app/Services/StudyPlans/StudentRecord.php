<?php

namespace App\Services\StudyPlans;

use SimpleXMLElement;

/**
 * The caller's progress through their study, from `vsp/getStudent`.
 *
 * Read from XML because the JSON form is broken (a cycle that truncates the
 * body). SimpleXML is iterated directly and never round-tripped through
 * json_encode: a repeated element collapses to an object when only one is
 * present, so `rokyStudia` would be a list for a second-year and an object
 * for a first-year. Every leaf arrives as a string, and `"false"` is truthy
 * in PHP, hence the explicit casts.
 *
 * Identity fields (`osCislo`, `osobIdno`, `email`) are not kept: the endpoint
 * ignores the osCislo it is given and answers for the ticket holder, so
 * nothing here should ever be echoed back as "the student you asked about".
 *
 * @phpstan-type Row array{
 *     code: string,
 *     credits: float,
 *     year: ?string,
 *     semester: ?string,
 *     statut: ?string,
 *     earned: bool,
 *     recognised: bool,
 *     in_progress: bool,
 *     next_year: bool,
 *     grade: ?string,
 *     attempt: ?int,
 * }
 * @phpstan-type Year array{year: ?string, grade_level: ?int, plan_id: ?int}
 */
final readonly class StudentRecord
{
    /**
     * @param  list<Year>  $years
     * @param  list<Row>  $rows
     */
    private function __construct(
        public ?int $creditsRequired,
        public float $creditsEarned,
        public float $creditsRecognised,
        public array $years,
        public array $rows,
    ) {}

    public static function fromXml(SimpleXMLElement $student): self
    {
        $years = [];
        $rows = [];

        foreach ($student->rokyStudia as $year) {
            $yearLabel = self::string($year->rokPlatnosti);
            $planId = null;

            foreach ($year->obory as $obor) {
                $planId ??= self::int($obor->stplIdno);

                foreach ($obor->predmety as $subject) {
                    $rows[] = self::toRow($subject, $yearLabel);
                }
            }

            $years[] = ['year' => $yearLabel, 'grade_level' => self::int($year->rocnik), 'plan_id' => $planId];
        }

        return new self(
            creditsRequired: self::int($student->kredityNutnoZiskatProgram),
            creditsEarned: (float) self::string($student->kredityZiskaneCelkem),
            creditsRecognised: (float) self::string($student->kredityUznaneCelkem),
            years: $years,
            rows: $rows,
        );
    }

    /**
     * The plan the student is assessed against: the latest year's. Each year
     * carries its own `stplIdno` because the plan version changes yearly.
     */
    public function currentPlanId(): ?int
    {
        $latest = null;

        foreach ($this->years as $year) {
            if ($year['plan_id'] !== null && ($latest === null || (string) $year['year'] >= (string) $latest['year'])) {
                $latest = $year;
            }
        }

        return $latest['plan_id'] ?? null;
    }

    public function currentGradeLevel(): ?int
    {
        $levels = array_filter(array_column($this->years, 'grade_level'));

        return $levels === [] ? null : max($levels);
    }

    /**
     * @return Row
     */
    private static function toRow(SimpleXMLElement $subject, ?string $year): array
    {
        $recognised = self::bool($subject->uznano);

        return [
            'code' => StudyPlan::code(['pracZkr' => self::string($subject->pracZkr), 'zkrPredm' => self::string($subject->zkrPredm)]),
            /* The row's own credits, which can differ from the subject's (`jinyPocetKredituNezOdPredmetu`). */
            'credits' => (float) self::string($subject->kredity),
            'year' => self::string($subject->rokPlatnosti) ?? $year,
            'semester' => self::string($subject->semestr),
            'statut' => self::string($subject->statut),
            'earned' => self::bool($subject->uspesneAbsolvovany) || $recognised,
            'recognised' => $recognised,
            'in_progress' => self::bool($subject->praveStudovan),
            'next_year' => self::bool($subject->zapsanProPristiRok),
            'grade' => self::string($subject->znamkaHodnoceni),
            'attempt' => self::int($subject->znamkaPokus),
        ];
    }

    private static function string(?SimpleXMLElement $node): ?string
    {
        if ($node === null || count($node) === 0 && trim((string) $node) === '') {
            return null;
        }

        return trim((string) $node);
    }

    private static function int(?SimpleXMLElement $node): ?int
    {
        $value = self::string($node);

        return is_numeric($value) ? (int) $value : null;
    }

    private static function bool(?SimpleXMLElement $node): bool
    {
        return filter_var(self::string($node), FILTER_VALIDATE_BOOL);
    }
}
