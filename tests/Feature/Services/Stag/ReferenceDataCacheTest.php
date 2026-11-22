<?php

use App\Exceptions\StagException;
use App\Services\Stag\ReferenceDataCache;
use Illuminate\Support\Facades\Cache;

const REFERENCE_TTL = 100;

function referenceCache(): ReferenceDataCache
{
    return new ReferenceDataCache;
}

it('fetches on a miss and stores the answer with its retrieval time', function () {
    $result = referenceCache()->remember('k', REFERENCE_TTL, fn () => ['a']);

    expect($result->data)->toBe(['a'])
        ->and($result->outdated)->toBeFalse()
        ->and(Cache::get('k'))->toBe(['retrieved_at' => now()->getTimestamp(), 'data' => ['a']]);
});

it('answers from the entry while it is inside the TTL', function () {
    referenceCache()->remember('k', REFERENCE_TTL, fn () => ['a']);
    $this->travel(REFERENCE_TTL - 1)->seconds();

    $result = referenceCache()->remember('k', REFERENCE_TTL, fn () => throw new LogicException('must not fetch'));

    expect($result->data)->toBe(['a'])->and($result->outdated)->toBeFalse();
});

it('keeps the entry beyond the TTL instead of letting the store drop it', function () {
    referenceCache()->remember('k', REFERENCE_TTL, fn () => ['a']);
    $this->travel(REFERENCE_TTL * 1000)->seconds();

    expect(referenceCache()->peek('k')?->data)->toBe(['a']);
});

it('replaces the entry when it expired and STAG answers', function () {
    referenceCache()->remember('k', REFERENCE_TTL, fn () => ['a']);
    $this->travel(REFERENCE_TTL + 1)->seconds();

    $result = referenceCache()->remember('k', REFERENCE_TTL, fn () => ['b']);

    expect($result->data)->toBe(['b'])->and($result->outdated)->toBeFalse();
});

it('serves the expired entry, flagged, when the fetch fails', function () {
    referenceCache()->remember('k', REFERENCE_TTL, fn () => ['a']);
    $storedAt = now();
    $this->travel(REFERENCE_TTL + 1)->seconds();

    $result = referenceCache()->remember('k', REFERENCE_TTL, fn () => throw StagException::unreachable());

    expect($result->data)->toBe(['a'])
        ->and($result->outdated)->toBeTrue()
        ->and($result->retrievedAt->getTimestamp())->toBe($storedAt->getTimestamp());
});

it('rethrows when the fetch fails and there is no entry, and stores nothing', function () {
    expect(fn () => referenceCache()->remember('k', REFERENCE_TTL, fn () => throw StagException::unreachable()))
        ->toThrow(StagException::class);

    expect(Cache::has('k'))->toBeFalse();
});

it('does not hide a bug that is not a STAG failure', function () {
    referenceCache()->remember('k', REFERENCE_TTL, fn () => ['a']);
    $this->travel(REFERENCE_TTL + 1)->seconds();

    expect(fn () => referenceCache()->remember('k', REFERENCE_TTL, fn () => throw new LogicException('bug')))
        ->toThrow(LogicException::class);
});

it('returns an empty answer without storing it when told not to', function () {
    $result = referenceCache()->remember('k', REFERENCE_TTL, fn () => [], storeEmpty: false);

    expect($result->data)->toBe([])->and(Cache::has('k'))->toBeFalse();
});

it('stores an empty answer by default', function () {
    referenceCache()->remember('k', REFERENCE_TTL, fn () => []);

    expect(referenceCache()->peek('k')?->data)->toBe([]);
});

it('refresh always fetches, and lets a failure through without touching the entry', function () {
    referenceCache()->remember('k', REFERENCE_TTL, fn () => ['a']);

    expect(referenceCache()->refresh('k', fn () => ['b'])->data)->toBe(['b']);
    expect(fn () => referenceCache()->refresh('k', fn () => throw StagException::unreachable()))->toThrow(StagException::class);
    expect(referenceCache()->peek('k')?->data)->toBe(['b']);
});

it('treats a value in another shape as no entry', function (mixed $value) {
    Cache::forever('k', $value);

    expect(referenceCache()->peek('k'))->toBeNull();
})->with([
    'plain list' => [[['id' => 1]]],
    'no timestamp' => [['data' => []]],
    'string timestamp' => [['retrieved_at' => 'yesterday', 'data' => []]],
    'scalar' => ['x'],
]);
