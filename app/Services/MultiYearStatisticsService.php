<?php

namespace App\Services;

use App\Support\ReportConfiguration;

/**
 * MultiYearStatisticsService
 *
 * Baut die Mehrjahres-Zeitreihe für die 5-Jahres-Vergleichsgrafik (offener
 * Punkt "Statistik: 5-Jahres-Vergleichsgrafik"). Sie beantwortet: "Wie haben
 * sich Starts und Teilnehmer je Geschlecht sowie die Staffelstarts je Typ über
 * die letzten Jahre entwickelt?"
 *
 * Wie das übrige Statistikmodul rechnet der Service nichts selbst, sondern ruft
 * ausschließlich den ParticipationStatisticsService je Kalenderjahr auf und
 * fügt die Ergebnisse zu einer Zeitreihe zusammen. Es wird nichts persistiert;
 * alle Werte entstehen bei jedem Aufruf neu aus den Bestandsdaten.
 *
 * Zählweisen (mit Erik abgestimmt, 22.09.2026):
 *   - Zeitraum: das übergebene Anker-Jahr und die (span − 1) Vorjahre; die
 *     Auswahl konkreter Veranstaltungen spielt hier bewusst keine Rolle
 *     (ein Mehrjahresvergleich ist nicht meet-gefiltert sinnvoll).
 *   - Einzelstarts/-teilnehmer je Geschlecht: unverändert aus byGender().
 *   - Staffelstarts je Typ (Herren/Damen/Mixed): "pro Athlet" — jede
 *     angetretene Staffel-Ergebniszeile zählt einzeln
 *     (relayStartsByEventGender()).
 */
final readonly class MultiYearStatisticsService
{
    /** Vorgabe-Anzahl der Jahre in der Zeitreihe (inkl. Anker-Jahr). */
    public const int DEFAULT_SPAN = 5;

    /**
     * Beschriftung der Einzel-Geschlechter — eine Quelle für Dashboard-Grafik,
     * Bericht und Export.
     *
     * @var array<string, string>
     */
    public const array GENDER_LABELS = ['M' => 'Herren', 'F' => 'Damen', 'N' => 'Nicht binär'];

    /**
     * Beschriftung der Staffel-Typen (swim_events.gender): Herren/Damen/Mixed.
     *
     * @var array<string, string>
     */
    public const array RELAY_GENDER_LABELS = ['M' => 'Herren', 'F' => 'Damen', 'X' => 'Mixed'];

    /**
     * Beschriftung der Ergebnis-Status für die Status-Zeitreihe. Reihenfolge und
     * Schlüssel entsprechen ParticipationStatisticsService::statusBreakdown()
     * (reguläre Ergebnisse unter 'regular').
     *
     * @var array<string, string>
     */
    public const array STATUS_LABELS = [
        'regular' => 'Regulär',
        'EXH' => 'EXH',
        'DSQ' => 'DSQ',
        'DNS' => 'DNS',
        'DNF' => 'DNF',
        'SICK' => 'SICK',
        'WDR' => 'WDR',
    ];

    /**
     * Immer geführte Einzel-Geschlechter. 'N' (nicht binär) erscheint nur, wenn
     * im Zeitraum tatsächlich Daten dazu vorliegen — sonst bliebe eine
     * durchgehende Null-Linie in der Grafik.
     *
     * @var list<string>
     */
    private const array ALWAYS_INDIVIDUAL_GENDERS = ['M', 'F'];

    public function __construct(
        private ParticipationStatisticsService $participation,
        private RecordStatisticsService $records,
    ) {}

    /**
     * Zeitreihe für das Anker-Jahr und die (span − 1) Vorjahre.
     *
     * Rückgabe (flache Keys pro Jahr, damit flux:chart je Serie ein "field"
     * lesen kann und Tabelle/Export unverändert darüber iterieren):
     *
     *   [
     *     'anchor_year' => 2026,
     *     'span' => 5,
     *     'years' => [2022, 2023, 2024, 2025, 2026],   // aufsteigend
     *     'individual_genders' => ['M', 'F'],           // ggf. + 'N'
     *     'relay_genders' => ['M', 'F', 'X'],
     *     'rows' => [
     *       ['year' => 2022, 'starts_m' => .., 'starts_f' => .., 'starts_n' => ..,
     *        'participants_m' => .., 'participants_f' => .., 'participants_n' => ..,
     *        'relay_m' => .., 'relay_f' => .., 'relay_x' => ..],
     *       ...
     *     ],
     *   ]
     *
     * @return array{
     *     anchor_year: int,
     *     span: int,
     *     years: list<int>,
     *     individual_genders: list<string>,
     *     relay_genders: list<string>,
     *     rows: list<array<string, int>>
     * }
     */
    public function series(int $anchorYear, int $span = self::DEFAULT_SPAN): array
    {
        $years = $this->yearRange($anchorYear, $span);

        $rows = array_map(fn (int $year): array => $this->row($year), $years);

        return [
            'anchor_year' => $anchorYear,
            'span' => max(1, $span),
            'years' => $years,
            'individual_genders' => $this->individualGendersInUse($rows),
            'relay_genders' => array_keys(self::RELAY_GENDER_LABELS),
            'rows' => $rows,
        ];
    }

    /**
     * Aufgestellte Rekorde je Jahr (nach set_date), als Zeitreihe für die
     * Jahresvergleich-Grafik.
     *
     * @return array{years: list<int>, values: list<int>}
     */
    public function recordsPerYear(int $anchorYear, int $span = self::DEFAULT_SPAN): array
    {
        $years = $this->yearRange($anchorYear, $span);

        return [
            'years' => $years,
            'values' => array_map(
                fn (int $year): int => $this->records->overview(ReportConfiguration::forYear($year))['total'],
                $years,
            ),
        ];
    }

    /**
     * Veranstaltungen mit mindestens einem gewerteten Start je Jahr.
     *
     * @return array{years: list<int>, values: list<int>}
     */
    public function meetsPerYear(int $anchorYear, int $span = self::DEFAULT_SPAN): array
    {
        $years = $this->yearRange($anchorYear, $span);

        return [
            'years' => $years,
            'values' => array_map(
                fn (int $year): int => $this->participation->meetsWithStarts(ReportConfiguration::forYear($year)),
                $years,
            ),
        ];
    }

    /**
     * Ergebnis-Status (regulär + EXH/DSQ/DNS/DNF/SICK/WDR) je Jahr, gesamt über
     * alle Veranstaltungen. Grundlage für die Status-Zeitreihe.
     *
     * @return array{years: list<int>, statuses: array<string, list<int>>}
     */
    public function statusTrend(int $anchorYear, int $span = self::DEFAULT_SPAN): array
    {
        $years = $this->yearRange($anchorYear, $span);

        $statuses = array_fill_keys(array_keys(self::STATUS_LABELS), []);

        foreach ($years as $year) {
            $breakdown = $this->participation->statusBreakdown(ReportConfiguration::forYear($year));

            foreach (array_keys(self::STATUS_LABELS) as $status) {
                $statuses[$status][] = $breakdown[$status] ?? 0;
            }
        }

        return ['years' => $years, 'statuses' => $statuses];
    }

    /**
     * Jahresachse: das Anker-Jahr und die (span − 1) Vorjahre, aufsteigend.
     *
     * @return list<int>
     */
    private function yearRange(int $anchorYear, int $span): array
    {
        $span = max(1, $span);

        return range($anchorYear - $span + 1, $anchorYear);
    }

    /**
     * Kennzahlen eines einzelnen Kalenderjahres, mit den flachen Chart-Keys.
     *
     * @return array<string, int>
     */
    private function row(int $year): array
    {
        $config = ReportConfiguration::forYear($year);

        $byGender = $this->participation->byGender($config)->keyBy('gender');
        $relay = $this->participation->relayStartsByEventGender($config);

        $row = ['year' => $year];

        foreach (array_keys(self::GENDER_LABELS) as $gender) {
            $suffix = strtolower($gender);
            $row["starts_$suffix"] = (int) ($byGender[$gender]['starts'] ?? 0);
            $row["participants_$suffix"] = (int) ($byGender[$gender]['participants'] ?? 0);
        }

        foreach (array_keys(self::RELAY_GENDER_LABELS) as $gender) {
            $row['relay_'.strtolower($gender)] = $relay[$gender] ?? 0;
        }

        return $row;
    }

    /**
     * Welche Einzel-Geschlechter in der Grafik gezeichnet werden: Herren und
     * Damen immer, 'N' nur, wenn über den Zeitraum überhaupt Starts oder
     * Teilnehmer dazu erfasst sind.
     *
     * @param  list<array<string, int>>  $rows
     * @return list<string>
     */
    private function individualGendersInUse(array $rows): array
    {
        $genders = self::ALWAYS_INDIVIDUAL_GENDERS;

        foreach (array_keys(self::GENDER_LABELS) as $gender) {
            if (in_array($gender, $genders, true)) {
                continue;
            }

            $suffix = strtolower($gender);
            $hasData = array_sum(array_column($rows, "starts_$suffix"))
                + array_sum(array_column($rows, "participants_$suffix")) > 0;

            if ($hasData) {
                $genders[] = $gender;
            }
        }

        return $genders;
    }
}
