<?php

namespace App\Mcp\Enums;

/**
 * STAG's `typRozvrhDne`/`obdobi` period codes (`kalendar` and `rozvrhy`
 * namespaces). Not available from `ciselniky/getCiselnik` (that endpoint
 * 500s regardless of parameter name tried), so these are hardcoded from
 * observed values cross-checked against `getHarmonogramRoku`'s Czech
 * descriptions, e.g. "ZR" runs from "Začátek ak. roku" through "Začíná:
 * Zimní semestr".
 */
enum CalendarPeriod: string
{
    case WinterPreparation = 'ZR';
    case WinterSemester = 'ZS';
    case WinterExamPeriod = 'ZZ';
    case WinterBreak = 'ZP';
    case SummerPreparation = 'LR';
    case SummerSemester = 'LS';
    case SummerExamPeriod = 'LZ';
    case SummerBreak = 'LP';

    public function label(): string
    {
        return match ($this) {
            self::WinterPreparation => 'Winter semester preparation',
            self::WinterSemester => 'Winter semester',
            self::WinterExamPeriod => 'Winter exam period',
            self::WinterBreak => 'Winter break',
            self::SummerPreparation => 'Summer semester preparation',
            self::SummerSemester => 'Summer semester',
            self::SummerExamPeriod => 'Summer exam period',
            self::SummerBreak => 'Summer break',
        };
    }

    /**
     * Only the two in-semester codes carry actual classes; the rest are
     * preparation weeks, exam periods, or breaks.
     */
    public function isTeachingPeriod(): bool
    {
        return $this === self::WinterSemester || $this === self::SummerSemester;
    }
}
