<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

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

    /**
     * Vorschlag für die allgemeinen Versionsdaten aus den Angaben der Datei — Vorbelegung des Formulars in der
     * Vorschau, vom Administrator korrigierbar (Spec wps-points-engine §11.4.6).
     *
     * Excel (Blatt "version control"): Das Datum wird zu "Gültig ab", das Jahr stammt aus dem Datum. Text
     * (Kopfblock): "Version" ist dort das Jahr der Tabelle, keine Versionsnummer; ein Datum fehlt, daher gilt der
     * 1. Jänner des Jahres. Was die Datei nicht hergibt, bleibt leer.
     *
     * @return array{label: ?string, year: ?int, version: ?string, source: ?string, valid_from: ?string}
     */
    public function versionSuggestion(): array
    {
        $date = $this->metadataDate();

        if (isset($this->metadata['short_name']) || isset($this->metadata['name'])) {
            $year = $this->yearFrom($this->metadata['version'] ?? null)
                ?? $this->yearFrom($this->metadata['short_name'] ?? null);

            return [
                'label' => $year !== null ? "WPS $year" : null,
                'year' => $year,
                'version' => null,
                'source' => $this->metadata['short_name'] ?? $this->metadata['name'] ?? null,
                'valid_from' => $year !== null ? "$year-01-01" : null,
            ];
        }

        $year = $date !== null ? (int) $date->format('Y') : null;

        return [
            'label' => $year !== null ? "WPS $year" : null,
            'year' => $year,
            'version' => $this->metadata['version'] ?? null,
            'source' => $year !== null
                ? "World Para Swimming Point Scores for Senior Long Course Events $year"
                : null,
            'valid_from' => $date?->format('Y-m-d'),
        ];
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

    /** Datum aus "version control" — der Service liefert es als "d.m.Y", sonst unverändert aus der Zelle. */
    private function metadataDate(): ?CarbonImmutable
    {
        $value = $this->metadata['date'] ?? null;

        if (! is_string($value) || preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!d.m.Y', $value);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    private function yearFrom(mixed $value): ?int
    {
        return is_string($value) && preg_match('/\b(19|20)\d{2}\b/', $value, $match) === 1
            ? (int) $match[0]
            : null;
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
