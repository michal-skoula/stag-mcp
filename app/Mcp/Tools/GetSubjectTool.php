<?php

namespace App\Mcp\Tools;

use App\Clients\StagHttpClient;
use App\Exceptions\StagException;
use App\Models\User;
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

#[Name('get-subject')]
#[Title('Get subject')]
#[Description('Full detail for one subject (předmět): credits, semesters, hours, how it is examined, who guarantees, lectures, runs exercises and examines it (with teacher_ids), the syllabus and requirements, and prerequisite or excluded subjects. Call it when the user asks about a specific subject: what it covers, how hard it is, who teaches it, what it needs. Needs department and code, e.g. from search-subjects or get-grades; year defaults to the current academic year.')]
#[IsReadOnly]
class GetSubjectTool extends Tool
{
    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fpredmety

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'department' => $schema->string()
                ->description('Department abbreviation, e.g. "KIV".')
                ->required(),
            'code' => $schema->string()
                ->description('Subject code within the department, e.g. "UPA".')
                ->required(),
            /*
             * Accepts a number as well as a string: the year reads
             * as numeric, and clients send 2025 as often as "2025".
             */
            'year' => $schema->anyOf([$schema->string(), $schema->integer()])
                ->description('Academic year as its starting year: 2025 means 2025/2026. Defaults to the current academic year.'),
            'language' => $schema->string()
                ->description('Language for the free-text fields STAG translates, "cs" or "en". Untranslated fields stay Czech.'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $nullableString = fn () => $schema->anyOf([$schema->string()])->nullable();
        $nullableBool = fn () => $schema->anyOf([$schema->boolean()])->nullable();
        $nullableInt = fn () => $schema->anyOf([$schema->integer()])->nullable();
        $stringList = fn (string $description) => $schema->array()->description($description)->items($schema->string());
        $intList = fn (string $description) => $schema->array()->description($description)->items($schema->integer());
        $hours = fn (string $what) => $schema->object([
            'amount' => $nullableInt()->description("{$what} hours, or null."),
            'unit' => $nullableString()->description("Unit the {$what} hours are counted in, e.g. \"HOD/TYD\" (hours a week), or null."),
        ]);

        return [
            'department' => $schema->string()->description('Department abbreviation.'),
            'code' => $schema->string()->description('Subject code within the department.'),
            'year' => $schema->string()->description('Academic year, as its starting year.'),
            'name' => $schema->string()->description('Subject name.'),
            'long_name' => $schema->string()->description('Long subject name.'),
            'accredited' => $nullableBool()->description('Whether the subject is accredited, or null.'),
            'level' => $nullableString()->description('Study level STAG computes for it, e.g. "Bc.", or null.'),
            'teaching' => $schema->object([
                'credits' => $nullableInt()->description('ECTS credits, or null.'),
                'taught_in_winter' => $nullableBool()->description('Taught in the winter semester, or null.'),
                'taught_in_summer' => $nullableBool()->description('Taught in the summer semester, or null.'),
                'has_teaching' => $nullableBool()->description('Whether it has teaching this year, or null.'),
                'frequency' => $nullableString()->description('How often it is offered, as STAG\'s raw code, or null.'),
                'frequency_note' => $nullableString()->description('Free-text detail on how often it is offered, or null.'),
                'repeatable' => $nullableString()->description('Whether it can be enrolled in again after passing, as STAG\'s raw value, or null.'),
                'min_enrolment' => $nullableInt()->description('Fewest students it opens for, or null.'),
                'languages' => $nullableString()->description('Languages of instruction, or null.'),
            ]),
            'hours' => $schema->object([
                'lectures' => $hours('Lecture'),
                'exercises' => $hours('Exercise'),
                'seminars' => $hours('Seminar'),
                'workload' => $nullableString()->description('Breakdown of the total workload by activity, or null.'),
            ]),
            'exam' => $schema->object([
                'type' => $nullableString()->description('How it is concluded, e.g. "Zkouška" or "Zápočet", or null.'),
                'form' => $nullableString()->description('Exam form, e.g. written or oral, or null.'),
                'zapocet_before_exam' => $nullableBool()->description('Whether a zápočet is needed before the exam can be taken, or null.'),
            ]),
            'people' => $schema->object([
                'guarantors' => $stringList('Guarantor (garant) names.'),
                'guarantor_teacher_ids' => $intList('Guarantor teacher_ids, for get-teacher.'),
                'lecturers' => $stringList('Lecturer (přednášející) names.'),
                'lecturer_teacher_ids' => $intList('Lecturer teacher_ids.'),
                'instructors' => $stringList('Names of those who run the exercises (cvičící).'),
                'instructor_teacher_ids' => $intList('Instructor teacher_ids.'),
                'seminar_leaders' => $stringList('Seminar leader (seminárník) names.'),
                'seminar_leader_teacher_ids' => $intList('Seminar leader teacher_ids.'),
                'examiners' => $stringList('Examiner (zkoušející) names.'),
                'examiner_teacher_ids' => $intList('Examiner teacher_ids.'),
                'recognition_approvers' => $stringList('Names of those who approve recognising the subject from another study.'),
                'recognition_approver_teacher_ids' => $intList('Recognition approver teacher_ids.'),
            ]),
            'content' => $schema->object([
                'summary' => $nullableString()->description('Annotation (anotace), or null.'),
                'syllabus' => $nullableString()->description('Overview of the topics covered, or null.'),
                'requirements' => $nullableString()->description('What it takes to pass, or null.'),
                'prerequisites_note' => $nullableString()->description('Expected prior knowledge, as free text, or null.'),
                'learning_outcomes' => $nullableString()->description('Competencies gained, or null.'),
                'teaching_methods' => $nullableString()->description('Teaching methods, or null.'),
                'assessment_methods' => $nullableString()->description('Assessment methods, or null.'),
                'literature' => $stringList('Reading list entries.'),
                'study_materials' => $nullableString()->description('Study materials link or note, or null.'),
            ]),
            'relations' => $schema->object([
                'prerequisites' => $stringList('Subjects that must be passed first, as "DEPARTMENT/CODE".'),
                'excludes' => $stringList('Subjects that cannot be taken alongside this one, as "DEPARTMENT/CODE".'),
                'prerequisite_for' => $stringList('Subjects that need this one first, as "DEPARTMENT/CODE".'),
                'replaces' => $stringList('Subjects this one replaces, as "DEPARTMENT/CODE".'),
            ]),
            'ects' => $schema->object([
                'shown' => $nullableBool()->description('Whether it is listed in the ECTS catalogue, or null.'),
                'accredited' => $nullableBool()->description('Whether it is ECTS-accredited, or null.'),
                'offered_to_incoming' => $nullableBool()->description('Whether it is offered to incoming exchange students, or null.'),
            ]),
            'misc' => $schema->object([
                'note' => $nullableString()->description('Internal note, or null.'),
                'public_note' => $nullableString()->description('Public note, or null.'),
                'url' => $nullableString()->description('Subject web page, or null.'),
                'accreditation_group' => $nullableString()->description('Accreditation group, or null.'),
                'in_full_time' => $nullableBool()->description('Included in full-time study, or null.'),
                'in_combined' => $nullableBool()->description('Included in combined study, or null.'),
                'internship_days' => $nullableString()->description('Number of internship days, or null.'),
                'auto_recognised' => $nullableBool()->description('Whether a zápočet or exam from elsewhere is recognised automatically, or null.'),
                'combined_hours_per_semester' => $nullableString()->description('Hours a semester in combined study, or null.'),
            ]),
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

        $validated = $request->validate([
            'department' => ['string', 'required'],
            'code' => ['string', 'required'],
            'year' => ['integer', 'min:1990', 'max:2100', 'nullable'],
            'language' => ['string', Rule::in(['cs', 'en']), 'nullable'],
        ], [
            'department' => 'department is required and must be a department abbreviation, e.g. "KIV".',
            'code' => 'code is required and must be the subject code within the department, e.g. "UPA".',
            'year' => 'year must be an academic year as its starting year, e.g. 2025 for 2025/2026.',
            'language' => 'language must be "cs" or "en".',
        ]);

        $params = array_filter([
            'katedra' => $validated['department'],
            'zkratka' => $validated['code'],
            'rok' => isset($validated['year']) ? (string) $validated['year'] : null,
            'lang' => $validated['language'] ?? null,
        ], fn ($value) => $value !== null);

        try {
            $row = (new StagHttpClient($user))->get('predmety/getPredmetInfo', $params);
        } catch (StagException $e) {
            return Response::error($e->getMessage());
        }

        // STAG returns [] (an empty list, not an object) when nothing matches
        // katedra/zkratka/rok, rather than a 404 or an error response.
        if ($row === [] || array_is_list($row)) {
            return Response::error("No subject found for department '{$validated['department']}' and code '{$validated['code']}'. Check both with search-subjects.");
        }

        return Response::structured($this->toSubjectInfo($row));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function toSubjectInfo(array $row): array
    {
        return [
            'department' => $row['katedra'],
            'code' => $row['zkratka'],
            'year' => $row['rok'],
            'name' => $row['nazev'],
            'long_name' => $row['nazevDlouhy'],
            'accredited' => $this->fromStagBool($row['akreditovan'] ?? null),
            // urovenNastavena is dropped: null in every sample seen so far and
            // redundant with the computed urovenVypoctena. skupinaAkreditaceKey
            // is dropped too — it's just skupinaAkreditace's numeric code.
            'level' => $row['urovenVypoctena'] ?? null,
            'teaching' => [
                'credits' => $row['kreditu'] ?? null,
                'taught_in_winter' => $this->fromStagBool($row['vyukaZS'] ?? null),
                'taught_in_summer' => $this->fromStagBool($row['vyukaLS'] ?? null),
                'has_teaching' => $this->fromStagBool($row['maVyuku'] ?? null),
                'frequency' => $row['jakCastoJeNabizen'] ?? null,
                'frequency_note' => $row['jakCastoJeNabizenUpresneni'] ?? null,
                'repeatable' => $row['viceZapis'] ?? null,
                'min_enrolment' => $row['minObsazeni'] ?? null,
                'languages' => $row['vyucovaciJazyky'] ?? null,
            ],
            'hours' => [
                'lectures' => [
                    'amount' => $row['jednotekPrednasek'] ?? null,
                    'unit' => $row['jednotkaPrednasky'] ?? null,
                ],
                'exercises' => [
                    'amount' => $row['jednotekCviceni'] ?? null,
                    'unit' => $row['jednotkaCviceni'] ?? null,
                ],
                'seminars' => [
                    'amount' => $row['jednotekSeminare'] ?? null,
                    'unit' => $row['jednotkaSeminare'] ?? null,
                ],
                'workload' => $row['casovaNarocnost'] ?? null,
            ],
            'exam' => [
                'type' => $row['typZkousky'] ?? null,
                'form' => $row['formaZkousky'] ?? null,
                'zapocet_before_exam' => $this->fromStagBool($row['maZapocetPredZk'] ?? null),
            ],
            // The *SPodily variants (name + workload percentage) are dropped as
            // redundant with the plain name list plus the matching *UcitIdno ids.
            'people' => [
                'guarantors' => $this->splitQuotedList($row['garanti'] ?? null),
                'guarantor_teacher_ids' => $this->splitIds($row['garantiUcitIdno'] ?? null),
                'lecturers' => $this->splitQuotedList($row['prednasejici'] ?? null),
                'lecturer_teacher_ids' => $this->splitIds($row['prednasejiciUcitIdno'] ?? null),
                'instructors' => $this->splitQuotedList($row['cvicici'] ?? null),
                'instructor_teacher_ids' => $this->splitIds($row['cviciciUcitIdno'] ?? null),
                'seminar_leaders' => $this->splitQuotedList($row['seminarici'] ?? null),
                'seminar_leader_teacher_ids' => $this->splitIds($row['seminariciUcitIdno'] ?? null),
                'examiners' => $this->splitQuotedList($row['examinatori'] ?? null),
                'examiner_teacher_ids' => $this->splitIds($row['examinatoriUcitIdno'] ?? null),
                'recognition_approvers' => $this->splitQuotedList($row['schvalujiciUznani'] ?? null),
                'recognition_approver_teacher_ids' => $this->splitIds($row['schvalujiciUznaniUcitIdno'] ?? null),
            ],
            'content' => [
                'summary' => $row['anotace'] ?? null,
                'syllabus' => $row['prehledLatky'] ?? null,
                'requirements' => $row['pozadavky'] ?? null,
                'prerequisites_note' => $row['predpoklady'] ?? null,
                'learning_outcomes' => $row['ziskaneZpusobilosti'] ?? null,
                'teaching_methods' => $row['metodyVyucovaci'] ?? null,
                'assessment_methods' => $row['metodyHodnotici'] ?? null,
                'literature' => $this->splitQuotedList($row['literatura'] ?? null),
                'study_materials' => $row['studijniOpory'] ?? null,
            ],
            'relations' => [
                'prerequisites' => $this->splitCodes($row['podminujiciPredmety'] ?? null),
                'excludes' => $this->splitCodes($row['vylucujiciPredmety'] ?? null),
                'prerequisite_for' => $this->splitCodes($row['podminujePredmety'] ?? null),
                'replaces' => $this->splitCodes($row['nahrazPredmety'] ?? null),
            ],
            'ects' => [
                'shown' => $this->fromStagBool($row['ectsZobrazit'] ?? null),
                'accredited' => $this->fromStagBool($row['ectsAkreditace'] ?? null),
                'offered_to_incoming' => $this->fromStagBool($row['ectsNabizetUPrijezdu'] ?? null),
            ],
            'misc' => [
                'note' => $row['poznamka'] ?? null,
                'public_note' => $row['poznamkaVerejna'] ?? null,
                'url' => $row['predmetUrl'] ?? null,
                'accreditation_group' => $row['skupinaAkreditace'] ?? null,
                'in_full_time' => $this->fromStagBool($row['zarazenDoPrezencnihoStudia'] ?? null),
                'in_combined' => $this->fromStagBool($row['zarazenDoKombinovanehoStudia'] ?? null),
                'internship_days' => $row['praxePocetDnu'] ?? null,
                'auto_recognised' => $this->fromStagBool($row['automatickyUznavatZppZk'] ?? null),
                'combined_hours_per_semester' => $row['hodZaSemKombForma'] ?? null,
            ],
        ];
    }

    /**
     * STAG uses both 'A'/'N' and 'ANO'/'NE' as its yes/no vocabulary,
     * sometimes on the same endpoint. Unrecognised values map to null
     * rather than guessing.
     */
    private function fromStagBool(?string $value): ?bool
    {
        return match ($value !== null ? mb_strtoupper($value) : null) {
            'A', 'ANO' => true,
            'N', 'NE' => false,
            default => null,
        };
    }

    /**
     * Splits STAG's `'Name One', 'Name Two, Jr.'`-style lists, where entries
     * are individually quoted because names and literature citations can
     * contain commas of their own. A naive explode(',', ...) would break
     * those entries apart.
     *
     * @return list<string>
     */
    private function splitQuotedList(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $entry) => trim(trim($entry), "'"),
            preg_split("/'\s*,\s*'/u", trim($value)) ?: [],
        ), fn (string $entry) => $entry !== ''));
    }

    /**
     * Splits STAG's plain comma-separated subject-code lists, which don't
     * carry the quoting that names and citations need.
     *
     * @return list<string>
     */
    private function splitCodes(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $entry) => $entry !== ''));
    }

    /**
     * @return list<int>
     */
    private function splitIds(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $entry) => (int) trim($entry),
            explode(',', $value),
        ), fn (int $entry) => $entry !== 0));
    }
}
