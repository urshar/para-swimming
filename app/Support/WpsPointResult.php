<?php

namespace App\Support;

use App\Models\Result;
use App\Models\WpsPointParameter;
use App\Models\WpsPointVersion;
use App\Models\WpsScmConversionFactor;

/**
 * Ergebnis einer WPS-Punkteberechnung für ein einzelnes Result.
 *
 * Bewusst ein Wert-Objekt statt eines Tupels: die Berechnung liefert fünf zusammengehörige
 * Angaben, und der Aufrufer muss zwischen "nicht berechenbar" (skipReason gesetzt) und
 * "berechnet" unterscheiden können, ohne auf null-Prüfungen einzelner Felder auszuweichen.
 *
 * Das Objekt trägt keine Persistenzlogik — das Speichern übernimmt der
 * WpsPointCalculationService.
 */
final readonly class WpsPointResult
{
    private function __construct(
        public ?int $points,
        public ?WpsPointParameter $parameter,
        public ?WpsPointVersion $version,
        public ?string $calculationType,
        public ?string $skipReason,
        public ?int $estimatedLcmTime = null,
        public ?WpsScmConversionFactor $conversionFactor = null,
    ) {}

    /**
     * $estimatedLcmTime und $conversionFactor sind nur bei umgerechneten Kurzbahnzeiten
     * gesetzt. Der Berechnungstyp folgt dabei nicht der Bahnlänge, sondern der Herkunft:
     * Eine Umrechnung ergibt stets "estimated", ein offizieller Parametersatz "official" —
     * auch auf der Kurzbahn, falls World Para Swimming dort je Werte veröffentlicht.
     */
    public static function calculated(
        int $points,
        WpsPointParameter $parameter,
        WpsPointVersion $version,
        ?int $estimatedLcmTime = null,
        ?WpsScmConversionFactor $conversionFactor = null,
    ): self {
        return new self(
            points: $points,
            parameter: $parameter,
            version: $version,
            calculationType: $conversionFactor !== null
                ? Result::WPS_TYPE_ESTIMATED
                : $parameter->calculationType(),
            skipReason: null,
            estimatedLcmTime: $estimatedLcmTime,
            conversionFactor: $conversionFactor,
        );
    }

    /** Ob die Punkte auf einer umgerechneten Kurzbahnzeit beruhen. */
    public function wasConverted(): bool
    {
        return $this->conversionFactor !== null;
    }

    /**
     * Kein Fehler, sondern ein fachlich begründeter Nicht-Fall: DSQ, fehlende Zeit,
     * Staffel, kein Parametersatz. Die Begründung ist deutschsprachig und wird in der
     * Zusammenfassung der Massenberechnung aggregiert.
     */
    public static function skipped(string $reason): self
    {
        return new self(
            points: null,
            parameter: null,
            version: null,
            calculationType: null,
            skipReason: $reason,
        );
    }

    public function wasCalculated(): bool
    {
        return $this->points !== null;
    }

    public function isEstimated(): bool
    {
        return $this->calculationType === Result::WPS_TYPE_ESTIMATED;
    }
}
