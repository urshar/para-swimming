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
     * @param  array<string, mixed>  $metadata  Versionsangaben aus der Datei (Excel: "version control", Text: Kopfblock)
     * @param  array<string, int>  $counts  Kennzahlen für die Vorschau
     */
    public function __construct(
        public array $rows,
        public array $errors,
        public array $metadata,
        public array $counts,
    ) {}

    /**
     * Baut die Vorschau aus den Ergebnissen der Zeilen-Parser — gemeinsam für alle Importformate.
     *
     * Übernimmt die formatunabhängigen Prüfungen: Fehlermeldungen mit Zeilennummer versehen,
     * doppelte Merkmalskombinationen erkennen und eine Datei ohne Datenzeilen melden.
     *
     * @param  array<int, array<string, mixed>|string>  $parsedRows  Zeilennummer → Parametersatz oder Fehlermeldung
     * @param  array<string, mixed>  $metadata
     */
    public static function fromParsedRows(array $parsedRows, array $metadata): self
    {
        $rows = [];
        $errors = [];
        $seen = [];

        foreach ($parsedRows as $number => $parsed) {
            if (is_string($parsed)) {
                $errors[] = "Zeile $number: $parsed";

                continue;
            }

            $key = implode('|', [
                $parsed['gender'],
                $parsed['stroke_type_id'],
                $parsed['distance'],
                $parsed['sport_class'],
            ]);

            if (isset($seen[$key])) {
                $errors[] = "Zeile $number: Kombination bereits in Zeile $seen[$key] enthalten.";

                continue;
            }

            $seen[$key] = $number;
            $rows[] = $parsed;
        }

        if ($rows === [] && $errors === []) {
            $errors[] = 'Die Datei enthält keine Datenzeilen.';
        }

        return new self(
            rows: $rows,
            errors: $errors,
            metadata: $metadata,
            counts: self::countsFor($rows),
        );
    }

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

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private static function countsFor(array $rows): array
    {
        return [
            'rows' => count($rows),
            'genders' => count(array_unique(array_column($rows, 'gender'))),
            'events' => count(array_unique(array_map(
                static fn (array $row): string => $row['stroke_type_id'].'-'.$row['distance'],
                $rows
            ))),
            'sport_classes' => count(array_unique(array_column($rows, 'sport_class'))),
        ];
    }
}
