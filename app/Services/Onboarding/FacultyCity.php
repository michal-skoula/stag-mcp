<?php

namespace App\Services\Onboarding;

use App\Mcp\Enums\City;

/**
 * A guess at the user's city from their faculty.
 *
 * STAG has no link between a faculty and a place, so this is a small
 * hand-kept table. It is only ever offered as a suggestion: the saved city
 * narrows `list-budovy` and the room tools, so a wrong one applied silently
 * would hide the right buildings. Nothing reads the suggestion except the
 * onboarding confirmation, which writes the preference once the user agrees.
 *
 * The table is specific to one university's faculty codes. A faculty it does
 * not know yields no guess rather than a wrong one.
 */
final class FacultyCity
{
    /** @var array<string, City> */
    private const array BY_FACULTY = [
        'FAV' => City::Plzen,
        'FEL' => City::Plzen,
        'FST' => City::Plzen,
        'FPE' => City::Plzen,
        'FF' => City::Plzen,
        'FPR' => City::Plzen,
        'FZS' => City::Plzen,
        'FDU' => City::Plzen,
        'FUD' => City::Plzen,
        'FEK' => City::Cheb,
    ];

    public static function guess(?string $faculty): ?string
    {
        return self::BY_FACULTY[strtoupper((string) $faculty)]?->value ?? null;
    }
}
