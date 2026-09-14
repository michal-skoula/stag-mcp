<?php

namespace App\Mcp\Tools;

use App\Contracts\StagClient;
use App\Exceptions\StagException;
use App\Mcp\Concerns\RequiresStagLogin;
use App\Mcp\Enums\RoomType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rules\Enum;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search-mistnosti')]
#[Title('Search Mistnosti')]
#[Description('Searches STAG rooms by building, workplace, or type. Results are paged; use offset to see more than the first count rows.')]
#[IsReadOnly]
class GetMistnostiTool extends Tool
{
    use RequiresStagLogin;

    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fmistnost

    /** How many rooms to return when count is not given. */
    private const int DEFAULT_COUNT = 100;

    /** Upper bound on count, so one call cannot return STAG's entire unfiltered table. */
    private const int MAX_COUNT = 500;

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'zkr_budovy' => $schema->string()
                ->description('Building abbreviation to filter by, e.g. "UL".'),
            'cislo_mistnosti' => $schema->string()
                ->description('Room number to filter by, e.g. "409".'),
            'pracoviste' => $schema->string()
                ->description('Workplace/department name to filter by.'),
            'typ' => $schema->string()
                ->description('Room type to filter by.')
                ->enum(RoomType::class),
            'jen_platne' => $schema->boolean()
                ->description('Only show currently valid rooms.')
                ->default(true),
            'count' => $schema->integer()
                ->description('How many rooms to return, up to '.self::MAX_COUNT.'.')
                ->min(1)
                ->max(self::MAX_COUNT)
                ->default(self::DEFAULT_COUNT),
            'offset' => $schema->integer()
                ->description('How many matching rooms to skip. Use with total from the previous response to page through the rest.')
                ->min(0)
                ->default(0),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'total' => $schema->integer()
                ->description('Total rooms matching the filters, before paging.'),
            'offset' => $schema->integer()
                ->description('The offset that was applied.'),
            'count' => $schema->integer()
                ->description('How many rooms are in this page.'),
            'rooms' => $schema->array()
                ->description('Matching rooms.')
                ->items($schema->object([
                    'zkr_budovy' => $schema->string()->description('Building abbreviation.'),
                    'cislo_mistnosti' => $schema->string()->description('Room number.'),
                    'typ' => $schema->string()->description('Room type.'),
                    'kapacita' => $schema->integer()->description('Seating capacity.'),
                    'podlazi' => $schema->string()->description('Floor.'),
                    'pracoviste' => $schema->string()->description('Workplace/department the room belongs to.'),
                    'katedra' => $schema->string()->description('Department abbreviation.'),
                    'address' => $schema->string()->description('Full building address.'),
                    'poznamka' => $schema->anyOf([$schema->string()])->description('Free-text note, or null.')->nullable(),
                    'coordinates' => $schema->object([
                        'latitude' => $schema->anyOf([$schema->number()])->description('Building latitude, or null.')->nullable(),
                        'longitude' => $schema->anyOf([$schema->number()])->description('Building longitude, or null.')->nullable(),
                    ]),
                ])),
        ];
    }

    /**
     * Handle the tool request.
     *
     * @throws StagException
     */
    protected function handleForStagUser(Request $request, StagClient $stag): ResponseFactory|Response
    {
        $validated = $request->validate([
            'zkr_budovy' => ['string', 'nullable'],
            'cislo_mistnosti' => ['string', 'nullable'],
            'pracoviste' => ['string', 'nullable'],
            'typ' => ['string', 'nullable', new Enum(RoomType::class)],
            'jen_platne' => ['boolean', 'nullable'],
            'count' => ['integer', 'min:1', 'max:'.self::MAX_COUNT, 'nullable'],
            'offset' => ['integer', 'min:0', 'nullable'],
        ]);

        $params = array_filter([
            'zkrBudovy' => $validated['zkr_budovy'] ?? null,
            'cisloMistnosti' => $validated['cislo_mistnosti'] ?? null,
            'pracoviste' => $validated['pracoviste'] ?? null,
            'typ' => $validated['typ'] ?? null,
        ], fn ($value) => $value !== null);

        $params['jenPlatne'] = $this->toStagBoolean($validated['jen_platne'] ?? true);

        $rooms = $stag->get('mistnost/getMistnostiInfo', $params);

        $rows = $rooms['mistnostInfo'] ?? [];
        $offset = $validated['offset'] ?? 0;
        $count = $validated['count'] ?? self::DEFAULT_COUNT;
        $page = array_slice($rows, $offset, $count);

        return Response::structured([
            'total' => count($rows),
            'offset' => $offset,
            'count' => count($page),
            'rooms' => array_map($this->toRoom(...), $page),
        ]);
    }

    private function toStagBoolean(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function toRoom(array $row): array
    {
        return [
            'zkr_budovy' => $row['zkrBudovy'],
            'cislo_mistnosti' => $row['cisloMistnosti'],
            'typ' => $row['typ'],
            'kapacita' => $row['kapacita'],
            'podlazi' => $row['podlazi'],
            'pracoviste' => $row['pracoviste'],
            'katedra' => $row['katedra'],
            'address' => $row['adresaBudovy'],
            'poznamka' => $row['poznamka'] ?? null,
            'coordinates' => [
                'latitude' => $row['budovaGPSY'] ?? null,
                'longitude' => $row['budovaGPSX'] ?? null,
            ],
        ];
    }
}
