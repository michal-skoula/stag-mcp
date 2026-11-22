<?php

use App\Exceptions\StagException;
use App\Jobs\PrefillOnboardingFromStag;
use App\Models\User;
use App\Services\Onboarding\SuggestionStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\OnboardingFixtures as Stag;

it('stores what STAG suggested and marks the record ready', function () {
    Stag::fakeStudent();
    $user = User::factory()->withStagToken()->create();

    (new PrefillOnboardingFromStag($user))->handle();

    $record = $user->onboardingSuggestion;

    expect($record->status)->toBe(SuggestionStatus::Ready)
        ->and($record->fetched_at)->not->toBeNull()
        ->and($record->suggestions['programme']['value'])->toBe('Softwarové inženýrství')
        ->and($record->suggestions['city'])->toBe(['value' => 'Plzeň', 'guessed' => true]);
});

it('never turns a suggestion into a saved preference', function () {
    Stag::fakeStudent();
    $user = User::factory()->withStagToken()->create();

    (new PrefillOnboardingFromStag($user))->handle();

    expect($user->preferences)->toBeNull();
});

it('keeps what the user confirmed when it fetches again', function () {
    Stag::fakeStudent();
    $user = User::factory()->withStagToken()->create();
    $user->onboardingSuggestion()->create([
        'status' => SuggestionStatus::Ready,
        'suggestions' => ['faculty' => ['value' => 'OLD', 'guessed' => false]],
        'confirmed' => ['faculty' => 'FEL'],
    ]);

    (new PrefillOnboardingFromStag($user))->handle();

    $record = $user->onboardingSuggestion()->first();

    expect($record->suggestions['faculty']['value'])->toBe('FAV')
        ->and($record->confirmed)->toBe(['faculty' => 'FEL']);
});

it('records a failure without calling STAG when the ticket has expired', function () {
    Http::fake();
    $user = User::factory()->withExpiredStagToken()->create();

    (new PrefillOnboardingFromStag($user))->handle();

    expect($user->onboardingSuggestion->status)->toBe(SuggestionStatus::Failed)
        ->and($user->onboardingSuggestion->failure)->toContain('expired');
    Http::assertNothingSent();
});

it('records a rejected ticket instead of retrying it', function () {
    Http::fake([Stag::USER_LIST => Http::response('', 401)]);
    $user = User::factory()->withStagToken()->create();

    (new PrefillOnboardingFromStag($user))->handle();

    expect($user->onboardingSuggestion->status)->toBe(SuggestionStatus::Failed)
        ->and($user->onboardingSuggestion->failure)->toContain('expired or been revoked');
});

it('lets the queue retry when STAG cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));
    $user = User::factory()->withStagToken()->create();

    (new PrefillOnboardingFromStag($user))->handle();
})->throws(StagException::class, 'Could not reach');

it('records the failure and keeps the old suggestions once the queue gives up', function () {
    $user = User::factory()->withStagToken()->create();
    $user->onboardingSuggestion()->create([
        'status' => SuggestionStatus::Pending,
        'suggestions' => ['faculty' => ['value' => 'FAV', 'guessed' => false]],
    ]);

    (new PrefillOnboardingFromStag($user))->failed(new RuntimeException('gave up'));

    $record = $user->onboardingSuggestion()->first();

    expect($record->status)->toBe(SuggestionStatus::Failed)
        ->and($record->failure)->toBe('gave up')
        ->and($record->suggestions['faculty']['value'])->toBe('FAV');
});

it('queues one job and marks the record pending', function () {
    Queue::fake();
    $user = User::factory()->withStagToken()->create();

    PrefillOnboardingFromStag::queueFor($user);

    Queue::assertPushed(PrefillOnboardingFromStag::class, fn ($job) => $job->user->is($user));
    expect($user->onboardingSuggestion->status)->toBe(SuggestionStatus::Pending);
});

it('allows three fetches in a week and refuses the fourth', function () {
    Queue::fake();
    $user = User::factory()->withStagToken()->create();

    $results = array_map(fn () => PrefillOnboardingFromStag::queueFor($user), range(1, 4));

    expect($results)->toBe([true, true, true, false]);
});

it('allows fetches again once the week has passed', function () {
    Queue::fake();
    $user = User::factory()->withStagToken()->create();

    array_map(fn () => PrefillOnboardingFromStag::queueFor($user), range(1, 3));
    $this->travel(7)->days();

    expect(PrefillOnboardingFromStag::queueFor($user))->toBeTrue();
});

it('counts each user on their own', function () {
    Queue::fake();
    [$first, $second] = User::factory()->withStagToken()->count(2)->create();

    array_map(fn () => PrefillOnboardingFromStag::queueFor($first), range(1, 3));

    expect(PrefillOnboardingFromStag::queueFor($second))->toBeTrue();
});

it('leaves the record alone when the limit is hit', function () {
    Queue::fake();
    $user = User::factory()->withStagToken()->create();
    array_map(fn () => PrefillOnboardingFromStag::queueFor($user), range(1, 3));
    $user->onboardingSuggestion()->update(['status' => SuggestionStatus::Ready]);

    PrefillOnboardingFromStag::queueFor($user);

    expect($user->onboardingSuggestion()->first()->status)->toBe(SuggestionStatus::Ready);
});

it('is unique per user', function () {
    $user = User::factory()->create();

    expect((new PrefillOnboardingFromStag($user))->uniqueId())->toBe((string) $user->id);
});
