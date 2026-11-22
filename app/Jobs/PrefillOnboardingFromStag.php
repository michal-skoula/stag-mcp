<?php

namespace App\Jobs;

use App\Clients\StagHttpClient;
use App\Exceptions\StagException;
use App\Models\User;
use App\Services\Onboarding\StagSuggestions;
use App\Services\Onboarding\SuggestionStatus;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reads what STAG knows about the user and stores it as suggested onboarding
 * answers, so onboarding only has to ask what STAG cannot answer.
 *
 * Queued when the user links STAG. It runs on the ticket saved at that moment,
 * which lasts 30 minutes, so a queue backlog longer than that fails the job
 * with a clear reason instead of calling STAG with a dead ticket.
 *
 * Retries only help when STAG could not be reached or answered with a server
 * error. A rejected ticket or role carries no previous exception and will not
 * get better on a second try, so that case is recorded and the job ends.
 */
class PrefillOnboardingFromStag implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function __construct(public User $user) {}

    /**
     * Queues a fetch and marks the record pending, so a page can show
     * "loading" straight away. A fetch from the last day is not repeated
     * unless `$force` is set, because STAG tickets are re-issued all the time
     * and each link would otherwise cost a round of STAG calls.
     */
    public static function queueFor(User $user, bool $force = false): void
    {
        if (! $force && $user->onboardingSuggestion?->isFresh()) {
            return;
        }

        $user->onboardingSuggestion()->updateOrCreate([], ['status' => SuggestionStatus::Pending]);
        $user->unsetRelation('onboardingSuggestion');

        self::dispatch($user);
    }

    public function uniqueId(): string
    {
        return (string) $this->user->getKey();
    }

    /**
     * @throws StagException when STAG failed in a way worth retrying
     */
    public function handle(): void
    {
        $user = $this->user->refresh();

        if (! $user->hasValidStagToken()) {
            $this->record(SuggestionStatus::Failed, failure: 'The STAG ticket expired before the job ran.');

            return;
        }

        try {
            $suggestions = (new StagSuggestions(new StagHttpClient($user)))->build();
        } catch (StagException $e) {
            if ($e->getPrevious() !== null) {
                throw $e;
            }

            $this->record(SuggestionStatus::Failed, failure: $e->getMessage());

            return;
        }

        $this->record(SuggestionStatus::Ready, $suggestions);
    }

    public function failed(Throwable $exception): void
    {
        $this->record(SuggestionStatus::Failed, failure: $exception->getMessage());
    }

    /**
     * Only `suggestions` is replaced: what the user already confirmed stays.
     *
     * @param  array<string, array{value: mixed, guessed: bool}>|null  $suggestions
     */
    private function record(SuggestionStatus $status, ?array $suggestions = null, ?string $failure = null): void
    {
        $attributes = ['status' => $status, 'failure' => $failure];

        if ($status === SuggestionStatus::Ready) {
            $attributes += ['suggestions' => $suggestions, 'fetched_at' => now()];
        }

        $this->user->onboardingSuggestion()->updateOrCreate([], $attributes);
    }
}
