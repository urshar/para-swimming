<?php

namespace App\Support;

/**
 * Detail der Medaillenaufstellung: eine Wertungsgruppe in einem Bewerb mit Teilnehmerzahl (bei Staffeln Teams) und
 * Medaillen (bei Staffeln schon mit der Zahl der Schwimmer vervielfacht).
 */
final readonly class MedalDetailRow
{
    public function __construct(
        public int $swimEventId,
        public string $eventLabel,
        public string $scoringLabel,
        public string $groupLabel,
        public bool $isRelay,
        public int $participants,
        public MedalCount $medals,
    ) {}
}
