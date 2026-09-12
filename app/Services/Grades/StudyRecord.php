<?php

namespace App\Services\Grades;

/**
 * A student's whole study record: every enrolment STAG knows about, with the
 * rules for summarising it.
 *
 * Always holds the complete history even when the caller only wants one
 * semester, because two of the rules here — whether a failed subject was later
 * retaken, and what the weighted average is — cannot be evaluated from a slice.
 * Filtering happens on the way out, via {@see filter()}.
 */
final readonly class StudyRecord
{
    /**
     * @param  list<Subject>  $subjects  ordered newest first
     */
    private function __construct(private array $subjects) {}

    /**
     * @param  list<array<string, mixed>>  $rows  json_decoded array of grades, from `znamky/getZnamkyByStudent` endpoint
     * @param  array<string, array<string, mixed>>  $subjectsCatalogue  subject names and credits tuples, keyed by enrolment
     */
    public static function build(array $rows, array $subjectsCatalogue, GradeScales $scales): self
    {
        $subjects = [];

        foreach ($rows as $row) {
            $subjects[] = Subject::fromRow($row, $subjectsCatalogue, $scales);
        }

        usort($subjects, fn (Subject $a, Subject $b) => $a->sortKey() <=> $b->sortKey());

        self::markRetakenSubjects($subjects);

        return new self($subjects);
    }

    /**
     * Flags each failed enrolment that a later passing enrolment of the same
     * subject supersedes.
     *
     * A retaken subject appears twice, once failed and once passed. Counting
     * both would score it as a 4 and as its real grade at once, permanently
     * dragging the average down for a subject that was in fact passed. STAG
     * draws the same distinction on its printed transcript, listing unfinished
     * subjects under "Nesplněné předměty později neopravené" — later *not*
     * corrected — which only means something if corrected ones are handled
     * differently.
     *
     * There is no status code for this. It exists only in the pair of rows.
     *
     * @param  list<Subject>  $subjects
     */
    private static function markRetakenSubjects(array $subjects): void
    {
        $supersededSubjects = [];

        // Pass one: marks subjects which were retaken, aka have an entry with the same identifier but older.
        foreach ($subjects as $subject) {
            if (! $subject->supersedesAnEarlierAttempt()) {
                continue;
            }

            $courseId = $subject->courseIdentifier();

            if (
                !isset($supersededSubjects[$courseId]) || // Guard for getting the latest retake if there are multiple.
                $subject->academicPeriodSortRank() > $supersededSubjects[$courseId]->academicPeriodSortRank()
            ) {
                $supersededSubjects[$courseId] = $subject;
            }
        }

        // Pass two: marks failed subjects which have been re-enrolled and thus retaken.
        foreach ($subjects as $subject) {
            if (! $subject->isUnfinished()) {
                continue;
            }

            $superseder = $supersededSubjects[$subject->courseIdentifier()] ?? null;

            if ($superseder !== null && $superseder->academicPeriodSortRank() > $subject->academicPeriodSortRank()) {
                $subject->supersededBy($superseder);
            }
        }
    }

    /**
     * @return list<Subject>
     */
    public function subjects(): array
    {
        return $this->subjects;
    }

    /**
     * A narrowed view. The retake flags computed over the full history survive,
     * so asking about 2025 alone still knows a subject was corrected in 2026.
     */
    public function filter(?string $rok, ?string $semestr, ?string $katedra, ?string $zkratka): self
    {
        return new self(array_values(array_filter(
            $this->subjects,
            fn (Subject $subject) => $subject->matchesSubjectFromParams($rok, $semestr, $katedra, $zkratka),
        )));
    }

    /**
     * Whether the study record is empty.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->subjects === [];
    }

    /**
     * How many subjects are in the record.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->subjects);
    }

    /**
     * Builds and sums counters for various attributes.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $counts = ['passed' => 0, 'failed' => 0, 'in_progress' => 0, 'other' => 0];
        $creditsEarned = 0;
        $creditsEnrolled = 0;
        $retaken = 0;

        foreach ($this->subjects as $subject) {

            /*
             * This normalizes statuses to only show relevant
             * ones, and truncates the others as `other`.
             */
            $statusNotNormalized = $subject->status();
            $status = match ($statusNotNormalized) {
                'passed', 'failed', 'in_progress' => $statusNotNormalized,
                default => 'other',
            };
            $counts[$status]++;

            $creditsEnrolled += $subject->credits() ?? 0;

            if ($subject->completionStatus()?->isPassed()) {
                $creditsEarned += $subject->credits() ?? 0;
            }

            if ($subject->isSuperseded()) {
                $retaken++;
            }
        }

        return [
            ...$counts,
            'credits_earned' => $creditsEarned,
            'credits_enrolled' => $creditsEnrolled,
            'retaken' => $retaken,
            'gpa_official' => $this->calculateWeightedAvg(
                fn (Subject $s) => ($s->completionStatus()?->countsTowardAverage() ?? false) && ! $s->isSuperseded(),
                'Credit-weighted mean over concluded subjects on a graded scale, scoring an unfinished one as 4, matching how STAG and the printed transcript compute it. Subjects later retaken and passed, and zápočet-only subjects, are excluded.',
            ),
            'gpa_passed_only' => $this->calculateWeightedAvg(
                fn (Subject $s) => $s->status() === 'passed',
                'Credit-weighted mean over passed subjects on a graded scale only. Not the official figure: it omits failed subjects, so it reads better than the transcript will.',
            ),
        ];
    }

    /**
     * @param  callable(Subject): bool  $qualifies
     * @return array<string, mixed>
     */
    private function calculateWeightedAvg(callable $qualifies, string $basis): array
    {
        $weighted = 0.0;
        $credits = 0;
        $counted = 0;

        foreach ($this->subjects as $subject) {
            $value = $subject->averageValue();
            $credit = $subject->credits();

            if ($value === null || $credit === null || $qualifies($subject) === false) {
                continue;
            }

            $weighted += $value * $credit;
            $credits += $credit;
            $counted++;
        }

        return [
            'value' => $credits > 0 ? round($weighted / $credits, 2) : null,
            'credits' => $credits,
            'subjects' => $counted,
            'basis' => $basis,
        ];
    }
}
