<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmOnboardingAnswersRequest;
use App\Jobs\PrefillOnboardingFromStag;
use App\Models\User;
use App\Services\Onboarding\OnboardingAnswers;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/**
 * JSON for the onboarding screen: what STAG suggested, and the write that
 * confirms or corrects it.
 */
class OnboardingSuggestionController extends Controller
{
    public function show(#[CurrentUser] User $user, OnboardingAnswers $answers): JsonResponse
    {
        return response()->json($answers->forUser($user));
    }

    public function update(
        #[CurrentUser] User $user,
        ConfirmOnboardingAnswersRequest $request,
        OnboardingAnswers $answers,
    ): JsonResponse {
        $answers->confirm($user, $request->answers());

        return response()->json($answers->forUser($user->refresh()));
    }

    /**
     * Fetches again, for when the first run failed or the user linked the wrong
     * account. Needs a live ticket, which the job checks. Answers 202 straight
     * away, or 429 once the fetches allowed per week are used up.
     */
    public function refresh(#[CurrentUser] User $user): JsonResponse
    {
        $queued = PrefillOnboardingFromStag::queueFor($user);

        return response()->json(status: $queued ? 202 : 429);
    }
}
