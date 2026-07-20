<?php

namespace App\Services;

use App\Models\Club;
use App\Models\Result;
use App\Support\ReportConfiguration;
use Illuminate\Database\Eloquent\Builder;

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
     * Grundgesamtheit aller Starts im Auswertungsumfang:
     *   - Einzelstarts (keine Staffeln — relay_count = 1),
     *   - ohne "nicht angetreten"-Status (siehe NON_START_STATUSES),
     *   - eingeschränkt auf die ausgewählten Veranstaltungen; ohne Auswahl auf
     *     alle Meets, deren start_date im Zeitraum liegt.
     *
     * Wichtig: `status = null` (reguläres Ergebnis) muss ausdrücklich
     * eingeschlossen werden, weil `NOT IN (...)` in SQL bei NULL nicht greift.
     *
     * Staffeln werden derzeit ausgeklammert (der Staffelcup ist noch nicht
     * definiert).
     */
    private function startsQuery(ReportConfiguration $config): Builder
    {
        $query = Result::query()
            ->where(function (Builder $q): void {
                $q->whereNull('status')
                    ->orWhereNotIn('status', self::NON_START_STATUSES);
            })
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
