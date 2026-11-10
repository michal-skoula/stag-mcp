<?php

use App\Services\StudyPlans\PlanAudit;
use App\Services\StudyPlans\StudentRecord;
use App\Services\StudyPlans\StudyPlan;
use Tests\Fixtures\StudyPlanFixtures as F;

/**
 * @param  list<array<string, string>>  $rows
 */
function recordOf(array $rows): StudentRecord
{
    return StudentRecord::fromXml(simplexml_load_string(F::studentXml([['2026', 2, 25230, $rows]])));
}

/**
 * @return array<string, mixed>
 */
function auditOf(?StudentRecord $record = null, ?array $plan = null, bool $ownPlan = true): array
{
    return PlanAudit::run(
        StudyPlan::fromStag($plan ?? F::bachelorPlan(), F::planInfo()),
        $record ?? StudentRecord::fromXml(simplexml_load_string(F::bachelorStudentXml())),
        $ownPlan,
    );
}

/**
 * @return array<string, mixed>
 */
function auditBlock(array $audit, string $name): array
{
    return collect($audit['blocks'])->first(fn (array $result) => $result['block']['name'] === $name);
}

it('audits the bachelor student block by block', function () {
    $audit = auditOf();
    $required = auditBlock($audit, 'Povinné předměty');

    expect($required)->toMatchArray([
        'credits_earned' => 11.0,
        'subjects_earned' => 2,
        'credits_in_progress' => 4.0,
        'satisfied' => false,
        'remaining_credits' => 4.0,
        'remaining_subjects' => 1,
        'earned' => ['KIV/PPA', 'KIV/DB1'],
        'in_progress' => ['KMA/DMA'],
        'untaken' => [],
    ])
        ->and(auditBlock($audit, 'Skupina 2 - Odborné předměty'))->toMatchArray([
            'credits_earned' => 4.0, 'credits_next_year' => 4.0, 'next_year' => ['KIV/UUR'], 'remaining_credits' => 6.0,
        ])
        ->and(array_column(auditBlock($audit, 'Státní závěrečná zkouška')['untaken'], 'code'))->toBe(['KIV/BZSWI']);
});

it('ignores a failed attempt and counts the retake where it stands', function () {
    $required = auditBlock(auditOf(), 'Povinné předměty');

    /* DMA failed in 2025 and is in progress in 2026: in progress once, never earned. */
    expect($required['in_progress'])->toBe(['KMA/DMA'])
        ->and($required['earned'])->not->toContain('KMA/DMA');
});

it('lets a subject listed twice in one block count twice', function () {
    $pe = auditBlock(auditOf(), 'Tělesná výchova');

    expect($pe['earned'])->toBe(['UTS/TV', 'UTS/TV'])
        ->and($pe['credits_earned'])->toBe(2.0)
        ->and($pe['satisfied'])->toBeTrue()
        ->and(array_column($pe['untaken'], 'code'))->toBe(['UTS/ZLK']);
});

it('counts a subject listed in two blocks only once, preferring the elective block', function () {
    $audit = auditOf(recordOf([F::row('KIV/PRO', 5, 'B', 'earned')]));

    expect(auditBlock($audit, 'Skupina 2 - Odborné předměty')['earned'])->toBe(['KIV/PRO'])
        ->and(auditBlock($audit, 'Skupina 6 - Doporučené předměty')['earned'])->toBe([]);
});

it('follows the status STAG gives the row on the student\'s own plan', function () {
    $audit = auditOf(recordOf([F::row('KIV/PRO', 5, 'C', 'earned')]));

    expect(auditBlock($audit, 'Skupina 6 - Doporučené předměty')['earned'])->toBe(['KIV/PRO'])
        ->and(auditBlock($audit, 'Skupina 2 - Odborné předměty')['earned'])->toBe([]);
});

it('ignores the row status against a plan that is not the student\'s own', function () {
    $audit = auditOf(recordOf([F::row('KIV/PRO', 5, 'C', 'earned')]), ownPlan: false);

    expect(auditBlock($audit, 'Skupina 2 - Odborné předměty')['earned'])->toBe(['KIV/PRO']);
});

it('spends a subject on a block that still needs it', function () {
    $plan = F::plan(['Test' => [
        F::block(1, 'Pick one', 'A', null, 1, [F::slot('X/ONE', 5), F::slot('X/TWO', 5)]),
        F::block(2, 'Electives', 'B', 5, null, [F::slot('X/TWO', 5)]),
    ]]);

    $audit = auditOf(recordOf([F::row('X/ONE', 5, 'A', 'earned'), F::row('X/TWO', 5, 'A', 'earned')]), $plan, ownPlan: false);

    /* A is met by X/ONE, so X/TWO goes where it is still needed even though A outranks B. */
    expect(auditBlock($audit, 'Pick one')['earned'])->toBe(['X/ONE'])
        ->and(auditBlock($audit, 'Electives')['earned'])->toBe(['X/TWO']);
});

it('reports subjects that fit no slot', function () {
    expect(array_column(auditOf()['unmatched'], 'code'))->toBe(['KIV/OKS']);
});

it('never counts in-progress credits towards a rule', function () {
    $language = auditBlock(auditOf(), 'Cizí jazyk - povinný');

    expect($language['credits_in_progress'])->toBe(4.0)
        ->and($language['satisfied'])->toBeFalse()
        ->and(array_column($language['untaken'], 'code'))->toBe(['UJP/NEP6']);
});

it('meets a block by whichever rules it sets', function (array $block, array $rows, bool $satisfied) {
    $plan = F::plan(['Test' => [$block]]);

    expect(auditOf(recordOf($rows), $plan, ownPlan: false)['blocks'][0]['satisfied'])->toBe($satisfied);
})->with([
    'credits only, met' => [F::block(1, 'B', 'B', 4, null, [F::slot('X/A', 2), F::slot('X/B', 2), F::slot('X/C', 2)]), [F::row('X/A', 2, 'B', 'earned'), F::row('X/B', 2, 'B', 'earned')], true],
    'credits only, short' => [F::block(1, 'B', 'B', 4, null, [F::slot('X/A', 2), F::slot('X/B', 2)]), [F::row('X/A', 2, 'B', 'earned')], false],
    'count only, met' => [F::block(1, 'A', 'A', null, 1, [F::slot('X/A', 2), F::slot('X/B', 2)]), [F::row('X/B', 2, 'A', 'earned')], true],
    'both, credits short' => [F::block(1, 'A', 'A', 6, 1, [F::slot('X/A', 2), F::slot('X/B', 4)]), [F::row('X/A', 2, 'A', 'earned')], false],
    'all required, no rule, one missing' => [F::block(1, 'A', 'A', null, null, [F::slot('X/A', 2), F::slot('X/B', 2)]), [F::row('X/A', 2, 'A', 'earned')], false],
    'all required, no rule, complete' => [F::block(1, 'A', 'A', null, null, [F::slot('X/A', 2), F::slot('X/B', 2)]), [F::row('X/A', 2, 'A', 'earned'), F::row('X/B', 2, 'A', 'earned')], true],
    'optional, nothing taken' => [F::block(1, 'C', 'C', 0, null, [F::slot('X/A', 2)]), [], true],
]);

it('tracks EU Track Level 1 against the 6 credits ZČU requires', function (array $rows, float $earned, bool $met) {
    $euTrack = auditOf(recordOf($rows))['eu_track'];

    expect($euTrack['credits_earned'])->toBe($earned)
        ->and($euTrack['level1_met'])->toBe($met);
})->with([
    'none' => [[], 0.0, false],
    'five' => [[F::row('REK/CHIN1', 2, 'C', 'earned'), F::row('REK/ICPD', 3, 'C', 'earned')], 5.0, false],
    'eight' => [[F::row('REK/CHIN1', 2, 'C', 'earned'), F::row('REK/ICPD', 3, 'C', 'earned'), F::row('REK/BH', 3, 'C', 'earned')], 8.0, true],
]);

it('has no EU Track summary for a plan without an EU Track block', function () {
    expect(auditOf(plan: F::certificatePlan(), ownPlan: false)['eu_track'])->toBeNull();
});

it('keeps a certificate unfinished until its final exam is passed', function () {
    $coursework = [
        F::row('KIV/ASWI', 6, 'A', 'earned'), F::row('KPM/PPP', 4, 'B', 'earned'),
        F::row('KPO/ZPIT2', 3, 'C', 'earned'), F::row('KIV/EITM', 6, 'B', 'earned'), F::row('KPM/ZAP', 4, 'B', 'earned'),
    ];

    $before = auditBlock(auditOf(recordOf($coursework), F::certificatePlan(), ownPlan: false), 'Povinné předměty');
    $after = auditBlock(auditOf(recordOf([...$coursework, F::row('KIV/PIZZ', 0, 'A', 'earned')]), F::certificatePlan(), ownPlan: false), 'Povinné předměty');

    /* 13 credits are already there; only the 0-credit exam is missing. */
    expect($before['credits_earned'])->toBe(13.0)
        ->and($before['satisfied'])->toBeFalse()
        ->and(array_column($before['untaken'], 'code'))->toBe(['KIV/PIZZ'])
        ->and($after['satisfied'])->toBeTrue();
});
