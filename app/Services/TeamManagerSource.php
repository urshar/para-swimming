<?php

namespace App\Services;

/**
 * Liest Vereine und Mitglieder aus einer Datei des Splash Team Managers.
 *
 * Eigene Schnittstelle, weil das Lesen der Access-Datei einen Windows-Treiber braucht: Die Tests ersetzen die
 * Quelle durch feste Zeilen, der eigentliche Import (TeamManagerImportService) bleibt so überall testbar.
 */
interface TeamManagerSource
{
    /**
     * Rohzeilen der Tabellen CLUBS und MEMBERS, Spaltennamen wie in der Datei, Texte in UTF-8.
     *
     * @return array{clubs: list<array<string, string|null>>, members: list<array<string, string|null>>}
     */
    public function read(string $path): array;
}
