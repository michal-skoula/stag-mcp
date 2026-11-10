<?php

namespace App\Mcp\Enums;

/**
 * How a subject in `vsp/getStudijniPlan` is finished, from its numeric `typZk`.
 *
 * No číselník domain holds these codes (`TYP_ZKOUSKY` and `TYP_ZK` are both
 * empty), so they were resolved one by one against `predmety/getPredmetInfo`'s
 * `typZkousky` text on 17 Oct 2026. Only the codes seen on real plans are here;
 * anything else is reported as its raw code.
 */
enum ExamType: string
{
    /* Zkouška */
    case Exam = '1';

    /* Zápočet */
    case Credit = '4';

    /* Závěrečná zkouška, the exam closing a certificate programme */
    case FinalExam = '5';

    /* Státní závěrečná zkouška */
    case StateExam = '10';

    /* Obhajoba bakalářské práce */
    case ThesisDefence = '13';

    public function label(): string
    {
        return match ($this) {
            self::Exam => 'exam (zkouška)',
            self::Credit => 'credit (zápočet)',
            self::FinalExam => 'final exam (závěrečná zkouška)',
            self::StateExam => 'state exam (státní závěrečná zkouška)',
            self::ThesisDefence => 'thesis defence (obhajoba)',
        };
    }

    public static function labelFor(?string $code): ?string
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        return self::tryFrom(trim($code))?->label() ?? trim($code);
    }
}
