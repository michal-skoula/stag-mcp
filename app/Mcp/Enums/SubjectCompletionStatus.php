<?php

namespace App\Mcp\Enums;

/**
 * STAG's `STAV_ABSOLVOVANI` číselník: the completion state of one enrolled subject.
 *
 * Sourced from `ciselniky/getCiselnik?domena=STAV_ABSOLVOVANI`. Note that
 * labels are STAG's own Czech wording; the endpoint returns Czech for this
 * domain even when asked for `lang=en`.
 */
enum SubjectCompletionStatus: string
{
    case Passed = 'A';
    case FailedWithGrade = 'N';
    case FailedWithoutGrade = 'NX';
    case FailedWithoutZapocet = 'NZ';
    case TransferredWithGrade = 'NP';
    case TransferredWithoutGrade = 'NPX';
    case RecognisedNotCompleted = 'NU';
    case Recognised = 'U';
    case RecognisedTransferred = 'UP';
    case DeferredInterrupted = 'O';
    case DeferredParenthood = 'R';
    case InProgress = 'S';
    case EnrolledNextYear = 'Z';

    /**
     * The grouped, English status an MCP client sees. Several STAG codes
     * collapse onto one status: the exact code always travels alongside it in
     * the subject's `status.code`, so nothing is lost here.
     */
    public function status(): string
    {
        return match ($this) {
            self::Passed => 'passed',
            self::FailedWithGrade,
            self::FailedWithoutGrade,
            self::FailedWithoutZapocet => 'failed',
            self::TransferredWithGrade,
            self::TransferredWithoutGrade => 'transferred',
            self::RecognisedNotCompleted,
            self::Recognised,
            self::RecognisedTransferred => 'recognised',
            self::DeferredInterrupted,
            self::DeferredParenthood => 'deferred',
            self::InProgress => 'in_progress',
            self::EnrolledNextYear => 'enrolled_next_year',
        };
    }

    /**
     * STAG's own Czech description of the code.
     */
    public function label(): string
    {
        return match ($this) {
            self::Passed => 'Absolvováno',
            self::FailedWithGrade => 'Neabsolvováno, hodnocení uvedeno',
            self::FailedWithoutGrade => 'Neabsolvováno, hodnocení neuvedeno, studováno v minulých letech',
            self::FailedWithoutZapocet => 'Neabsolvováno, nezískal zápočet před zkouškou, závěrečné hodnocení neuvedeno',
            self::TransferredWithGrade => 'Neabsolvováno, převedeno z jiného studia, hodnocení uvedeno',
            self::TransferredWithoutGrade => 'Neabsolvováno, převedeno z jiného studia, hodnocení neuvedeno',
            self::RecognisedNotCompleted => 'Neabsolvováno, uznáno',
            self::Recognised => 'Uznáno',
            self::RecognisedTransferred => 'Uznáno, převedeno z jiného studia',
            self::DeferredInterrupted => 'Odloženo při přerušení',
            self::DeferredParenthood => 'Odloženo při rodičovství',
            self::InProgress => 'Studováno',
            self::EnrolledNextYear => 'Zapsáno, pro studium v příštím roce',
        };
    }

    /**
     * Whether the subject's period is over, so a missing grade means "never
     * finished" rather than "not sat yet".
     */
    public function isConcluded(): bool
    {
        return $this->countsTowardAverage();
    }

    /**
     * Whether the subject enters STAG's weighted average.
     *
     * Verified against a printed FAV transcript for `A` and `NX`: both count,
     * with `NX`'s missing grade scored as the scale's
     * `nevyplnenoHodnotaDoPrumeru` (4.0 on the 1|2|3|4 scale). `N` and `NZ`
     * follow by the same logic — a concluded enrolment that was not passed.
     *
     * Recognised, transferred and deferred subjects are held out: the
     * transcript counts `Uznáno` separately rather than folding it into the
     * average, and a deferral is not an outcome. Neither case appears on the
     * account this was verified against, so those four are reasoned rather
     * than observed.
     */
    public function countsTowardAverage(): bool
    {
        return match ($this) {
            self::Passed,
            self::FailedWithGrade,
            self::FailedWithoutGrade,
            self::FailedWithoutZapocet => true,
            default => false,
        };
    }

    /**
     * Whether STAG says a grade is on record for this code.
     *
     * Descriptive only — the average reads the actual `hodnidno` on the row
     * rather than trusting this, so a row that disagrees with its own status
     * code still computes correctly.
     */
    public function hasRecordedGrade(): bool
    {
        return match ($this) {
            self::Passed,
            self::FailedWithGrade,
            self::TransferredWithGrade => true,
            default => false,
        };
    }

    /**
     * Whether the subject was passed, by any route including recognition.
     */
    public function isPassed(): bool
    {
        return match ($this) {
            self::Passed,
            self::Recognised,
            self::RecognisedTransferred => true,
            default => false,
        };
    }
}
