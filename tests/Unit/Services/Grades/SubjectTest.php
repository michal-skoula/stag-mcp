<?php

use App\Services\Grades\GradeScales;
use App\Services\Grades\Subject;
use Tests\Fixtures\GradesFixtures;

function subjectScales(): GradeScales
{
    return GradeScales::fromPayload(GradesFixtures::typyHodnoceni());
}

it('merges name and credits from the catalogue when the enrolment matches', function () {
    $row = GradesFixtures::znamka();
    $catalogue = GradesFixtures::catalogue([GradesFixtures::absolvoval()]);

    $subject = Subject::fromRow($row, $catalogue, subjectScales());

    expect($subject->toArray())
        ->toMatchArray(['nazev' => 'Databázové systémy 1', 'kredity' => 6]);
});

it('leaves name and credits null when the catalogue has no matching row', function () {
    $subject = Subject::fromRow(GradesFixtures::znamka(), [], subjectScales());

    expect($subject->toArray())->toMatchArray(['nazev' => null, 'kredity' => null])
        ->and($subject->credits())->toBeNull();
});

it('decodes the grade through the codebook rather than echoing the raw code', function () {
    $subject = Subject::fromRow(GradesFixtures::znamka(), [], subjectScales());

    expect($subject->toArray()['zkouska'])->toMatchArray([
        'grade' => '1',
        'grade_name' => 'Výborně',
        'is_pass' => true,
        'scale' => '1|2|3|4',
    ]);
});

it('normalises empty strings to null', function () {
    // zppzk_typ_hodnoceni is the empty string on the base fixture.
    $subject = Subject::fromRow(GradesFixtures::znamka(), [], subjectScales());

    expect($subject->toArray()['zapocet']['scale'])->toBeNull();
});

it('parses the padded dd.MM.yyyy date format to ISO', function () {
    $subject = Subject::fromRow(GradesFixtures::znamka(['zk_datum' => '19.01.2026']), [], subjectScales());

    expect($subject->toArray()['zkouska']['date'])->toBe('2026-01-19');
});

it('degrades an unparseable date to null instead of throwing', function () {
    $subject = Subject::fromRow(GradesFixtures::znamka(['zk_datum' => 'not a date']), [], subjectScales());

    expect($subject->toArray()['zkouska']['date'])->toBeNull();
});

it('omits the zkouska block entirely when every zk_ field is absent', function () {
    $row = GradesFixtures::znamka([
        'zk_hodnidno' => null,
        'zk_hodnoceni' => '',
        'zk_datum' => '',
        'zk_ucit_jmeno' => '',
        'zk_pokus' => '',
    ]);

    $subject = Subject::fromRow($row, [], subjectScales());

    expect($subject->toArray()['zkouska'])->toBeNull();
});

it('omits the zapocet block entirely when every zppzk_ field is absent', function () {
    $subject = Subject::fromRow(GradesFixtures::znamkaUngraded(), [], subjectScales());

    expect($subject->toArray()['zapocet'])->toBeNull();
});

it('reads status from the completion code and keeps the raw code', function () {
    $subject = Subject::fromRow(GradesFixtures::znamka(['stavAbsolvovani' => 'NZ']), [], subjectScales());

    expect($subject->toArray()['status'])->toMatchArray([
        'type' => 'failed',
        'code' => 'NZ',
    ])
        ->and($subject->toArray()['status']['label'])->toContain('nezískal zápočet');
});

it('falls back to unknown for a completion code outside the domain', function () {
    $subject = Subject::fromRow(GradesFixtures::znamka(['stavAbsolvovani' => 'ZZZ']), [], subjectScales());

    expect($subject->status())->toBe('unknown')
        ->and($subject->completionStatus())->toBeNull()
        ->and($subject->toArray()['status'])->toMatchArray([
            'type' => 'unknown',
            'code' => 'ZZZ',
            'label' => null,
        ]);
});

it('matches rok exactly', function () {
    $subject = Subject::fromRow(GradesFixtures::znamka(['rok' => '2025']), [], subjectScales());

    expect($subject->matchesSubjectFromParams('2025', null, null, null))->toBeTrue()
        ->and($subject->matchesSubjectFromParams('2026', null, null, null))->toBeFalse();
});

it('matches semestr exactly or against the wildcard', function () {
    $subject = Subject::fromRow(GradesFixtures::znamka(['semestr' => 'ZS']), [], subjectScales());

    expect($subject->matchesSubjectFromParams(null, 'ZS', null, null))->toBeTrue()
        ->and($subject->matchesSubjectFromParams(null, 'LS', null, null))->toBeFalse()
        ->and($subject->matchesSubjectFromParams(null, '%', null, null))->toBeTrue();
});

it('matches katedra and zkratka as case-insensitive substrings', function () {
    $subject = Subject::fromRow(GradesFixtures::znamka(['katedra' => 'KIV', 'zkratka' => 'DB1']), [], subjectScales());

    expect($subject->matchesSubjectFromParams(null, null, 'kiv', null))->toBeTrue()
        ->and($subject->matchesSubjectFromParams(null, null, null, 'db1'))->toBeTrue()
        ->and($subject->matchesSubjectFromParams(null, null, 'kma', null))->toBeFalse();
});

it('ranks a later year and LS above ZS in the same year', function () {
    $zs2025 = Subject::fromRow(GradesFixtures::znamka(['rok' => '2025', 'semestr' => 'ZS']), [], subjectScales());
    $ls2025 = Subject::fromRow(GradesFixtures::znamka(['rok' => '2025', 'semestr' => 'LS']), [], subjectScales());
    $zs2026 = Subject::fromRow(GradesFixtures::znamka(['rok' => '2026', 'semestr' => 'ZS']), [], subjectScales());

    expect($ls2025->academicPeriodSortRank())->toBeGreaterThan($zs2025->academicPeriodSortRank())
        ->and($zs2026->academicPeriodSortRank())->toBeGreaterThan($ls2025->academicPeriodSortRank());
});

it('sorts newest period first, alphabetical by subject within a period', function () {
    $dbZs2025 = Subject::fromRow(GradesFixtures::znamka(['katedra' => 'KIV', 'zkratka' => 'DB1', 'rok' => '2025', 'semestr' => 'ZS']), [], subjectScales());
    $ppaZs2025 = Subject::fromRow(GradesFixtures::znamka(['katedra' => 'KIV', 'zkratka' => 'PPA', 'rok' => '2025', 'semestr' => 'ZS']), [], subjectScales());
    $adtLs2025 = Subject::fromRow(GradesFixtures::znamka(['katedra' => 'KIV', 'zkratka' => 'ADT', 'rok' => '2025', 'semestr' => 'LS']), [], subjectScales());

    $subjects = [$dbZs2025, $ppaZs2025, $adtLs2025];
    usort($subjects, fn (Subject $a, Subject $b) => $a->sortKey() <=> $b->sortKey());

    expect(array_map(fn (Subject $s) => $s->zkratka, $subjects))->toBe(['ADT', 'DB1', 'PPA']);
});

it('is unfinished only when its status is failed', function () {
    $failed = Subject::fromRow(GradesFixtures::znamka(['stavAbsolvovani' => 'NX']), [], subjectScales());
    $passed = Subject::fromRow(GradesFixtures::znamka(['stavAbsolvovani' => 'A']), [], subjectScales());
    $inProgress = Subject::fromRow(GradesFixtures::znamka(['stavAbsolvovani' => 'S']), [], subjectScales());

    expect($failed->isUnfinished())->toBeTrue()
        ->and($passed->isUnfinished())->toBeFalse()
        ->and($inProgress->isUnfinished())->toBeFalse();
});

it('can be superseded only by a passed or recognised enrolment', function () {
    $passed = Subject::fromRow(GradesFixtures::znamka(['stavAbsolvovani' => 'A']), [], subjectScales());
    $recognised = Subject::fromRow(GradesFixtures::znamka(['stavAbsolvovani' => 'U']), [], subjectScales());
    $inProgress = Subject::fromRow(GradesFixtures::znamka(['stavAbsolvovani' => 'S']), [], subjectScales());
    $failed = Subject::fromRow(GradesFixtures::znamka(['stavAbsolvovani' => 'NX']), [], subjectScales());

    expect($passed->supersedesAnEarlierAttempt())->toBeTrue()
        ->and($recognised->supersedesAnEarlierAttempt())->toBeTrue()
        ->and($inProgress->supersedesAnEarlierAttempt())->toBeFalse()
        ->and($failed->supersedesAnEarlierAttempt())->toBeFalse();
});

it('records what superseded it and reflects that in toArray', function () {
    $earlier = Subject::fromRow(GradesFixtures::znamka(['rok' => '2025', 'semestr' => 'ZS', 'stavAbsolvovani' => 'NX']), [], subjectScales());
    $later = Subject::fromRow(GradesFixtures::znamka(['rok' => '2026', 'semestr' => 'ZS', 'stavAbsolvovani' => 'A']), [], subjectScales());

    expect($earlier->isSuperseded())->toBeFalse();

    $earlier->supersededBy($later);

    expect($earlier->isSuperseded())->toBeTrue()
        ->and($earlier->toArray()['status']['superseded_by'])->toBe('2026/ZS');
});

it('exposes the katedra/zkratka course key', function () {
    $subject = Subject::fromRow(GradesFixtures::znamka(['katedra' => 'KIV', 'zkratka' => 'DB1']), [], subjectScales());

    expect($subject->courseIdentifier())->toBe('KIV/DB1');
});
