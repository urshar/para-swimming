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
     * Sind $cup UND $sportClassGroup gesetzt, werden die Altersgrenzen für
     * diese Kombination DYNAMISCH neu berechnet (Erik, 2026-07-20): Eine
     * deaktivierte Altersgruppe fällt komplett weg — ihre Alterspanne wird
     * automatisch von den benachbarten aktiven Gruppen übernommen, statt
     * dass Athleten in dieser Spanne in eine gemeinsame Wertung ohne
     * Alterskategorie fallen. Siehe effectiveAgeGroupBoundaries().
     *
     * Ist $cup oder $sportClassGroup nicht gesetzt (z.B. unaufgelöste
     * Sportklassengruppe, oder Aufruf außerhalb eines Cup-Kontexts), wird
     * auf die statische, in AgeGroup fix konfigurierte Alters-Spanne
     * zurückgegriffen (Rückwärtskompatibilität).
     *
     * Gibt null zurück, wenn kein Geburtsdatum hinterlegt ist, oder wenn
     * für die Kombination keine einzige Altersgruppe aktiv ist (Erik
     * bestätigt: dann gemeinsame Wertung ohne Alterskategorie).
     */
    public function resolveAgeGroup(
        Athlete $athlete,
        CarbonInterface|string $meetDate,
        ?Cup $cup = null,
        ?SportClassGroup $sportClassGroup = null,
    ): ?AgeGroup {
        if (! $athlete->birth_date) {
            return null;
        }

        $meetDate = $meetDate instanceof CarbonInterface ? $meetDate : Carbon::parse($meetDate);
        $yearEnd = Carbon::create($meetDate->year, 12, 31);
        // Explizite Ganzzahl-Umwandlung: diffInYears() liefert in dieser
        // Carbon-Version einen Float zurück, was sonst zu einer PHP-
        // Deprecation-Warnung führt ("implicit conversion from float to
        // int"). Abschneiden (nicht Runden) ist hier korrekt: die Stichtags-
        // regel fragt "wie viele volle Jahre sind vergangen", nicht das
        // rechnerisch gerundete Alter.
        $age = (int) $athlete->birth_date->diffInYears($yearEnd);

        if ($cup && $sportClassGroup) {
            $match = $this->effectiveAgeGroupBoundaries($cup, $sportClassGroup)
                ->first(fn (array $b
                ) => $age >= $b['effectiveMin'] && ($b['effectiveMax'] === null || $age <= $b['effectiveMax']));

            return $match['ageGroup'] ?? null;
        }

        return AgeGroup::active()
            ->orderBy('sort_order')
            ->get()
            ->first(fn (AgeGroup $group) => $group->matchesAge($age));
    }

    /**
     * Berechnet für eine Kombination (Cup, Sportklassengruppe) die
     * tatsächlichen Altersgrenzen der AKTIVEN Altersgruppen (Erik,
     * 2026-07-20). Prinzip: eine deaktivierte Altersgruppe verschwindet
     * komplett aus der Alters-Skala — die verbleibenden aktiven Gruppen
     * rücken lückenlos zusammen:
     *   - die erste aktive Gruppe (niedrigstes Alter) beginnt immer bei 0,
     *     unabhängig von ihrer konfigurierten Untergrenze;
     *   - die letzte aktive Gruppe (höchstes Alter) ist immer nach oben
     *     offen (kein Maximum), unabhängig von ihrer konfigurierten
     *     Obergrenze;
     *   - alle "mittleren" aktiven Gruppen behalten ihre eigene
     *     konfigurierte Untergrenze; ihre Obergrenze ergibt sich aus der
     *     Untergrenze der nächsten aktiven Gruppe minus 1.
     *
     * Beispiel: Jugend (aktiv, Konfig 0–18) + offen (aktiv, Konfig 19+) +
     * Senioren (aktiv, Konfig 50+) → effektiv Jugend 0–18, offen 19–49,
     * Senioren 50+. Ist Senioren deaktiviert, wird offen 19+ (unbegrenzt).
     * Ist zusätzlich Jugend deaktiviert, wird offen 0+ (die einzige aktive
     * Gruppe deckt die gesamte Altersskala ab).
     *
     * @return Collection<int, array{ageGroup: AgeGroup, effectiveMin: int, effectiveMax: ?int}>
     */
    public function effectiveAgeGroupBoundaries(Cup $cup, SportClassGroup $sportClassGroup): Collection
    {
        $active = AgeGroup::active()
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (AgeGroup $ageGroup) => $cup->isAgeGroupActive($ageGroup, $sportClassGroup))
            ->values();

        return $active->map(function (AgeGroup $ageGroup, int $index) use ($active) {
            $effectiveMin = $index === 0 ? 0 : (int) $ageGroup->min_age;

            $next = $active->get($index + 1);
            $effectiveMax = $next ? ((int) $next->min_age - 1) : null;

            return ['ageGroup' => $ageGroup, 'effectiveMin' => $effectiveMin, 'effectiveMax' => $effectiveMax];
        });
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
