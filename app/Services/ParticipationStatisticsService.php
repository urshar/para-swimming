<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Support\ReportConfiguration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * ParticipationStatisticsService
 *
 * Basis-Zählmaschine der Statistik (Spec Phase 2): Anzahl Veranstaltungen,
 * Teilnehmer, Vereine, Starts und Teilnahmen für eine ReportConfiguration.
 *
 * Alle Kennzahlen werden live aus `results` berechnet — es wird nichts
 * persistiert (Spec §23). Ändert oder löscht sich ein Ergebnis, ändern sich
 * die Kennzahlen beim nächsten Aufruf automatisch mit.
 *
 * Die Definition eines "Starts" ist bewusst an genau einer Stelle gekapselt
 * (startsQuery), damit sie zentral anpassbar und in Phase 16 gegen die
 * Referenzzahlen des ÖBSV kalibrierbar bleibt.
 *
 * statusBreakdown() liefert ergänzend, wie oft jeder Ergebnisstatus vorkam
 * (inkl. DNS/SICK/WDR), damit der Bericht diese Fälle ausweisen kann.
 */
final readonly class ParticipationStatisticsService
{
    /**
     * Statuswerte, bei denen der Athlet NICHT angetreten ist und die daher
     * nicht als Start zählen (fachliche Definition B, mit dem ÖBSV bestätigt):
     *   DNS  = did not start
     *   SICK = krank abgemeldet
     *   WDR  = zurückgezogen
     *
     * Als Start zählen dagegen reguläre Ergebnisse (status = null) sowie DSQ,
     * DNF und EXH — in diesen Fällen ist der Athlet angetreten.
     *
     * @var list<string>
     */
    private const array NON_START_STATUSES = ['DNS', 'SICK', 'WDR'];

    /**
     * Alle Nicht-null-Statuswerte des results.status-Enums, in Enum-Reihenfolge.
     * Reguläre Ergebnisse (status = null) werden getrennt unter dem Schlüssel
     * 'regular' geführt.
     *
     * @var list<string>
     */
    private const array SPECIAL_STATUSES = ['EXH', 'DSQ', 'DNS', 'DNF', 'SICK', 'WDR'];

    /**
     * Basiskennzahlen für den Übersichtsabschnitt.
     *
     * @return array{
     *     meets: int,
     *     participants: int,
     *     clubs: int,
     *     foreign_clubs: int,
     *     starts: int,
     *     participations: int
     * }
     */
    public function overview(ReportConfiguration $config): array
    {
        $base = $this->startsQuery($config);
        $clubs = $this->clubBreakdown($base);

        return [
            'meets' => (clone $base)->distinct()->count('meet_id'),
            'participants' => (clone $base)->distinct()->count('athlete_id'),
            'clubs' => $clubs['austrian'],
            'foreign_clubs' => $clubs['foreign'],
            'starts' => (clone $base)->count(),
            'participations' => $this->countParticipations($base),
        ];
    }

    /**
     * Ergebnisse pro Status im Auswertungsumfang (Einzelbewerbe, gleicher
     * Umfang wie overview()). Enthält ausdrücklich auch die "nicht
     * angetreten"-Status DNS, SICK und WDR sowie reguläre Ergebnisse
     * (status = null) unter dem Schlüssel 'regular'.
     *
     * Alle bekannten Schlüssel erscheinen immer (0, falls nicht vorhanden), in
     * stabiler Reihenfolge (regular, dann Enum-Reihenfolge) — praktisch für
     * Berichtstabellen. Die Summe aller Werte entspricht der Gesamtzahl der
     * Einzelergebnisse im Umfang; die Summe von regular + EXH + DSQ + DNF
     * entspricht der Startzahl aus overview().
     *
     * @return array<string, int>
     */
    public function statusBreakdown(ReportConfiguration $config): array
    {
        $breakdown = ['regular' => 0];
        foreach (self::SPECIAL_STATUSES as $status) {
            $breakdown[$status] = 0;
        }

        $rows = $this->scopedQuery($config)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $key = $row->status ?? 'regular';
            $breakdown[$key] = (int) $row->aggregate;
        }

        return $breakdown;
    }

    /**
     * Veranstaltungsstatistik (Spec Phase 3): pro Veranstaltung im Umfang die
     * Anzahl Teilnehmer (unterschiedliche Athleten) und Starts.
     *
     * Ein Athlet zählt je Veranstaltung nur einmal als Teilnehmer. Es werden
     * nur Veranstaltungen mit mindestens einem relevanten Start geliefert
     * (leere Meets erscheinen nicht), sortiert chronologisch nach start_date,
     * dann nach Name.
     *
     * @return Collection<int, array{meet_id: int, meet: string, start_date: ?string, participants: int, starts: int}>
     */
    public function byMeet(ReportConfiguration $config): Collection
    {
        $aggregates = $this->startsQuery($config)
            ->selectRaw('meet_id, COUNT(*) as starts, COUNT(DISTINCT athlete_id) as participants')
            ->groupBy('meet_id')
            ->get()
            ->keyBy('meet_id');

        if ($aggregates->isEmpty()) {
            return collect();
        }

        return Meet::query()
            ->whereIn('id', $aggregates->keys())
            ->orderBy('start_date')
            ->orderBy('name')
            ->get(['id', 'name', 'start_date'])
            ->map(fn (Meet $meet): array => [
                'meet_id' => $meet->id,
                'meet' => $meet->name,
                'start_date' => $meet->start_date?->toDateString(),
                'participants' => (int) $aggregates[$meet->id]->participants,
                'starts' => (int) $aggregates[$meet->id]->starts,
            ])
            ->values();
    }

    /**
     * Vereinsstatistik (Spec Phase 4): pro Verein die Anzahl Teilnehmer
     * (unterschiedliche Athleten) und Starts, absteigend gereiht.
     *
     * Verein = Verein zum Zeitpunkt des Ergebnisses (results.club_id). Ein
     * Athlet zählt je Verein nur einmal als Teilnehmer. Gereiht wird nach
     * Starts (desc), dann Teilnehmer (desc), dann Name (asc); 'rank' ist die
     * 1-basierte Position. Es werden alle beteiligten Vereine (inkl.
     * ausländischer, erkennbar am Nationscode) geliefert.
     *
     * @return Collection<int, array{rank: int, club_id: int, club: string, nation: ?string, participants: int, starts: int}>
     */
    public function byClub(ReportConfiguration $config): Collection
    {
        $aggregates = $this->startsQuery($config)
            ->selectRaw('club_id, COUNT(*) as starts, COUNT(DISTINCT athlete_id) as participants')
            ->groupBy('club_id')
            ->get()
            ->keyBy('club_id');

        if ($aggregates->isEmpty()) {
            return collect();
        }

        return Club::query()
            ->with('nation:id,code')
            ->whereIn('id', $aggregates->keys())
            ->get(['id', 'name', 'nation_id'])
            ->map(fn (Club $club): array => [
                'club_id' => $club->id,
                'club' => $club->name,
                'nation' => $club->nation?->code,
                'participants' => (int) $aggregates[$club->id]->participants,
                'starts' => (int) $aggregates[$club->id]->starts,
            ])
            ->sort(fn (array $a, array $b): int => [$b['starts'], $b['participants']] <=> [$a['starts'], $a['participants']]
                ?: strcmp($a['club'], $b['club']))
            ->values()
            ->map(fn (array $row, int $index): array => ['rank' => $index + 1] + $row);
    }

    /**
     * Sportlerstatistik (Spec Phase 5): pro Sportler die Anzahl der
     * Veranstaltungs-Teilnahmen (unterschiedliche Meets) und Starts, gereiht
     * nach den meisten Teilnahmen.
     *
     * Ein Sportler zählt je Veranstaltung nur einmal als Teilnahme. Gereiht
     * wird nach Teilnahmen (desc), dann Starts (desc), dann Name (asc);
     * 'rank' ist die 1-basierte Position. Der Nationscode stammt aus der
     * Nation des Sportlers (nicht des Vereins), da in Österreich lebende
     * EU-Bürger für österreichische Vereine starten können.
     *
     * @return Collection<int, array{rank: int, athlete_id: int, athlete: string, nation: ?string, participations: int, starts: int}>
     */
    public function byAthlete(ReportConfiguration $config): Collection
    {
        $aggregates = $this->startsQuery($config)
            ->selectRaw('athlete_id, COUNT(*) as starts, COUNT(DISTINCT meet_id) as participations')
            ->groupBy('athlete_id')
            ->get()
            ->keyBy('athlete_id');

        if ($aggregates->isEmpty()) {
            return collect();
        }

        return Athlete::query()
            ->with('nation:id,code')
            ->whereIn('id', $aggregates->keys())
            ->get()
            ->map(fn (Athlete $athlete): array => [
                'athlete_id' => $athlete->id,
                'athlete' => $athlete->display_name,
                'nation' => $athlete->nation?->code,
                'participations' => (int) $aggregates[$athlete->id]->participations,
                'starts' => (int) $aggregates[$athlete->id]->starts,
            ])
            ->sort(fn (array $a, array $b): int => [$b['participations'], $b['starts']] <=> [$a['participations'], $a['starts']]
                ?: strcmp($a['athlete'], $b['athlete']))
            ->values()
            ->map(fn (array $row, int $index): array => ['rank' => $index + 1] + $row);
    }

    /**
     * Anzahl Sportler mit mindestens X Veranstaltungs-Teilnahmen (Spec Phase 5).
     * X stammt aus der Konfiguration (min_participations, Standard 2).
     *
     * Der Schwellenwert wird bewusst in PHP geprüft: Ein gebundener Parameter
     * in HAVING wird von PDO als String übergeben, wodurch SQLite den Vergleich
     * Integer/Text falsch auswertet. Die Aggregatmenge (eine Zeile je Sportler)
     * ist klein genug, um sie zu laden und zu filtern.
     */
    public function countAthletesWithMinParticipations(ReportConfiguration $config): int
    {
        return $this->startsQuery($config)
            ->selectRaw('athlete_id, COUNT(DISTINCT meet_id) as participations')
            ->groupBy('athlete_id')
            ->get()
            ->filter(fn ($row): bool => (int) $row->participations >= $config->minParticipations)
            ->count();
    }

    /**
     * Nationenstatistik (Spec Phase 6): pro Nation die Anzahl Teilnehmer
     * (unterschiedliche Athleten) und Starts, absteigend gereiht.
     *
     * Zuordnung über die Nation des Sportlers (athletes.nation_id), nicht die
     * Vereinsnation — konsistent mit der Sportlerstatistik. Es werden alle
     * beteiligten Nationen inkl. AUT geliefert. Gereiht nach Starts (desc),
     * dann Teilnehmer (desc), dann Nationscode (asc); 'rank' ist die
     * 1-basierte Position.
     *
     * @return Collection<int, array{rank: int, nation_id: int, nation: string, nation_name: string, participants: int, starts: int}>
     */
    public function byNation(ReportConfiguration $config): Collection
    {
        $aggregates = $this->startsQuery($config)
            ->join('athletes', 'athletes.id', '=', 'results.athlete_id')
            ->selectRaw('athletes.nation_id as nation_id, COUNT(*) as starts, COUNT(DISTINCT results.athlete_id) as participants')
            ->groupBy('athletes.nation_id')
            ->get()
            ->keyBy('nation_id');

        if ($aggregates->isEmpty()) {
            return collect();
        }

        return Nation::query()
            ->whereIn('id', $aggregates->keys())
            ->get(['id', 'code', 'name_de'])
            ->map(fn (Nation $nation): array => [
                'nation_id' => $nation->id,
                'nation' => $nation->code,
                'nation_name' => $nation->name_de,
                'participants' => (int) $aggregates[$nation->id]->participants,
                'starts' => (int) $aggregates[$nation->id]->starts,
            ])
            ->sort(fn (array $a, array $b): int => [$b['starts'], $b['participants']] <=> [$a['starts'], $a['participants']]
                ?: strcmp($a['nation'], $b['nation']))
            ->values()
            ->map(fn (array $row, int $index): array => ['rank' => $index + 1] + $row);
    }

    /**
     * Gemeinsamer Auswertungsumfang für alle Kennzahlen:
     *   - Einzelbewerbe (keine Staffeln — relay_count = 1),
     *   - eingeschränkt auf die ausgewählten Veranstaltungen; ohne Auswahl auf
     *     alle Meets, deren start_date im Zeitraum liegt.
     *
     * Bewusst OHNE Status-Filter, damit darauf sowohl die Startzählung
     * (startsQuery) als auch die vollständige Status-Aufschlüsselung
     * (statusBreakdown) aufsetzen können.
     *
     * Staffeln werden derzeit ausgeklammert (der Staffelcup ist noch nicht
     * definiert).
     */
    private function scopedQuery(ReportConfiguration $config): Builder
    {
        $query = Result::query()
            ->whereHas('swimEvent', fn (Builder $q) => $q->where('relay_count', '<=', 1));

        if ($config->isMeetFiltered()) {
            $query->whereIn('results.meet_id', $config->meetIds);
        } else {
            $query->whereHas('meet', fn (Builder $q) => $q->whereBetween('start_date', [
                $config->dateFrom->toDateString(),
                $config->dateTo->toDateString(),
            ]));
        }

        return $query;
    }

    /**
     * Grundgesamtheit aller Starts: der Auswertungsumfang, eingeschränkt auf
     * angetretene Ergebnisse (ohne NON_START_STATUSES).
     *
     * Wichtig: `status = null` (reguläres Ergebnis) muss ausdrücklich
     * eingeschlossen werden, weil `NOT IN (...)` in SQL bei NULL nicht greift.
     */
    private function startsQuery(ReportConfiguration $config): Builder
    {
        return $this->scopedQuery($config)->where(function (Builder $q): void {
            $q->whereNull('results.status')
                ->orWhereNotIn('results.status', self::NON_START_STATUSES);
        });
    }

    /**
     * Anzahl Teilnahmen = Anzahl unterschiedlicher (Athlet, Veranstaltung)-
     * Kombinationen. Ein Athlet zählt pro Veranstaltung genau einmal.
     *
     * Bewusst DB-portabel gelöst (SQLite-Test-DB kennt kein
     * COUNT(DISTINCT a, b)): distinct Paare laden und in PHP zählen.
     */
    private function countParticipations(Builder $base): int
    {
        return (clone $base)
            ->distinct()
            ->get(['athlete_id', 'meet_id'])
            ->count();
    }

    /**
     * Zerlegt die beteiligten Vereine (Verein zum Zeitpunkt des Ergebnisses,
     * results.club_id) in österreichische und ausländische. Nationalität über
     * die Vereinsnation (Code 'AUT'), konsistent mit
     * GroupResolverService::isForeignClub().
     *
     * @return array{austrian: int, foreign: int}
     */
    private function clubBreakdown(Builder $base): array
    {
        $clubIds = (clone $base)->distinct()->pluck('club_id');

        if ($clubIds->isEmpty()) {
            return ['austrian' => 0, 'foreign' => 0];
        }

        $clubs = Club::query()
            ->with('nation:id,code')
            ->whereIn('id', $clubIds)
            ->get(['id', 'nation_id']);

        $austrian = $clubs
            ->filter(fn (Club $club): bool => $club->nation?->code === 'AUT')
            ->count();

        return [
            'austrian' => $austrian,
            'foreign' => $clubs->count() - $austrian,
        ];
    }
}
