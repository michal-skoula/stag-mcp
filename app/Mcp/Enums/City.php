<?php

namespace App\Mcp\Enums;

/**
 * Cities STAG buildings sit in (`mistnost/getBudovy`'s `obec`).
 *
 * Deliberately not enforced anywhere: STAG can add a city without a source
 * change here, so this exists to make tool schemas self-documenting, not to
 * validate input. See GetBudovyTool's `city` filter.
 */
enum City: string
{
    case Plzen = 'Plzeň';
    case CeskeBudejovice = 'České Budějovice';
    case Cheb = 'Cheb';
    case KarlovyVary = 'Karlovy Vary';
    case Strakonice = 'Strakonice';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
