<?php

namespace App\Services\Stag;

use Carbon\CarbonImmutable;

/**
 * A payload read through {@see ReferenceDataCache}, with the bookkeeping a
 * caller needs to tell the user how far to trust it.
 *
 * `outdated` means STAG was asked for a newer copy and failed, so `data` is the
 * last good answer. A merely old but still within-TTL entry is not outdated.
 *
 * @template TData of array
 */
final readonly class CachedStagData
{
    /**
     * @param  TData  $data
     */
    public function __construct(
        public array $data,
        public CarbonImmutable $retrievedAt,
        public bool $outdated = false,
    ) {}

    /**
     * Same freshness, different payload. For callers that filter or reshape
     * the cached rows before handing them on.
     *
     * @template TNew of array
     *
     * @param  TNew  $data
     * @return CachedStagData<TNew>
     */
    public function withData(array $data): self
    {
        return new self($data, $this->retrievedAt, $this->outdated);
    }

    /**
     * @return CachedStagData<TData>
     */
    public function asOutdated(): self
    {
        return new self($this->data, $this->retrievedAt, true);
    }
}
