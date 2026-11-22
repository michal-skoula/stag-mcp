<?php

namespace App\Services\StudyPlans;

/**
 * Matches a student's subject rows against a plan's blocks: what is done,
 * what is under way, and what is still missing.
 *
 * Each row fills at most one slot, and each slot takes at most one row, so
 * `UTS/TV` passed twice fills both of its slots and `KIV/PRO`, listed in two
 * blocks, counts once. Rows are placed in three passes (earned, then in
 * progress, then booked for next year) so finished work gets first pick.
 * Among the free slots with a matching code, a row prefers:
 *
 * 1. the block status STAG itself gives the row (its `statut`), but only
 *    against the student's own plan, where that status means something;
 * 2. a block not yet satisfied, so a subject is not spent where it is no
 *    longer needed;
 * 3. A over B over C.
 *
 * It is a greedy fit, not an optimal assignment. On real plans a subject sits
 * in at most two blocks, where greedy and optimal agree.
 *
 * @phpstan-import-type Block from StudyPlan
 * @phpstan-import-type Slot from StudyPlan
 * @phpstan-import-type Row from StudentRecord
 *
 * @phpstan-type Fill array{state: string, credits: float}
 * @phpstan-type BlockResult array{
 *     block: Block,
 *     credits_earned: float,
 *     subjects_earned: int,
 *     credits_in_progress: float,
 *     credits_next_year: float,
 *     satisfied: bool,
 *     remaining_credits: ?float,
 *     remaining_subjects: ?int,
 *     earned: list<string>,
 *     in_progress: list<string>,
 *     next_year: list<string>,
 *     untaken: list<Slot>,
 * }
 * @phpstan-type Result array{
 *     blocks: list<BlockResult>,
 *     unmatched: list<Row>,
 *     eu_track: array{credits_earned: float, credits_in_progress: float, level1_met: bool}|null,
 * }
 */
final class PlanAudit
{
    /**
     * ZČU awards EU Track Level 1 for this many ECTS from the EU Track
     * subjects. STAG does not hold the rule (the blok says `minKred: 0`); it is
     * stated on https://www.zcu.cz/en/Partnership/EUPeace/EUTrack only.
     */
    public const int EU_TRACK_LEVEL1_CREDITS = 6;

    public const string EARNED = 'earned';

    public const string IN_PROGRESS = 'in_progress';

    public const string NEXT_YEAR = 'next_year';

    private const array STATUT_RANK = ['A' => 0, 'B' => 1, 'C' => 2];

    /** @var list<Block> */
    private array $blocks;

    /** @var array<int, array<int, Fill>> block index => slot index => fill */
    private array $fills = [];

    private function __construct(StudyPlan $plan, private readonly bool $ownPlan)
    {
        $this->blocks = $plan->blocks();
    }

    /**
     * @param  bool  $ownPlan  Whether this is the plan the student studies, as
     *                         opposed to a certificate or another plan they are
     *                         measuring themselves against.
     * @return Result
     */
    public static function run(StudyPlan $plan, StudentRecord $student, bool $ownPlan): array
    {
        return (new self($plan, $ownPlan))->audit($student->rows);
    }

    /**
     * @param  list<Row>  $rows
     * @return Result
     */
    private function audit(array $rows): array
    {
        $unmatched = [];

        $passes = [
            self::EARNED => fn (array $row) => $row['earned'],
            self::IN_PROGRESS => fn (array $row) => ! $row['earned'] && $row['in_progress'],
            self::NEXT_YEAR => fn (array $row) => ! $row['earned'] && ! $row['in_progress'] && $row['next_year'],
        ];

        foreach ($passes as $state => $belongs) {
            foreach (array_filter($rows, $belongs) as $row) {
                if (! $this->place($row, $state)) {
                    $unmatched[] = $row;
                }
            }
        }

        $results = [];

        foreach ($this->blocks as $index => $block) {
            $results[] = $this->summarise($index, $block);
        }

        return [
            'blocks' => $results,
            'unmatched' => $unmatched,
            'eu_track' => $this->euTrack($results),
        ];
    }

    /**
     * @param  Row  $row
     */
    private function place(array $row, string $state): bool
    {
        $candidates = [];

        foreach ($this->blocks as $blockIndex => $block) {
            foreach ($block['slots'] as $slotIndex => $slot) {
                if ($slot['code'] === $row['code'] && ! isset($this->fills[$blockIndex][$slotIndex])) {
                    $candidates[] = [
                        'block' => $blockIndex,
                        'slot' => $slotIndex,
                        'rank' => [
                            $this->ownPlan && $row['statut'] !== null && $row['statut'] !== $block['statut'] ? 1 : 0,
                            $this->isSatisfied($blockIndex, $block) ? 1 : 0,
                            self::STATUT_RANK[$block['statut']] ?? 3,
                            $blockIndex,
                        ],
                    ];
                }
            }
        }

        if ($candidates === []) {
            return false;
        }

        usort($candidates, fn (array $a, array $b) => $a['rank'] <=> $b['rank']);

        $this->fills[$candidates[0]['block']][$candidates[0]['slot']] = ['state' => $state, 'credits' => $row['credits']];

        return true;
    }

    /**
     * @param  Block  $block
     * @return BlockResult
     */
    private function summarise(int $index, array $block): array
    {
        $byState = [self::EARNED => [], self::IN_PROGRESS => [], self::NEXT_YEAR => []];
        $credits = [self::EARNED => 0.0, self::IN_PROGRESS => 0.0, self::NEXT_YEAR => 0.0];
        $untaken = [];

        foreach ($block['slots'] as $slotIndex => $slot) {
            $fill = $this->fills[$index][$slotIndex] ?? null;

            if ($fill === null) {
                $untaken[] = $slot;

                continue;
            }

            $byState[$fill['state']][] = $slot['code'];
            $credits[$fill['state']] += $fill['credits'];
        }

        return [
            'block' => $block,
            'credits_earned' => $credits[self::EARNED],
            'subjects_earned' => count($byState[self::EARNED]),
            'credits_in_progress' => $credits[self::IN_PROGRESS],
            'credits_next_year' => $credits[self::NEXT_YEAR],
            'satisfied' => $this->isSatisfied($index, $block),
            'remaining_credits' => $block['min_credits'] !== null ? max(0.0, $block['min_credits'] - $credits[self::EARNED]) : null,
            'remaining_subjects' => $block['min_subjects'] !== null ? max(0, $block['min_subjects'] - count($byState[self::EARNED])) : null,
            'earned' => $byState[self::EARNED],
            'in_progress' => $byState[self::IN_PROGRESS],
            'next_year' => $byState[self::NEXT_YEAR],
            'untaken' => $untaken,
        ];
    }

    /**
     * Met when every rule the block sets is met on earned work alone. A block
     * can set only credits, only a count, or neither: an all-required block
     * with no rule needs every slot, any other block with no rule (C bloks,
     * EU Track) is satisfied from the start.
     *
     * @param  Block  $block
     */
    private function isSatisfied(int $index, array $block): bool
    {
        $earned = array_filter($this->fills[$index] ?? [], fn (array $fill) => $fill['state'] === self::EARNED);
        $credits = array_sum(array_column($earned, 'credits'));

        if ($block['min_credits'] === null && $block['min_subjects'] === null) {
            return ! $block['all_required'] || count($earned) >= count($block['slots']);
        }

        return ($block['min_credits'] === null || $credits >= $block['min_credits'])
            && ($block['min_subjects'] === null || count($earned) >= $block['min_subjects']);
    }

    /**
     * @param  list<BlockResult>  $results
     * @return array{credits_earned: float, credits_in_progress: float, level1_met: bool}|null
     */
    private function euTrack(array $results): ?array
    {
        $euTrack = array_filter($results, fn (array $result) => $result['block']['eu_track']);

        if ($euTrack === []) {
            return null;
        }

        $earned = array_sum(array_column($euTrack, 'credits_earned'));

        return [
            'credits_earned' => (float) $earned,
            'credits_in_progress' => (float) array_sum(array_column($euTrack, 'credits_in_progress')),
            'level1_met' => $earned >= self::EU_TRACK_LEVEL1_CREDITS,
        ];
    }
}
