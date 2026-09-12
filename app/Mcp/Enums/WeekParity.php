<?php

namespace App\Mcp\Enums;

/**
 * STAG's `TYDEN` číselník codes: J/K/L/S (Jiný, Každý, Lichý, Sudý). Used
 * per-day in `kalendar/getKalendarRoku`'s `typTydne` and per-occurrence in
 * `rozvrhy/getRozvrhByStudent`'s `tydenZkr`.
 */
enum WeekParity: string
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
