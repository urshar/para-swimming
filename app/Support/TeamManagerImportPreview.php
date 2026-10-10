<?php

namespace App\Support;

/**
 * Ergebnis des Lesens einer Team-Manager-Datei, noch ohne Schreibzugriff auf die Datenbank.
 *
 * Die Vorschau zeigt Kennzahlen und Hinweise; unbekannte Klassifizierer-Namen müssen vor dem Import einem
 * vorhandenen Klassifizierer zugeordnet oder ausdrücklich übergangen werden.
 */
final readonly class TeamManagerImportPreview
{
    /**
     * @param  list<array<string, mixed>>  $clubs  aufbereitete Vereine (club_id = vorhandener Verein oder null)
     * @param  list<array<string, mixed>>  $athletes  aufbereitete Athleten (athlete_id = vorhandener Athlet oder null)
     * @param  array<string, int>  $knownClassifiers  normalisierter Name → Klassifizierer-ID
     * @param  array<string, array{name: string, count: int}>  $unknownClassifiers  normalisierter Name → Anzeige
     * @param  list<string>  $warnings  Hinweise, die den Import nicht verhindern
     * @param  array<string, int>  $counts  Kennzahlen für die Vorschau
     */
    public function __construct(
        public array $clubs,
        public array $athletes,
        public array $knownClassifiers,
        public array $unknownClassifiers,
        public array $warnings,
        public array $counts,
    ) {}
}
