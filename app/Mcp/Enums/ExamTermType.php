<?php

namespace App\Mcp\Enums;

/**
 * What an exam term is for, keyed by the Czech label `getTerminyZkousek`
 * returns in `typTerminu`.
 *
 * STAG's `TYP_TERMINU` číselník has numeric codes (1, 2, 99), but the terms
 * endpoints only ever send the label, so the label is the key here.
 */
enum ExamTermType: string
{
    case Exam = 'Zkouška';
    case Credit = 'Zápočet';
    case CreditBeforeExam = 'Záp. před zk.';

    public function label(): string
    {
        return match ($this) {
            self::Exam => 'exam',
            self::Credit => 'credit',
            self::CreditBeforeExam => 'credit_before_exam',
        };
    }
}
