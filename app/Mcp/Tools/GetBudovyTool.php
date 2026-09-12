<?php

namespace App\Mcp\Tools;

use App\Clients\StagClient;
use App\Exceptions\StagException;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-budovy')]
#[Title('List Budovy')]
#[Description('Lists every building known to STAG, with address and GPS coordinates.')]
#[IsReadOnly]
class GetBudovyTool extends Tool
{
    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fmistnost

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'count' => $schema->integer()
                ->description('How many buildings were returned.'),
            'buildings' => $schema->array()
                ->description('Every building STAG knows about.')
                ->items($schema->object([
                    // todo: would be cool to have a "learning" mechanism, essentially adding something like
                    //       `mcp_name`, note the mcp_ prefix, which would be learned as the app is being used
                    //       and it could provide better data as time goes on. probably needs a separate tool tho.
                    //       purely an idea for now.
                    'code' => $schema->string()->description('Building abbreviation, e.g. "UL".'),
                    // todo: decode meaning of location code
                    'campus' => $schema->string()->description('STAG campus/location code. Meaning is not yet decoded.'),
                    'map_url' => $schema->anyOf([$schema->string()])->description('Directions link, or null.')->nullable(),
                    'address' => $schema->object([
                        'city' => $schema->anyOf([$schema->string()])->description('City, or null.')->nullable(),
                        'street' => $schema->anyOf([$schema->string()])->description('Street name, or null.')->nullable(),
                        'house_number' => $schema->anyOf([$schema->string()])->description('Street/house number, or null.')->nullable(),
                    ]),
                    'coordinates' => $schema->object([
                        'latitude' => $schema->anyOf([$schema->number()])->description('Building latitude, or null.')->nullable(),
                        'longitude' => $schema->anyOf([$schema->number()])->description('Building longitude, or null.')->nullable(),
                    ]),
                ])),
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(#[CurrentUser('sanctum')] ?User $user = null): ResponseFactory|Response
    {
        if ($user === null) {
            return Response::error('No authenticated user. The MCP client must send a bearer token.');
        }

        try {
            // TODO: this data changes very infrequently. Cache it with a
            //       long TTL rather than calling STAG on every request.
            $budovy = (new StagClient($user))->get('mistnost/getBudovy');
        } catch (StagException $e) {
            return Response::error($e->getMessage());
        }

        $items = $budovy['items'] ?? [];

        return Response::structured([
            'count' => count($items),
            'buildings' => array_map($this->toBuilding(...), $items),
        ]);
    }

    /**
     * STAG mixes X/Y axes across its two coordinate pairs: gpsBudovaX/Y is
     * longitude/latitude, but gpsAdresniMistoX/Y is the reverse and redundant with it.
     * Only gpsBudova* is surfaced here, mapped explicitly rather than by position.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function toBuilding(array $row): array
    {
        return [
            'code' => $row['zkrBudovy'],
            'campus' => $row['lokalita'],
            'map_url' => $row['url'] ?? null,
            'address' => [
                'city' => $row['obec'] ?? null,
                'street' => $row['ulice'] ?? null,
                'house_number' => $row['cisloUlice'] ?? null,
            ],
            'coordinates' => [
                'latitude' => $row['gpsBudovaY'] ?? null,
                'longitude' => $row['gpsBudovaX'] ?? null,
            ],
        ];
    }
}
