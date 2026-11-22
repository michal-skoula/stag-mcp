<?php

namespace App\Services\Onboarding;

use App\Models\OnboardingSuggestion;
use App\Models\User;

/**
 * The data an onboarding screen needs, and the write behind "confirm or
 * correct": per question the suggested value, what the user confirmed, and
 * therefore what is still left to ask.
 *
 * A suggestion is never a preference. Only {@see self::confirm()} writes one,
 * and only for questions that have a preference column.
 */
final class OnboardingAnswers
{
    /**
     * @return array{
     *     status: string,
     *     fetched_at: string|null,
     *     failure: string|null,
     *     questions: list<array{question: string, suggested: mixed, guessed: bool, confirmed: mixed, needs_answer: bool}>
     * }
     */
    public function forUser(User $user): array
    {
        $record = $user->onboardingSuggestion;

        $questions = array_map(function (OnboardingQuestion $question) use ($record): array {
            $suggestion = $record?->suggestions[$question->value] ?? null;
            $confirmed = $record?->confirmedAnswer($question);

            return [
                'question' => $question->value,
                'suggested' => $suggestion['value'] ?? null,
                'guessed' => $suggestion['guessed'] ?? $question->isGuess(),
                'confirmed' => $confirmed,
                'needs_answer' => $confirmed === null,
            ];
        }, OnboardingQuestion::cases());

        return [
            'status' => ($record?->status ?? SuggestionStatus::None)->value,
            'fetched_at' => $record?->fetched_at?->toIso8601String(),
            'failure' => $record?->failure,
            'questions' => $questions,
        ];
    }

    /**
     * Stores the user's answers, whether they accepted a suggestion or typed
     * their own, and writes through to the preference where one exists.
     *
     * @param  array<string, mixed>  $answers  keyed by {@see OnboardingQuestion} value
     */
    public function confirm(User $user, array $answers): OnboardingSuggestion
    {
        $record = $user->onboardingSuggestion()->firstOrNew([], ['status' => SuggestionStatus::None]);
        $confirmed = $record->confirmed ?? [];

        foreach ($answers as $key => $value) {
            $question = OnboardingQuestion::from($key);
            $confirmed[$key] = $value;

            if ($column = $question->preferenceColumn()) {
                $user->preferences()->updateOrCreate([], [$column => $value]);
            }
        }

        $record->confirmed = $confirmed;
        $record->save();

        return $record;
    }
}
