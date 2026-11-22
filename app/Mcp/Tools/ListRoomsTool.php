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
#[Title('List rooms')]
#[Description('University rooms (místnosti): lecture halls, classrooms, labs and offices, with type, capacity, floor, owning department, address and coordinates. Call it to find where a room is, how big it is, or which rooms a building or department has. Every filter is optional; building and room are exact codes, e.g. building "UC" and room "336" for UC336. Paged with count and offset.')]
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
            'building' => $schema->string()
                ->description('Building abbreviation, e.g. "UL" or "UC". Exact match.'),
            'room' => $schema->string()
                ->description('Room number within the building, e.g. "409". Exact match.'),
            'department' => $schema->string()
                ->description('Department abbreviation to filter by, e.g. "KIV". STAG matches the abbreviation only, so the full workplace name returns nothing.'),
            'room_type' => $schema->string()
                ->description('Room type to filter by.')
                ->enum(RoomType::class),
            'include_retired' => $schema->boolean()
                ->description('Also return rooms taken out of service, whose end-of-operation date has passed. Adds roughly 485 rooms university-wide.')
                ->default(false),
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
                    'building' => $schema->string()->description('Building abbreviation.'),
                    'room' => $schema->string()->description('Room number.'),
                    'type' => $schema->string()->description('Room type, in Czech as STAG names it, e.g. "Učebna".'),
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
            'building' => ['string', 'nullable'],
            'room' => ['string', 'nullable'],
            'department' => ['string', 'nullable'],
            'room_type' => ['string', 'nullable', new Enum(RoomType::class)],
            'include_retired' => ['boolean', 'nullable'],
            ...$this->paginationRules(),
        ], [
            'building' => 'building must be a building abbreviation, e.g. "UL".',
            'room' => 'room must be a room number as text, e.g. "409".',
            'department' => 'department must be a department abbreviation, e.g. "KIV".',
            'room_type' => 'room_type must be one of: '.implode(', ', array_column(RoomType::cases(), 'value')).'.',
            'include_retired' => 'include_retired must be true or false.',
            ...$this->paginationMessages(),
        ]);

        $roomType = isset($validated['room_type'])
            ? RoomType::from($validated['room_type'])->code()
            : null;

        $params = array_filter([
            'zkrBudovy' => $validated['building'] ?? null,
            'cisloMistnosti' => $validated['room'] ?? null,
            'pracoviste' => $validated['department'] ?? null,
            'typ' => $roomType,
        ], fn ($value) => $value !== null);

        $params['jenPlatne'] = $this->toStagBoolean(! ($validated['include_retired'] ?? false));

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
            'building' => $row['zkrBudovy'],
            'room' => $row['cisloMistnosti'],
            'type' => $row['typ'],
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
