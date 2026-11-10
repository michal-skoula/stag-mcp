<?php

use App\Services\StudyPlans\StudyPlan;
use Tests\Fixtures\StudyPlanFixtures;

function bachelorPlan(): StudyPlan
{
    return StudyPlan::fromStag(StudyPlanFixtures::bachelorPlan(), StudyPlanFixtures::planInfo());
}

/**
 * @return array<string, mixed>
 */
function planBlock(StudyPlan $plan, string $name): array
{
    return collect($plan->blocks())->firstWhere('name', $name);
}

it('takes the name and semester count from getPlanInfo, since vsp has neither', function () {
    $plan = bachelorPlan()->toArray();

    expect($plan['id'])->toBe(25230)
        ->and($plan['name'])->toBe('Softwarové inženýrství')
        ->and($plan['semesters'])->toBe(6)
        ->and($plan['credit_limit'])->toBe(180)
        ->and($plan['valid_for'])->toBe('2026')
        ->and($plan['description_url'])->toBe('https://www.kiv.zcu.cz/cs/Education/bc-sw.html');
});

it('still parses the plan when getPlanInfo was refused', function () {
    $plan = StudyPlan::fromStag(StudyPlanFixtures::bachelorPlan(), [])->toArray();

    expect($plan['name'])->toBeNull()
        ->and($plan['semesters'])->toBeNull()
        ->and($plan['blocks'])->toHaveCount(7);
});

it('flattens segments into blocks, keeping the segment name', function () {
    $blocks = bachelorPlan()->blocks();

    expect(array_column($blocks, 'name'))->toBe([
        'Povinné předměty', 'Státní závěrečná zkouška', 'Skupina 2 - Odborné předměty',
        'Skupina 6 - Doporučené předměty', 'Cizí jazyk - povinný', 'Tělesná výchova', 'EU Track-Level1',
    ])
        ->and($blocks[4]['segment'])->toBe('FAV bc. - společná neoborová výuka');
});

it('builds codes from pracZkr and zkrPredm, never from the display markup', function () {
    $slot = planBlock(bachelorPlan(), 'Povinné předměty')['slots'][1];

    expect($slot['code'])->toBe('KIV/DB1')
        ->and($slot['excludes'])->toBe(['KIV/DB1-E'])
        ->and(json_encode(bachelorPlan()->toArray()))->not->toContain('<span>');
});

it('keeps a subject listed twice in one block as two slots', function () {
    $codes = array_column(planBlock(bachelorPlan(), 'Tělesná výchova')['slots'], 'code');

    expect($codes)->toBe(['UTS/TV', 'UTS/TV', 'UTS/ZLK']);
});

it('tells all-required blocks from pick-some blocks', function (string $name, bool $allRequired) {
    expect(planBlock(bachelorPlan(), $name)['all_required'])->toBe($allRequired);
})->with([
    'A, count covers every slot' => ['Povinné předměty', true],
    'A, one slot' => ['Státní závěrečná zkouška', true],
    'A, one of two is enough' => ['Cizí jazyk - povinný', false],
    'B' => ['Skupina 2 - Odborné předměty', false],
    'C' => ['EU Track-Level1', false],
]);

it('reads a zero credit minimum as no credit rule', function () {
    $euTrack = planBlock(bachelorPlan(), 'EU Track-Level1');

    expect($euTrack['min_credits'])->toBeNull()
        ->and($euTrack['eu_track'])->toBeTrue()
        ->and(planBlock(bachelorPlan(), 'Tělesná výchova')['eu_track'])->toBeFalse();
});

it('maps the subject detail a reader needs', function () {
    $stateExam = planBlock(bachelorPlan(), 'Státní závěrečná zkouška')['slots'][0];
    $noYear = planBlock(bachelorPlan(), 'Skupina 6 - Doporučené předměty')['slots'][1];

    expect($stateExam)->toMatchArray([
        'code' => 'KIV/BZSWI', 'name' => 'Předmět BZSWI', 'credits' => 0, 'exam_type' => '10',
        'hours' => '2+2+0', 'recommended_year' => 3, 'recommended_semester' => 'ZS', 'state_exam' => true,
        'prerequisites' => [],
    ])
        ->and($noYear['recommended_year'])->toBeNull();
});

it('survives a round trip through the cache shape', function () {
    $plan = bachelorPlan();

    expect(StudyPlan::fromArray($plan->toArray())->toArray())->toBe($plan->toArray());
});
