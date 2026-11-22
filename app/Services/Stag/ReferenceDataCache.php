<?php

namespace App\Services\Stag;

use App\Exceptions\StagException;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cache for STAG data that is the same for every user and changes rarely:
 * the teacher directory, buildings, grading scales, the academic calendar.
 * Anything per-user stays out of here.
 *
 * Entries are written with `Cache::forever()` next to a `retrieved_at`
 * timestamp, and expiry is decided here rather than by the store. That is what
 * makes stale-on-failure possible: when an entry is past its TTL we ask STAG
 * again, and if STAG fails we hand back the old payload flagged `outdated`
 * instead of an error. The payload is the same or close to it, and an agent
 * can say so.
 *
 * Rules every entry follows:
 *
 * - A failed STAG call is never stored. The fetch closure throws, nothing is written.
 * - A failure never overwrites a good entry.
 * - After a failed refresh the entry is served as-is for {@see self::RETRY_COOLDOWN_SECONDS},
 *   so an outage does not make every request wait on a dead endpoint.
 * - With no entry at all, there is nothing to fall back to and the exception propagates.
 *
 * The fetch closure decides what counts as a failure: an answer that can never
 * legitimately be empty should throw {@see StagException::emptyResponse()}.
 */
final readonly class ReferenceDataCache
{
    /** How long to keep serving an outdated entry before asking STAG again. */
    public const int RETRY_COOLDOWN_SECONDS = 300;

    /**
     * The entry for `$key`, fetched anew when it is missing or older than `$ttlSeconds`.
     *
     * @param  Closure(): array<array-key, mixed>  $fetch  Throws {@see StagException} on failure.
     * @param  bool  $storeEmpty  False when an empty answer is a real "no data yet" that should
     *                            be returned but not kept, e.g. a year STAG has not published.
     * @return CachedStagData<array<array-key, mixed>>
     *
     * @throws StagException when STAG fails and there is no older entry to serve
     */
    public function remember(string $key, int $ttlSeconds, Closure $fetch, bool $storeEmpty = true): CachedStagData
    {
        $entry = $this->peek($key);

        if ($entry !== null && $entry->retrievedAt->addSeconds($ttlSeconds)->isFuture()) {
            return $entry;
        }

        if ($entry !== null && Cache::has($this->cooldownKey($key))) {
            return $entry->asOutdated();
        }

        try {
            return $this->refresh($key, $fetch, $storeEmpty);
        } catch (StagException $e) {
            if ($entry === null) {
                throw $e;
            }

            Cache::put($this->cooldownKey($key), true, self::RETRY_COOLDOWN_SECONDS);
            Log::warning("STAG refresh of [{$key}] failed, serving the snapshot from {$entry->retrievedAt->toIso8601String()}.", [
                'exception' => $e->getMessage(),
            ]);

            return $entry->asOutdated();
        }
    }

    /**
     * Fetches and stores regardless of age, without any fallback. For the
     * scheduled refresh, which wants to know a call failed rather than be
     * handed the old data back.
     *
     * @param  Closure(): array<array-key, mixed>  $fetch
     * @return CachedStagData<array<array-key, mixed>>
     *
     * @throws StagException
     */
    public function refresh(string $key, Closure $fetch, bool $storeEmpty = true): CachedStagData
    {
        $data = $fetch();
        $retrievedAt = CarbonImmutable::now();

        if ($data === [] && ! $storeEmpty) {
            return new CachedStagData($data, $retrievedAt);
        }

        Cache::forever($key, ['retrieved_at' => $retrievedAt->getTimestamp(), 'data' => $data]);
        Cache::forget($this->cooldownKey($key));

        return new CachedStagData($data, $retrievedAt);
    }

    /**
     * The stored entry as-is, expired or not, or null when there is none (or
     * it is in a shape this class did not write).
     *
     * @return CachedStagData<array<array-key, mixed>>|null
     */
    public function peek(string $key): ?CachedStagData
    {
        $raw = Cache::get($key);

        if (! is_array($raw) || ! is_int($raw['retrieved_at'] ?? null) || ! is_array($raw['data'] ?? null)) {
            return null;
        }

        return new CachedStagData($raw['data'], CarbonImmutable::createFromTimestamp($raw['retrieved_at']));
    }

    private function cooldownKey(string $key): string
    {
        return $key.':retry_after';
    }
}
