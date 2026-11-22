<?php

namespace App\Mcp\Tools;

use App\Contracts\StagClient;
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

#[Name('get-grades')]
#[Title('Get grades')]
#[Description("The caller's grades (známky) and study progress: per subject the exam and zápočet result, attempt, date, examiner and credits, plus credits earned and the weighted average. Call it for \"how am I doing\", \"what is still open\" or \"what is my average\". Takes no arguments for the current semester; pass year and semester for another one, or all_years for the whole degree. A subject's status comes from STAG's completion code, not the grade, because a zápočet-only subject has no number and an unfinished one is sometimes recorded as a 4.")]
#[IsReadOnly]
class GetGradesTool extends Tool
{
    use RequiresStagLogin;
    use ResolvesStagIdentity;

    private const array SEMESTERS = ['winter', 'summer', 'both'];

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
            'year' => $schema->anyOf([$schema->string(), $schema->integer()])
                ->description('Academic year as its starting year: 2025 means 2025/2026. Defaults to the current academic year. Ignored when all_years is true.'),
            'semester' => $schema->string()->enum(self::SEMESTERS)
                ->description('"winter", "summer" or "both". Defaults to the semester today falls in. Ignored when all_years is true.'),
            'all_years' => $schema->boolean()
                ->description('Return the whole study history instead of one semester, ignoring year and semester. Use for questions about the whole degree.')
                ->default(false),
            'department' => $schema->string()
                ->description('Only subjects whose department contains this, case-insensitive, e.g. "KIV".'),
            'code' => $schema->string()
                ->description('Only subjects whose code contains this, case-insensitive, e.g. "MA1".'),
            'student_id' => $schema->string()
                ->description('Personal number (osCislo) of the study to read, for accounts with more than one student role. Found automatically when left out; get-profile lists the roles.'),
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
            'teacher_id' => $nullableInt()->description('teacher_id of that teacher, for get-teacher.'),
        ])])->nullable()->description($description);

        return [
            'student_id' => $schema->string()->description('The student_id these grades were fetched for.'),
            'year' => $nullableString()->description('Academic year filtered to, e.g. "2025/2026", or null when all_years is set.'),
            'semester' => $nullableString()->description('Semester filtered to, "winter", "summer" or "both", or null when all_years is set.'),
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
                    'department' => $schema->string()->description('Department abbreviation.'),
                    'code' => $schema->string()->description('Subject code within the department.'),
                    'name' => $nullableString()->description('Subject name, or null when STAG does not list this enrolment.'),
                    'credits' => $nullableInt()->description('Credit value, or null when STAG does not list this enrolment.'),
                    'year' => $schema->string()->description('Academic year, as its starting year.'),
                    'semester' => $schema->string()->description('"winter" or "summer".'),
                    'status' => $schema->object([
                        'type' => $schema->string()->description('passed, failed, in_progress, recognised, transferred, deferred, enrolled_next_year, or unknown.'),
                        'code' => $schema->string()->description("STAG's raw stavAbsolvovani code, always present even when type is unknown."),
                        'label' => $nullableString()->description("STAG's own Czech description of the code, or null for an unrecognised one."),
                        'superseded_by' => $nullableString()->description('When this failed enrolment was later retaken and passed, the year and semester that superseded it, e.g. "2026 winter". Null otherwise.'),
                    ]),
                    'exam' => $assessment('The exam, or the sole assessment on a zápočet-only subject. Null when STAG records none.'),
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
            'year' => ['integer', 'min:1990', 'max:2100', 'nullable'],
            /* Same reasoning as year: a garbage semester returns an empty
             * result upstream rather than an error. */
            'semester' => ['string', Rule::in(self::SEMESTERS), 'nullable'],
            'all_years' => ['boolean', 'nullable'],
            'department' => ['string', 'nullable'],
            'code' => ['string', 'nullable'],
            'student_id' => ['string', 'nullable'],
        ], [
            'year' => 'year must be an academic year as its starting year, e.g. 2025 for 2025/2026.',
            'semester' => 'semester must be "winter", "summer" or "both".',
            'all_years' => 'all_years must be true or false.',
            'department' => 'department must be a department abbreviation or part of one, e.g. "KIV".',
            'code' => 'code must be a subject code or part of one, e.g. "MA1".',
            'student_id' => 'student_id must be a personal number as text, e.g. "A21B0123P". get-profile lists the account\'s own.',
        ]);

        $osCislo = $validated['student_id'] ?? $this->resolveOsCislo($stag);

        if ($osCislo === null) {
            return Response::error('Could not find a student_id for this account. Pass student_id explicitly; get-profile lists the account\'s roles.');
        }

        $allYears = $validated['all_years'] ?? false;
        $today = Carbon::today();
        $rok = $allYears ? null : (string) ($validated['year'] ?? $this->academicYearFor($today));
        $semestr = $allYears ? null : match ($validated['semester'] ?? null) {
            'winter' => 'ZS',
            'summer' => 'LS',
            'both' => '%',
            default => $this->semesterFor($today),
        };

        $record = (new StudyRecordService($stag))->getRecordForStudent($osCislo);

        if ($record->isEmpty()) {
            return Response::error("STAG returned no subjects for student_id '{$osCislo}'. A student_id that is not this account's own comes back empty rather than as an error, so check it if you passed one.");
        }

        $matching = $record->filter($rok, $semestr, $validated['department'] ?? null, $validated['code'] ?? null);

        return Response::structured([
            'student_id' => $osCislo,
            'year' => is_numeric($rok) ? $rok.'/'.(int) $rok + 1 : null,
            'semester' => $semestr === null ? null : ($semestr === '%' ? 'both' : Subject::semesterName($semestr)),
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
