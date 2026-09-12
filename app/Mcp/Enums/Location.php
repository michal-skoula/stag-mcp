<?php

namespace App\Mcp\Enums;

/**
 * STAG's `LOKALITA` číselník: the campus or part of town a
 * building sits in (`mistnost/getBudovy`'s `lokalita`).
 * Names come from `ciselniky/getCiselnik?domena=LOKALITA`,
 */
enum Location: string
{
    case Cheb = 'C';
    case Klatovska = 'K';
    case Lochotin = 'L';
    case Neznama = 'X';
    case Skvrnany = 'Y';
    case Slovany = 'V';
    case StredPlzne = 'S';
    case Univerzitni = 'B';

    public function label(): string
    {
        return match ($this) {
            self::Cheb => 'Cheb',
            self::Klatovska => 'Klatovská',
            self::Lochotin => 'Lochotín',
            self::Neznama => 'Není známa',
            self::Skvrnany => 'Skvrňany',
            self::Slovany => 'Slovany',
            self::StredPlzne => 'Střed Plzně',
            self::Univerzitni => 'Univerzitní',
        };
    }

    /**
     * Reverse of label(), so the campus filter takes a name as readily as a
     * code. Matching is accent- and case-insensitive: an agent that types
     * "lochotin" for "Lochotín" should not come back empty.
     */
    public static function fromLabel(string $label): ?self
    {
        $needle = self::fold($label);

        foreach (self::cases() as $case) {
            if (self::fold($case->label()) === $needle) {
                return $case;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return list<string>
     */
    public static function labels(): array
    {
        return array_map(fn (self $case) => $case->label(), self::cases());
    }

    private static function fold(string $value): string
    {
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII', $value);

        return mb_strtolower($ascii !== false ? $ascii : $value);
    }
}
