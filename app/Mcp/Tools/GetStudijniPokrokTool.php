<?php

namespace App\Mcp\Tools;

use App\Clients\StagHttpClient;
use App\Exceptions\StagException;
use App\Mcp\Concerns\DescribesStudyPlans;
use App\Mcp\Concerns\RequiresStagLogin;
use App\Services\StudyPlans\PlanAudit;
use App\Services\StudyPlans\StudentRecord;
use App\Services\StudyPlans\StudyPlanRepository;
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

#[Name('get-studijni-pokrok')]
#[Title('Get Studijni Pokrok')]
#[Description('What the caller still needs to finish their study plan: for every block of the plan, the credits and subjects earned, in progress and booked for next year, whether the block\'s rule is met, and which subjects are still missing or available to pick. Pass certificate_id (from list-certifikaty) to measure the caller\'s passed subjects against a certificate programme instead, e.g. "how close am I to the Podnikání v IT certificate". No grades or GPA here: use get-znamky for those.')]
#[IsReadOnly]
class GetStudijniPokrokTool extends Tool
{
    use DescribesStudyPlans;
    use RequiresStagLogin;

    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fvsp

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'certificate_id' => $schema->integer()
                ->description('A certificate_id from list-certifikaty, to measure progress towards that certificate instead of the caller\'s own plan.'),
            'stpl_id' => $schema->integer()
                ->description('Any plan id (stplIdno) to measure against instead of the caller\'s own plan.'),
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
        $codes = fn () => $schema->array()->items($schema->string());
        $subjects = fn () => $schema->anyOf([$schema->array()->items($schema->object($this->subjectSchema($schema)))])->nullable();

        return [
            'plan' => $schema->object([
                'stpl_id' => $schema->integer()->description('stplIdno of the plan measured against.'),
                'name' => $nullableString()->description('Plan name.'),
                'own_plan' => $schema->boolean()->description('True when this is the caller\'s own study plan, false for a certificate or other plan.'),
                'semesters' => $nullableInteger()->description('Standard length in semesters.'),
            ]),
            'programme' => $schema->anyOf([$schema->object([
                'credits_required' => $nullableInteger()->description('Credits the whole programme needs, e.g. 180.'),
                'credits_earned' => $schema->number()->description('Credits earned so far, recognised ones included.'),
                'credits_recognised' => $schema->number()->description('Credits recognised from earlier study (uznané).'),
                'year_of_study' => $nullableInteger()->description('Current year of study (ročník).'),
            ])])->nullable()->description('Programme totals as STAG counts them. Only for the caller\'s own plan.'),
            'blocks' => $schema->array()->description('One entry per block of the plan, in plan order.')
                ->items($schema->object([
                    'segment' => $nullableString()->description('Part of the plan the block belongs to.'),
                    'name' => $nullableString()->description('Block name in Czech.'),
                    'kind' => $schema->string()->enum(['required', 'elective', 'optional', 'other'])->description('required (A), elective (B: pick enough to meet the rule) or optional (C: no rule).'),
                    'min_credits' => $nullableInteger()->description('Credits the block needs, or null when it sets no credit rule.'),
                    'min_subjects' => $nullableInteger()->description('Subjects the block needs, or null when it sets no count rule.'),
                    'satisfied' => $schema->boolean()->description('The block\'s rules are met by passed subjects alone. Optional blocks are always satisfied.'),
                    'credits_earned' => $schema->number()->description('Credits passed or recognised in this block.'),
                    'subjects_earned' => $schema->integer()->description('Subjects passed or recognised in this block.'),
                    'credits_in_progress' => $schema->number()->description('Credits of subjects being studied now. Not earned until passed.'),
                    'credits_next_year' => $schema->number()->description('Credits of subjects already booked for next year.'),
                    'remaining_credits' => $nullableNumber()->description('Credits still needed, counting only passed subjects. Null when the block sets no credit rule.'),
                    'remaining_subjects' => $nullableInteger()->description('Subjects still needed, counting only passed subjects. Null when the block sets no count rule.'),
                    'earned' => $codes()->description('Codes of the subjects passed in this block.'),
                    'in_progress' => $codes()->description('Codes of the subjects being studied now.'),
                    'next_year' => $codes()->description('Codes of the subjects booked for next year.'),
                    'missing' => $subjects()->description('Subjects not yet taken in a block where every subject is required. Null for blocks where only some are needed.'),
                    'options' => $subjects()->description('Subjects not yet taken in a block where a few are enough: what is left to choose from. Null for all-required and optional blocks.'),
                    'untaken_count' => $schema->integer()->description('Subjects of the block not yet taken, passed or booked.'),
                ])),
            'eu_track' => $schema->anyOf([$schema->object([
                'credits_earned' => $schema->number()->description('Credits passed from EU Track subjects.'),
                'credits_in_progress' => $schema->number()->description('Credits of EU Track subjects being studied now.'),
                'level1_credits_required' => $schema->integer()->description('Credits ZČU requires for the EU Track Level 1 certificate.'),
                'level1_met' => $schema->boolean()->description('Enough passed credits for Level 1.'),
            ])])->nullable()->description('Progress towards the EUPeace EU Track Level 1 certificate, when the plan has an EU Track block. The 6-credit rule comes from zcu.cz, not from STAG.'),
            'unmatched' => $schema->array()->description('Subjects passed, in progress or booked that fit no slot of the caller\'s own plan: free electives outside it, leftovers from an older plan version, or extra attempts. Always empty for a certificate or other plan, where it would list nearly everything the student has taken.')
                ->items($schema->object([
                    'code' => $schema->string()->description('Subject code.'),
                    'credits' => $schema->number()->description('Credits.'),
                    'state' => $schema->string()->enum([PlanAudit::EARNED, PlanAudit::IN_PROGRESS, PlanAudit::NEXT_YEAR])->description('Whether it is passed, being studied, or booked for next year.'),
                    'year' => $nullableString()->description('Academic year it was taken in.'),
                ])),
            'note' => $schema->string()->description('How to read the result.'),
        ];
    }

    /**
     * Handle the tool request.
     *
     * @throws StagException
     */
    protected function handleAsStagUser(Request $request, StagHttpClient $stag): ResponseFactory|Response
    {
        $validated = $request->validate([
            'certificate_id' => ['nullable', 'integer', 'min:1'],
            'stpl_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $plans = new StudyPlanRepository($stag);
        $record = $plans->studentRecord($this->resolveStudentOsCislo($stag));
        $certificateId = isset($validated['certificate_id']) ? (int) $validated['certificate_id'] : null;

        $plan = $this->resolvePlan(
            $plans,
            isset($validated['stpl_id']) ? (int) $validated['stpl_id'] : null,
            $certificateId,
            fn () => $record,
        );

        if (is_string($plan)) {
            return Response::error($plan);
        }

        $ownPlan = $plan->id() === $record->currentPlanId();
        $audit = PlanAudit::run($plan, $record, $ownPlan);

        return Response::structured([
            'plan' => [
                'stpl_id' => $plan->id(),
                'name' => $plan->name(),
                'own_plan' => $ownPlan,
                'semesters' => $plan->toArray()['semesters'],
            ],
            'programme' => $ownPlan ? $this->programme($record) : null,
            'blocks' => array_map($this->toBlock(...), $audit['blocks']),
            'eu_track' => $ownPlan && $audit['eu_track'] !== null ? [
                'credits_earned' => $audit['eu_track']['credits_earned'],
                'credits_in_progress' => $audit['eu_track']['credits_in_progress'],
                'level1_credits_required' => PlanAudit::EU_TRACK_LEVEL1_CREDITS,
                'level1_met' => $audit['eu_track']['level1_met'],
            ] : null,
            'unmatched' => ! $ownPlan ? [] : array_map(fn (array $row) => [
                'code' => $row['code'],
                'credits' => $row['credits'],
                'state' => $row['earned'] ? PlanAudit::EARNED : ($row['in_progress'] ? PlanAudit::IN_PROGRESS : PlanAudit::NEXT_YEAR),
                'year' => $row['year'],
            ], $audit['unmatched']),
            'note' => $this->note($ownPlan, $certificateId !== null),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function programme(StudentRecord $record): array
    {
        return [
            'credits_required' => $record->creditsRequired,
            'credits_earned' => $record->creditsEarned,
            'credits_recognised' => $record->creditsRecognised,
            'year_of_study' => $record->currentGradeLevel(),
        ];
    }

    /**
     * @param  array<string, mixed>  $result  {@see PlanAudit} block result
     * @return array<string, mixed>
     */
    private function toBlock(array $result): array
    {
        $block = $result['block'];
        $listable = $block['statut'] !== 'C';
        $untaken = array_map($this->toSubject(...), $result['untaken']);

        return [
            'segment' => $block['segment'],
            'name' => $block['name'],
            'kind' => $this->blockKind($block['statut']),
            'min_credits' => $block['min_credits'],
            'min_subjects' => $block['min_subjects'],
            'satisfied' => $result['satisfied'],
            'credits_earned' => $result['credits_earned'],
            'subjects_earned' => $result['subjects_earned'],
            'credits_in_progress' => $result['credits_in_progress'],
            'credits_next_year' => $result['credits_next_year'],
            'remaining_credits' => $result['remaining_credits'],
            'remaining_subjects' => $result['remaining_subjects'],
            'earned' => $result['earned'],
            'in_progress' => $result['in_progress'],
            'next_year' => $result['next_year'],
            'missing' => $listable && $block['all_required'] ? $untaken : null,
            /* A satisfied pick-some block needs nothing more, so its leftovers are noise. */
            'options' => $listable && ! $block['all_required'] && ! $result['satisfied'] ? $untaken : null,
            'untaken_count' => count($untaken),
        ];
    }

    private function note(bool $ownPlan, bool $certificate): string
    {
        if ($certificate) {
            return 'Measured against the certificate programme\'s current plan using the caller\'s passed subjects. There is no enrolment: the subjects are taken through předzápis, and a final exam (závěrečná zkouška) listed among the required subjects closes the programme. STAG records no "certificate awarded" state, so satisfied blocks mean the requirements are met, not that the certificate has been issued. The programme\'s subject area must differ from the main study, a rule STAG does not check.';
        }

        if ($ownPlan) {
            return 'Measured against the plan of the caller\'s latest year of study. Each passed subject counts once, in one block. In-progress credits are not earned until passed. Subjects in unmatched still count towards the programme total.';
        }

        return 'Measured against a plan that is not the caller\'s own, using their passed subjects. Only subjects belonging to this plan are shown.';
    }
}
