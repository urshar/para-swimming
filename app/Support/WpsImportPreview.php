<?php

namespace App\Support;

/**
 * Ergebnis des Parsens einer WPS-Point-Score-Datei.
 *
 * Trennt bewusst Lesen von Schreiben: Der Preview-Schritt erzeugt dieses Objekt, ohne die
 * Datenbank zu berühren. Erst der ausdrückliche zweite Schritt importiert.
 *
 * Fehler brechen das Parsen nicht ab — sie werden gesammelt, damit der Administrator alle
 * Probleme einer Datei auf einen Blick sieht, statt sie einzeln nachzureichen.
 */
final readonly class WpsImportPreview
{
    /**
     * @param  list<array<string, mixed>>  $rows  geparste, gültige Parametersätze
     * @param  list<string>  $errors  Meldungen inklusive Zeilennummer
     * @param  array<string, mixed>  $metadata  Versionsangaben aus "version control"
     * @param  array<string, int>  $counts  Kennzahlen für die Vorschau
     */
    public function __construct(
        public array $rows,
        public array $errors,
        public array $metadata,
        public array $counts,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [] && $this->rows !== [];
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    public function errorCount(): int
    {
        return count($this->errors);
    }
}
