<?php

namespace App\Services;

use App\Models\Club;
use App\Models\Cup;
use App\Models\Meet;
use App\Models\Result;
use App\Support\StartClubRankingResult;
use Illuminate\Support\Collection;
use stdClass;

/**
 * StartBasedClubRankingService
 *
 * Berechnet die klassische Startwertung der ÖBSV-Cup-Vereinswertung
 * (Wertungssystem A, Spec §3). Sie beantwortet: "Welcher Verein hatte im ÖBSV
 * Cup die meisten Starts?" und bildet die bisherige Vereinswertung ab.
 *
 * Die Wertung wird dynamisch aus den Bestandsdaten (`results`) berechnet und
 * nicht persistiert (Spec §12.1) — Korrekturen an Ergebnissen oder
 * Cup-Zuordnungen wirken sich beim nächsten Aufruf automatisch aus. Historische
 * Cup-Jahre bleiben dadurch reproduzierbar, solange die zugrunde liegenden
 * Ergebnisse existieren.
 *
 * Definition eines Starts (Spec §3.2, mit dem ÖBSV abgestimmt):
 *
 *   1 Athlet + 1 Einzelbewerb + 1 ÖBSV-Cup-Meet = 1 Start
 *
 * Ein "Einzelbewerb" ist die logische Disziplin (Distanz + Schwimmart). Mehrere
 * Ergebnisse desselben Athleten in derselben Disziplin und demselben Meet —
 * z.B. Vorlauf und Finale (getrennte swim_events) oder verschiedene Läufe/Heats
 * — zählen als genau ein Start. In der Praxis sind die Cup-Meets überwiegend
 * Timed Finals (round = TIM), sodass diese Zusammenfassung selten greift, aber
 * korrekt bleibt.
 *
 * Berücksichtigt werden ausschließlich:
 *   - Meets mit meets.cup_id = $cup->id (Spec §2),
 *   - Einzelbewerbe (swim_events.relay_count ≤ 1) — Staffeln werden ignoriert,
 *   - angetretene Einzelergebnisse: reguläre Ergebnisse (status = null) sowie
 *     DSQ und DNF (der Athlet ist angetreten). Nicht gewertet werden DNS, SICK,
 *     WDR (nicht angetreten) und EXH (außer Konkurrenz, Spec §15).
 *
 * Der maßgebliche Verein ist results.club_id — der Startverein zum Zeitpunkt
 * des Ergebnisses. Vereinswechsel sind damit korrekt berücksichtigt, ohne dass
 * athlete.club_id (nur aktueller Verein) herangezogen wird (Spec §9).
 *
 * Ausländische Vereine (club.nation.code != 'AUT') werden standardmäßig nicht
 * gewertet; über die Konfiguration cup_club_ranking.include_foreign_clubs bzw.
 * das Argument $includeForeignClubs lassen sie sich zuschalten (Spec §8/§15).
 */
final readonly class StartBasedClubRankingService
{
    /**
     * Ergebnisstatus, die als Start zählen (der Athlet ist angetreten). Ein
     * reguläres Ergebnis wird über status = null geführt und getrennt
     * eingeschlossen (NULL greift nicht bei IN (...)). Der Status wird bewusst
     * als Whitelist geführt: Ein künftig ergänzter, hier nicht klassifizierter
     * Status zählt dann nicht versehentlich als Start.
     *
     * @var list<string>
     */
    private const array COUNTING_STATUSES = ['DSQ', 'DNF'];

    /** Vereinsnation, die als "inländisch" gilt. */
    private const string DOMESTIC_NATION_CODE = 'AUT';

    /**
     * Vereinswertung nach Starts für einen Cup.
     *
     * Reihung (Spec §3.3 / §14): Starts absteigend, dann Anzahl Athleten
     * absteigend, dann Anzahl Cup-Meets absteigend, dann Vereinsname aufsteigend.
     *
     * @param  bool|null  $includeForeignClubs  ausländische Vereine mitwerten;
     *                                          null = Konfigurationswert verwenden
     * @return Collection<int, StartClubRankingResult> gereiht, mit dynamischem Rang;
     *                                                 leer, wenn keine gewerteten Starts
     *                                                 vorliegen
     */
    public function getRanking(Cup $cup, ?bool $includeForeignClubs = null): Collection
    {
        $includeForeignClubs ??= (bool) config('cup_club_ranking.include_foreign_clubs', false);

        $meetIds = Meet::where('cup_id', $cup->id)->pluck('id');

        if ($meetIds->isEmpty()) {
            return collect();
        }

        $starts = $this->distinctStarts($meetIds);

        if ($starts->isEmpty()) {
            return collect();
        }

        $byClub = $starts->groupBy('club_id');

        // withTrashed(): Vereine, die inzwischen soft-deleted sind, aber
        // historisch Ergebnisse haben, bleiben in der (historischen) Wertung
        // sichtbar und ihre Nation ist für den Inland-/Ausland-Filter verfügbar.
        $clubs = Club::withTrashed()
            ->with('nation:id,code')
            ->whereIn('id', $byClub->keys())
            ->get(['id', 'name', 'short_name', 'nation_id'])
            ->keyBy('id');

        $rows = $byClub
            ->reject(fn (Collection $clubStarts, int $clubId): bool => ! $includeForeignClubs
                && ! $this->isDomestic($clubs->get($clubId)))
            ->map(fn (Collection $clubStarts, int $clubId): array => [
                'club_id' => $clubId,
                'club_name' => (string) ($clubs->get($clubId)?->display_name ?? '—'),
                'starts' => $clubStarts->count(),
                'athletes' => $clubStarts->pluck('athlete_id')->unique()->count(),
                'meets' => $clubStarts->pluck('meet_id')->unique()->count(),
            ])
            ->values()
            ->sort(fn (array $a, array $b): int => [$b['starts'], $b['athletes'], $b['meets']]
            <=> [$a['starts'], $a['athletes'], $a['meets']]
                ?: strcmp($a['club_name'], $b['club_name']))
            ->values();

        return $this->assignRanks($rows);
    }

    /**
     * Ermittelt die eindeutigen Starts (distinct Athlet × Einzelbewerb ×
     * Cup-Meet) über alle Cup-Meets.
     *
     * Der "Einzelbewerb" wird über (Distanz, Schwimmart) des swim_events
     * abgebildet; Runden (Vorlauf/Finale = getrennte Events) und Heats fallen
     * dadurch je Athlet und Meet zu einem Start zusammen. Der Startverein ist
     * je (Athlet, Meet, Disziplin) eindeutig — ein Vereinswechsel innerhalb
     * eines Meets ist nicht möglich — daher genügt das erste Vorkommen.
     *
     * Bewusst über den Base-Query-Builder (toBase()) mit explizitem Join auf
     * swim_events: Es werden Projektionszeilen benötigt, keine hydrierten
     * Result-Models. COUNT(DISTINCT a, b) wird vermieden (SQLite der Testsuite
     * unterstützt es nicht) — stattdessen wird in PHP dedupliziert; die
     * Datenmenge einer Cup-Saison ist dafür klein genug. Die Abfrage bleibt so
     * auf MySQL und SQLite gleich portabel.
     *
     * @param  Collection<int, int>  $meetIds  IDs der Cup-Meets
     * @return Collection<int, array{club_id: int, athlete_id: int, meet_id: int}>
     */
    private function distinctStarts(Collection $meetIds): Collection
    {
        $rows = Result::query()
            ->join('swim_events', 'swim_events.id', '=', 'results.swim_event_id')
            ->whereIn('results.meet_id', $meetIds)
            ->where('swim_events.relay_count', '<=', 1)
            ->where(function ($query): void {
                $query->whereNull('results.status')
                    ->orWhereIn('results.status', self::COUNTING_STATUSES);
            })
            ->toBase()
            ->selectRaw(
                'results.club_id as club_id, results.athlete_id as athlete_id, '
                .'results.meet_id as meet_id, swim_events.distance as distance, '
                .'swim_events.stroke_type_id as stroke_type_id'
            )
            ->get();

        return $rows
            ->groupBy(fn (stdClass $row): string => implode('|', [
                $row->athlete_id,
                $row->meet_id,
                $row->distance,
                $row->stroke_type_id,
            ]))
            ->map(fn (Collection $group): stdClass => $group->first())
            ->values()
            ->map(fn (stdClass $row): array => [
                'club_id' => (int) $row->club_id,
                'athlete_id' => (int) $row->athlete_id,
                'meet_id' => (int) $row->meet_id,
            ]);
    }

    /** Ist der Verein einem inländischen (österreichischen) Verband zugeordnet? */
    private function isDomestic(?Club $club): bool
    {
        return $club?->nation?->code === self::DOMESTIC_NATION_CODE;
    }

    /**
     * Weist der bereits gereihten Vereinsliste den Rang zu. Gleiche
     * Wertungskriterien (starts, athletes, meets) ergeben denselben Rang, der
     * nächste Rang überspringt entsprechend (Sportwertung). Der Vereinsname ist
     * nur Anzeigekriterium und beeinflusst den Rang nicht.
     *
     * @param  Collection<int, array{club_id: int, club_name: string, starts: int, athletes: int, meets: int}>  $rowsSorted
     * @return Collection<int, StartClubRankingResult>
     */
    private function assignRanks(Collection $rowsSorted): Collection
    {
        $rank = 0;
        $position = 0;
        $previousKey = null;

        return $rowsSorted->map(function (array $row) use (&$rank, &$position, &$previousKey): StartClubRankingResult {
            $position++;
            $key = [$row['starts'], $row['athletes'], $row['meets']];

            if ($previousKey === null || $key !== $previousKey) {
                $rank = $position;
            }

            $previousKey = $key;

            return new StartClubRankingResult(
                rank: $rank,
                clubId: $row['club_id'],
                clubName: $row['club_name'],
                starts: $row['starts'],
                athletes: $row['athletes'],
                meets: $row['meets'],
            );
        });
    }
}
