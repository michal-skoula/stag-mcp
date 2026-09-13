<?php

namespace App\Mcp\Enums;

/**
 * STAG's `STAV_STUDENTA` číselník: whether a study is running, paused or over.
 *
 * Confirmed against `ciselniky/getCiselnik?domena=STAV_STUDENTA`, which returns
 * exactly these three codes. The Czech wording in the comments is STAG's own.
 */
enum StudyState: string
{
    /* Studuje */
    case Active = 'S';

    /* Přerušil */
    case Interrupted = 'P';

    /* Nestuduje */
    case Ended = 'N';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'studying',
            self::Interrupted => 'interrupted',
            self::Ended => 'not studying',
        };
    }
}
