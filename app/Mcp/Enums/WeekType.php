<?php

namespace App\Mcp\Enums;

/**
 * STAG's `TYDEN` číselník codes, confirmed against
 * `ciselniky/getCiselnik?domena=TYDEN`.
 */
enum WeekType: string
{
    case Odd = 'L';
    case Even = 'S';
    case Every = 'K';
    case Other = 'J';

    public function label(): string
    {
        return match ($this) {
            self::Odd => 'odd',
            self::Even => 'even',
            self::Every => 'every',
            self::Other => 'other',
        };
    }
}
