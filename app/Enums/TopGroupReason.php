<?php

namespace App\Enums;

/**
 * Grund der automatischen Top-Gruppen-Einstufung eines Athleten in einem Cup-Jahr (Spec cup-top-group.md).
 * Eine Hand-Einstufung ist kein eigener Grund, sondern liegt darüber (manual_is_top_group).
 */
enum TopGroupReason: string
{
    case Kader = 'KADER';
    case TwoYearsAbove = 'TWO_YEARS_ABOVE';
    case TwoYearsBelow = 'TWO_YEARS_BELOW';
    case CarriedOver = 'CARRIED_OVER';

    /** Alte Regel (bestes Ergebnis der zwei Vorjahre), nur in Einstufungen vor der Zwei-Jahres-Regel. */
    case PointsHistory = 'POINTS_HISTORY';

    public function label(): string
    {
        return match ($this) {
            self::Kader => 'Nationalkader',
            self::TwoYearsAbove => 'zwei Jahre über der Grenze',
            self::TwoYearsBelow => 'zwei Jahre unter der Grenze',
            self::CarriedOver => 'Status vom Vorjahr',
            self::PointsHistory => 'Punkte (alte Regel)',
        };
    }
}
