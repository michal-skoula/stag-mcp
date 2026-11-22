<?php

namespace App\Mcp\Tools;

use App\Clients\StagHttpClient;
use App\Exceptions\StagException;
use App\Mcp\Concerns\PaginatesResponses;
use App\Models\User;
use App\Services\Programmes\ProgrammeCatalogue;
use App\Services\Teachers\TeacherSearch;
use Illuminate\Container\Attributes\CurrentUser;
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

#[Name('search-programy')]
#[Title('Search Programy')]
#[Description('Search the university\'s study programmes (studijní programy), e.g. "what can I study at FAV", "is there an English-taught master in informatics", "which programmes have Ekonomie in the name". Filters by name, faculty, level, form and teaching language; with no filters it lists every currently valid programme. Returns short rows: use get-program-info with a programme_id for what the programme is about, what it leads to, its specialisations and their study plans.')]
#[IsReadOnly]
class SearchProgramyTool extends Tool
{
    use PaginatesResponses;

    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fprogramy

    protected function paginationDefault(): int
    {
        return 50;
    }

    protected function paginationMax(): int
    {
        return 300;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Words to find in the programme name (Czech or English) or code. Every word must match; case, diacritics and word order are ignored.'),
            'faculty' => $schema->string()
                ->description('Faculty code, e.g. "FAV", "FEK", "FF". Case-insensitive.'),
            'level' => $schema->string()->enum(array_values(ProgrammeCatalogue::LEVELS))
                ->description('Degree level. "follow-up master" is the two-year navazující magistr after a bachelor; "master" is the long, undivided one.'),
            'form' => $schema->string()->enum(array_values(ProgrammeCatalogue::FORMS))
                ->description('How it is attended: full-time (prezenční), combined (kombinovaná) or distance.'),
            'language' => $schema->string()->enum(array_values(ProgrammeCatalogue::LANGUAGES))
                ->description('Teaching language.'),
            'include_certificates' => $schema->boolean()
                ->description('Include certificate programmes (kód 9999). Off by default: list-certifikaty covers them better.')
                ->default(false),
            'include_expired' => $schema->boolean()
                ->description('Include programmes that are no longer valid. Off by default.')
                ->default(false),
            ...$this->paginationInputSchema($schema, 'programmes'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $nullableString = fn () => $schema->anyOf([$schema->string()])->nullable();

        return [
            ...$this->paginationOutputSchema($schema, 'programmes'),
            'programmes' => $schema->array()->description('Best name match first, then by faculty and name.')
                ->items($schema->object([
                    'programme_id' => $schema->integer()->description('stprIdno. Pass to get-program-info.'),
                    'code' => $nullableString()->description('Accreditation code, e.g. "B0613A140036".'),
                    'name' => $nullableString()->description('Name in Czech.'),
                    'name_en' => $nullableString()->description('Name in English, when STAG has one.'),
                    'faculty' => $nullableString()->description('Faculty code.'),
                    'level' => $nullableString()->description('bachelor, follow-up master, master, doctoral, lifelong learning, rigorosum or other. STAG\'s own label when it is none of these.'),
                    'form' => $nullableString()->description('full-time, combined or distance.'),
                    'language' => $nullableString()->description('Teaching language: cs or en.'),
                    'degree' => $nullableString()->description('Title awarded, e.g. "Bc.", "Ing.", "Ph.D.". Null for non-degree study.'),
                    'standard_years' => $schema->anyOf([$schema->number()])->nullable()->description('Standard length in years.'),
                    'credits' => $schema->anyOf([$schema->integer()])->nullable()->description('Credits needed to graduate.'),
                    'valid_from' => $nullableString()->description('First academic year it ran, e.g. "2023".'),
                    'valid_until' => $nullableString()->description('Academic year from which it is no longer valid, or null when open-ended. A programme being phased out takes no new students.'),
                    'certificate' => $schema->boolean()->description('A certificate programme rather than a degree.'),
                ])),
        ];
    }

    /**
     * Handle the tool request. The catalogue answers anonymously.
     */
    public function handle(Request $request, #[CurrentUser('sanctum')] ?User $user = null): ResponseFactory|Response
    {
        if ($user === null) {
            return Response::error('No authenticated user. The MCP client must send a bearer token.');
        }

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:200'],
            'faculty' => ['nullable', 'string', 'max:10'],
            'level' => ['nullable', Rule::in(array_values(ProgrammeCatalogue::LEVELS))],
            'form' => ['nullable', Rule::in(array_values(ProgrammeCatalogue::FORMS))],
            'language' => ['nullable', Rule::in(array_values(ProgrammeCatalogue::LANGUAGES))],
            'include_certificates' => ['nullable', 'boolean'],
            'include_expired' => ['nullable', 'boolean'],
            ...$this->paginationRules(),
        ]);

        $faculty = isset($validated['faculty']) ? mb_strtoupper(trim($validated['faculty'])) : null;

        try {
            $programmes = (new ProgrammeCatalogue(new StagHttpClient($user)))
                ->programmes($faculty, (bool) ($validated['include_expired'] ?? false));
        } catch (StagException $e) {
            return Response::error($e->getMessage());
        }

        $programmes = array_filter($programmes, fn (array $programme) => ($validated['include_certificates'] ?? false) || ! $programme['certificate']);

        foreach (['level', 'form', 'language'] as $field) {
            if (isset($validated[$field])) {
                $programmes = array_filter($programmes, fn (array $programme) => $programme[$field] === $validated[$field]);
            }
        }

        $programmes = $this->match(array_values($programmes), $validated['name'] ?? null);

        return Response::structured($this->paginate($programmes, $validated, 'programmes'));
    }

    /**
     * Every query word must appear in the folded key. A name that is the
     * query exactly ranks first, a name starting with it second, so
     * "Informatika" puts Informatika above Aplikovaná informatika.
     *
     * @param  list<array<string, mixed>>  $programmes
     * @return list<array<string, mixed>>
     */
    private function match(array $programmes, ?string $query): array
    {
        $tokens = TeacherSearch::tokens((string) $query);
        $normalizedQuery = implode(' ', $tokens);
        $ranked = [];

        foreach ($programmes as $programme) {
            $key = ProgrammeCatalogue::searchKey($programme);

            foreach ($tokens as $token) {
                if (! str_contains($key, $token)) {
                    continue 2;
                }
            }

            $name = TeacherSearch::normalize((string) $programme['name']);
            $ranked[] = [
                'rank' => match (true) {
                    $tokens === [] => 2,
                    $name === $normalizedQuery => 0,
                    str_starts_with($name, $normalizedQuery) => 1,
                    default => 2,
                },
                'programme' => $programme,
            ];
        }

        usort($ranked, fn (array $a, array $b) => [$a['rank'], (string) $a['programme']['faculty'], TeacherSearch::normalize((string) $a['programme']['name'])]
            <=> [$b['rank'], (string) $b['programme']['faculty'], TeacherSearch::normalize((string) $b['programme']['name'])]);

        return array_column($ranked, 'programme');
    }
}
