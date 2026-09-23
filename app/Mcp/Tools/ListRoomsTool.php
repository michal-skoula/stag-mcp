<?php

namespace App\Mcp\Tools;

use App\Contracts\StagClient;
use App\Exceptions\StagException;
use App\Mcp\Concerns\PaginatesResponses;
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

#[Name('list-rooms')]
#[Title('List Rooms')]
#[Description('List university rooms, offices, lecture halls etc. by building, department, or type. Results are paged; use offset to see more than the first count rows.')]
#[IsReadOnly]
class ListRoomsTool extends Tool
{
    use PaginatesResponses;
    use RequiresStagLogin;

    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fmistnost

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'building_shortcode' => $schema->string()
                ->description('Building abbreviation to filter by, e.g. "UL", "UC".'),
            'room_number' => $schema->string()
                ->description('Room number to filter by, e.g. "409".'),
            'department' => $schema->string()
                ->description('Department abbreviation to filter by, e.g. "KIV". STAG matches the abbreviation only, so the full workplace name returns nothing.'),
            'room_type' => $schema->string()
                ->description('Room type to filter by.')
                ->enum(RoomType::class),
            'only_valid' => $schema->boolean()
                ->description('Exclude rooms taken out of service, meaning their end-of-operation date has passed. Set false to include them, which adds roughly 485 rooms university-wide.')
                ->default(true),
            ...$this->paginationInputSchema($schema, 'rooms'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            ...$this->paginationOutputSchema($schema, 'rooms'),
            'rooms' => $schema->array()
                ->description('Matching rooms.')
                ->items($schema->object([
                    'building_shortcode' => $schema->string()->description('Building abbreviation.'),
                    'room_number' => $schema->string()->description('Room number.'),
                    'room_type' => $schema->string()->description('Room type.'),
                    'capacity' => $schema->integer()->description('Seating capacity.'),
                    'floor' => $schema->string()->description('Floor.'),
                    'workplace' => $schema->string()->description('Full name of the workplace the room belongs to.'),
                    'department' => $schema->string()->description('Department abbreviation, e.g. "KIV".'),
                    'address' => $schema->string()->description('Full building address.'),
                    'note' => $schema->anyOf([$schema->string()])->description('Free-text note, or null.')->nullable(),
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
            'building_shortcode' => ['string', 'nullable'],
            'room_number' => ['string', 'nullable'],
            'department' => ['string', 'nullable'],
            'room_type' => ['string', 'nullable', new Enum(RoomType::class)],
            'only_valid' => ['boolean', 'nullable'],
            ...$this->paginationRules(),
        ]);

        $roomType = isset($validated['room_type'])
            ? RoomType::from($validated['room_type'])->code()
            : null;

        $params = array_filter([
            'zkrBudovy' => $validated['building_shortcode'] ?? null,
            'cisloMistnosti' => $validated['room_number'] ?? null,
            'pracoviste' => $validated['department'] ?? null,
            'typ' => $roomType,
        ], fn ($value) => $value !== null);

        $params['jenPlatne'] = $this->toStagBoolean($validated['only_valid'] ?? true);

        $rooms = $stag->get('mistnost/getMistnostiInfo', $params);

        return Response::structured(
            $this->paginate($rooms['mistnostInfo'] ?? [], $validated, 'rooms', $this->toRoom(...)),
        );
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
            'building_shortcode' => $row['zkrBudovy'],
            'room_number' => $row['cisloMistnosti'],
            'room_type' => $row['typ'],
            'capacity' => $row['kapacita'],
            'floor' => $row['podlazi'],
            'workplace' => $row['pracoviste'],
            'department' => $row['katedra'],
            'address' => $row['adresaBudovy'],
            'note' => $row['poznamka'] ?? null,
            'coordinates' => [
                'latitude' => $row['budovaGPSY'] ?? null,
                'longitude' => $row['budovaGPSX'] ?? null,
            ],
        ];
    }
}
