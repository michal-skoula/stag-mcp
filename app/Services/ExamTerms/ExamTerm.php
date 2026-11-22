<?php

namespace App\Services\ExamTerms;

use App\Mcp\Enums\ExamTermAttempts;
use App\Mcp\Enums\ExamTermStatus;
use App\Mcp\Enums\ExamTermType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * One exam or zápočet term, mapped from a `terminy` row.
 *
 * `getTerminyZkousek` and `getTerminyProStudenta` share the row shape (the
 * second extends the first with the student's state), so both map here. All
 * times are Prague wall-clock times, which is what STAG stores.
 */
final readonly class ExamTerm
{
    public const string TIMEZONE = 'Europe/Prague';

    /**
     * @param  array<string, mixed>  $row
     */
    private function __construct(
        public int $id,
        public array $row,
        public ?CarbonImmutable $date,
        public ?Registration $registration,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row, ?Registration $registration = null): self
    {
        return new self(
            id: (int) $row['termIdno'],
            row: $row,
            date: self::parseDate($row['datum']['value'] ?? null, 'j.n.Y'),
            registration: $registration,
        );
    }

    public function withRegistration(?Registration $registration): self
    {
        return new self($this->id, $this->row, $this->date, $registration);
    }

    public function status(): ?ExamTermStatus
    {
        return ExamTermStatus::tryFrom((string) ($this->row['platnost'] ?? ''));
    }

    public function isRoomBooking(): bool
    {
        return $this->status() === ExamTermStatus::RoomBooking;
    }

    public function isCancelled(): bool
    {
        return $this->status() === ExamTermStatus::Cancelled;
    }

    public function isRegistered(): bool
    {
        return $this->registration?->registered ?? false;
    }

    /**
     * A term is past once its day is over, so today's exams still show.
     */
    public function isPast(CarbonInterface $now): bool
    {
        return $this->date !== null && $this->date->endOfDay()->lt($now);
    }

    public function rok(): ?string
    {
        return $this->row['rok'] ?? null;
    }

    public function semestr(): ?string
    {
        return $this->row['semestr'] ?? null;
    }

    /**
     * "KIV/DB1", the way students write a subject.
     */
    public function subjectCode(): string
    {
        return ($this->row['katedra'] ?? '').'/'.($this->row['predmet'] ?? '');
    }

    public function timeFrom(): ?string
    {
        return self::toTime($this->row['casOd'] ?? null);
    }

    /**
     * "UU 108", building and room, or null when STAG has neither.
     */
    public function roomLabel(): ?string
    {
        $label = trim(implode(' ', array_filter([
            self::nullIfBlank($this->row['budova'] ?? null),
            self::nullIfBlank($this->row['mistnost'] ?? null),
        ])));

        return $label === '' ? null : $label;
    }

    /**
     * The four facts a student checks before signing up, which the agent must
     * restate back before a change goes through.
     *
     * @return array{subject: string, date: ?string, time_from: ?string, room: ?string}
     */
    public function identifyingFacts(): array
    {
        return [
            'subject' => $this->subjectCode(),
            'date' => $this->date?->toDateString(),
            'time_from' => $this->timeFrom(),
            'room' => $this->roomLabel(),
        ];
    }

    public function freePlaces(): ?int
    {
        $registered = self::toInt($this->row['obsazeni'] ?? null);
        $limit = self::toInt($this->row['limit'] ?? null);

        return ($limit !== null && $registered !== null) ? max(0, $limit - $registered) : null;
    }

    public function registerBy(): ?CarbonImmutable
    {
        return self::parseDate($this->row['deadlineDatumPrihlaseni']['value'] ?? null, 'j.n.Y G:i');
    }

    public function unregisterBy(): ?CarbonImmutable
    {
        return self::parseDate($this->row['deadlineDatumOdhlaseni']['value'] ?? null, 'j.n.Y G:i');
    }

    /**
     * Ascending date and start time, for ordering a mixed list.
     */
    public function sortKey(): string
    {
        return ($this->date?->format('Y-m-d') ?? '9999-12-31').' '.($this->row['casOd'] ?? '').' '.$this->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(CarbonInterface $now): array
    {
        $row = $this->row;
        $registerBy = $this->registerBy();
        $unregisterBy = $this->unregisterBy();
        $opensAt = self::parseDate($row['zacatekPrihlasovani']['value'] ?? null, 'j.n.Y G:i');
        $typeLabel = $row['typTerminu'] ?? null;
        $attempts = $row['opravny'] ?? null;

        return [
            'term_id' => $this->id,
            'subject' => [
                'katedra' => $row['katedra'] ?? null,
                'zkratka' => $row['predmet'] ?? null,
                'rok' => $row['rok'] ?? null,
                'semestr' => $row['semestr'] ?? null,
            ],
            'type' => $typeLabel !== null ? ExamTermType::tryFrom($typeLabel)?->label() : null,
            'type_label' => $typeLabel,
            'status' => $this->status()?->label() ?? ($row['platnost'] ?? null),
            'date' => $this->date?->toDateString(),
            'time' => [
                'from' => $this->timeFrom(),
                'to' => self::toTime($row['casDo'] ?? null),
            ],
            'location' => [
                'building' => self::nullIfBlank($row['budova'] ?? null),
                'room' => self::nullIfBlank($row['mistnost'] ?? null),
            ],
            'examiner' => $this->examiner(),
            'capacity' => [
                'registered' => self::toInt($row['obsazeni'] ?? null),
                'limit' => self::toInt($row['limit'] ?? null),
                'free' => $this->freePlaces(),
            ],
            'group' => isset($row['grteIdno']) ? [
                'id' => (int) $row['grteIdno'],
                'registered' => self::toInt($row['skupinaObsazeni'] ?? null),
                'limit' => self::toInt($row['skupinaLimit'] ?? null),
                'parent_registered' => self::toInt($row['nadSkupinaObsazeni'] ?? null),
                'parent_limit' => self::toInt($row['nadSkupinaLimit'] ?? null),
            ] : null,
            'attempts_allowed' => $attempts !== null ? (ExamTermAttempts::tryFrom($attempts)?->label() ?? $attempts) : null,
            'deadlines' => [
                'registration_opens' => $opensAt?->format('Y-m-d H:i'),
                'register_by' => $registerBy?->format('Y-m-d H:i'),
                'register_by_passed' => $registerBy?->lt($now),
                'unregister_by' => $unregisterBy?->format('Y-m-d H:i'),
                'unregister_by_passed' => $unregisterBy?->lt($now),
            ],
            'contact' => self::nullIfBlank($row['kontakt'] ?? null),
            'note' => self::nullIfBlank($row['poznamka'] ?? null),
            'registration' => $this->registration?->toArray(),
        ];
    }

    /**
     * The embedded `ucitel` row carries email, phone and office too; only the
     * id and name travel, since get-ucitel-info answers the rest.
     *
     * @return array{teacher_id: ?int, name: ?string}
     */
    private function examiner(): array
    {
        $teacher = $this->row['ucitel'] ?? [];
        $name = trim(implode(' ', array_filter([
            $teacher['titulPred'] ?? null,
            $teacher['jmeno'] ?? null,
            $teacher['prijmeni'] ?? null,
        ])));
        $after = self::nullIfBlank($teacher['titulZa'] ?? null);

        return [
            'teacher_id' => isset($this->row['ucitIdno']) ? (int) $this->row['ucitIdno'] : null,
            'name' => $name === '' ? null : ($after !== null ? "{$name}, {$after}" : $name),
        ];
    }

    private static function parseDate(?string $value, string $format): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!'.$format, trim($value), self::TIMEZONE);

        return $date === false ? null : $date;
    }

    /**
     * STAG sends `casOd`/`casDo` as "HH:MM:SS"; seconds are always zero.
     */
    private static function toTime(?string $value): ?string
    {
        return $value !== null && preg_match('/^(\d{1,2}):(\d{2})/', $value, $m) ? sprintf('%02d:%s', $m[1], $m[2]) : null;
    }

    private static function toInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
