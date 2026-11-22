<?php

namespace App\Services\Onboarding;

/**
 * The onboarding questions STAG can answer, or at least guess at.
 *
 * Left out on purpose, because STAG knows nothing about them: the preferred
 * response language, and which role to act as when an account holds several.
 * STAG only reports the roles that exist; choosing one is the user's call,
 * and belongs to the role preference, not to this list.
 *
 * Every case is a suggestion until the user confirms it. `City` is the one
 * that is a guess rather than a reading, see {@see FacultyCity}.
 */
enum OnboardingQuestion: string
{
    /**
     * First academic year of the study, as the plan version it started on.
     * The current year of study is not stored, it goes stale every September.
     * Work it out from this value when it is needed.
     */
    case StudyStart = 'study_start';

    case Programme = 'programme';

    case Faculty = 'faculty';

    /** The STAG role codes the account holds, e.g. `["ST", "VY"]`. */
    case Roles = 'roles';

    case City = 'city';

    /**
     * Validation rules for a confirmed value, which may differ from the
     * suggestion: the user is allowed to correct it.
     *
     * @return list<string|array<string, mixed>>
     */
    public function valueRules(): array
    {
        return match ($this) {
            self::StudyStart => ['integer', 'between:1990,'.(now()->year + 1)],
            self::Programme, self::City => ['string', 'max:255'],
            self::Faculty => ['string', 'max:20'],
            self::Roles => ['array', 'max:10'],
        };
    }

    /**
     * Rules for the items of an array value, or null for a scalar one.
     *
     * @return list<string>|null
     */
    public function itemRules(): ?array
    {
        return $this === self::Roles ? ['string', 'max:10'] : null;
    }

    /**
     * The `user_preferences` column a confirmed answer is written to, or null
     * when no preference holds it yet. Confirmed answers without one stay in
     * the onboarding record.
     */
    public function preferenceColumn(): ?string
    {
        return match ($this) {
            self::City => 'city',
            default => null,
        };
    }

    /**
     * Whether the suggestion is an inference rather than something STAG states.
     */
    public function isGuess(): bool
    {
        return $this === self::City;
    }
}
