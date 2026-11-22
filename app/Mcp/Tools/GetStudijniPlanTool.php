<?php

namespace App\Mcp\Tools;

use App\Clients\StagHttpClient;
use App\Exceptions\StagException;
use App\Mcp\Concerns\DescribesStudyPlans;
use App\Models\User;
use App\Services\StudyPlans\StudyPlan;
use App\Services\StudyPlans\StudyPlanRepository;
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

#[Name('get-studijni-plan')]
#[Title('Get Studijni Plan')]
#[Description('A study plan (studijní plán): its blocks of required, elective and optional subjects, the credit rule of each block, and the subjects in it with credits and the recommended year and semester. Defaults to the caller\'s own current plan; pass certificate_id (from list-certifikaty) for a certificate programme, or stpl_id for any plan. Shows the plan only, not the caller\'s progress: use get-studijni-pokrok for what is done and what is left.')]
#[IsReadOnly]
class GetStudijniPlanTool extends Tool
{
    use DescribesStudyPlans;

    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fvsp

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'stpl_id' => $schema->integer()
                ->description('A plan id (stplIdno). Omit for the caller\'s own current plan.'),
            'certificate_id' => $schema->integer()
                ->description('A certificate_id from list-certifikaty, to show that certificate\'s current plan.'),
            'include_optional' => $schema->boolean()
                ->description('List the subjects of optional (C) blocks too. They are often long lists of free electives (EU Track alone is 37), so by default only their subject count is given.')
                ->default(false),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $nullableString = fn () => $schema->anyOf([$schema->string()])->nullable();
        $nullableInteger = fn () => $schema->anyOf([$schema->integer()])->nullable();

        return [
            'plan' => $schema->object([
                'stpl_id' => $schema->integer()->description('stplIdno.'),
                'name' => $nullableString()->description('Plan name, usually the programme or specialisation.'),
                'semesters' => $nullableInteger()->description('Standard length in semesters.'),
                'credit_limit' => $nullableInteger()->description('Credits needed to complete the plan, e.g. 180 for a bachelor degree.'),
                'valid_for' => $nullableString()->description('Academic year the plan is valid for, e.g. "2026" for 2026/2027.'),
                'version' => $nullableString()->description('Plan version as STAG names it.'),
                'description_url' => $nullableString()->description('The department\'s own page about the plan, when STAG has one.'),
            ]),
            'blocks' => $schema->array()->description('Blocks in plan order. A student completes the plan by meeting the rule of every required and elective block.')
                ->items($schema->object([
                    'segment' => $nullableString()->description('The part of the plan the block belongs to, e.g. the specialisation or the faculty-wide part.'),
                    'name' => $nullableString()->description('Block name in Czech.'),
                    'kind' => $schema->string()->enum(['required', 'elective', 'optional', 'other'])->description('required (povinné, A), elective (povinně volitelné, B: pick enough to meet the rule) or optional (volitelné, C: free choice, no rule).'),
                    'min_credits' => $nullableInteger()->description('Credits needed from this block, or null when it sets no credit rule.'),
                    'min_subjects' => $nullableInteger()->description('Subjects needed from this block, or null when it sets no count rule.'),
                    'all_required' => $schema->boolean()->description('Every subject in the block must be passed. False for a block where a few of the listed subjects are enough.'),
                    'subject_count' => $schema->integer()->description('Subjects listed in the block.'),
                    'subjects' => $schema->anyOf([$schema->array()->items($schema->object([
                        ...$this->subjectSchema($schema),
                        'hours' => $nullableString()->description('Weekly hours as lecture+seminar+other, e.g. "2+2+0".'),
                        'state_exam' => $schema->boolean()->description('Part of the state exam (státní závěrečná zkouška).'),
                        'excludes' => $schema->array()->items($schema->string())->description('Subjects that cannot be passed together with this one.'),
                        'replaces' => $schema->array()->items($schema->string())->description('Older subjects this one stands in for.'),
                        'recommended_first' => $schema->array()->items($schema->string())->description('Subjects worth taking before this one. Advice only: ZČU has no prerequisites.'),
                    ]))])->nullable()->description('Null for an optional block unless include_optional is set.'),
                ])),
        ];
    }

    /**
     * Handle the tool request. Not RequiresStagLogin: a plan is public, so
     * only the default (the caller's own plan) needs a ticket.
     */
    public function handle(Request $request, #[CurrentUser('sanctum')] ?User $user = null): ResponseFactory|Response
    {
        if ($user === null) {
            return Response::error('No authenticated user. The MCP client must send a bearer token.');
        }

        $validated = $request->validate([
            'stpl_id' => ['nullable', 'integer', 'min:1'],
            'certificate_id' => ['nullable', 'integer', 'min:1'],
            'include_optional' => ['nullable', 'boolean'],
        ]);

        $stag = new StagHttpClient($user);
        $plans = new StudyPlanRepository($stag);

        try {
            $plan = $this->resolvePlan(
                $plans,
                isset($validated['stpl_id']) ? (int) $validated['stpl_id'] : null,
                isset($validated['certificate_id']) ? (int) $validated['certificate_id'] : null,
                $user->hasValidStagToken() ? fn () => $plans->studentRecord($this->resolveStudentOsCislo($stag)) : null,
            );
        } catch (StagException $e) {
            return Response::error($e->getMessage());
        }

        if (is_string($plan)) {
            return Response::error($plan);
        }

        return Response::structured($this->toOutput($plan, (bool) ($validated['include_optional'] ?? false)));
    }

    /**
     * @return array<string, mixed>
     */
    private function toOutput(StudyPlan $plan, bool $includeOptional): array
    {
        $meta = $plan->toArray();

        return [
            'plan' => [
                'stpl_id' => $meta['id'],
                'name' => $meta['name'],
                'semesters' => $meta['semesters'],
                'credit_limit' => $meta['credit_limit'],
                'valid_for' => $meta['valid_for'],
                'version' => $meta['version'],
                'description_url' => $meta['description_url'],
            ],
            'blocks' => array_map(fn (array $block) => [
                'segment' => $block['segment'],
                'name' => $block['name'],
                'kind' => $this->blockKind($block['statut']),
                'min_credits' => $block['min_credits'],
                'min_subjects' => $block['min_subjects'],
                'all_required' => $block['all_required'],
                'subject_count' => count($block['slots']),
                'subjects' => $block['statut'] === 'C' && ! $includeOptional ? null : array_map(fn (array $slot) => [
                    ...$this->toSubject($slot),
                    'hours' => $slot['hours'],
                    'state_exam' => $slot['state_exam'],
                    'excludes' => $slot['excludes'],
                    'replaces' => $slot['replaces'],
                    'recommended_first' => $slot['recommended_first'],
                ], $block['slots']),
            ], $plan->blocks()),
        ];
    }
}
