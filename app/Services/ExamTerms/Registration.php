<?php

namespace App\Services\ExamTerms;

/**
 * The caller's relationship to one exam term.
 *
 * STAG answers this in two places with different depth.
 * `getTerminyProStudenta` is the portal's own view: registered or not, whether
 * the student may change that right now, and STAG's reason when not, plus the
 * same three for the waiting queue. `getTerminyZkousek?osCislo=` only says
 * which terms the student is (or was) registered on, but reaches past years
 * too. A term known only from the second source carries `registered` and
 * leaves the rest unknown rather than guessing.
 */
final readonly class Registration
{
    public function __construct(
        public bool $registered,
        public ?bool $canChange = null,
        public ?string $reasonCode = null,
        public ?string $reason = null,
        public ?string $reasonDetail = null,
        public ?bool $queueRegistered = null,
        public ?bool $queueCanChange = null,
        public ?string $queueReason = null,
    ) {}

    /**
     * @param  array<string, mixed>  $row  One `getTerminyProStudenta` row.
     */
    public static function fromStudentRow(array $row): self
    {
        return new self(
            registered: (bool) ($row['zapsan'] ?? false),
            canChange: isset($row['lzeZapsatOdepsat']) ? (bool) $row['lzeZapsatOdepsat'] : null,
            reasonCode: self::nullIfBlank($row['kodDuvoduProcNelzeZapsatOdepsat'] ?? null),
            reason: self::nullIfBlank($row['textDuvoduProcNelzeZapsatOdepsat'] ?? null),
            reasonDetail: self::nullIfBlank($row['popisDuvoduProcNelzeZapsatOdepsat'] ?? null),
            queueRegistered: isset($row['frontaZapsan']) ? (bool) $row['frontaZapsan'] : null,
            queueCanChange: isset($row['frontaLzeZapsatOdepsat']) ? (bool) $row['frontaLzeZapsatOdepsat'] : null,
            queueReason: self::nullIfBlank($row['frontaTextDuvoduProcNelzeZapsatOdepsat'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'registered' => $this->registered,
            'can_change' => $this->canChange,
            'reason_code' => $this->reasonCode,
            'reason' => $this->reason,
            'reason_detail' => $this->reasonDetail,
            'queue' => [
                'registered' => $this->queueRegistered,
                'can_change' => $this->queueCanChange,
                'reason' => $this->queueReason,
            ],
        ];
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
