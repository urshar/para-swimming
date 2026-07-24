<?php

namespace App\Console\Commands;

use App\Models\Meet;
use App\Services\StatisticsService;
use App\Support\ReportConfiguration;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * StatisticsReferenceCheckCommand
 *
 * Prüfinstrument für die Referenzvalidierung (Spec Phase 16): Es stellt die
 * ermittelten Kennzahlen eines Berichtsjahres den Werten eines vorliegenden
 * Berichts gegenüber und liefert die Angaben mit, die zur Erklärung von
 * Abweichungen nötig sind.
 *
 * Der Befehl rechnet NICHTS selbst: Er liest ausschließlich das Ergebnis des
 * StatisticsService. Die Referenzwerte werden als Optionen übergeben und
 * dienen nur dem Vergleich — sie fließen an keiner Stelle in eine Berechnung
 * ein. Es wird also nichts "passend gemacht".
 *
 * Beispiel für den Bericht 2024:
 *   php artisan statistics: reference-check 2024 \
 *       --participants=186 --clubs=25 --starts=1464 \
 *       --repeat-athletes=97 --records=85
 */
class StatisticsReferenceCheckCommand extends Command
{
    protected $signature = 'statistics:reference-check
        {year : Berichtsjahr, z.B. 2024}
        {--participants= : erwartete Anzahl Sportler}
        {--clubs= : erwartete Anzahl österreichischer Vereine}
        {--starts= : erwartete Anzahl Starts}
        {--repeat-athletes= : erwartete Sportler mit mindestens X Teilnahmen}
        {--records= : erwartete Anzahl neuer Rekorde}
        {--threshold=2 : Schwellenwert X für "mindestens X Teilnahmen"}';

    protected $description = 'Vergleicht die Statistik eines Jahres mit den Werten eines vorliegenden Berichts';

    public function handle(StatisticsService $statistics): int
    {
        $year = (int) $this->argument('year');

        $config = ReportConfiguration::fromArray([
            'year' => $year,
            'min_participations' => (int) $this->option('threshold'),
            'sections' => collect(ReportConfiguration::SECTION_KEYS)
                ->mapWithKeys(fn (string $key): array => [
                    $key => in_array($key, ['overview', 'meets', 'records'], true),
                ])
                ->all(),
        ]);

        $result = $statistics->generate($config);
        $overview = $result['overview'];
        $records = $result['records']['overview'];

        $this->info("Referenzabgleich Jahresbericht $year");

        // Ohne Daten für das gewählte Jahr wären sämtliche Kennzahlen 0 und
        // jeder Referenzwert würde scheinbar abweichen. Deshalb hier abbrechen
        // und stattdessen zeigen, für welche Jahre Daten vorliegen.
        if ($overview['meets'] === 0 && $records['total'] === 0) {
            $available = Meet::yearsWithMeets();

            $this->warn("Für $year sind keine Veranstaltungen mit Starts erfasst — ein Abgleich ist nicht möglich.");
            $this->line($available->isEmpty()
                ? 'Es sind überhaupt keine Veranstaltungen erfasst.'
                : 'Daten liegen vor für: '.$available->sort()->implode(', '));

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Zeitraum %s bis %s, alle Veranstaltungen des Jahres',
            $config->dateFrom->format('d.m.Y'),
            $config->dateTo->format('d.m.Y'),
        ));
        $this->newLine();

        $deviations = $this->renderComparison($overview, $records);

        $this->renderStartDefinitions($overview['status_breakdown']);
        $this->renderMeets($result['meets']);

        if ($deviations === 0) {
            $this->info('Keine Abweichungen zu den übergebenen Referenzwerten.');

            return self::SUCCESS;
        }

        $this->warn("$deviations Kennzahl(en) weichen ab — mögliche Ursachen siehe unten.");
        $this->line('  • andere Auswahl von Veranstaltungen (Liste unten prüfen)');
        $this->line('  • andere Definition von "Start" (Vergleich oben prüfen)');
        $this->line('  • zwischenzeitlich korrigierte oder nachgetragene Ergebnisse');
        $this->line('  • geänderte Geschäftsregeln, etwa bei Sportklassen oder Vereinen');

        return self::SUCCESS;
    }

    /**
     * Gegenüberstellung der Kennzahlen. Gibt die Anzahl der Kennzahlen zurück,
     * die von einem übergebenen Referenzwert abweichen.
     *
     * @param  array<string, mixed>  $overview
     * @param  array<string, int>  $records
     */
    private function renderComparison(array $overview, array $records): int
    {
        $threshold = (int) $this->option('threshold');

        $metrics = [
            ['Sportler', 'participants', $overview['participants']],
            ['Österreichische Vereine', 'clubs', $overview['clubs']],
            ['Starts', 'starts', $overview['starts']],
            [
                "Sportler mit mind. $threshold Teilnahmen", 'repeat-athletes',
                $overview['athletes_with_min_participations'],
            ],
            ['Neue Rekorde', 'records', $records['total']],
        ];

        $rows = [];
        $deviations = 0;

        foreach ($metrics as [$label, $option, $actual]) {
            $expected = $this->option($option);

            if ($expected === null) {
                $rows[] = [$label, '—', $this->number($actual), '—'];

                continue;
            }

            $delta = $actual - (int) $expected;

            if ($delta !== 0) {
                $deviations++;
            }

            $rows[] = [
                $label,
                $this->number((int) $expected),
                $this->number($actual),
                $delta === 0 ? '0' : sprintf('%+d', $delta),
            ];
        }

        $this->table(['Kennzahl', 'Referenz', 'Ermittelt', 'Abweichung'], $rows);

        return $deviations;
    }

    /**
     * Wirkung der Startdefinition auf die Startzahl. Die Werte werden rein
     * rechnerisch aus der Statusaufschlüsselung abgeleitet — es gibt keine
     * zweite Zähllogik. Weicht die Startzahl ab, lässt sich hier ablesen, ob
     * eine andere Definition die Referenz treffen würde.
     *
     * @param  array<string, int>  $status
     */
    private function renderStartDefinitions(array $status): void
    {
        $regular = $status['regular'] + $status['EXH'];

        $this->line('<comment>Startdefinition — Auswirkung auf die Startzahl</comment>');
        $this->table(
            ['Definition', 'Starts'],
            [
                ['Alle Ergebniszeilen', $this->number(array_sum($status))],
                [
                    'Angetreten: ohne DNS/SICK/WDR (aktuell)',
                    $this->number($regular + $status['DSQ'] + $status['DNF']),
                ],
                ['Nur gewertete Ergebnisse: zusätzlich ohne DSQ/DNF', $this->number($regular)],
            ],
        );

        $this->line('Ergebnisse nach Status: '.collect($status)
            ->map(fn (int $count, string $key): string => "$key $count")
            ->implode(' · '));
        $this->newLine();
    }

    /**
     * Die ausgewerteten Veranstaltungen — hilft zu erkennen, ob dem Bericht
     * eine andere Auswahl zugrunde lag.
     *
     * @param  Collection<int, array<string, mixed>>  $meets
     */
    private function renderMeets(Collection $meets): void
    {
        $this->line('<comment>Ausgewertete Veranstaltungen</comment>');

        if ($meets->isEmpty()) {
            $this->line('Keine Veranstaltungen mit Starts im Zeitraum.');
            $this->newLine();

            return;
        }

        $this->table(
            ['Veranstaltung', 'Datum', 'Teilnehmer', 'Starts'],
            $meets->map(fn (array $row): array => [
                $row['meet'],
                $row['start_date'],
                $row['participants'],
                $row['starts'],
            ])->all(),
        );
    }

    private function number(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }
}
