<?php

namespace App\Services\ExamTerms;

use App\Contracts\StagClient;
use App\Exceptions\ExamRegistrationException;
use App\Exceptions\StagException;
use App\Mcp\Enums\ExamRegistrationAction;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Registers and unregisters a student for exam terms, in two steps.
 *
 * prepare() changes nothing: it reads the term and the student's state,
 * refuses a change that contradicts that state, collects warnings for the
 * ones STAG may still refuse, and parks a single-use token in the cache.
 * confirm() takes the token back with the subject, date, time and room
 * restated, checks the term has not moved in between, and only then makes
 * the one STAG call.
 *
 * This is the only class allowed to call a `terminy` write endpoint. They
 * are all plain GETs, so the call itself is the change.
 */
final readonly class ExamRegistrar
{
    /** How long a prepared change stays confirmable. */
    public const int TOKEN_TTL_SECONDS = 300;

    private ExamTermService $terms;

    public function __construct(private StagClient $stag)
    {
        $this->terms = new ExamTermService($stag);
    }

    /**
     * @return array{term: ExamTerm, warnings: list<string>, pending: PendingExamRegistration, expires_at: CarbonImmutable}
     *
     * @throws ExamRegistrationException
     * @throws StagException
     */
    public function prepare(int $userId, string $osCislo, int $termId, ExamRegistrationAction $action): array
    {
        $term = $this->terms->findForStudent($termId, $osCislo)
            ?? throw new ExamRegistrationException("No exam term with term_id {$termId}. Nothing was changed.");

        $this->refuseContradictions($term, $action);

        $pending = PendingExamRegistration::for($userId, $osCislo, $action, $term);
        Cache::put(self::cacheKey($pending->token), $pending->toArray(), self::TOKEN_TTL_SECONDS);

        return [
            'term' => $term,
            'warnings' => $this->warnings($term, $action, CarbonImmutable::now(ExamTerm::TIMEZONE)),
            'pending' => $pending,
            'expires_at' => CarbonImmutable::now(ExamTerm::TIMEZONE)->addSeconds(self::TOKEN_TTL_SECONDS),
        ];
    }

    /**
     * @param  array{subject?: ?string, date?: ?string, time_from?: ?string, room?: ?string}  $restated
     * @return array{action: ExamRegistrationAction, term: ?ExamTerm, stag_message: string}
     *
     * @throws ExamRegistrationException
     * @throws StagException
     */
    public function confirm(int $userId, string $token, array $restated): array
    {
        /* Pulled, not read: a token is spent by the first confirm attempt,
         * whether that attempt goes through or not. */
        $data = Cache::pull(self::cacheKey($token));
        $pending = is_array($data) ? PendingExamRegistration::fromArray($data) : null;

        if ($pending === null || $pending->userId !== $userId) {
            throw new ExamRegistrationException('This confirmation_token is unknown, expired or already used. Nothing was changed. Call prepare-exam-registration again.');
        }

        if ($mismatches = $pending->mismatches($restated)) {
            $expected = implode(', ', array_map(fn (string $field, ?string $value) => "{$field} \"{$value}\"", array_keys($mismatches), $mismatches));

            throw new ExamRegistrationException("The restated term does not match the prepared one (expected {$expected}). Nothing was changed and the token is spent. Call prepare-exam-registration again and restate that term to the user.");
        }

        $term = $this->terms->find($pending->termId)
            ?? throw new ExamRegistrationException('The term no longer exists in STAG. Nothing was changed.');

        if ($term->identifyingFacts() !== $pending->facts) {
            throw new ExamRegistrationException('The term changed in STAG since it was prepared (now '.$this->describe($term).'). Nothing was changed. Call prepare-exam-registration again and tell the user about the change.');
        }

        $message = $this->send($pending);

        if ($message !== 'OK') {
            throw new ExamRegistrationException('STAG did not make the change'.($message !== '' ? ": {$message}" : ', and gave no reason.'));
        }

        return [
            'action' => $pending->action,
            'term' => $this->freshState($pending),
            'stag_message' => $message,
        ];
    }

    /**
     * @throws ExamRegistrationException
     * @throws StagException
     */
    private function send(PendingExamRegistration $pending): string
    {
        $context = [
            'user_id' => $pending->userId,
            'action' => $pending->action->value,
            'term_id' => $pending->termId,
        ];

        Log::info('Exam registration change sent to STAG.', $context);

        try {
            /* stagUser must equal osCislo for a student caller, as on
             * getTerminyProStudenta. */
            $message = trim($this->stag->getText($pending->action->endpoint(), [
                'osCislo' => $pending->osCislo,
                'stagUser' => $pending->osCislo,
                'termIdno' => $pending->termId,
            ]));
        } catch (StagException $e) {
            Log::warning('Exam registration change failed.', [...$context, 'error' => $e->getMessage()]);

            if ($e->getPrevious() instanceof ConnectionException) {
                throw new ExamRegistrationException('STAG did not answer, so whether the change went through is unknown. Check list-exam-dates before trying again.', previous: $e);
            }

            $previous = $e->getPrevious();
            $said = $previous instanceof RequestException ? trim($previous->response->body()) : '';
            $said = $said !== '' && mb_strlen($said) <= 500 ? " STAG said: {$said}" : '';

            throw new ExamRegistrationException($e->getMessage().$said.' Check list-exam-dates for the current state before trying again.', previous: $e);
        }

        Log::info('Exam registration change answered.', [...$context, 'stag_message' => $message]);

        return $message;
    }

    /**
     * The state after a successful change. Failing to read it back does not
     * undo the change, so it degrades to null instead of erroring.
     */
    private function freshState(PendingExamRegistration $pending): ?ExamTerm
    {
        try {
            return $this->terms->findForStudent($pending->termId, $pending->osCislo);
        } catch (StagException) {
            return null;
        }
    }

    /**
     * Refuses a change the current state rules out. Anything STAG might still
     * allow is a warning instead, and STAG decides on confirm.
     *
     * @throws ExamRegistrationException
     */
    private function refuseContradictions(ExamTerm $term, ExamRegistrationAction $action): void
    {
        $registration = $term->registration;
        $about = $this->describe($term);

        $refusal = match ($action) {
            ExamRegistrationAction::Register => match (true) {
                $registration?->registered === true => "You are already registered for {$about}.",
                $term->isCancelled() => "{$about} is cancelled.",
                default => null,
            },
            ExamRegistrationAction::Unregister => $registration?->registered === false
                ? "You are not registered for {$about}."
                : null,
            ExamRegistrationAction::JoinQueue => match (true) {
                $registration?->registered === true => "You are already registered for {$about}, so there is no queue to join.",
                $registration?->queueRegistered === true => "You are already in the waiting queue for {$about}.",
                $term->isCancelled() => "{$about} is cancelled.",
                default => null,
            },
            ExamRegistrationAction::LeaveQueue => $registration?->queueRegistered === false
                ? "You are not in the waiting queue for {$about}."
                : null,
        };

        if ($refusal !== null) {
            throw new ExamRegistrationException($refusal.' Nothing was changed.');
        }
    }

    /**
     * @return list<string>
     */
    private function warnings(ExamTerm $term, ExamRegistrationAction $action, CarbonImmutable $now): array
    {
        $registration = $term->registration;
        $warnings = [];

        $canChange = $action->isQueue() ? $registration?->queueCanChange : $registration?->canChange;
        $reason = $action->isQueue() ? $registration?->queueReason : ($registration?->reason ?? $registration?->reasonDetail);

        if ($canChange === false) {
            $warnings[] = 'STAG currently reports this change as not allowed'.($reason !== null ? ": {$reason}" : '.').' It will most likely refuse.';
        } elseif ($canChange === null) {
            $warnings[] = 'STAG\'s student view does not list this term, so whether the change is allowed is unknown until STAG answers.';
        }

        $deadline = match ($action) {
            ExamRegistrationAction::Register, ExamRegistrationAction::JoinQueue => $term->registerBy(),
            ExamRegistrationAction::Unregister, ExamRegistrationAction::LeaveQueue => $term->unregisterBy(),
        };

        if ($deadline?->lt($now)) {
            $warnings[] = "The deadline to {$action->verb()} this term passed on {$deadline->format('Y-m-d H:i')}. STAG decides whether it still accepts the change.";
        }

        $free = $term->freePlaces();

        if ($action === ExamRegistrationAction::Register && $free === 0) {
            $warnings[] = 'The term is full. join_queue puts the student in line for a freed place instead.';
        }

        if ($action === ExamRegistrationAction::JoinQueue && $free !== null && $free > 0) {
            $warnings[] = "The term still has {$free} free places, so register would get a place directly.";
        }

        if ($action === ExamRegistrationAction::Unregister && $free === 0) {
            $warnings[] = 'The term is full, so the place will likely go to someone else and may not be available again.';
        }

        return $warnings;
    }

    private function describe(ExamTerm $term): string
    {
        $facts = $term->identifyingFacts();

        return trim("{$facts['subject']} on {$facts['date']} at {$facts['time_from']} in {$facts['room']}");
    }

    private static function cacheKey(string $token): string
    {
        return 'exam-registration:'.hash('sha256', $token);
    }
}
