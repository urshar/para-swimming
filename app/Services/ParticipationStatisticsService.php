<?php

namespace App\Services;

use App\Models\Club;
use App\Models\Meet;
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
            $query->whereIn('meet_id', $config->meetIds);
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
            $q->whereNull('status')
                ->orWhereNotIn('status', self::NON_START_STATUSES);
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
