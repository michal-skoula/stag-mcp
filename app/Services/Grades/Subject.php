<?php

namespace App\Services\Grades;

use App\Mcp\Enums\SubjectCompletionStatus;
use Illuminate\Support\Carbon;

/**
 * One enrolment: a subject in a given year and semester, with whatever
 * assessment STAG has recorded against it.
 *
 * Built from a `znamky/getZnamkyByStudent` row, enriched with the name and
 * credit value that only `student/getStudentPredmetyAbsolvoval` carries.
 */
final class Subject
{
    private ?Subject $supersededBy = null;

    /**
     * @param  array<string, mixed>|null  $zkouska
     * @param  array<string, mixed>|null  $zapocet
     */
    private function __construct(
        public readonly string $katedra,
        public readonly string $zkratka,
        public readonly string $rok,
        public readonly string $semestr,
        private readonly ?string $nazev,
        private readonly ?int $kredity,
        private readonly string $statusCode,
        private readonly ?SubjectCompletionStatus $stav,
        private readonly ?array $zkouska,
        private readonly ?array $zapocet,
        private readonly ?float $averageValue,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, array<string, mixed>>  $catalogue
     */
    public static function fromRow(array $row, array $catalogue, GradeScales $scales): self
    {
        $listed = $catalogue[self::enrolmentKey($row)] ?? null;
        $statusCode = (string) ($row['stavAbsolvovani'] ?? '');

        return new self(
            katedra: (string) $row['katedra'],
            zkratka: (string) $row['zkratka'],
            rok: (string) $row['rok'],
            semestr: (string) $row['semestr'],
            nazev: $listed['nazevPredmetu'] ?? null,
            kredity: $listed['pocetKreditu'] ?? null,
            statusCode: $statusCode,
            stav: SubjectCompletionStatus::tryFrom($statusCode),
            zkouska: self::assessment($row, 'zk_', $scales),
            zapocet: self::assessment($row, 'zppzk_', $scales),
            averageValue: $scales->assessmentContributionToWeightedAverage(
                self::intOrNull($row['zk_hodnidno'] ?? null),
                self::intOrNull($row['zk_tyhoidno'] ?? null),
            ),
        );
    }

    /**
     * One half of a row: the `zk_*` exam block or the `zppzk_*` zápočet block.
     * Whole blocks come back null on subjects with no zápočet requirement, and
     * individual fields arrive as empty strings as often as nulls.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private static function assessment(array $row, string $prefix, GradeScales $scales): ?array
    {
        $hodnIdno = self::intOrNull($row[$prefix.'hodnidno'] ?? null);
        $grade = self::normalizeValues($row[$prefix.'hodnoceni'] ?? null);
        $date = self::normalizeValues($row[$prefix.'datum'] ?? null);
        $teacher = self::normalizeValues($row[$prefix.'ucit_jmeno'] ?? null);
        $attempt = self::normalizeValues($row[$prefix.'pokus'] ?? null);

        if ($hodnIdno === null && $grade === null && $date === null && $teacher === null && $attempt === null) {
            return null;
        }

        $teacherId = self::normalizeValues($row[$prefix.'ucit_idno'] ?? null);

        return [
            'grade' => $grade,
            'grade_name' => $scales->nameOfGrade($hodnIdno),
            'is_pass' => $scales->isPass($hodnIdno),
            'scale' => self::normalizeValues($row[$prefix.'typ_hodnoceni'] ?? null),
            'points' => self::normalizeValues($row[$prefix.'body'] ?? null),
            'attempt' => $attempt !== null ? (int) $attempt : null,
            'date' => self::toIsoDate($date),
            'teacher' => $teacher,
            'teacher_id' => $teacherId !== null ? (int) $teacherId : null,
            'language' => self::normalizeValues($row[$prefix.'jazyk'] ?? null),
        ];
    }

    public function status(): string
    {
        return $this->stav?->status() ?? 'unknown';
    }

    public function completionStatus(): ?SubjectCompletionStatus
    {
        return $this->stav;
    }

    /**
     * How many credits this subject is worth.
     *
     * @return int|null
     */
    public function credits(): ?int
    {
        return $this->kredity;
    }

    public function averageValue(): ?float
    {
        return $this->averageValue;
    }

    /**
     * Unique identifier for the course, e.g. `KIV/IDT`.
     *
     * @return string
     */
    public function courseIdentifier(): string
    {
        return $this->katedra.'/'.$this->zkratka;
    }

    /**
     * Sortable rank for the academic period, so "later than" is comparable.
     * ZS precedes LS within the same starting year.
     *
     * @return int Odd numbers are LS, even are LS. Larger number = later year.
     */
    public function academicPeriodSortRank(): int
    {
        return ((int) $this->rok) * 2 + ($this->semestr === 'LS' ? 1 : 0);
    }

    /**
     * Newest period first, alphabetical by subject within a period.
     *
     * @return array{0: int, 1: string, 2: string}
     */
    public function sortKey(): array
    {
        return [-$this->academicPeriodSortRank(), $this->katedra, $this->zkratka];
    }

    /**
     * A concluded enrolment that was not passed, so an earlier one of the same
     * subject could still be corrected by a later attempt.
     */
    public function isUnfinished(): bool
    {
        return $this->status() === 'failed';
    }

    /**
     * Whether passing this enrolment retrospectively settles an earlier failed
     * attempt at the same subject. Recognition counts; being enrolled again
     * does not.
     */
    public function supersedesAnEarlierAttempt(): bool
    {
        return in_array($this->status(), ['passed', 'recognised'], true);
    }

    /**
     * Marks a subject as superseded.
     *
     * @param Subject $later By whom it has been superseded.
     *
     * @return void
     */
    public function supersededBy(self $later): void
    {
        $this->supersededBy = $later;
    }

    public function isSuperseded(): bool
    {
        return $this->supersededBy !== null;
    }

    /**
     * Checks whether these parameters canonically make the same subject as the current instance.
     *
     * @param string|null $rok
     * @param string|null $semestr
     * @param string|null $katedra
     * @param string|null $zkratka
     *
     * @return bool
     */
    public function matchesSubjectFromParams(?string $rok, ?string $semestr, ?string $katedra, ?string $zkratka): bool
    {
        if ($rok !== null && $this->rok !== $rok) {
            return false;
        }

        if ($semestr !== null && $semestr !== '%' && $this->semestr !== $semestr) {
            return false;
        }

        if ($katedra !== null && ! str_contains(mb_strtolower($this->katedra), mb_strtolower($katedra))) {
            return false;
        }

        return $zkratka === null || str_contains(mb_strtolower($this->zkratka), mb_strtolower($zkratka));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'katedra' => $this->katedra,
            'zkratka' => $this->zkratka,
            'nazev' => $this->nazev,
            'kredity' => $this->kredity,
            'rok' => $this->rok,
            'semestr' => $this->semestr,
            'status' => [
                'type' => $this->status(),
                'code' => $this->statusCode,
                'label' => $this->stav?->label(),
                'superseded_by' => $this->supersededBy !== null
                    ? $this->supersededBy->rok.'/'.$this->supersededBy->semestr
                    : null,
            ],
            'zkouska' => $this->zkouska,
            'zapocet' => $this->zapocet,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function enrolmentKey(array $row): string
    {
        return implode('|', [$row['rok'] ?? '', $row['semestr'] ?? '', $row['katedra'] ?? '', $row['zkratka'] ?? '']);
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * STAG uses empty strings as often as nulls here, sometimes for the same
     * field across two rows of one response.
     */
    private static function normalizeValues(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * znamky pads its dates (`19.01.2026`), unlike the unpadded `d.M.yyyy`
     * other STAG namespaces use, so parse leniently.
     */
    private static function toIsoDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::createFromFormat('j.n.Y', $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
