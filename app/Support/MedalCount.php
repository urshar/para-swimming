<?php

namespace App\Support;

/**
 * Anzahl Medaillen nach Art: Gold, Silber, Bronze und Anerkennung (Medaillenplanung, MedalRule).
 */
final readonly class MedalCount
{
    public function __construct(
        public int $gold,
        public int $silver,
        public int $bronze,
        public int $recognition,
    ) {}

    public static function none(): self
    {
        return new self(0, 0, 0, 0);
    }

    public function plus(self $other): self
    {
        return new self(
            $this->gold + $other->gold,
            $this->silver + $other->silver,
            $this->bronze + $other->bronze,
            $this->recognition + $other->recognition,
        );
    }

    /** Vervielfacht, z. B. je Staffelteam um die Zahl der Schwimmer. */
    public function times(int $factor): self
    {
        return new self($this->gold * $factor, $this->silver * $factor, $this->bronze * $factor, $this->recognition * $factor);
    }

    public function total(): int
    {
        return $this->gold + $this->silver + $this->bronze + $this->recognition;
    }

    /** Kurzform wie "3 G, 2 S, 1 B, 1 A"; Arten ohne Medaille entfallen, ganz ohne Medaillen "–". */
    public function label(): string
    {
        $parts = array_filter([
            $this->gold > 0 ? $this->gold.' G' : null,
            $this->silver > 0 ? $this->silver.' S' : null,
            $this->bronze > 0 ? $this->bronze.' B' : null,
            $this->recognition > 0 ? $this->recognition.' A' : null,
        ]);

        return $parts === [] ? '–' : implode(', ', $parts);
    }
}
