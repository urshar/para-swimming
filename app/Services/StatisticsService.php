<?php

namespace App\Services;

use App\Support\ReportConfiguration;
use Illuminate\Support\Collection;

/**
 * StatisticsService
 *
 * Zentrale Fassade des Statistikmoduls (Spec Phase 11). Sie führt die in den
 * Phasen 2–10 entstandenen Einzelservices zusammen und liefert die fertigen
 * Berichtsabschnitte.
 *
 * Die Fassade enthält bewusst KEINE eigene Auswertungslogik: Sie ruft nur die
 * bestehenden Services auf und ordnet deren Ergebnisse den Abschnitten zu.
 * Ebenso wird nichts persistiert — alle Werte entstehen bei jedem Aufruf neu
 * aus den Bestandsdaten.
 *
 * Es werden ausschließlich die in der Konfiguration aktivierten Abschnitte
 * berechnet; deaktivierte Abschnitte fehlen im Ergebnis vollständig. Die
 * Reihenfolge entspricht immer ReportConfiguration::SECTION_KEYS, damit
 * Ansicht und PDF ohne eigene Sortierung darüber iterieren können.
 */
final readonly class StatisticsService
{
    public function __construct(
        private ParticipationStatisticsService $participation,
        private RecordStatisticsService $records,
        private CupStatisticsService $cup,
    ) {}

    /**
     * Erzeugt die Auswertung für die übergebene Konfiguration.
     *
     * Aufbau der Abschnitte:
     *   overview       Basiskennzahlen inkl. Status-Aufschlüsselung
     *   meets          je Veranstaltung Teilnehmer und Starts
     *   participants   Struktur der Teilnehmer (Altersgruppe, Geschlecht)
     *   clubs          je Verein Teilnehmer und Starts
     *   athletes       je Sportler Teilnahmen und Starts
     *   nations        je Nation Teilnehmer und Starts
     *   sport_classes  je Sportklasse und Behinderungsgruppe
     *   records        Rekorde des Zeitraums
     *   cup            Gesamtwertung des ÖBSV-Cups
     *
     * @return array<string, mixed> nur die aktivierten Abschnitte, in der
     *                              Reihenfolge von ReportConfiguration::SECTION_KEYS
     */
    public function generate(ReportConfiguration $config): array
    {
        $sections = [];

        foreach ($config->enabledSections() as $section) {
            $sections[$section] = $this->section($section, $config);
        }

        return $sections;
    }

    /**
     * Baut einen einzelnen Abschnitt. Ausgelagert, damit generate() nur die
     * Auswahl steuert und die Zuordnung Abschnitt ⇒ Service an einer Stelle
     * nachvollziehbar bleibt.
     *
     * Bewusst ohne default-Zweig: Wird ReportConfiguration::SECTION_KEYS um
     * einen Schlüssel erweitert, ohne ihn hier zuzuordnen, schlägt der Aufruf
     * mit einem UnhandledMatchError fehl, statt still einen leeren Abschnitt
     * zu liefern.
     *
     * @return array<string, mixed>|Collection<int, mixed>
     */
    private function section(string $section, ReportConfiguration $config): array|Collection
    {
        return match ($section) {
            'overview' => $this->overview($config),
            'meets' => $this->participation->byMeet($config),
            'participants' => [
                'by_age_group' => $this->participation->byAgeGroup($config),
                'by_gender' => $this->participation->byGender($config),
                'by_age_group_and_gender' => $this->participation->byAgeGroupAndGender($config),
            ],
            'clubs' => $this->participation->byClub($config),
            'athletes' => $this->participation->byAthlete($config),
            'nations' => $this->participation->byNation($config),
            'sport_classes' => [
                'by_sport_class' => $this->participation->bySportClass($config),
                'by_disability_group' => $this->participation->byDisabilityGroup($config),
            ],
            'records' => [
                'overview' => $this->records->overview($config),
                'by_athlete' => $this->records->byAthlete($config),
                'by_record_type' => $this->records->byRecordType($config),
            ],
            'cup' => $this->cup->overallRankingForConfiguration($config),
            'oebm' => $this->championship($config, $config->oebmMeetIds),
            'oejm' => $this->championship($config, $config->oejmMeetIds),
        };
    }

    /**
     * Auswertung einer Meisterschaft (ÖBM/ÖJM, Spec Phase 13).
     *
     * Es gibt kein Datenfeld, das ein Meet als Meisterschaft kennzeichnet;
     * maßgeblich ist deshalb die Auswahl der betreffenden Veranstaltungen in
     * der Konfiguration. Ausgewertet wird derselbe Zeitraum, eingeschränkt auf
     * diese Veranstaltungen — es kommen dieselben Services zum Einsatz wie im
     * übrigen Bericht, es wird nichts gesondert berechnet.
     *
     * Ohne ausgewählte Veranstaltungen bleibt der Abschnitt leer.
     *
     * @param  list<int>  $meetIds
     * @return array<string, mixed>
     */
    private function championship(ReportConfiguration $config, array $meetIds): array
    {
        if ($meetIds === []) {
            return [];
        }

        $scoped = $config->restrictedToMeets($meetIds);

        return [
            'overview' => $this->participation->overview($scoped),
            'meets' => $this->participation->byMeet($scoped),
            'athletes' => $this->participation->byAthlete($scoped),
        ];
    }

    /**
     * Basiskennzahlen, ergänzt um die Status-Aufschlüsselung und die Anzahl
     * der Sportler mit mindestens X Teilnahmen. Der verwendete Schwellenwert
     * wird mitgeliefert, damit der Bericht ihn ausweisen kann, ohne die
     * Konfiguration erneut heranzuziehen.
     *
     * @return array<string, mixed>
     */
    private function overview(ReportConfiguration $config): array
    {
        return $this->participation->overview($config) + [
            'min_participations' => $config->minParticipations,
            'athletes_with_min_participations' => $this->participation->countAthletesWithMinParticipations($config),
            'status_breakdown' => $this->participation->statusBreakdown($config),
        ];
    }
}
