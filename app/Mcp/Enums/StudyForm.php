<?php

namespace App\Mcp\Enums;

/**
 * STAG's `FORMA_OBORU_NEW` číselník: how the study is attended.
 *
 * Confirmed against `ciselniky/getCiselnik?domena=FORMA_OBORU_NEW`, which
 * returns exactly these three codes. `student/getStudentInfo` reports it as
 * `formaSp`. The Czech wording in the comments is STAG's own.
 */
enum StudyForm: string
{
    /* Prezenční */
    case FullTime = 'P';

    /* Kombinovaná */
    case Combined = 'K';

    /* Distanční */
    case Distance = 'D';

    public function label(): string
    {
        return match ($this) {
            self::FullTime => 'full-time',
            self::Combined => 'combined',
            self::Distance => 'distance',
        };
    }
}
