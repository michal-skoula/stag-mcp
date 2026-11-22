<?php

namespace App\Models;

use App\Services\Onboarding\OnboardingQuestion;
use App\Services\Onboarding\SuggestionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Answers to onboarding questions read from STAG, held apart from the user's
 * preferences until the user confirms or corrects them.
 *
 * `suggestions` is what STAG said (and what we inferred from it), `confirmed`
 * is what the user agreed to. A refresh only ever replaces `suggestions`.
 *
 * @property array<string, array{value: mixed, guessed: bool}>|null $suggestions
 * @property array<string, mixed>|null $confirmed
 */
class OnboardingSuggestion extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SuggestionStatus::class,
            'suggestions' => 'array',
            'confirmed' => 'array',
            'fetched_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The user's answer to one question, if they gave one.
     */
    public function confirmedAnswer(OnboardingQuestion $question): mixed
    {
        return ($this->confirmed ?? [])[$question->value] ?? null;
    }
}
