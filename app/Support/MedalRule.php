<?php

namespace App\Support;

/**
 * Medaillen einer Wertungsgruppe in einem Bewerb nach der Zahl der Teilnehmer (docs/specs/meet-entry-summary.md).
 *
 * Mit Medaillenregel gibt es alle drei Medaillen erst ab vier Teilnehmern; bei weniger bekommt der Letzte statt seiner
 * Medaille eine Anerkennung (3: Gold, Silber, Anerkennung; 2: Gold, Anerkennung; 1: Anerkennung). Ohne Regel bekommt
 * jeder der ersten drei seine Medaille.
 */
final class MedalRule
{
    public static function forParticipants(int $participants, bool $reductionRule): MedalCount
    {
        if ($participants <= 0) {
            return MedalCount::none();
        }

        if ($participants >= 4) {
            return new MedalCount(1, 1, 1, 0);
        }

        if (! $reductionRule) {
            return new MedalCount(1, $participants >= 2 ? 1 : 0, $participants >= 3 ? 1 : 0, 0);
        }

        return match ($participants) {
            3 => new MedalCount(1, 1, 0, 1),
            2 => new MedalCount(1, 0, 0, 1),
            default => new MedalCount(0, 0, 0, 1),
        };
    }
}
