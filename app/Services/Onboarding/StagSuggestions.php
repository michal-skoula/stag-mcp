<?php

namespace App\Services\Onboarding;

use App\Contracts\StagClient;
use App\Exceptions\StagException;
use App\Mcp\Enums\StudyState;
use App\Services\Stag\StagProfile;

/**
 * Reads what STAG already knows about the user and turns it into suggested
 * answers to onboarding questions.
 *
 * The identity and per-role detail come from {@see StagProfile}, the same
 * code `who-am-i` runs. The one extra call is `programy/getPlanyStudenta`,
 * for the study start, which no student endpoint reports directly.
 *
 * A user can hold several studies. The one that is running wins, then the
 * first STAG lists. A teacher-only account has no study, so every study
 * question is simply left out and onboarding asks it.
 */
final readonly class StagSuggestions
{
    public function __construct(private StagClient $stag) {}

    /**
     * One entry per question STAG could answer for this user. A question it
     * cannot answer is absent, never null.
     *
     * @return array<string, array{value: int|string|list<string>, guessed: bool}>
     *
     * @throws StagException when the identity itself cannot be read
     */
    public function build(): array
    {
        $roles = (new StagProfile($this->stag))->describe()['roles'];
        $study = $this->chooseStudy($roles);

        $answers = [
            OnboardingQuestion::Roles->value => $this->roleCodes($roles),
            OnboardingQuestion::Faculty->value => $this->faculty($roles, $study),
            OnboardingQuestion::Programme->value => $study['student']['programme']['name'] ?? null,
            OnboardingQuestion::StudyYear->value => $this->studyYear($study),
            OnboardingQuestion::StudyStart->value => $this->studyStart($study),
        ];

        $answers[OnboardingQuestion::City->value] = FacultyCity::guess($answers[OnboardingQuestion::Faculty->value]);

        $suggestions = [];

        foreach ($answers as $key => $value) {
            if ($value !== null && $value !== []) {
                $suggestions[$key] = [
                    'value' => $value,
                    'guessed' => OnboardingQuestion::from($key)->isGuess(),
                ];
            }
        }

        return $suggestions;
    }

    /**
     * @param  list<array<string, mixed>>  $roles
     * @return array<string, mixed>|null
     */
    private function chooseStudy(array $roles): ?array
    {
        $studies = array_values(array_filter($roles, fn (array $role) => $role['student'] !== null));

        foreach ($studies as $study) {
            if ($study['student']['state'] === StudyState::Active->label()) {
                return $study;
            }
        }

        return $studies[0] ?? null;
    }

    /**
     * @param  list<array<string, mixed>>  $roles
     * @return list<string>
     */
    private function roleCodes(array $roles): array
    {
        return array_values(array_unique(array_filter(array_column($roles, 'role'))));
    }

    /**
     * The study's faculty, else the faculty of the first role that names one.
     *
     * @param  list<array<string, mixed>>  $roles
     * @param  array<string, mixed>|null  $study
     */
    private function faculty(array $roles, ?array $study): ?string
    {
        $faculty = $study['student']['programme']['faculty'] ?? $study['fakulta'] ?? null;

        return filled($faculty)
            ? $faculty
            : collect($roles)->pluck('fakulta')->first(fn ($code) => filled($code));
    }

    /**
     * STAG quotes the year as a string.
     *
     * @param  array<string, mixed>|null  $study
     */
    private function studyYear(?array $study): ?int
    {
        $year = $study['student']['rocnik'] ?? null;

        return is_numeric($year) ? (int) $year : null;
    }

    /**
     * The year the student's plan version began, taken from `getPlanyStudenta`.
     *
     * Plans are re-issued every academic year and `verze` is the intake year
     * the plan was written for, so the student's own plan names the year they
     * started. When the study has several specialisations the plans of the
     * enrolled ones are preferred, and the earliest version wins. An
     * unreadable answer means no suggestion; it is not worth failing the rest.
     *
     * @param  array<string, mixed>|null  $study
     */
    private function studyStart(?array $study): ?int
    {
        $osCislo = $study['os_cislo'] ?? null;

        if (blank($osCislo)) {
            return null;
        }

        try {
            $plans = $this->stag->get('programy/getPlanyStudenta', ['osCislo' => $osCislo])['planInfo'] ?? [];
        } catch (StagException) {
            return null;
        }

        $enrolled = array_map('trim', explode(',', (string) ($study['student']['programme']['specialisation_ids'] ?? '')));
        $own = array_filter($plans, fn (array $plan) => in_array((string) ($plan['oborIdno'] ?? ''), $enrolled, true));

        $versions = array_filter(
            array_column($own ?: $plans, 'verze'),
            fn ($version) => is_numeric($version),
        );

        return $versions === [] ? null : (int) min($versions);
    }
}
