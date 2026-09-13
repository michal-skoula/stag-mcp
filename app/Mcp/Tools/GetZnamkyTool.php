<?php

namespace App\Mcp\Tools;

use App\Clients\StagClient;
use App\Exceptions\StagException;
use App\Mcp\Concerns\RequiresStagLogin;
use App\Mcp\Concerns\ResolvesStagIdentity;
use App\Services\Grades\StudyRecordService;
use App\Services\Grades\Subject;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-znamky')]
#[Title('Get Znamky')]
#[Description("The caller's grades and study progress: per subject the exam and zápočet outcome, attempt count, date, examiner and credits, plus a summary with credits earned and the weighted average. Defaults to the current semester, so it answers \"what have I done and what is still open\" without arguments; pass all_years for the whole degree. Subject status comes from STAG's own completion code, not from the grade, because a zápočet-only subject has no numeric grade and an unfinished one is sometimes recorded as a 4.")]
#[IsReadOnly]
class GetZnamkyTool extends Tool
{
    use RequiresStagLogin;
    use ResolvesStagIdentity;

    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fznamky
    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fstudent

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            /*
             * Accepts a number as well as a string: the year reads
             * as numeric, and clients send 2025 as often as "2025".
             */
            'rok' => $schema->anyOf([$schema->string(), $schema->integer()])
                ->description('Academic year to show, given as its starting year: 2025 means 2025/2026. Defaults to the current academic year. Ignored when all_years is true.'),
            /*
             * Accepts "" as an alias for "%": an agent that leaves the field
             * blank rather than typing the wildcard should still get both.
             */
            // todo: replace enum with winter, summer, both, default to both, remove empty state since now we have a default.
            'semestr' => $schema->string()->enum(['ZS', 'LS', '%', ''])
                ->description('Semester to show: "ZS" (winter), "LS" (summer), "%" or "" for both. Defaults to the semester the current date falls in. Ignored when all_years is true.'),
            'all_years' => $schema->boolean()
                ->description('Return the entire study history instead of one semester, ignoring rok and semestr. Use for questions about the whole degree.')
                ->default(false),
            'katedra' => $schema->string()
                ->description('Only subjects whose department contains this, case-insensitive, e.g. "KIV".'),
            'zkratka' => $schema->string()
                ->description('Only subjects whose code contains this, case-insensitive, e.g. "MA1".'),
            'os_cislo' => $schema->string()
                ->description('Personal number (osCislo) to fetch grades for, for accounts holding more than one STAG role. Resolved automatically when omitted; call who-am-i to see the roles an account holds.'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $nullableString = fn () => $schema->anyOf([$schema->string()])->nullable();
        $nullableInt = fn () => $schema->anyOf([$schema->integer()])->nullable();
        $nullableNumber = fn () => $schema->anyOf([$schema->number()])->nullable();
        $nullableBool = fn () => $schema->anyOf([$schema->boolean()])->nullable();

        $average = fn (string $description) => $schema->object([
            'value' => $nullableNumber()->description('The weighted average, or null when no subject qualifies.'),
            'credits' => $schema->integer()->description('Credits the average was computed over. Not the same as credits_earned.'),
            'subjects' => $schema->integer()->description('How many subjects went into it.'),
            'basis' => $schema->string()->description('One sentence naming the rule used, so the number can be quoted accurately.'),
        ])->description($description);

        /*
         * Whole assessment blocks come back null: a subject with no zápočet
         * requirement has no zppzk_* data at all, so the object has to be
         * nullable rather than merely have nullable members.
         */
        $assessment = fn (string $description) => $schema->anyOf([$schema->object([
            'grade' => $nullableString()->description('Grade as STAG records it: "1".."4" on the numeric scale, "S"/"N" on the pass/fail one. Null when not graded yet.'),
            'grade_name' => $nullableString()->description('What the grade means, e.g. "Výborně" or "Splněno". Null when not graded yet.'),
            'is_pass' => $nullableBool()->description('Whether this grade is a pass, from STAG\'s codebook rather than inferred. Null when not graded yet.'),
            'scale' => $nullableString()->description('The grading scale in use: "1|2|3|4" for an exam, "S|N" for zápočet-only.'),
            'points' => $nullableString()->description('Points awarded, e.g. "98.00". Frequently null even for a graded subject.'),
            'attempt' => $nullableInt()->description('Which attempt this outcome is from. 0 when nothing has been attempted.'),
            'date' => $nullableString()->description('ISO date of the outcome, or null.'),
            'teacher' => $nullableString()->description('Who recorded it, "Surname Forename" as STAG stores it. Null when not graded yet.'),
            'teacher_id' => $nullableInt()->description('ucitIdno of that teacher, for cross-referencing the ucitel namespace.'),
            // todo: this is pointless; remove
            'language' => $nullableString()->description('Language the assessment was taken in, e.g. "CZ". Null when not applicable.'),
        ])])->nullable()->description($description);

        return [
            'os_cislo' => $schema->string()->description('The osCislo these grades were fetched for.'),
            'rok' => $nullableString()->description('Academic year filtered to, or null when all_years is set.'),
            'semestr' => $nullableString()->description('Semester filtered to, or null when all_years is set.'),
            'all_years' => $schema->boolean()->description('Whether the whole study history was returned.'),
            'total' => $schema->integer()->description('Total matching subjects.'),
            'summary' => $schema->object([
                'credits_earned' => $schema->integer()->description('Credits from subjects actually passed.'),
                'credits_enrolled' => $schema->integer()->description('Credits across every subject in view, passed or not.'),
                'passed' => $schema->integer()->description('Number of subjects passed.'),
                'failed' => $schema->integer()->description('Number of subjects enrolled in a concluded period and not passed.'),
                'in_progress' => $schema->integer()->description('Number of subjects currently being studied, with no outcome yet.'),
                'other' => $schema->integer()->description('Number of subjects recognised, transferred, deferred, or in a state none of the above covers.'),
                'retaken' => $schema->integer()->description('Number of earlier failed enrolments that a later passing enrolment supersedes. Excluded from gpa_official so a retaken subject is not counted twice.'),
                'uncredited' => $schema->integer()->description('Number of subjects with no credit value on record (STAG\'s two sources can disagree on what exists). Excluded from credits_earned, credits_enrolled, and both averages, since a credit-weighted mean cannot weigh a subject with no credit value.'),
                'gpa_official' => $average("STAG's own weighted average, the number the portal, mobile app and printed transcript show."),
                'gpa_passed_only' => $average('The same weighting over passed subjects alone. Higher, and not the official figure.'),
            ])->description('Totals over every subject in view.'),
            'subjects' => $schema->array()
                ->description('Matching subjects, newest first.')
                ->items($schema->object([
                    'katedra' => $schema->string()->description('Department abbreviation.'),
                    'zkratka' => $schema->string()->description('Subject abbreviation.'),
                    'nazev' => $nullableString()->description('Subject name, or null when STAG does not list this enrolment.'),
                    'kredity' => $nullableInt()->description('Credit value, or null when STAG does not list this enrolment.'),
                    'rok' => $schema->string()->description('Academic year, as its starting year.'),
                    'semestr' => $schema->string()->description('Semester, "ZS" or "LS".'),
                    'status' => $schema->object([
                        'type' => $schema->string()->description('passed, failed, in_progress, recognised, transferred, deferred, enrolled_next_year, or unknown.'),
                        'code' => $schema->string()->description("STAG's raw stavAbsolvovani code, always present even when type is unknown."),
                        'label' => $nullableString()->description("STAG's own Czech description of the code, or null for an unrecognised one."),
                        'superseded_by' => $nullableString()->description('When this failed enrolment was later retaken and passed, the year/semester that superseded it, e.g. "2026/ZS". Null otherwise.'),
                    ]),
                    'zkouska' => $assessment('The exam, or the sole assessment on a zápočet-only subject. Null when STAG records none.'),
                    'zapocet' => $assessment('The zápočet taken before the exam, on subjects that require one. Null otherwise.'),
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
            /* A wrong year silently returns nothing upstream, so reject one
             * that cannot be a year rather than reporting an empty record. */
            'rok' => ['integer', 'min:1900', 'max:2200', 'nullable'],
            /*
             * Same reasoning as rok: a garbage semestr returns an empty result
             * upstream rather than an error, so it is rejected here instead.
             * "" is accepted deliberately, as an alias for "%".
             */
            'semestr' => ['string', Rule::in(['ZS', 'LS', '%', '']), 'nullable'],
            'all_years' => ['boolean', 'nullable'],
            'katedra' => ['string', 'nullable'],
            'zkratka' => ['string', 'nullable'],
            'os_cislo' => ['string', 'nullable'],
        ]);

        $osCislo = $validated['os_cislo'] ?? $this->resolveOsCislo($stag);

        if ($osCislo === null) {
            return Response::error('Could not resolve an osCislo for this account. Pass os_cislo explicitly.');
        }

        $allYears = $validated['all_years'] ?? false;
        $today = Carbon::today();
        $semestrInput = $validated['semestr'] ?? null;
        $semestrInput = $semestrInput === '' ? '%' : $semestrInput;
        $rok = $allYears ? null : (string) ($validated['rok'] ?? $this->academicYearFor($today));
        $semestr = $allYears ? null : ($semestrInput ?? $this->semesterFor($today));

        $record = (new StudyRecordService($stag))->getRecordForStudent($osCislo);

        if ($record->isEmpty()) {
            return Response::error("STAG returned no subjects for osCislo '{$osCislo}'. An osCislo that is not the one this ticket belongs to comes back empty rather than as an error, so check it if you passed it explicitly.");
        }

        $matching = $record->filter($rok, $semestr, $validated['katedra'] ?? null, $validated['zkratka'] ?? null);

        return Response::structured([
            'os_cislo' => $osCislo,
            'rok' => is_numeric($rok) ? $rok.'/'.(int) $rok + 1 : null,
            'semestr' => $semestr,
            'all_years' => $allYears,
            'total' => $matching->count(),
            'summary' => $matching->summary(),
            'subjects' => array_map(fn (Subject $s) => $s->toArray(), $matching->subjects()),
        ]);
    }

    private function academicYearFor(Carbon $date): int
    {
        return $date->month >= 9 ? $date->year : $date->year - 1;
    }

    /**
     * The winter semester runs from September to January, the summer one from
     * February to August.
     */
    private function semesterFor(Carbon $date): string
    {
        return $date->month >= 9 || $date->month === 1 ? 'ZS' : 'LS';
    }
}
