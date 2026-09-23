<?php

namespace App\Mcp\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * count/offset paging over a list the tool has already fetched and filtered.
 * The noun passed to each method names what is being paged ("rooms", "days")
 * in the schema descriptions the model reads.
 */
trait PaginatesResponses
{
    /**
     * How many rows to return when count is not given. Override in the tool to change it.
     */
    protected function paginationDefault(): int
    {
        return 100;
    }

    /**
     * Upper bound on count, so one call cannot return STAG's entire unfiltered table.
     * Override in the tool to change it.
     */
    protected function paginationMax(): int
    {
        return 500;
    }

    /**
     * @return array<string, Type>
     */
    protected function paginationInputSchema(JsonSchema $schema, string $noun): array
    {
        return [
            'count' => $schema->integer()
                ->description("How many {$noun} to return, up to ".$this->paginationMax().'.')
                ->min(1)
                ->max($this->paginationMax())
                ->default($this->paginationDefault()),
            'offset' => $schema->integer()
                ->description("How many matching {$noun} to skip. Use with total from the previous response to page through the rest.")
                ->min(0)
                ->default(0),
        ];
    }

    /**
     * @return array<string, Type>
     */
    protected function paginationOutputSchema(JsonSchema $schema, string $noun): array
    {
        return [
            'total' => $schema->integer()
                ->description("Total {$noun} matching the filters, before paging."),
            'offset' => $schema->integer()
                ->description('The offset that was applied.'),
            'count' => $schema->integer()
                ->description("How many {$noun} are in this page."),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    protected function paginationRules(): array
    {
        return [
            'count' => ['integer', 'min:1', 'max:'.$this->paginationMax(), 'nullable'],
            'offset' => ['integer', 'min:0', 'nullable'],
        ];
    }

    /**
     * Slice $rows by the validated count/offset, mapping only the rows kept.
     *
     * @param  list<mixed>  $rows
     * @param  array{count?: int|null, offset?: int|null}  $validated
     * @return array<string, mixed>
     */
    protected function paginate(array $rows, array $validated, string $key, ?callable $map = null): array
    {
        $offset = $validated['offset'] ?? 0;
        $page = array_slice($rows, $offset, $validated['count'] ?? $this->paginationDefault());

        return [
            'total' => count($rows),
            'offset' => $offset,
            'count' => count($page),
            $key => $map === null ? $page : array_map($map, $page),
        ];
    }
}
