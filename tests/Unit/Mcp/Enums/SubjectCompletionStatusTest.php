<?php

use App\Mcp\Enums\SubjectCompletionStatus;

it('covers every code in the STAV_ABSOLVOVANI domain', function () {
    $codes = array_map(fn (SubjectCompletionStatus $c) => $c->value, SubjectCompletionStatus::cases());

    // The full domain as ciselniky/getCiselnik?domena=STAV_ABSOLVOVANI returns it.
    expect($codes)->toEqualCanonicalizing(['A', 'N', 'NX', 'NZ', 'NP', 'NPX', 'NU', 'U', 'UP', 'O', 'R', 'S', 'Z']);
});

it('gives every case a status and a label', function (SubjectCompletionStatus $case) {
    expect($case->status())->not->toBeEmpty()
        ->and($case->label())->not->toBeEmpty();
})->with(SubjectCompletionStatus::cases());

it('returns null for a code outside the domain', function () {
    expect(SubjectCompletionStatus::tryFrom('ZZZ'))->toBeNull();
});

it('separates a failure with a grade on record from one without', function () {
    // N and NX are both failures, but only N has a grade recorded. Treating
    // them alike would overwrite a real grade with a substituted 4.
    expect(SubjectCompletionStatus::FailedWithGrade->hasRecordedGrade())->toBeTrue()
        ->and(SubjectCompletionStatus::FailedWithoutGrade->hasRecordedGrade())->toBeFalse()
        ->and(SubjectCompletionStatus::FailedWithGrade->status())->toBe('failed')
        ->and(SubjectCompletionStatus::FailedWithoutGrade->status())->toBe('failed');
});

it('counts only concluded enrolments toward the average', function (SubjectCompletionStatus $case, bool $counts) {
    expect($case->countsTowardAverage())->toBe($counts);
})->with([
    'passed' => [SubjectCompletionStatus::Passed, true],
    'failed, graded' => [SubjectCompletionStatus::FailedWithGrade, true],
    'failed, ungraded' => [SubjectCompletionStatus::FailedWithoutGrade, true],
    'no zápočet' => [SubjectCompletionStatus::FailedWithoutZapocet, true],
    'still studying' => [SubjectCompletionStatus::InProgress, false],
    'enrolled next year' => [SubjectCompletionStatus::EnrolledNextYear, false],
    'recognised' => [SubjectCompletionStatus::Recognised, false],
    'transferred' => [SubjectCompletionStatus::TransferredWithGrade, false],
    'deferred at interruption' => [SubjectCompletionStatus::DeferredInterrupted, false],
    'deferred for parenthood' => [SubjectCompletionStatus::DeferredParenthood, false],
]);

it('treats recognition as a pass but a deferral as neither', function () {
    expect(SubjectCompletionStatus::Recognised->isPassed())->toBeTrue()
        ->and(SubjectCompletionStatus::RecognisedTransferred->isPassed())->toBeTrue()
        ->and(SubjectCompletionStatus::Passed->isPassed())->toBeTrue()
        ->and(SubjectCompletionStatus::DeferredInterrupted->isPassed())->toBeFalse()
        ->and(SubjectCompletionStatus::FailedWithoutGrade->isPassed())->toBeFalse();
});

it('groups the three recognition codes under one status', function () {
    expect(SubjectCompletionStatus::Recognised->status())->toBe('recognised')
        ->and(SubjectCompletionStatus::RecognisedTransferred->status())->toBe('recognised')
        ->and(SubjectCompletionStatus::RecognisedNotCompleted->status())->toBe('recognised');
});
