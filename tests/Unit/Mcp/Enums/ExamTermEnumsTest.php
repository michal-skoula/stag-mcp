<?php

use App\Mcp\Enums\ExamTermAttempts;
use App\Mcp\Enums\ExamTermStatus;
use App\Mcp\Enums\ExamTermType;

it('covers every code in the TERMINY_KDO domain', function () {
    // The full domain as ciselniky/getCiselnik?domena=TERMINY_KDO returns it.
    expect(array_column(ExamTermAttempts::cases(), 'value'))->toEqualCanonicalizing(['N', 'A', '1', '1a2', '2', '3']);
});

it('covers every code in the PLATNOST_TERMINU domain', function () {
    expect(array_column(ExamTermStatus::cases(), 'value'))->toEqualCanonicalizing(['A', 'N', 'B']);
});

it('reads the term type from the Czech label STAG sends', function (string $label, string $type) {
    expect(ExamTermType::from($label)->label())->toBe($type);
})->with([
    ['Zkouška', 'exam'],
    ['Zápočet', 'credit'],
    ['Záp. před zk.', 'credit_before_exam'],
]);
