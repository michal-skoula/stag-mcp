<?php

use App\Services\StudyPlans\StudentRecord;
use Tests\Fixtures\StudyPlanFixtures;

function studentRecord(?string $xml = null): StudentRecord
{
    return StudentRecord::fromXml(simplexml_load_string($xml ?? StudyPlanFixtures::bachelorStudentXml()));
}

it('reads the programme totals', function () {
    $record = studentRecord();

    expect($record->creditsRequired)->toBe(180)
        ->and($record->creditsEarned)->toBe(42.0)
        ->and($record->creditsRecognised)->toBe(0.0);
});

it('reads every year with its own plan, and picks the latest as current', function () {
    $record = studentRecord();

    expect($record->years)->toBe([
        ['year' => '2025', 'grade_level' => 1, 'plan_id' => 24735],
        ['year' => '2026', 'grade_level' => 2, 'plan_id' => 25230],
    ])
        ->and($record->currentPlanId())->toBe(25230)
        ->and($record->currentGradeLevel())->toBe(2)
        ->and($record->rows)->toHaveCount(10);
});

it('handles a first-year document holding exactly one of everything', function () {
    /* json_encode would turn the lone rokyStudia and predmety into objects here. */
    $record = studentRecord(StudyPlanFixtures::studentXml([
        ['2026', 1, 25230, [StudyPlanFixtures::row('KIV/PPA', 5, 'A', 'in_progress')]],
    ]));

    expect($record->years)->toHaveCount(1)
        ->and($record->rows)->toHaveCount(1)
        ->and($record->rows[0]['code'])->toBe('KIV/PPA')
        ->and($record->currentPlanId())->toBe(25230);
});

it('reads the string "false" as false', function () {
    $failed = studentRecord()->rows[2];

    expect($failed['code'])->toBe('KMA/DMA')
        ->and($failed['earned'])->toBeFalse()
        ->and($failed['in_progress'])->toBeFalse()
        ->and($failed['next_year'])->toBeFalse();
});

it('maps the row states', function () {
    $rows = collect(studentRecord()->rows)->keyBy(fn (array $row) => $row['code'].'@'.$row['year']);

    expect($rows['KIV/PPA@2025'])->toMatchArray(['earned' => true, 'credits' => 5.0, 'statut' => 'A', 'grade' => '1', 'semester' => 'ZS'])
        ->and($rows['KMA/DMA@2026'])->toMatchArray(['earned' => false, 'in_progress' => true, 'grade' => null])
        ->and($rows['KIV/UUR@2026'])->toMatchArray(['earned' => false, 'next_year' => true]);
});

it('counts a recognised subject as earned', function () {
    $row = studentRecord(StudyPlanFixtures::studentXml([
        ['2026', 1, 25230, [StudyPlanFixtures::row('KIV/PPA', 5, 'A', 'recognised')]],
    ]))->rows[0];

    expect($row['earned'])->toBeTrue()
        ->and($row['recognised'])->toBeTrue();
});

it('takes credits from the student row, not the nested subject', function () {
    expect(studentRecord()->rows[0]['credits'])->toBe(5.0);
});

it('keeps no identity fields', function () {
    $dump = json_encode(get_object_vars(studentRecord()));

    expect($dump)->not->toContain(StudyPlanFixtures::FAKE_OS_CISLO)
        ->not->toContain('fake@students.zcu.cz')
        ->not->toContain('999999');
});
