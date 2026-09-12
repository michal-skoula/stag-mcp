<?php

use App\Services\Grades\GradeScales;
use App\Services\Grades\StudyRecord;
use Tests\Fixtures\GradesFixtures;

/**
 * @param  list<array<string, mixed>>  $znamky
 * @param  list<array<string, mixed>>  $absolvoval
 */
function buildStudyRecord(array $znamky, array $absolvoval): StudyRecord
{
    return StudyRecord::build(
        $znamky,
        GradesFixtures::catalogue($absolvoval),
        GradeScales::fromPayload(GradesFixtures::typyHodnoceni()),
    );
}

it('builds an empty record from nothing', function () {
    $record = buildStudyRecord([], []);

    expect($record->isEmpty())->toBeTrue()
        ->and($record->count())->toBe(0);
});

it('orders subjects newest period first', function () {
    $znamky = [
        GradesFixtures::znamka(['katedra' => 'KIV', 'zkratka' => 'DB1', 'rok' => '2025', 'semestr' => 'ZS']),
        GradesFixtures::znamka(['katedra' => 'KIV', 'zkratka' => 'ADT', 'rok' => '2025', 'semestr' => 'LS']),
    ];

    $record = buildStudyRecord($znamky, []);

    expect(array_map(fn ($s) => $s->zkratka, $record->subjects()))->toBe(['ADT', 'DB1']);
});

it('supersedes an earlier failure once the subject is retaken and passed', function () {
    $znamky = [
        GradesFixtures::znamka(['katedra' => 'KMA', 'zkratka' => 'MA1', 'rok' => '2025', 'semestr' => 'ZS', 'stavAbsolvovani' => 'NX', 'zk_hodnidno' => null]),
        GradesFixtures::znamka(['katedra' => 'KMA', 'zkratka' => 'MA1', 'rok' => '2026', 'semestr' => 'ZS', 'stavAbsolvovani' => 'A', 'zk_hodnidno' => 2]),
    ];

    $record = buildStudyRecord($znamky, []);

    $earlier = collect($record->subjects())->firstWhere('rok', '2025');

    expect($earlier->toArray()['status']['superseded_by'])->toBe('2026/ZS')
        ->and($record->summary()['retaken'])->toBe(1);
});

it('does not supersede a failure whose retake is only in progress', function () {
    $znamky = [
        GradesFixtures::znamka(['katedra' => 'KMA', 'zkratka' => 'MA1', 'rok' => '2025', 'semestr' => 'ZS', 'stavAbsolvovani' => 'NX', 'zk_hodnidno' => null]),
        GradesFixtures::znamkaUngraded(['katedra' => 'KMA', 'zkratka' => 'MA1', 'rok' => '2026', 'semestr' => 'ZS', 'stavAbsolvovani' => 'S']),
    ];

    $record = buildStudyRecord($znamky, []);

    $earlier = collect($record->subjects())->firstWhere('rok', '2025');

    expect($earlier->toArray()['status']['superseded_by'])->toBeNull()
        ->and($record->summary()['retaken'])->toBe(0);
});

it('supersedes an earlier failure when the retake is recognised rather than passed', function () {
    $znamky = [
        GradesFixtures::znamka(['katedra' => 'KMA', 'zkratka' => 'MA1', 'rok' => '2025', 'semestr' => 'ZS', 'stavAbsolvovani' => 'NX', 'zk_hodnidno' => null]),
        GradesFixtures::znamka(['katedra' => 'KMA', 'zkratka' => 'MA1', 'rok' => '2026', 'semestr' => 'ZS', 'stavAbsolvovani' => 'U']),
    ];

    $record = buildStudyRecord($znamky, []);

    $earlier = collect($record->subjects())->firstWhere('rok', '2025');

    expect($earlier->toArray()['status']['superseded_by'])->toBe('2026/ZS');
});

it("reproduces the printed transcript's weighted average of 2.05", function () {
    [$znamky, $absolvoval] = GradesFixtures::firstYear();

    $summary = buildStudyRecord($znamky, $absolvoval)->summary();

    // 84 weighted points over 41 credits across 8 numeric-scale subjects,
    // exactly as the FAV transcript states. This is the load-bearing
    // assertion for the whole service: it proves the GPA formula, not
    // arithmetic anyone did by hand.
    expect($summary['gpa_official'])->toMatchArray(['value' => 2.05, 'credits' => 41, 'subjects' => 8])
        ->and($summary['passed'])->toBe(11)
        ->and($summary['failed'])->toBe(3)
        ->and($summary['credits_earned'])->toBe(42)
        ->and($summary['credits_enrolled'])->toBe(55)
        ->and($summary['uncredited'])->toBe(0);
});

it('reports a higher passed-only average over fewer credits', function () {
    [$znamky, $absolvoval] = GradesFixtures::firstYear();

    $summary = buildStudyRecord($znamky, $absolvoval)->summary();

    // 40 weighted points over 30 credits: the three unfinished KMA subjects
    // drop out entirely, unlike gpa_official which scores them a 4.
    expect($summary['gpa_passed_only'])->toMatchArray(['value' => 1.33, 'credits' => 30, 'subjects' => 6]);
});

it('excludes a failed zapocet-only subject from the average on scale, not status', function () {
    [$znamky, $absolvoval] = GradesFixtures::firstYear();

    $summary = buildStudyRecord($znamky, $absolvoval)->summary();

    // KMA/SMP was never completed (NX) but is zápočet-only (S|N, doPrumeru
    // false). Were it counted, the denominator would be 43, not 41.
    expect($summary['gpa_official']['credits'])->toBe(41);
});

it('excludes a passed subject from both credits and both averages when the catalogue does not list it', function () {
    // A real, observed STAG condition: getZnamkyByStudent and
    // getStudentPredmetyAbsolvoval can disagree on what exists, so an
    // enrolment can have a grade with no matching catalogue row at all —
    // not just an empty catalogue overall, exercised with the passed spec's
    // (real) sibling row still present.
    [$znamky, $absolvoval] = GradesFixtures::firstYear();

    // Drop KIV/DB1's catalogue row (passed, 6 credits, grade 1) while its
    // znamky row stays, simulating the catalogue endpoint not listing it.
    $absolvoval = array_values(array_filter(
        $absolvoval,
        fn (array $row) => ! ($row['katedra'] === 'KIV' && $row['zkratka'] === 'DB1'),
    ));

    $summary = buildStudyRecord($znamky, $absolvoval)->summary();

    // DB1 still counts as passed (that comes from stavAbsolvovani, not the
    // catalogue), but contributes no credits and drops out of the GPA
    // denominator entirely rather than silently landing as a smaller number.
    expect($summary['uncredited'])->toBe(1)
        ->and($summary['passed'])->toBe(11)
        ->and($summary['credits_earned'])->toBe(36)
        ->and($summary['credits_enrolled'])->toBe(49)
        ->and($summary['gpa_official'])->toMatchArray(['credits' => 35, 'subjects' => 7]);
});

it('drops a retaken subject from the official average once it is passed', function () {
    [$znamky, $absolvoval] = GradesFixtures::firstYear();

    $znamky[] = GradesFixtures::znamka([
        'katedra' => 'KMA', 'zkratka' => 'MA1', 'rok' => '2026', 'semestr' => 'ZS',
        'zk_tyhoidno' => 1, 'zk_hodnidno' => 2, 'zk_hodnoceni' => '2', 'stavAbsolvovani' => 'A',
    ]);
    $absolvoval[] = GradesFixtures::absolvoval([
        'katedra' => 'KMA', 'zkratka' => 'MA1', 'rok' => '2026', 'semestr' => 'ZS',
        'nazevPredmetu' => 'Matematická analýza 1', 'pocetKreditu' => 6, 'znamka' => '2',
    ]);

    $summary = buildStudyRecord($znamky, $absolvoval)->summary();

    // Without supersession MA1 would score both a 4 and a 2: (84+12)/47 = 2.04.
    // Dropping the 2025 failure gives (84-24+12)/(41-6+6) = 72/41 = 1.76.
    expect($summary['gpa_official']['value'])->toBe(1.76)
        ->and($summary['retaken'])->toBe(1);
});

it('buckets recognised, transferred, deferred and enrolled_next_year subjects as other', function () {
    $znamky = [
        GradesFixtures::znamka(['stavAbsolvovani' => 'U']),
        GradesFixtures::znamka(['stavAbsolvovani' => 'NP']),
        GradesFixtures::znamka(['stavAbsolvovani' => 'O']),
        GradesFixtures::znamka(['stavAbsolvovani' => 'Z']),
    ];

    $summary = buildStudyRecord($znamky, [])->summary();

    expect($summary['other'])->toBe(4)
        ->and($summary['passed'])->toBe(0)
        ->and($summary['failed'])->toBe(0)
        ->and($summary['in_progress'])->toBe(0);
});

it('keeps a superseded flag intact after filtering to a narrower year', function () {
    [$znamky, $absolvoval] = GradesFixtures::firstYear();

    $znamky[] = GradesFixtures::znamka([
        'katedra' => 'KMA', 'zkratka' => 'MA1', 'rok' => '2026', 'semestr' => 'ZS',
        'zk_tyhoidno' => 1, 'zk_hodnidno' => 2, 'zk_hodnoceni' => '2', 'stavAbsolvovani' => 'A',
    ]);
    $absolvoval[] = GradesFixtures::absolvoval([
        'katedra' => 'KMA', 'zkratka' => 'MA1', 'rok' => '2026', 'semestr' => 'ZS',
        'nazevPredmetu' => 'Matematická analýza 1', 'pocetKreditu' => 6, 'znamka' => '2',
    ]);

    $record = buildStudyRecord($znamky, $absolvoval);
    $narrowed = $record->filter('2025', 'ZS', null, null);

    // Asking about 2025 alone must still know the 2026 pass supersedes MA1 —
    // the flag was computed over the full history before filtering.
    $ma1 = collect($narrowed->subjects())->first(fn ($s) => $s->zkratka === 'MA1');

    expect($ma1->toArray()['status']['superseded_by'])->toBe('2026/ZS');
});

it('recomputes the summary over only the filtered subjects', function () {
    [$znamky, $absolvoval] = GradesFixtures::firstYear();

    $record = buildStudyRecord($znamky, $absolvoval);
    $narrowed = $record->filter('2025', 'ZS', null, null);

    // 8 subjects in 2025/ZS: 4 KIV passed, 3 KMA failed, 1 UTS passed.
    expect($narrowed->count())->toBe(8)
        ->and($narrowed->summary()['passed'])->toBe(5)
        ->and($narrowed->summary()['failed'])->toBe(3);
});
