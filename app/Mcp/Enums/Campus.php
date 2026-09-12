<?php

namespace App\Mcp\Enums;

/**
 * Raw STAG campus/location codes (`mistnost/getBudovy`'s `lokalita`). STAG
 * does not document what these mean, so label() currently just echoes the
 * code back. todo: update doc when deciphered
 */
enum Campus: string
{
    // todo: decode the real names check the ciselniky service, flagged as a
    //        separate to-do item and replace the placeholders in label(). Once that
    //        lands this becomes a real two-way mapping without touching any caller:
    //        GetBudovyTool's `campus` filter already accepts either the raw code or a
    //        label via fromLabel(), and its output already reads through label().
    case S = 'S';
    case X = 'X';
    case C = 'C';
    case B = 'B';
    case L = 'L';
    case K = 'K';
    case V = 'V';
    case Y = 'Y';

    /**
     * TODO: replace with the real campus name once decoded.
     */
    public function label(): string
    {
        return $this->value;
    }

    /**
     * Reverse of label(). A no-op today since label() is a passthrough, but
     * this is what keeps the mapping two-way once label() returns real names.
     */
    public static function fromLabel(string $label): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->label() === $label) {
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
}
