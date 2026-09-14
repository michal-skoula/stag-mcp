<?php

namespace App\Mcp\Tools;

use App\Contracts\StagClient;
use App\Exceptions\StagException;
use App\Mcp\Concerns\RequiresStagLogin;
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

#[Name('search-predmety')]
#[Title('Search Predmety')]
#[Description('Searches STAG subjects by name, department, or abbreviation — all substring matches. Leave every filter out to page through the whole catalog. Results are paged; use offset to see more than the first count rows. Use get-predmet-info afterward for full detail on a specific subject.')]
#[IsReadOnly]
class SearchPredmetyTool extends Tool
{
    use RequiresStagLogin;

    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fpredmety

    /** How many subjects to return when count is not given. */
    private const int DEFAULT_COUNT = 100;

    /** Upper bound on count, so one call cannot return the whole catalog at once. */
    private const int MAX_COUNT = 500;

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'nazev' => $schema->string()
                ->description('Case-insensitive substring match against the subject name.'),
            'katedra' => $schema->string()
                ->description('Case-insensitive substring match against the department abbreviation, e.g. "KIV".'),
            'zkratka' => $schema->string()
                ->description('Case-insensitive substring match against the subject abbreviation, e.g. "UPA".'),
            'fakulta' => $schema->string()
                ->description('Faculty code to narrow the search to, e.g. "FAV". Sent to STAG directly, unlike the other filters.'),
            'rok' => $schema->string()
                ->description('Academic year to filter by, e.g. "2026". Defaults to the current STAG year when omitted.'),
            'lang' => $schema->string()
                ->description('Language code for translated fields, e.g. "en". Not validated — passed through as-is.'),
            'count' => $schema->integer()
                ->description('How many subjects to return, up to '.self::MAX_COUNT.'.')
                ->min(1)
                ->max(self::MAX_COUNT)
                ->default(self::DEFAULT_COUNT),
            'offset' => $schema->integer()
                ->description('How many matching subjects to skip. Use with total from the previous response to page through the rest.')
                ->min(0)
                ->default(0),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $nullableBool = fn () => $schema->anyOf([$schema->boolean()])->nullable();

        return [
            'total' => $schema->integer()
                ->description('Total subjects matching the filters, before paging.'),
            'offset' => $schema->integer()
                ->description('The offset that was applied.'),
            'count' => $schema->integer()
                ->description('How many subjects are in this page.'),
            'subjects' => $schema->array()
                ->description('Matching subjects. Only identifying and offering fields are included — call get-predmet-info for full detail.')
                ->items($schema->object([
                    'katedra' => $schema->string()->description('Department abbreviation.'),
                    'zkratka' => $schema->string()->description('Subject abbreviation.'),
                    'rok' => $schema->string()->description('Academic year.'),
                    'nazev' => $schema->string()->description('Subject name.'),
                    'ma_vyuku' => $nullableBool()->description('Currently has teaching, or null.'),
                    'vyuka_zs' => $nullableBool()->description('Taught in winter semester, or null.'),
                    'vyuka_ls' => $nullableBool()->description('Taught in summer semester, or null.'),
                    'nabizi_prijezdy_ects' => $nullableBool()->description('Offered to incoming ECTS students, or null.'),
                    'jazyky' => $schema->array()->description('Languages of instruction.')->items($schema->string()),
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
            'nazev' => ['string', 'nullable'],
            'katedra' => ['string', 'nullable'],
            'zkratka' => ['string', 'nullable'],
            'fakulta' => ['string', 'nullable'],
            'rok' => ['string', 'nullable'],
            'lang' => ['string', 'nullable'],
            'count' => ['integer', 'min:1', 'max:'.self::MAX_COUNT, 'nullable'],
            'offset' => ['integer', 'min:0', 'nullable'],
        ]);

        $params = array_filter([
            'fakulta' => $validated['fakulta'] ?? null,
            'rok' => $validated['rok'] ?? null,
            'lang' => $validated['lang'] ?? null,
        ], fn ($value) => $value !== null);

        // getPredmetyByFakulta has no exact-match filters of its own beyond
        // fakulta/rok/lang — everything the caller can search by (nazev,
        // katedra, zkratka) is matched client-side below. Unfiltered this is
        // STAG's whole subject catalog (~10k rows, ~3MB, sub-second to fetch
        // and negligible to filter), so leaving every filter out just pages
        // through all of it rather than erroring like najdiPredmety used to.
        // todo: these results are easy to cache server-side with a long TTL. Implement.
        $result = $stag->get('predmety/getPredmetyByFakulta', $params);

        $rows = array_values(array_filter(
            $result['predmetKatedry'] ?? [],
            fn (array $row) => $this->matchesFilters($row, $validated),
        ));

        $offset = $validated['offset'] ?? 0;
        $count = $validated['count'] ?? self::DEFAULT_COUNT;
        $page = array_slice($rows, $offset, $count);

        return Response::structured([
            'total' => count($rows),
            'offset' => $offset,
            'count' => count($page),
            'subjects' => array_map($this->toSubject(...), $page),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{nazev?: string, katedra?: string, zkratka?: string}  $filters
     */
    private function matchesFilters(array $row, array $filters): bool
    {
        if (isset($filters['nazev']) && ! $this->containsSubstring($row['nazev'] ?? null, $filters['nazev'])) {
            return false;
        }

        if (isset($filters['katedra']) && ! $this->containsSubstring($row['katedra'] ?? null, $filters['katedra'])) {
            return false;
        }

        if (isset($filters['zkratka']) && ! $this->containsSubstring($row['zkratka'] ?? null, $filters['zkratka'])) {
            return false;
        }

        return true;
    }

    private function containsSubstring(?string $haystack, string $needle): bool
    {
        return $haystack !== null && str_contains(mb_strtolower($haystack), mb_strtolower(trim($needle)));
    }

    /**
     * semestr, pocetStudentu, and the aMax/bMax/cMax/aSkut/bSkut/cSkut
     * capacity fields are always null on this endpoint — dropped rather than
     * surfaced as dead weight. jazyk1..4 are sparse, so only the populated
     * ones are kept.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function toSubject(array $row): array
    {
        return [
            'katedra' => $row['katedra'],
            'zkratka' => $row['zkratka'],
            'rok' => $row['rok'],
            'nazev' => $row['nazev'],
            'ma_vyuku' => $this->fromStagBool($row['maVyuku'] ?? null),
            'vyuka_zs' => $this->fromStagBool($row['vyukaZS'] ?? null),
            'vyuka_ls' => $this->fromStagBool($row['vyukaLS'] ?? null),
            'nabizi_prijezdy_ects' => $this->fromStagBool($row['nabizetPrijezdyEcts'] ?? null),
            'jazyky' => array_values(array_filter([
                $row['jazyk1'] ?? null,
                $row['jazyk2'] ?? null,
                $row['jazyk3'] ?? null,
                $row['jazyk4'] ?? null,
            ])),
        ];
    }

    private function fromStagBool(?string $value): ?bool
    {
        return match ($value !== null ? mb_strtoupper($value) : null) {
            'A', 'ANO' => true,
            'N', 'NE' => false,
            default => null,
        };
    }
}
