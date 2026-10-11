<?php

namespace App\Services;

use App\Models\Meet;
use App\Models\Qualification;
use App\Models\SwimRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Löscht eine Veranstaltung endgültig samt allem, was daran hängt (Entscheidung Erik, 11.10.2026).
 *
 * Bewerbe, Abschnitte, Meldegelder, Meldungen, Ergebnisse (samt Splits und Mitgliedern), Gaststarts,
 * Cup-Tageswertungen, Punktesysteme, Vereinszuordnung und Klassen-Hinweise aus der Ergebnisliste in der
 * Klassifizierungshistorie entfernt die Datenbank über cascadeOnDelete. Zusätzlich:
 *   - Qualifikationen mit dieser Veranstaltung — ihre Zeiten sind ohne die Veranstaltung nicht mehr gültig
 *     (der Fremdschlüssel würde sie nur vom Wettkampf lösen),
 *   - Rekorde aus Ergebnissen der Veranstaltung über SwimRecord::removeFromHistory(): Der Vorgänger wird wieder
 *     aktuell bzw. Vorgänger und Nachfolger werden verknüpft. Rekorde aus der Rekordliste oder manuell eingetragene
 *     (ohne Ergebnisbezug) bleiben.
 *
 * Eine schon berechnete Cup-Gesamtwertung wird nicht automatisch neu berechnet.
 */
final readonly class MeetDeletionService
{
    /**
     * Was beim Löschen mit verschwindet — für Rückfrage und Probelauf. Funktioniert auch für bereits als gelöscht
     * markierte Veranstaltungen (Bestand vor dem endgültigen Löschen).
     *
     * @return array{events: int, entries: int, relay_entries: int, results: int, relay_results: int, foreign_starts: int, qualifications: int, records: int}
     */
    public function summary(Meet $meet): array
    {
        return [
            'events' => $meet->swimEvents()->count(),
            'entries' => $meet->entries()->count(),
            'relay_entries' => $meet->relayEntries()->count(),
            'results' => $meet->results()->count(),
            'relay_results' => $meet->relayResults()->count(),
            'foreign_starts' => $meet->foreignStarts()->count(),
            'qualifications' => Qualification::query()->where('meet_id', $meet->id)->count(),
            'records' => $this->recordsOf($meet)->count(),
        ];
    }

    /** @throws Throwable */
    public function delete(Meet $meet): void
    {
        DB::transaction(function () use ($meet): void {
            // Neueste zuerst: So wird jeweils der aktuelle Rekord entfernt und sein Vorgänger wieder aktuell, auch wenn
            // die Veranstaltung mehrere Rekorde derselben Kette aufgestellt hat.
            /** @var SwimRecord $record */
            foreach ($this->recordsOf($meet)->latest('id')->get() as $record) {
                $record->removeFromHistory();
            }

            Qualification::query()->where('meet_id', $meet->id)->delete();

            $meet->forceDelete();
        });
    }

    /** @return Builder<SwimRecord> Rekorde, die aus Einzel- oder Staffelergebnissen dieser Veranstaltung stammen */
    private function recordsOf(Meet $meet): Builder
    {
        return SwimRecord::query()->where(fn (Builder $query) => $query
            ->whereIn('result_id', $meet->results()->select('id'))
            ->orWhereIn('relay_result_id', $meet->relayResults()->select('id')));
    }
}
