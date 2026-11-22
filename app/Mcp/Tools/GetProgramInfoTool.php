<?php

namespace App\Mcp\Tools;

use App\Clients\StagHttpClient;
use App\Exceptions\StagException;
use App\Models\User;
use App\Services\Programmes\ProgrammeCatalogue;
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

#[Name('get-program-info')]
#[Title('Get Program Info')]
#[Description('One study programme in detail: what it aims at, the graduate profile and typical jobs, admission requirements, length, credits, title, guarantor, and its specialisations (obory) with this year\'s study plans. Answers "what does this programme lead to", "how many credits is it", "what specialisations does it have". Get a programme_id from search-programy. Pass a plan\'s stpl_id to get-studijni-plan for its subjects. The prose is in Czech, as the faculty wrote it.')]
#[IsReadOnly]
class GetProgramInfoTool extends Tool
{
    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fprogramy

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'programme_id' => $schema->integer()
                ->description('programme_id (stprIdno) from search-programy.')
                ->required(),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $nullableString = fn () => $schema->anyOf([$schema->string()])->nullable();
        $nullableInteger = fn () => $schema->anyOf([$schema->integer()])->nullable();
        $nullableNumber = fn () => $schema->anyOf([$schema->number()])->nullable();

        return [
            'programme_id' => $schema->integer()->description('stprIdno.'),
            'code' => $nullableString()->description('Accreditation code.'),
            'name' => $nullableString()->description('Name in Czech.'),
            'name_en' => $nullableString()->description('Name in English, when STAG has one.'),
            'faculty' => $nullableString()->description('Faculty code.'),
            'level' => $nullableString()->description('bachelor, follow-up master, master, doctoral, lifelong learning, rigorosum or other.'),
            'form' => $nullableString()->description('full-time, combined or distance.'),
            'language' => $nullableString()->description('Teaching language: cs or en.'),
            'degree' => $nullableString()->description('Title awarded, e.g. "Bc.". Null for non-degree study.'),
            'standard_years' => $nullableNumber()->description('Standard length in years.'),
            'max_years' => $nullableNumber()->description('Longest a student may take, in years.'),
            'credits' => $nullableInteger()->description('Credits needed to graduate.'),
            'valid_from' => $nullableString()->description('First academic year it ran.'),
            'valid_until' => $nullableString()->description('Academic year from which it is no longer valid, or null when open-ended.'),
            'certificate' => $schema->boolean()->description('A certificate programme rather than a degree: see list-certifikaty.'),
            'profile' => $nullableString()->description('akademický (academic) or profesní (professional).'),
            'accredited_until' => $nullableString()->description('End of the accreditation, as d.m.yyyy.'),
            'areas' => $schema->anyOf([$schema->array()->items($schema->string())])->nullable()->description('Fields of education with their share, e.g. "Informatika (100%)". Null when STAG refused the lookup.'),
            'guarantor' => $schema->anyOf([$schema->object([
                'name' => $nullableString()->description('Name with titles, surname first.'),
                'teacher_id' => $schema->integer()->description('ucitIdno. Pass to get-teacher for contact details.'),
            ])])->nullable()->description('The programme guarantor (garant).'),
            'about' => $schema->object([
                'goals' => $nullableString()->description('What the programme aims at (cíle).'),
                'graduate_profile' => $nullableString()->description('What a graduate knows and can do.'),
                'employability' => $nullableString()->description('Where graduates find work.'),
                'job_positions' => $nullableString()->description('Typical job positions.'),
                'admission_requirements' => $nullableString()->description('What admission requires.'),
                'admission_url' => $nullableString()->description('Where the admission details are published.'),
                'intake' => $nullableInteger()->description('Planned maximum number of students admitted per year.'),
                'health_requirements' => $nullableString()->description('Required health fitness, where the programme sets one.'),
                'follow_up_study' => $nullableString()->description('Which programmes it leads on to.'),
                'practice' => $nullableString()->description('Professional practice included in the study.'),
                'thesis_topics' => $nullableString()->description('Typical thesis topics.'),
            ])->description('The faculty\'s own description, in Czech. Each field is null when the faculty left it empty, which many do.'),
            'specialisations' => $schema->array()->description('Specialisations (obory) of the programme.')
                ->items($schema->object([
                    'specialisation_id' => $schema->integer()->description('oborIdno. A certificate\'s specialisation_id is its certificate_id.'),
                    'name' => $nullableString()->description('Name in Czech.'),
                    'name_en' => $nullableString()->description('Name in English.'),
                    'code' => $nullableString()->description('Short code, e.g. "PV23bp".'),
                    'credits' => $nullableInteger()->description('Credits the specialisation needs.'),
                    'plans' => $schema->anyOf([$schema->array()->items($schema->object([
                        'stpl_id' => $schema->integer()->description('stplIdno. Pass to get-studijni-plan for the subjects.'),
                        'version' => $nullableString()->description('Plan version, which is the intake year: a student stays on the version of the year they started. Someone starting now follows the newest.'),
                        'valid_for' => $nullableString()->description('Academic year the plan is valid for.'),
                        'semesters' => $nullableInteger()->description('Standard length in semesters.'),
                        'credits' => $nullableInteger()->description('Credits the plan needs.'),
                    ]))])->nullable()->description('This year\'s plans, newest version first. Null when the programme has more than '.ProgrammeCatalogue::MAX_SPECIALISATIONS_WITH_PLANS.' specialisations (not fetched) or STAG refused the lookup.'),
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
            'programme_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $programme = (new ProgrammeCatalogue(new StagHttpClient($user)))->programme((int) $validated['programme_id']);
        } catch (StagException $e) {
            return Response::error($e->getMessage());
        }

        if ($programme === null) {
            return Response::error("No study programme found with programme_id {$validated['programme_id']}. Find one with search-programy.");
        }

        return Response::structured($programme);
    }
}
