<?php

use App\Services\Grades\GradeScales;
use Tests\Fixtures\GradesFixtures;

it('resolves a known grade to its name and pass flag', function () {
    $scales = GradeScales::fromPayload(GradesFixtures::typyHodnoceni());

    expect($scales->nameOfGrade(1))->toBe('Výborně')
        ->and($scales->isPass(1))->toBeTrue();
});

it('returns null for an unknown or null hodnidno', function () {
    $scales = GradeScales::fromPayload(GradesFixtures::typyHodnoceni());

    expect($scales->nameOfGrade(999))->toBeNull()
        ->and($scales->isPass(999))->toBeNull()
        ->and($scales->nameOfGrade(null))->toBeNull()
        ->and($scales->isPass(null))->toBeNull();
});

it('scores a numeric-scale grade at its own hodnotaDoPrumeru', function () {
    $scales = GradeScales::fromPayload(GradesFixtures::typyHodnoceni());

    // hodnidno 1 is "Výborně" on the 1|2|3|4 scale, worth 1.0.
    expect($scales->assessmentContributionToWeightedAverage(1, 1))->toBe(1.0);
});

it('excludes an S/N grade from the average even though a real grade exists', function () {
    $scales = GradeScales::fromPayload(GradesFixtures::typyHodnoceni());

    // hodnidno 5 is "Splněno" on the S|N scale, whose doPrumeru is false.
    expect($scales->assessmentContributionToWeightedAverage(5, 2))->toBeNull();
});

it('falls back to the scale default when no grade is recorded', function () {
    $scales = GradeScales::fromPayload(GradesFixtures::typyHodnoceni());

    // No hodnidno on the numeric scale (tyhoidno 1) scores its
    // nevyplnenoHodnotaDoPrumeru, 4.0 — this is what makes an unfinished
    // subject count as a 4.
    expect($scales->assessmentContributionToWeightedAverage(null, 1))->toBe(4.0);
});

it('has no default to fall back to on the pass/fail scale', function () {
    $scales = GradeScales::fromPayload(GradesFixtures::typyHodnoceni());

    // tyhoidno 2 (S|N) has nevyplnenoHodnotaDoPrumeru: null, so an unfinished
    // zápočet-only subject contributes nothing to any average.
    expect($scales->assessmentContributionToWeightedAverage(null, 2))->toBeNull();
});

it('returns null when neither a grade nor a scale is known', function () {
    $scales = GradeScales::fromPayload(GradesFixtures::typyHodnoceni());

    expect($scales->assessmentContributionToWeightedAverage(null, null))->toBeNull();
});

it('treats a malformed payload the same as an empty one instead of throwing', function (mixed $payload) {
    $scales = GradeScales::fromPayload($payload);

    expect($scales->nameOfGrade(1))->toBeNull()
        ->and($scales->isPass(1))->toBeNull()
        ->and($scales->assessmentContributionToWeightedAverage(1, 1))->toBeNull()
        ->and($scales->assessmentContributionToWeightedAverage(null, 1))->toBeNull();
})->with([null, 'not an array', 42, [[]]]);
