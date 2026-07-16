<?php

namespace App\Services;

use App\Models\AgeGroup;
use App\Models\Athlete;
use App\Models\Cup;
use App\Models\Result;
use App\Models\SportClassGroup;
use App\Models\SportClassGroupMember;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * GroupResolverService
 *
 * Ordnet ein Cup-Ergebnis (Athlet + Result) für die Cupwertung einer
 * Sportklassengruppe zu — inklusive der Top-Gruppen-Logik (Punkt 8 der Spec)
 * — sowie einer Altersgruppe nach der 31.12.-Stichtagsregel.
 *
 * Top-Gruppen-Kriterien:
 *   - Ausländischer Verein (Punkt 6) — sofortige, ergebnisbezogene Prüfung.
 *   - Nationalkader ODER Punkte-Historie der letzten zwei Kalenderjahre —
 *     saisonale Klassifizierung, siehe TopGroupClassificationService. MUSS
 *     vorab per calculateForCup() berechnet worden sein; ohne geladene
 *     Klassifizierungs-Map (siehe resolveSportClassGroup()) gilt ein Athlet
 *     über dieses Kriterium NICHT als Top-Gruppe.
 *
 * Reine Zuordnungslogik, keine Wertungsberechnung (siehe DailyRankingService /
 * OverallRankingService für die eigentliche Tages-/Gesamtwertung).
 */
readonly class GroupResolverService
{
    // ── Sportklassengruppe ───────────────────────────────────────────────────

    /**
     * Ordnet ein Result der für den Cup gültigen Sportklassengruppe zu.
     *
     * Gibt null zurück, wenn
     *   - die Sportklasse keiner Gruppe zugeordnet ist (z.B. Staffel-Klassen), oder
     *   - die zuständige Gruppe für dieses Cup-Jahr deaktiviert ist.
     *
     * @param  Collection<string, SportClassGroup>|null  $sportClassMap  optionale, vorab
     *                                                                   geladene Zuordnung sport_class => SportClassGroup (siehe loadSportClassMap()),
     *                                                                   um bei Massenverarbeitung (Tageswertung) N+1-Abfragen zu vermeiden.
     * @param  Collection<int, bool>|null  $topGroupClassificationMap  optionale, vorab
     *                                                                 geladene Saison-Klassifizierung athlete_id => is_top_group (siehe
     *                                                                 TopGroupClassificationService::loadClassificationMap()).
     */
    public function resolveSportClassGroup(
        Result $result,
        Cup $cup,
        ?Collection $sportClassMap = null,
        ?Collection $topGroupClassificationMap = null
    ): ?SportClassGroup {
        if ($this->isTopGroup($result, $topGroupClassificationMap)) {
            $topGroup = $this->topGroup();

            if ($topGroup && $cup->isGroupActive($topGroup)) {
                return $topGroup;
            }
        }

        $baseGroup = $this->resolveBaseSportClassGroup($result->sport_class, $sportClassMap);

        if (! $baseGroup || ! $cup->isGroupActive($baseGroup)) {
            return null;
        }

        return $baseGroup;
    }

    /**
     * Top-Gruppen-Zugehörigkeit für ein einzelnes Ergebnis (Punkt 8 der Spec).
     *
     * @param  Collection<int, bool>|null  $topGroupClassificationMap  siehe resolveSportClassGroup().
     *                                                                 Fehlt sie, zählt nur das "ausländischer Verein"-Kriterium (die
     *                                                                 saisonale Klassifizierung kann dann nicht geprüft werden).
     */
    public function isTopGroup(Result $result, ?Collection $topGroupClassificationMap = null): bool
    {
        if ($this->isForeignClub($result)) {
            return true;
        }

        if ($topGroupClassificationMap === null) {
            return false;
        }

        return $topGroupClassificationMap->get($result->athlete_id, false);
    }

    /**
     * Sportklasse (z.B. "S4", "SB9") → globale Gruppenzuordnung, unabhängig vom
     * jeweiligen Cup-Jahr. Nutzt sportClassMap, falls übergeben.
     */
    public function resolveBaseSportClassGroup(?string $sportClass, ?Collection $sportClassMap = null): ?SportClassGroup
    {
        if (! $sportClass) {
            return null;
        }

        $sportClass = strtoupper($sportClass);

        if ($sportClassMap !== null) {
            return $sportClassMap[$sportClass] ?? null;
        }

        return SportClassGroupMember::where('sport_class', $sportClass)->first()?->sportClassGroup;
    }

    /**
     * Lädt die komplette Sportklasse-→-Gruppe-Zuordnung einmalig als Map,
     * damit resolveSportClassGroup() bei vielen Results nicht pro Aufruf
     * einzeln nachfragen muss.
     *
     * @return Collection<string, SportClassGroup>
     */
    public function loadSportClassMap(): Collection
    {
        return SportClassGroupMember::with('sportClassGroup')
            ->get()
            ->mapWithKeys(fn (SportClassGroupMember $member) => [$member->sport_class => $member->sportClassGroup]);
    }

    // ── Altersgruppe (Punkt 5 der Spec) ──────────────────────────────────────

    /**
     * Ordnet einen Athleten einer Altersgruppe zu — nach der im Schwimmsport
     * üblichen Stichtagsregel: maßgeblich ist das Alter, das der Athlet am
     * 31.12. des Wettkampfjahres erreicht (nicht das exakte Alter am
     * Wettkampftag selbst). Ein Athlet, der z.B. am 31.12. eines Jahres
     * Geburtstag hat, zählt das ganze Jahr über bereits als ein Jahr älter.
     *
     * Ist die passende Altersgruppe für den übergebenen Cup deaktiviert
     * (Erik: z.B. Jugendwertung für dieses Jahr ausgeschaltet), wird null
     * zurückgegeben — der Athlet landet dadurch in der Gesamtwertung in
     * einer gemeinsamen, altersgruppen-übergreifenden Wertung statt in einer
     * eigenen Alters-Kategorie.
     *
     * Gibt null zurück, wenn kein Geburtsdatum hinterlegt ist oder keine
     * aktive Altersgruppe passt.
     */
    public function resolveAgeGroup(Athlete $athlete, CarbonInterface|string $meetDate, ?Cup $cup = null): ?AgeGroup
    {
        if (! $athlete->birth_date) {
            return null;
        }

        $meetDate = $meetDate instanceof CarbonInterface ? $meetDate : Carbon::parse($meetDate);
        $yearEnd = Carbon::create($meetDate->year, 12, 31);
        $age = $athlete->birth_date->diffInYears($yearEnd);

        $group = AgeGroup::active()
            ->orderBy('sort_order')
            ->get()
            ->first(fn (AgeGroup $group) => $group->matchesAge($age));

        if ($group && $cup && ! $cup->isAgeGroupActive($group)) {
            return null;
        }

        return $group;
    }

    // ── Top-Gruppen-Kriterien ─────────────────────────────────────────────────

    private function isForeignClub(Result $result): bool
    {
        $nationCode = $result->club?->nation?->code;

        return $nationCode !== null && $nationCode !== 'AUT';
    }

    private function topGroup(): ?SportClassGroup
    {
        return SportClassGroup::where('code', 'TOP')->where('is_virtual', true)->first();
    }
}
