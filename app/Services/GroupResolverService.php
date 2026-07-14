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
 * Sportklassengruppe zu — inklusive der Top-Gruppen-Logik (Punkt 8 der Spec:
 * Nationalkader zum Wettkampfdatum, Punktgrenze, ausländischer Verein) —
 * sowie einer Altersgruppe nach der 31.12.-Stichtagsregel.
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
     *         geladene Zuordnung sport_class => SportClassGroup (siehe loadSportClassMap()),
     *         um bei Massenverarbeitung (Tageswertung) N+1-Abfragen zu vermeiden.
     */
    public function resolveSportClassGroup(Result $result, Cup $cup, ?Collection $sportClassMap = null): ?SportClassGroup
    {
        if ($this->isTopGroup($result, $cup)) {
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
     * Prüft die drei Top-Gruppen-Kriterien aus Punkt 8 der Spec. Ein einziges
     * zutreffendes Kriterium genügt.
     */
    public function isTopGroup(Result $result, Cup $cup): bool
    {
        return $this->isNationalKaderAthlete($result)
            || $this->exceedsTopGroupThreshold($result, $cup)
            || $this->isForeignClub($result);
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

    // ── Top-Gruppen-Kriterien (Punkt 8 der Spec) ─────────────────────────────

    private function isNationalKaderAthlete(Result $result): bool
    {
        $athlete = $result->athlete;

        if (! $athlete) {
            return false;
        }

        return $athlete->isInKaderOn($result->meet?->start_date ?? now());
    }

    /** "mehr als" die konfigurierte Punktgrenze → strikt größer, nicht größer-gleich. */
    private function exceedsTopGroupThreshold(Result $result, Cup $cup): bool
    {
        return $result->points !== null && $result->points > $cup->top_group_points_threshold;
    }

    private function isForeignClub(Result $result): bool
    {
        $nationCode = $result->club?->nation?->code;

        return $nationCode !== null && $nationCode !== 'AUT';
    }

    private function topGroup(): ?SportClassGroup
    {
        return SportClassGroup::where('code', 'TOP')->where('is_virtual', true)->first();
    }

    // ── Altersgruppe (Punkt 5 der Spec) ──────────────────────────────────────

    /**
     * Ordnet einen Athleten einer Altersgruppe zu — nach der im Schwimmsport
     * üblichen Stichtagsregel: maßgeblich ist das Alter, das der Athlet am
     * 31.12. des Wettkampfjahres erreicht (nicht das exakte Alter am
     * Wettkampftag selbst). Ein Athlet, der z.B. am 31.12. eines Jahres
     * Geburtstag hat, zählt das ganze Jahr über bereits als ein Jahr älter.
     *
     * Gibt null zurück, wenn kein Geburtsdatum hinterlegt ist oder keine
     * aktive Altersgruppe passt.
     */
    public function resolveAgeGroup(Athlete $athlete, CarbonInterface|string $meetDate): ?AgeGroup
    {
        if (! $athlete->birth_date) {
            return null;
        }

        $meetDate = $meetDate instanceof CarbonInterface ? $meetDate : Carbon::parse($meetDate);
        $yearEnd = Carbon::create($meetDate->year, 12, 31);
        $age = $athlete->birth_date->diffInYears($yearEnd);

        return AgeGroup::active()
            ->orderBy('sort_order')
            ->get()
            ->first(fn (AgeGroup $group) => $group->matchesAge($age));
    }
}
