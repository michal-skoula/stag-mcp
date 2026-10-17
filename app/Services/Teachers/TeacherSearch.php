<?php

namespace App\Services\Teachers;

use Collator;
use Illuminate\Support\Str;

/**
 * Matches and ranks rows from a {@see TeacherDirectory} snapshot. Pure PHP, no
 * STAG calls, so it is tested on fixture rows alone.
 *
 * A row matches when every query token is a substring of its search key, which
 * is "surname first-name" folded to lowercase ASCII. Token order is free and
 * diacritics are ignored either way round, so "denisa sperlova" and "Šperlová"
 * find the same person. Titles are not in the key, so "Ing." matches nothing.
 *
 * @phpstan-import-type TeacherRow from TeacherDirectory
 */
final class TeacherSearch
{
    private const int TIER_EXACT_SURNAME = 0;

    private const int TIER_SURNAME_PREFIX = 1;

    private const int TIER_SUBSTRING = 2;

    /**
     * Folds a name to the form search keys are stored in: ASCII, lowercase,
     * anything but letters and digits turned into single spaces.
     */
    public static function normalize(string $value): string
    {
        $folded = Str::lower(Str::ascii($value));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $folded) ?? '');
    }

    /**
     * @return list<string>
     */
    public static function tokens(string $query): array
    {
        $normalized = self::normalize($query);

        return $normalized === '' ? [] : explode(' ', $normalized);
    }

    /**
     * @param  list<TeacherRow>  $rows
     * @return list<TeacherRow>
     */
    public static function search(array $rows, ?string $name, bool $activeOnly = false, bool $employeesOnly = false): array
    {
        $tokens = $name === null ? [] : self::tokens($name);

        $matches = array_filter($rows, function (array $row) use ($tokens, $activeOnly, $employeesOnly): bool {
            if ($activeOnly && ! $row['active']) {
                return false;
            }

            if ($employeesOnly && ! $row['employee']) {
                return false;
            }

            foreach ($tokens as $token) {
                if (! str_contains($row['key'], $token)) {
                    return false;
                }
            }

            return true;
        });

        $ranked = array_map(fn (array $row) => [
            'tier' => $tokens === [] ? self::TIER_SUBSTRING : self::tier($row, $tokens),
            'row' => $row,
        ], array_values($matches));

        $collator = new Collator('cs_CZ');

        usort($ranked, fn (array $a, array $b) => [$a['tier'], $b['row']['active']] <=> [$b['tier'], $a['row']['active']]
            ?: $collator->compare($a['row']['last_name'] ?? '', $b['row']['last_name'] ?? '')
            ?: $collator->compare($a['row']['first_name'] ?? '', $b['row']['first_name'] ?? ''));

        return array_column($ranked, 'row');
    }

    /**
     * How well the query hits the surname, best token wins: a whole surname
     * word beats the start of one, which beats a match anywhere else in the
     * key. "novak" ranks Novák above Nováková above Janovák.
     *
     * @param  TeacherRow  $row
     * @param  list<string>  $tokens
     */
    private static function tier(array $row, array $tokens): int
    {
        $surnameWords = self::tokens($row['last_name'] ?? '');
        $best = self::TIER_SUBSTRING;

        foreach ($tokens as $token) {
            foreach ($surnameWords as $word) {
                if ($word === $token) {
                    return self::TIER_EXACT_SURNAME;
                }

                if (str_starts_with($word, $token)) {
                    $best = self::TIER_SURNAME_PREFIX;
                }
            }
        }

        return $best;
    }
}
