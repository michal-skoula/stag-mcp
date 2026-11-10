<?php

namespace App\Mcp\Concerns;

use App\Clients\StagHttpClient;
use App\Exceptions\StagException;
use App\Mcp\Enums\ExamType;
use App\Services\StudyPlans\StudentRecord;
use App\Services\StudyPlans\StudyPlan;
use App\Services\StudyPlans\StudyPlanRepository;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * Shared by get-studijni-plan and get-studijni-pokrok: picking which plan a
 * call is about, and describing its subjects the same way in both.
 *
 * @phpstan-import-type Slot from StudyPlan
 */
trait DescribesStudyPlans
{
    use ResolvesStagIdentity;

    /**
     * STAG's A/B/C block status in words. X appears as a flag on vsp rows but
     * on no block sampled, so anything else is "other".
     */
    protected function blockKind(string $statut): string
    {
        return match ($statut) {
            'A' => 'required',
            'B' => 'elective',
            'C' => 'optional',
            default => 'other',
        };
    }

    /**
     * The plan a call targets: an explicit plan, a certificate's newest plan,
     * or (with neither) the caller's own. Returns an error message instead of
     * a plan when the id does not resolve.
     *
     * @param  (callable(): StudentRecord)|null  $ownRecord  null when the caller has no ticket
     *
     * @throws StagException
     */
    protected function resolvePlan(StudyPlanRepository $plans, ?int $planId, ?int $certificateId, ?callable $ownRecord): StudyPlan|string
    {
        if ($planId !== null && $certificateId !== null) {
            return 'Pass either stpl_id or certificate_id, not both.';
        }

        if ($certificateId !== null) {
            $planId = $plans->currentPlanForObor($certificateId);

            if ($planId === null) {
                return "No plan found for certificate_id {$certificateId}. Get a valid id from list-certifikaty.";
            }
        }

        if ($planId === null) {
            if ($ownRecord === null) {
                return StagException::notAuthorized()->getMessage().' Or pass stpl_id or certificate_id, which need no login.';
            }

            $planId = $ownRecord()->currentPlanId();

            if ($planId === null) {
                return 'STAG lists no study plan for this account. Pass stpl_id or certificate_id instead.';
            }
        }

        return $plans->plan($planId)
            ?? "No study plan found with stpl_id {$planId}. Use certificate_id from list-certifikaty, or omit both for your own plan.";
    }

    /**
     * `vsp/getStudent` demands an osCislo it then ignores, answering for the
     * ticket holder whatever is sent, so this only satisfies STAG and is never
     * reported back.
     *
     * @throws StagException
     */
    protected function resolveStudentOsCislo(StagHttpClient $stag): string
    {
        return $this->resolveOsCislo($stag)
            ?? throw new StagException('This account holds no student role in STAG, so it has no study plan of its own. Pass stpl_id or certificate_id instead.');
    }

    /**
     * @param  Slot  $slot
     * @return array<string, mixed>
     */
    protected function toSubject(array $slot): array
    {
        return [
            'code' => $slot['code'],
            'name' => $slot['name'],
            'credits' => $slot['credits'],
            'exam_type' => ExamType::labelFor($slot['exam_type']),
            'recommended_year' => $slot['recommended_year'],
            'recommended_semester' => $slot['recommended_semester'],
        ];
    }

    /**
     * @return array<string, Type>
     */
    protected function subjectSchema(JsonSchema $schema): array
    {
        $nullableString = fn () => $schema->anyOf([$schema->string()])->nullable();
        $nullableInteger = fn () => $schema->anyOf([$schema->integer()])->nullable();

        return [
            'code' => $schema->string()->description('Department and subject code, e.g. "KIV/PPA". Pass the two halves to get-predmet-info for detail.'),
            'name' => $nullableString()->description('Subject name in Czech.'),
            'credits' => $schema->integer()->description('ECTS credits. 0 for final and state exams.'),
            'exam_type' => $nullableString()->description('How it is finished, e.g. "exam (zkouška)", "credit (zápočet)", "final exam (závěrečná zkouška)".'),
            'recommended_year' => $nullableInteger()->description('Year of study the plan recommends taking it in.'),
            'recommended_semester' => $nullableString()->description('ZS (winter) or LS (summer).'),
        ];
    }
}
