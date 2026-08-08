<?php

namespace App\Support;

/**
 * Ergebnis des Parsens einer WPS-Normdatei (Spec "WPS Qualification" §9.2).
 *
 * Trennt Lesen von Schreiben: Der Vorschauschritt erzeugt dieses Objekt, ohne die Datenbank
 * zu berühren. Erst der ausdrückliche zweite Schritt importiert. Aufgebaut wie
 * WpsImportPreview, mit zwei Ergänzungen.
 *
 * Fehler UND Hinweise
 * -------------------
 * Bei den Punkteparametern ist jede unlesbare Zeile ein Fehler. Hier gibt es daneben
 * Beobachtungen, die den Import nicht verhindern dürfen — der übersprungene Staffelabschnitt
 * etwa, oder vorhandene Normen, die in der Datei fehlen. Ohne die Trennung wäre entweder der
 * Import blockiert oder die Beobachtung unsichtbar.
 *
 * suggestedPeriod
 * ---------------
 * Die Titelzeile der bekannten Dateien nennt den Qualifikationszeitraum im Klartext. Er wird
 * in der Vorschau als Vorschlag angezeigt, aber NICHT übernommen: Die Formulierung ist nicht
 * garantiert stabil, und ein still falsch gesetzter Zeitraum würde später Ergebnisse aus der
 * Wertung nehmen, ohne dass jemand die Ursache sieht.
 */
final readonly class ChampionshipStandardImportPreview
{
    /**
     * @param  list<array<string, mixed>>  $rows  geparste, gültige Normzeilen
     * @param  list<string>  $errors  Meldungen inklusive Zeilennummer
     * @param  list<string>  $warnings  Hinweise, die den Import nicht verhindern
     * @param  array<string, int>  $counts  Kennzahlen für die Vorschau
     * @param  array{start: string, end: string}|null  $suggestedPeriod  aus der Titelzeile gelesen
     * @param  string|null  $title  Titelzeile der Datei, zur Wiedererkennung
     */
    public function __construct(
        public array $rows,
        public array $errors,
        public array $warnings,
        public array $counts,
        public ?array $suggestedPeriod,
        public ?string $title,
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

    public function warningCount(): int
    {
        return count($this->warnings);
    }
}
