<?php

use App\Jobs\PrefillOnboardingFromStag;
use App\Models\User;
use App\Services\Onboarding\SuggestionStatus;
use Illuminate\Support\Facades\Queue;

function suggestedUser(array $confirmed = []): User
{
    $user = User::factory()->withStagToken()->create();
    $user->onboardingSuggestion()->create([
        'status' => SuggestionStatus::Ready,
        'fetched_at' => now(),
        'suggestions' => [
            'faculty' => ['value' => 'FAV', 'guessed' => false],
            'city' => ['value' => 'Plzeň', 'guessed' => true],
        ],
        'confirmed' => $confirmed ?: null,
    ]);

    return $user;
}

it('lists every question with its suggestion and whether it still needs an answer', function () {
    $user = suggestedUser(['faculty' => 'FEL']);

    $response = $this->actingAs($user)->getJson(route('onboarding.suggestions.show'))->assertOk();

    expect($response->json('status'))->toBe('ready')
        ->and(collect($response->json('questions'))->keyBy('question')->all())->toMatchArray([
            'faculty' => ['question' => 'faculty', 'suggested' => 'FAV', 'guessed' => false, 'confirmed' => 'FEL', 'needs_answer' => false],
            'city' => ['question' => 'city', 'suggested' => 'Plzeň', 'guessed' => true, 'confirmed' => null, 'needs_answer' => true],
            'programme' => ['question' => 'programme', 'suggested' => null, 'guessed' => false, 'confirmed' => null, 'needs_answer' => true],
        ]);
});

it('reports status none for a user nothing was fetched for', function () {
    $this->actingAs(User::factory()->create())
        ->getJson(route('onboarding.suggestions.show'))
        ->assertOk()
        ->assertJsonPath('status', 'none')
        ->assertJsonPath('fetched_at', null);
});

it('stores a confirmed answer apart from the suggestion', function () {
    $user = suggestedUser();

    $this->actingAs($user)
        ->putJson(route('onboarding.suggestions.update'), ['answers' => ['faculty' => 'FEL', 'study_start' => 2024]])
        ->assertJsonPath('questions.0.question', 'study_start')
        ->assertJsonPath('questions.0.confirmed', 2024);

    $record = $user->onboardingSuggestion()->first();

    expect($record->confirmed)->toBe(['study_start' => 2024, 'faculty' => 'FEL'])
        ->and($record->suggestions['faculty']['value'])->toBe('FAV');
});

it('keeps earlier confirmations when a later request confirms another question', function () {
    $user = suggestedUser(['faculty' => 'FEL']);

    $this->actingAs($user)->putJson(route('onboarding.suggestions.update'), ['answers' => ['programme' => 'Informatika']])->assertOk();

    expect($user->onboardingSuggestion()->first()->confirmed)->toBe(['faculty' => 'FEL', 'programme' => 'Informatika']);
});

it('writes a confirmed city to the preferences, and nothing else does', function () {
    $user = suggestedUser();

    $this->actingAs($user)->putJson(route('onboarding.suggestions.update'), ['answers' => ['faculty' => 'FAV']])->assertOk();

    expect($user->preferences()->exists())->toBeFalse();

    $this->actingAs($user)->putJson(route('onboarding.suggestions.update'), ['answers' => ['city' => 'Cheb']])->assertOk();

    expect($user->preferences()->first()->city)->toBe('Cheb');
});

it('accepts a confirmation from a user with no fetched suggestions', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson(route('onboarding.suggestions.update'), ['answers' => ['faculty' => 'FAV']])->assertOk();

    expect($user->onboardingSuggestion->confirmed)->toBe(['faculty' => 'FAV'])
        ->and($user->onboardingSuggestion->status)->toBe(SuggestionStatus::None);
});

it('stores a corrected list of roles', function () {
    $user = suggestedUser();

    $this->actingAs($user)->putJson(route('onboarding.suggestions.update'), ['answers' => ['roles' => ['ST', 'VY']]])->assertOk();

    expect($user->onboardingSuggestion()->first()->confirmed)->toBe(['roles' => ['ST', 'VY']]);
});

it('rejects invalid answers', function (array $answers, string $field) {
    $this->actingAs(suggestedUser())
        ->putJson(route('onboarding.suggestions.update'), ['answers' => $answers])
        ->assertJsonValidationErrors($field);
})->with([
    'unknown question' => [['shoe_size' => 42], 'answers.shoe_size'],
    'study start in the distant past' => [['study_start' => 1900], 'answers.study_start'],
    'roles not a list' => [['roles' => 'ST'], 'answers.roles'],
    'role item not a string' => [['roles' => [['ST']]], 'answers.roles.0'],
    'faculty too long' => [['faculty' => str_repeat('A', 21)], 'answers.faculty'],
]);

it('rejects an empty confirmation', function () {
    $this->actingAs(suggestedUser())
        ->putJson(route('onboarding.suggestions.update'), ['answers' => []])
        ->assertJsonValidationErrors('answers');
});

it('queues a refresh', function () {
    Queue::fake();
    $user = suggestedUser();

    $this->actingAs($user)->postJson(route('onboarding.suggestions.refresh'))->assertAccepted();

    Queue::assertPushed(PrefillOnboardingFromStag::class);
    expect($user->onboardingSuggestion()->first()->status)->toBe(SuggestionStatus::Pending);
});

it('answers 429 to a refresh once the weekly fetches are used', function () {
    Queue::fake();
    $user = suggestedUser();

    foreach (range(1, PrefillOnboardingFromStag::MAX_FETCHES) as $ignored) {
        $this->actingAs($user)->postJson(route('onboarding.suggestions.refresh'))->assertAccepted();
    }

    $this->actingAs($user)->postJson(route('onboarding.suggestions.refresh'))->assertStatus(429);
});

it('only ever shows the signed-in user their own suggestions', function () {
    suggestedUser();

    $this->actingAs(User::factory()->create())
        ->getJson(route('onboarding.suggestions.show'))
        ->assertJsonPath('status', 'none');
});

it('turns guests away', function () {
    $this->getJson(route('onboarding.suggestions.show'))->assertUnauthorized();
    $this->putJson(route('onboarding.suggestions.update'), ['answers' => ['faculty' => 'FAV']])->assertUnauthorized();
    $this->postJson(route('onboarding.suggestions.refresh'))->assertUnauthorized();
});
