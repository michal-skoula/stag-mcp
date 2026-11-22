<?php

namespace App\Mcp\Tools;

use App\Contracts\StagClient;
use App\Exceptions\StagException;
use App\Mcp\Concerns\PaginatesResponses;
use App\Mcp\Concerns\RequiresStagLogin;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search-subjects')]
#[Title('Search subjects')]
#[Description('Finds subjects (předměty) in the STAG catalogue by name, department or code, all as case-insensitive substrings, and returns each one\'s department, code, name, semesters taught and languages. Call it to identify a subject the user names loosely ("the architecture course at KIV") before asking for detail. Every filter is optional; with none it pages through the whole catalogue. year defaults to the current academic year. Pass a result\'s department and code to get-subject for credits, teachers and syllabus. Paged with count and offset.')]
#[IsReadOnly]
class SearchSubjectsTool extends Tool
{
    use PaginatesResponses;
    use RequiresStagLogin;

    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fpredmety

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Part of the subject name, case-insensitive.'),
            'department' => $schema->string()
                ->description('Part of the department abbreviation, case-insensitive, e.g. "KIV".'),
            'code' => $schema->string()
                ->description('Part of the subject code, case-insensitive, e.g. "UPA".'),
            'faculty' => $schema->string()
                ->description('Faculty code, exact, e.g. "FAV".'),
            /*
             * Accepts a number as well as a string: the year reads
             * as numeric, and clients send 2025 as often as "2025".
             */
            'year' => $schema->anyOf([$schema->string(), $schema->integer()])
                ->description('Academic year as its starting year: 2025 means 2025/2026. Defaults to the current academic year.'),
            'language' => $schema->string()
                ->description('Language for the names STAG translates, "cs" or "en". Subjects without a translation keep their Czech name.'),
            ...$this->paginationInputSchema($schema, 'subjects'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $nullableBool = fn () => $schema->anyOf([$schema->boolean()])->nullable();

        return [
            ...$this->paginationOutputSchema($schema, 'subjects'),
            'subjects' => $schema->array()
                ->description('Matching subjects. Only identifying and offering fields are included; call get-subject for full detail.')
                ->items($schema->object([
                    'department' => $schema->string()->description('Department abbreviation.'),
                    'code' => $schema->string()->description('Subject code within the department.'),
                    'year' => $schema->string()->description('Academic year, as its starting year.'),
                    'name' => $schema->string()->description('Subject name.'),
                    'has_teaching' => $nullableBool()->description('Whether the subject has teaching this year, or null.'),
                    'taught_in_winter' => $nullableBool()->description('Taught in the winter semester, or null.'),
                    'taught_in_summer' => $nullableBool()->description('Taught in the summer semester, or null.'),
                    'offered_to_incoming' => $nullableBool()->description('Offered to incoming exchange (ECTS) students, or null.'),
                    'languages' => $schema->array()->description('Languages of instruction, as STAG codes, e.g. "CZ", "AN".')->items($schema->string()),
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
            'name' => ['string', 'nullable'],
            'department' => ['string', 'nullable'],
            'code' => ['string', 'nullable'],
            'faculty' => ['string', 'nullable'],
            'year' => ['integer', 'min:1990', 'max:2100', 'nullable'],
            'language' => ['string', Rule::in(['cs', 'en']), 'nullable'],
            ...$this->paginationRules(),
        ], [
            'name' => 'name must be text to look for in the subject name.',
            'department' => 'department must be a department abbreviation or part of one, e.g. "KIV".',
            'code' => 'code must be a subject code or part of one, e.g. "UPA".',
            'faculty' => 'faculty must be a faculty code, e.g. "FAV".',
            'year' => 'year must be an academic year as its starting year, e.g. 2025 for 2025/2026.',
            'language' => 'language must be "cs" or "en".',
            ...$this->paginationMessages(),
        ]);

        $params = array_filter([
            'fakulta' => $validated['faculty'] ?? null,
            'rok' => isset($validated['year']) ? (string) $validated['year'] : null,
            'lang' => $validated['language'] ?? null,
        ], fn ($value) => $value !== null);

        // getPredmetyByFakulta has no exact-match filters of its own beyond
        // fakulta/rok/lang, so everything else the caller can search by (name,
        // department, code) is matched client-side below. Unfiltered this is
        // STAG's whole subject catalog (~10k rows, ~3MB, sub-second to fetch
        // and negligible to filter), so leaving every filter out just pages
        // through all of it rather than erroring like najdiPredmety used to.
        // todo: these results are easy to cache server-side with a long TTL. Implement.
        $result = $stag->get('predmety/getPredmetyByFakulta', $params);

        $rows = array_values(array_filter(
            $result['predmetKatedry'] ?? [],
            fn (array $row) => $this->matchesFilters($row, $validated),
        ));

        return Response::structured(
            $this->paginate($rows, $validated, 'subjects', $this->toSubject(...)),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{name?: string, department?: string, code?: string}  $filters
     */
    private function matchesFilters(array $row, array $filters): bool
    {
        if (isset($filters['name']) && ! $this->containsSubstring($row['nazev'] ?? null, $filters['name'])) {
            return false;
        }

        if (isset($filters['department']) && ! $this->containsSubstring($row['katedra'] ?? null, $filters['department'])) {
            return false;
        }

        if (isset($filters['code']) && ! $this->containsSubstring($row['zkratka'] ?? null, $filters['code'])) {
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
            'department' => $row['katedra'],
            'code' => $row['zkratka'],
            'year' => $row['rok'],
            'name' => $row['nazev'],
            'has_teaching' => $this->fromStagBool($row['maVyuku'] ?? null),
            'taught_in_winter' => $this->fromStagBool($row['vyukaZS'] ?? null),
            'taught_in_summer' => $this->fromStagBool($row['vyukaLS'] ?? null),
            'offered_to_incoming' => $this->fromStagBool($row['nabizetPrijezdyEcts'] ?? null),
            'languages' => array_values(array_filter([
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
