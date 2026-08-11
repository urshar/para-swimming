<?php

namespace App\Services;

use App\Models\AthleteKaderMembership;
use Illuminate\Support\Collection;

/**
 * AthleteKaderResolver
 *
 * Kaderzugehörigkeit je Athlet zu einem Stichtag.
 *
 * Als eigener Service, weil zwei Module dieselbe Auflösung brauchen: das Qualifikationsmodul
 * für die Gliederung der Qualifikantenansicht und das Ranglistenmodul für den Kaderfilter.
 * Zweimal ausprogrammiert liefe insbesondere die Auflösung bei mehreren gültigen
 * Zugehörigkeiten auseinander.
 *
 * Rein lesend.
 */
final readonly class AthleteKaderResolver
{
    /**
     * Kaderzugehörigkeit je Athlet zum Stichtag.
     *
     * Einmal geladen statt je Athlet abgefragt. Gibt es mehrere gültige Zugehörigkeiten,
     * gewinnt die mit der kleinsten Sortierung — also die höchste Kaderstufe.
     *
     * @return array<int, array{id: int, name: string, sort_order: int}>
     */
    public function byAthlete(string $stichtag): array
    {
        return AthleteKaderMembership::query()
            ->with('kaderType')
            ->activeOn($stichtag)
            ->get()
            ->filter(static fn ($mitgliedschaft): bool => $mitgliedschaft->kaderType !== null)
            ->sortBy(static fn ($mitgliedschaft): int => (int) $mitgliedschaft->kaderType->sort_order)
            ->groupBy(static fn ($mitgliedschaft): int => (int) $mitgliedschaft->athlete_id)
            ->map(static fn (Collection $desAthleten): array => [
                'id' => (int) $desAthleten->first()->kaderType->id,
                'name' => (string) $desAthleten->first()->kaderType->name_de,
                'sort_order' => (int) $desAthleten->first()->kaderType->sort_order,
            ])
            ->all();
    }

    /**
     * Stichtag für ein Auswertungsjahr.
     *
     * Liegt das Jahr in der Vergangenheit, gilt sein Ende: Eine Auswertung der Saison 2024
     * soll auch 2028 dieselbe Kadereinteilung zeigen. Läuft das Jahr noch, gilt der heutige
     * Tag — dann stützt die Auswertung eine Entscheidung, die jetzt getroffen wird.
     *
     * Dieselbe Regel wie im Qualifikationsmodul dort bezogen auf den Qualifikationszeitraum.
     */
    public function referenceDateForYear(int $jahr): string
    {
        // Datumsstrings im Format Y-m-d lassen sich als Zeichenketten vergleichen; min()
        // liefert damit den früheren der beiden Tage.
        return min(date('Y-m-d'), "$jahr-12-31");
    }
}
