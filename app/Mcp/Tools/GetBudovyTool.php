<?php

namespace App\Mcp\Tools;

use App\Clients\StagClient;
use App\Exceptions\StagException;
use App\Mcp\Enums\Campus;
use App\Mcp\Enums\City;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-budovy')]
#[Title('List Budovy')]
#[Description('Lists buildings known to STAG, with address and GPS coordinates. Unfiltered this is 80+ buildings - pass city, campus, or address to narrow it down.')]
#[IsReadOnly]
class GetBudovyTool extends Tool
{
    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fmistnost

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        // schema() is invoked as a plain closure (see JsonSchemaTypeFactory::object()),
        // not through the container, so it cannot take a #[CurrentUser] parameter like
        // handle() does. The 'sanctum' guard mirrors what CurrentUser('sanctum') resolves.
        $city = auth('sanctum')->user()?->preferences?->city;

        $cityDescription = 'Filter by city. Known values: '.implode(', ', City::values())
            .'. Not validated — any string is matched as-is in case STAG adds a new city.';

        if ($city !== null) {
            $cityDescription .= " Defaults to '{$city}' from user preference.";
        }

        return [
            'city' => $schema->string()->description($cityDescription),
            'campus' => $schema->string()
                ->description('Filter by campus/location code. Accepts a raw code ('.implode(', ', Campus::values()).') or its label. Labels are placeholders until the codes are decoded, see the Campus enum. Not validated — any string is matched.'),
            'address' => $schema->string()
                ->description('Case-insensitive substring match against the street, house number, and city.'),
        ];
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
                ->description('Buildings matching the filters.')
                ->items($schema->object([
                    // todo: would be cool to have a "learning" mechanism, essentially adding something like
                    //       `mcp_name`, note the mcp_ prefix, which would be learned as the app is being used
                    //       and it could provide better data as time goes on. probably needs a separate tool tho.
                    //       purely an idea for now.
                    'code' => $schema->string()->description('Building abbreviation, e.g. "UL".'),
                    // FIXME: campus is Campus::label(), a placeholder for the raw code until the codes are decoded.
                    'campus' => $schema->string()->description('Campus/location label. Not yet decoded — currently the raw STAG code.'),
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
    public function handle(Request $request, #[CurrentUser('sanctum')] ?User $user = null): ResponseFactory|Response
    {
        if ($user === null) {
            return Response::error('No authenticated user. The MCP client must send a bearer token.');
        }

        $filters = $request->validate([
            'city' => ['string', 'nullable'],
            'campus' => ['string', 'nullable'],
            'address' => ['string', 'nullable'],
        ]);

        // An agent has no reason to know the user's city unless told, so an
        // explicit filter always wins; only fall back to the saved preference
        // when the caller left 'city' out entirely.
        if (! isset($filters['city']) && $user->preferences?->city !== null) {
            $filters['city'] = $user->preferences->city;
        }

        try {
            // TODO: this data changes very infrequently. Cache it with a
            //       long TTL rather than calling STAG on every request.
            $budovy = (new StagClient($user))->get('mistnost/getBudovy');
        } catch (StagException $e) {
            return Response::error($e->getMessage());
        }

        $items = array_values(array_filter(
            $budovy['items'] ?? [],
            fn (array $row) => $this->matchesFilters($row, $filters),
        ));

        return Response::structured([
            'count' => count($items),
            'buildings' => array_map($this->toBuilding(...), $items),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{city?: string, campus?: string, address?: string}  $filters
     */
    private function matchesFilters(array $row, array $filters): bool
    {
        if (isset($filters['city']) && ! $this->matchesCity($row['obec'] ?? null, $filters['city'])) {
            return false;
        }

        if (isset($filters['campus']) && ! $this->matchesCampus($row['lokalita'] ?? null, $filters['campus'])) {
            return false;
        }

        if (isset($filters['address']) && ! $this->matchesAddress($row, $filters['address'])) {
            return false;
        }

        return true;
    }

    private function matchesCity(?string $obec, string $filter): bool
    {
        return $obec !== null && mb_strtolower($obec) === mb_strtolower(trim($filter));
    }

    /**
     * Accepts either a raw STAG code or a Campus label, so once label() stops
     * being a passthrough (see the fix-me on Campus), old and new-style calls
     * both keep working.
     */
    private function matchesCampus(?string $lokalita, string $filter): bool
    {
        if ($lokalita === null) {
            return false;
        }

        $filter = trim($filter);
        $resolved = Campus::tryFrom(strtoupper($filter)) ?? Campus::fromLabel($filter);

        return $resolved !== null
            ? $lokalita === $resolved->value
            : strcasecmp($lokalita, $filter) === 0;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function matchesAddress(array $row, string $needle): bool
    {
        $haystack = implode(' ', array_filter([
            $row['ulice'] ?? null,
            $row['cisloUlice'] ?? null,
            $row['obec'] ?? null,
        ]));

        return str_contains(mb_strtolower($haystack), mb_strtolower(trim($needle)));
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
            'campus' => Campus::tryFrom($row['lokalita'])?->label() ?? $row['lokalita'],
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
