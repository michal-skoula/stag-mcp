<?php

namespace App\Mcp\Enums;

/**
 * The degree level of a study programme, from `student/getStudentInfo`'s `typSp`.
 *
 * Unlike the other enums here, no číselník backs this one: `TYP_STUDIA` is a
 * different thing entirely (it classifies the school, 1 = Vysoká škola), and no
 * other domain in `ciselniky/getSeznamDomen` carries these codes. Only `B` and
 * `D` were seen live; `N` and `M` follow the standard Czech convention, so treat
 * them as reasoned rather than observed and keep every call site on `tryFrom()`.
 */
enum StudyProgrammeType: string
{
    /** Bakalář */
    case Bachelor = 'B';

    /** Navazující magistr */
    case FollowUpMaster = 'N';

    /** Magistr */
    case Master = 'M';

    /** Doktorát */
    case Doctoral = 'D';

    public function label(): string
    {
        return match ($this) {
            self::Bachelor => 'bachelor',
            self::FollowUpMaster => 'follow-up master',
            self::Master => 'master',
            self::Doctoral => 'doctoral',
        };
    }
}
