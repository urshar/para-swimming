<?php

namespace App\Support;

use App\Models\RelayEntry;
use Illuminate\Support\Collection;

/**
 * Anzeigename von Staffelmeldungen — eine Stelle für App-Listen, PDF-Meldelisten und LENEX-Export.
 *
 * Regel: frei vergebener Name (relay_entries.name), sonst der Vereinsname. Gibt es im selben Bewerb
 * mehrere UNBENANNTE Staffeln desselben Vereins, bekommen nur diese eine laufende Nummer (nach Anlage,
 * also nach ID): "Team Kärnten", "BSV Spittal 1", "BSV Spittal 2". Eine einzelne unbenannte Staffel
 * bleibt ohne Nummer.
 *
 * Die Nummer hängt von den Geschwister-Staffeln ab, nicht nur von der übergebenen Auswahl — eine gefilterte
 * oder paginierte Liste würde sonst falsch zählen. for() lädt deshalb alle Staffeln der betroffenen
 * (Bewerb, Verein)-Paare in einer einzigen Abfrage nach.
 */
final readonly class RelayNames
{
    /**
     * @param  Collection<int, RelayEntry>  $relays  mit geladener club-Relation (sonst Nachlade-Abfragen)
     * @return array<int, string> Anzeigename je relay_entries.id
     */
    public static function for(Collection $relays): array
    {
        if ($relays->isEmpty()) {
            return [];
        }

        // Laufende Nummer je unbenannter Staffel — nur in Gruppen mit mehr als einer unbenannten.
        $numbers = [];
        RelayEntry::query()
            ->whereIn('swim_event_id', $relays->pluck('swim_event_id')->unique())
            ->whereIn('club_id', $relays->pluck('club_id')->unique())
            ->orderBy('id')
            ->get(['id', 'swim_event_id', 'club_id', 'name'])
            ->reject(fn (RelayEntry $r): bool => self::hasOwnName($r))
            ->groupBy(fn (RelayEntry $r): string => $r->swim_event_id.'|'.$r->club_id)
            ->each(function (Collection $group) use (&$numbers): void {
                if ($group->count() > 1) {
                    foreach ($group->values() as $i => $relay) {
                        $numbers[$relay->id] = $i + 1;
                    }
                }
            });

        return $relays->mapWithKeys(function (RelayEntry $relay) use ($numbers): array {
            if (self::hasOwnName($relay)) {
                return [$relay->id => trim($relay->name)];
            }

            $name = $relay->club?->display_name ?? '';

            return [$relay->id => isset($numbers[$relay->id]) ? $name.' '.$numbers[$relay->id] : $name];
        })->all();
    }

    private static function hasOwnName(RelayEntry $relay): bool
    {
        return trim((string) $relay->name) !== '';
    }
}
