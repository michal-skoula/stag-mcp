<?php

namespace App\Mcp\Tools;

use App\Contracts\StagClient;
use App\Exceptions\StagException;
use App\Mcp\Concerns\DescribesExamTerms;
use App\Mcp\Concerns\PaginatesResponses;
use App\Mcp\Concerns\RequiresStagLogin;
use App\Mcp\Concerns\ResolvesStagIdentity;
use App\Services\ExamTerms\ExamTerm;
use App\Services\ExamTerms\ExamTermService;
use Carbon\CarbonImmutable;
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

#[Name('list-exam-dates')]
#[Title('List Exam Dates')]
#[Description('Exam and zápočet terms (termíny) with date, time, room, examiner, capacity, registration deadlines and the caller\'s own registration state. With no katedra, zkratka or teacher_id it lists the caller\'s own terms: what the student portal offers them for the current year, plus every term they are registered on (use rok for an earlier year). With any of those three it lists that subject\'s, department\'s or teacher\'s terms and marks which ones the caller is on. Room bookings STAG stores as terms are never returned. Past and cancelled terms are hidden unless asked for, except a cancelled term the caller is still registered on. This tool cannot register or unregister.')]
#[IsReadOnly]
class ListExamDatesTool extends Tool
{
    use DescribesExamTerms;
    use PaginatesResponses;
    use RequiresStagLogin;
    use ResolvesStagIdentity;

    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fterminy

    /** Department and subject codes: letters (Czech included), digits and hyphens, as in `KČJ` or `ZSW-E`. */
    private const string CODE_PATTERN = '/^[\pL\pN-]{1,20}$/u';

    protected function paginationDefault(): int
    {
        return 50;
    }

    protected function paginationMax(): int
    {
        return 200;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'katedra' => $schema->string()
                ->description('Department code, e.g. "KIV". Exact, case-insensitive. A faculty code (e.g. "FAV") matches nothing.'),
            'zkratka' => $schema->string()
                ->description('Subject code, e.g. "DB1". Exact, case-insensitive. Without katedra it matches that code in every department.'),
            'teacher_id' => $schema->integer()
                ->description('Examiner\'s ucitIdno, as returned by search-ucitele.'),
            'rok' => $schema->integer()
                ->description('Academic year as its starting year (2025 = 2025/2026). Defaults to the current one. Combine with include_past for an earlier year.'),
            'semestr' => $schema->string()
                ->enum(['ZS', 'LS'])
                ->description('Semester: ZS (winter) or LS (summer). Both when omitted.'),
            'include_past' => $schema->boolean()
                ->description('Include terms whose day is already over.')
                ->default(false),
            'include_cancelled' => $schema->boolean()
                ->description('Include cancelled terms. A cancelled term the caller is still registered on is always included.')
                ->default(false),
            'registered_only' => $schema->boolean()
                ->description('Only terms the caller is registered on.')
                ->default(false),
            'os_cislo' => $schema->string()
                ->description('Personal number (osCislo) to look up registrations for, for accounts holding more than one study. Resolved automatically when omitted. STAG refuses any osCislo that is not the caller\'s own.'),
            ...$this->paginationInputSchema($schema, 'terms'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'source' => $schema->string()->enum(['student', 'search'])
                ->description('"student" when listing the caller\'s own terms, "search" when filtered by katedra, zkratka or teacher_id.'),
            'os_cislo' => $schema->anyOf([$schema->string()])->nullable()->description('The osCislo registration state was read for, or null when the caller has no student role.'),
            ...$this->paginationOutputSchema($schema, 'terms'),
            'terms' => $schema->array()
                ->description('Matching terms, by date and start time ascending.')
                ->items($this->examTermSchema($schema)),
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
            'katedra' => ['string', 'regex:'.self::CODE_PATTERN, 'nullable'],
            'zkratka' => ['string', 'regex:'.self::CODE_PATTERN, 'nullable'],
            'teacher_id' => ['integer', 'min:1', 'nullable'],
            /* A wrong year or semester silently returns nothing upstream, so
             * both are rejected here rather than reported as "no terms". */
            'rok' => ['integer', 'min:1900', 'max:2200', 'nullable'],
            'semestr' => ['string', Rule::in(['ZS', 'LS']), 'nullable'],
            'include_past' => ['boolean', 'nullable'],
            'include_cancelled' => ['boolean', 'nullable'],
            'registered_only' => ['boolean', 'nullable'],
            'os_cislo' => ['string', 'nullable'],
            ...$this->paginationRules(),
        ], [
            'katedra.regex' => 'katedra must be a department code such as "KIV": letters, digits and hyphens only.',
            'zkratka.regex' => 'zkratka must be a subject code such as "DB1": letters, digits and hyphens only.',
        ]);

        $service = new ExamTermService($stag);
        $rok = isset($validated['rok']) ? (string) $validated['rok'] : null;
        $semestr = $validated['semestr'] ?? null;
        $isSearch = isset($validated['katedra']) || isset($validated['zkratka']) || isset($validated['teacher_id']);

        if ($isSearch) {
            $terms = $service->search($validated['katedra'] ?? null, $validated['zkratka'] ?? null, $validated['teacher_id'] ?? null, $rok, $semestr);
            [$osCislo, $terms] = $this->attachRegistrations($service, $stag, $terms, $validated['os_cislo'] ?? null, $rok);
        } else {
            $osCislo = $validated['os_cislo'] ?? $this->resolveOsCislo($stag);

            if ($osCislo === null) {
                return Response::error('This account has no student role, so it has no terms of its own. Pass katedra, zkratka or teacher_id to list terms instead.');
            }

            $terms = $service->forStudent($osCislo, $rok);

            if ($semestr !== null) {
                $terms = array_filter($terms, fn (ExamTerm $term) => $term->semestr() === $semestr);
            }
        }

        $now = CarbonImmutable::now(ExamTerm::TIMEZONE);
        $includePast = $validated['include_past'] ?? false;
        $includeCancelled = $validated['include_cancelled'] ?? false;
        $registeredOnly = $validated['registered_only'] ?? false;

        $terms = array_values(array_filter($terms, fn (ExamTerm $term) => ($includePast || ! $term->isPast($now))
            && ($includeCancelled || ! $term->isCancelled() || $term->isRegistered())
            && (! $registeredOnly || $term->isRegistered())));

        usort($terms, fn (ExamTerm $a, ExamTerm $b) => strcmp($a->sortKey(), $b->sortKey()));

        return Response::structured([
            'source' => $isSearch ? 'search' : 'student',
            'os_cislo' => $osCislo,
            ...$this->paginate($terms, $validated, 'terms', fn (ExamTerm $term) => $term->toArray($now)),
        ]);
    }

    /**
     * Registration state is a bonus on a search, so a caller without a student
     * role, or a refused lookup, still gets the terms, with registration null.
     *
     * @param  list<ExamTerm>  $terms
     * @return array{0: ?string, 1: list<ExamTerm>}
     */
    private function attachRegistrations(ExamTermService $service, StagClient $stag, array $terms, ?string $osCislo, ?string $rok): array
    {
        try {
            $osCislo ??= $this->resolveOsCislo($stag);

            if ($osCislo === null) {
                return [null, $terms];
            }

            return [$osCislo, $service->withRegistrations($terms, $osCislo, $rok)];
        } catch (StagException) {
            return [null, $terms];
        }
    }
}
