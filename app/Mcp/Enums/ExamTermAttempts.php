<?php

namespace App\Mcp\Enums;

/**
 * STAG's `TERMINY_KDO` číselník: which attempts may register for a term.
 *
 * This is what `getTerminyZkousek` sends as `opravny`, so despite the name it
 * is not a yes/no "resit term" flag. Sourced from
 * `ciselniky/getCiselnik?domena=TERMINY_KDO`.
 */
enum ExamTermAttempts: string
{
    case All = 'N';
    case RetakesOnly = 'A';
    case FirstOnly = '1';
    case FirstAndSecond = '1a2';
    case SecondOnly = '2';
    case ThirdOnly = '3';

    public function label(): string
    {
        return match ($this) {
            self::All => 'all',
            self::RetakesOnly => 'retakes_only',
            self::FirstOnly => 'first_attempt_only',
            self::FirstAndSecond => 'first_and_second_attempt',
            self::SecondOnly => 'second_attempt_only',
            self::ThirdOnly => 'third_attempt_only',
        };
    }
}
