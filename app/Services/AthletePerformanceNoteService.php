<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\AthletePerformanceNote;
use App\Models\Result;
use Illuminate\Support\Collection;

/**
 * AthletePerformanceNoteService
 *
 * Anlegen und Pflege der Leistungsnotizen (Spec "WPS Rankings" §7.5).
 *
 * Die Berechtigungsprüfung liegt beim Aufrufer über `AthletePerformanceNotePolicy` — der
 * Service selbst trifft keine Entscheidung darüber, wer was darf.
 */
final readonly class AthletePerformanceNoteService
{
    /**
     * Notizen eines Athleten, chronologisch absteigend.
     *
     * @return Collection<int, AthletePerformanceNote>
     */
    public function forAthlete(Athlete $athlete, ?int $fromYear, ?int $toYear): Collection
    {
        $abfrage = AthletePerformanceNote::query()
            ->with(['author', 'result.swimEvent.strokeType', 'result.meet'])
            ->where('athlete_id', $athlete->getKey());

        if ($fromYear !== null && $toYear !== null) {
            // Ohne when(): Dessen Rückgabetyp ist bool|Builder, und darauf lässt sich kein
            // Scope auflösen. whereBetween mit Datumsstrings statt YEAR() — nicht
            // DB-portabel, und die Testsuite läuft auf SQLite.
            $abfrage->between("$fromYear-01-01", "$toYear-12-31");
        }

        return $abfrage
            ->orderByDesc('noted_on')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Notizen, nach Ergebnis-Kennung indiziert — für die Anzeige an der jeweiligen Zeile.
     *
     * @param  Collection<int, AthletePerformanceNote>  $notes
     * @return array<int, list<AthletePerformanceNote>>
     */
    public function indexByResult(Collection $notes): array
    {
        $nachErgebnis = [];

        foreach ($notes as $notiz) {
            if ($notiz->result_id === null) {
                continue;
            }

            $nachErgebnis[$notiz->result_id][] = $notiz;
        }

        return $nachErgebnis;
    }

    /**
     * Notizen ohne Ergebnisbezug — sie gelten für einen Zeitpunkt, nicht für einen Start.
     *
     * @param  Collection<int, AthletePerformanceNote>  $notes
     * @return Collection<int, AthletePerformanceNote>
     */
    public function withoutResult(Collection $notes): Collection
    {
        return $notes->filter(
            static fn (AthletePerformanceNote $n): bool => $n->getAttribute('result_id') === null
        )->values();
    }

    /**
     * Legt eine Notiz an.
     *
     * Das Datum wird aus dem Ergebnis übernommen, wenn eines angegeben ist: Eine Notiz zu
     * einem Start gehört an dessen Wettkampftag, und zwei abweichende Daten wären eine
     * Widersprüchlichkeit, die niemand auflösen kann.
     */
    public function create(
        Athlete $athlete,
        string $category,
        string $note,
        ?Result $result,
        ?string $notedOn,
        ?int $userId,
    ): AthletePerformanceNote {
        $datum = $result?->meet?->start_date?->format('Y-m-d')
            ?? $notedOn
            ?? date('Y-m-d');

        return AthletePerformanceNote::query()->create([
            'athlete_id' => $athlete->getKey(),
            'result_id' => $result?->getKey(),
            'noted_on' => $datum,
            'category' => in_array($category, AthletePerformanceNote::CATEGORIES, true)
                ? $category
                : AthletePerformanceNote::CATEGORY_OTHER,
            'note' => trim($note),
            'created_by' => $userId,
        ]);
    }

    public function delete(AthletePerformanceNote $note): void
    {
        $note->delete();
    }
}
