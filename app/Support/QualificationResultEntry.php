<?php

namespace App\Support;

/**
 * Ein einzelnes Ergebnis im Leistungsverlauf eines Bewerbs.
 *
 * Die Bewerbszeile der Qualifikantenansicht zeigt die Bestleistung; aufgeklappt zeigt sie
 * alle Ergebnisse des Zeitraums. Daran ist ablesbar, ob ein Athlet seine Leistung hält, sich
 * verbessert oder nachlässt — die Bestleistung allein sagt darüber nichts, weil sie auch
 * zwei Jahre alt sein kann.
 */
final readonly class QualificationResultEntry
{
    public function __construct(
        public int $resultId,
        public int $swimTime,
        public ?int $points,
        public ?int $place,
        public ?string $meetName,
        public ?string $meetDate,
        public bool $exhibition,
        /** Erfüllt dieses einzelne Ergebnis die MQS? */
        public bool $meetsMqs,
        /** Erfüllt dieses einzelne Ergebnis die MET (aber nicht die MQS)? */
        public bool $meetsMet,
    ) {}

    /**
     * Kurzzeichen für die Norm, die dieses Ergebnis erreicht — oder null.
     *
     * MQS hat Vorrang: Wer die MQS erfüllt, erfüllt zwangsläufig auch die langsamere MET,
     * und zweimal dieselbe Leistung auszuzeichnen wäre irreführend.
     */
    public function standardLabel(): ?string
    {
        return match (true) {
            $this->meetsMqs => 'MQS',
            $this->meetsMet => 'MET',
            default => null,
        };
    }
}
