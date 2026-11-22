<?php

namespace App\Services\ExamTerms;

use App\Mcp\Enums\ExamRegistrationAction;
use Illuminate\Support\Str;

/**
 * A change prepared by prepare-exam-registration and waiting for
 * confirm-exam-registration: who, what, which term, and the facts the agent
 * has to restate. Lives in the cache under its token and is used once.
 */
final readonly class PendingExamRegistration
{
    /**
     * @param  array{subject: string, date: ?string, time_from: ?string, room: ?string}  $facts
     */
    public function __construct(
        public string $token,
        public int $userId,
        public string $osCislo,
        public ExamRegistrationAction $action,
        public int $termId,
        public array $facts,
    ) {}

    public static function for(int $userId, string $osCislo, ExamRegistrationAction $action, ExamTerm $term): self
    {
        return new self(Str::random(40), $userId, $osCislo, $action, $term->id, $term->identifyingFacts());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            token: $data['token'],
            userId: $data['user_id'],
            osCislo: $data['os_cislo'],
            action: ExamRegistrationAction::from($data['action']),
            termId: $data['term_id'],
            facts: $data['facts'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'user_id' => $this->userId,
            'os_cislo' => $this->osCislo,
            'action' => $this->action->value,
            'term_id' => $this->termId,
            'facts' => $this->facts,
        ];
    }

    /**
     * The restated facts that do not match, as field => expected value.
     * Case, spacing and a leading zero on the hour are forgiven; anything
     * else is a different term.
     *
     * @param  array{subject?: ?string, date?: ?string, time_from?: ?string, room?: ?string}  $restated
     * @return array<string, ?string>
     */
    public function mismatches(array $restated): array
    {
        $mismatches = [];

        foreach ($this->facts as $field => $expected) {
            if (self::normalise($field, $restated[$field] ?? null) !== self::normalise($field, $expected)) {
                $mismatches[$field] = $expected;
            }
        }

        return $mismatches;
    }

    private static function normalise(string $field, ?string $value): string
    {
        $value = mb_strtoupper(preg_replace('/\s+/u', '', (string) $value));

        if ($field === 'time_from' && preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m)) {
            return sprintf('%02d:%s', $m[1], $m[2]);
        }

        return $value;
    }
}
