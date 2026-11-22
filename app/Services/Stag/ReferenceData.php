<?php

namespace App\Services\Stag;

use App\Contracts\StagClient;
use App\Exceptions\StagException;
use App\Services\Grades\GradeScales;

/**
 * The STAG datasets that are identical for every user and change rarely, each
 * with its endpoint, cache key, TTL and failure rule in one place, cached
 * through {@see ReferenceDataCache}. Tools ask for the dataset and never
 * touch the cache.
 *
 * Checksum-based invalidation was considered and dropped. STAG offers no
 * cheap version or ETag to compare, so checking a checksum means downloading
 * the payload, which is the refresh. These payloads are 2-54 KB and answer in
 * about 30 ms; a TTL plus stale-on-failure covers it.
 *
 * `kalendar/getAktualniObdobiInfo` is deliberately not here. It answers "what
 * is the period right now", so a cached copy goes wrong the next day.
 */
final readonly class ReferenceData
{
    private const string KEY_PREFIX = 'stag_ref:';

    /** Buildings change roughly never. */
    public const int BUILDINGS_TTL_SECONDS = 2_592_000;

    /** A grading scale is a university-wide rule, changed by regulation, not by the semester. */
    public const int GRADING_SCALES_TTL_SECONDS = 2_592_000;

    /** The yearly calendar is corrected a few times a year at most, so a week of lag is harmless. */
    public const int CALENDAR_TTL_SECONDS = 604_800;

    public function __construct(
        private StagClient $stag,
        private ReferenceDataCache $cache = new ReferenceDataCache,
    ) {}

    /**
     * `mistnost/getBudovy`, anonymous. The list is never empty in practice
     * (80+ buildings), so an empty answer is a STAG fault and is not cached.
     *
     * @return CachedStagData<list<array<string, mixed>>>
     *
     * @throws StagException
     */
    public function buildings(): CachedStagData
    {
        return $this->cache->remember(
            self::KEY_PREFIX.'buildings',
            self::BUILDINGS_TTL_SECONDS,
            // `items` is a wrapper key containing the buildings array.
            fn () => ($this->stag->get('mistnost/getBudovy')['items'] ?? []) ?: throw StagException::emptyResponse('building list'),
        );
    }

    /**
     * `znamky/typyHodnoceni`, anonymous: the raw payload, for
     * {@see GradeScales::fromPayload()}. An empty answer
     * is a fault, since a record cannot be read without its scales.
     *
     * @return CachedStagData<array<array-key, mixed>>
     *
     * @throws StagException
     */
    public function gradingScales(): CachedStagData
    {
        return $this->cache->remember(
            self::KEY_PREFIX.'grading_scales',
            self::GRADING_SCALES_TTL_SECONDS,
            fn () => $this->stag->get('znamky/typyHodnoceni') ?: throw StagException::emptyResponse('grading scale list'),
        );
    }

    /**
     * `kalendar/getHarmonogramRoku` for one academic year (its starting year),
     * anonymous. The year is always sent explicitly, so "the current year"
     * never gets cached under a key that outlives the 1 September rollover.
     * An empty answer is returned but not kept: STAG answers `[]` for a year it
     * has not published yet, and that should not stick once it does.
     *
     * @return CachedStagData<list<array<string, mixed>>>
     *
     * @throws StagException
     */
    public function harmonogram(string $year): CachedStagData
    {
        return $this->cache->remember(
            self::KEY_PREFIX."harmonogram:{$year}",
            self::CALENDAR_TTL_SECONDS,
            fn () => $this->stag->get('kalendar/getHarmonogramRoku', ['rok' => $year])['harmonogramItem'] ?? [],
            storeEmpty: false,
        );
    }

    /**
     * `kalendar/getKalendarRoku`, one row per day of an academic year
     * (anonymous). Empty is returned but not kept, same as the harmonogram.
     *
     * @return CachedStagData<list<array<string, mixed>>>
     *
     * @throws StagException
     */
    public function calendar(string $year): CachedStagData
    {
        return $this->cache->remember(
            self::KEY_PREFIX."calendar:{$year}",
            self::CALENDAR_TTL_SECONDS,
            fn () => $this->stag->get('kalendar/getKalendarRoku', ['rok' => $year])['kalendarItem'] ?? [],
            storeEmpty: false,
        );
    }
}
