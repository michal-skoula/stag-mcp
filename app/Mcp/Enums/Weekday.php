<?php

namespace App\Mcp\Enums;

/**
 * STAG's short Czech weekday codes, as used in `kalendar/getKalendarRoku`'s
 * `rozvrhDen` and `rozvrhy/getRozvrhByStudent`'s `denZkr`. Does not cover
 * `rozvrhDen`'s non-weekday codes (`Sv`, `Rd`); callers filter those out
 * before reaching this enum.
 */
enum Weekday: string
{
    case Monday = 'Po';
    case Tuesday = 'Út';
    case Wednesday = 'St';
    case Thursday = 'Čt';
    case Friday = 'Pá';
    case Saturday = 'So';
    case Sunday = 'Ne';

    public function label(): string
    {
        return match ($this) {
            self::Monday => 'Monday',
            self::Tuesday => 'Tuesday',
            self::Wednesday => 'Wednesday',
            self::Thursday => 'Thursday',
            self::Friday => 'Friday',
            self::Saturday => 'Saturday',
            self::Sunday => 'Sunday',
        };
    }
}
