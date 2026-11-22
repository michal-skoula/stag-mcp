<?php

namespace App\Mcp\Concerns;

use App\Services\Stag\CachedStagData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * For tools answering from {@see CachedStagData}: tells the agent when the
 * data it got is the last good copy rather than a fresh one, and how old that
 * copy is, so it can say the answer may be out of date.
 */
trait ReportsDataFreshness
{
    /**
     * @return array<string, Type>
     */
    protected function freshnessOutputSchema(JsonSchema $schema): array
    {
        return [
            'using_outdated_data' => $schema->boolean()
                ->description('True when STAG could not be reached for a fresh copy and this answer comes from the last good one. It is usually still right, but say it may be out of date.'),
            'retrieved_at' => $schema->string()
                ->description('ISO 8601 time the data was last fetched from STAG. With several sources, the oldest.'),
        ];
    }

    /**
     * @param  CachedStagData<array<array-key, mixed>>  ...$sources
     * @return array{using_outdated_data: bool, retrieved_at: string}
     */
    protected function freshness(CachedStagData ...$sources): array
    {
        $oldest = collect($sources)->sortBy(fn (CachedStagData $source) => $source->retrievedAt->getTimestamp())->first();

        return [
            'using_outdated_data' => collect($sources)->contains(fn (CachedStagData $source) => $source->outdated),
            'retrieved_at' => $oldest->retrievedAt->toIso8601String(),
        ];
    }
}
