<?php

namespace App\Services;

use App\Models\CupOverallResult;
use App\Support\ReportConfiguration;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Exception;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * StatisticsExportService
 *
 * Export des Jahresberichts als Excel und CSV (Spec Phase 15). Der PDF-Export
 * liegt weiterhin beim PdfExportService.
 *
 * Der Service rechnet nichts: Er erhält die fertigen Abschnitte des
 * StatisticsService und überführt sie in Tabellen. Genutzt wird die bereits
 * vorhandene Excel-Infrastruktur (PhpSpreadsheet), im selben Muster wie
 * BaseTimeExportService — Datei in storage ablegen, Pfad zurückgeben, der
 * Controller liefert sie aus und löscht sie danach.
 */
final readonly class StatisticsExportService
{
    /** Verzeichnis für die erzeugten Dateien (wird nach dem Download geleert). */
    private const string EXPORT_DIRECTORY = 'app/statistics-exports';

    /** Excel begrenzt Blattnamen auf 31 Zeichen. */
    private const int MAX_SHEET_TITLE = 31;

    /**
     * Erzeugt eine Excel-Datei mit einem Arbeitsblatt je Tabelle und gibt den
     * absoluten Dateipfad zurück.
     *
     * @param  array<string, mixed>  $statistics
     * @param  string|null  $section  nur diesen Abschnitt exportieren; null = alle
     *
     * @throws Exception
     */
    public function xlsx(array $statistics, ?string $section = null): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        $tables = $this->tables($statistics, $section);

        if ($tables->isEmpty()) {
            $spreadsheet->createSheet()->setTitle('Keine Daten');
        }

        $usedTitles = [];

        foreach ($tables as $table) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($this->uniqueSheetTitle($table['title'], $usedTitles));

            // Ohne startCell schreibt fromArray() ab A1 — das ist hier gewünscht.
            // strictNullComparison ist zwingend: ohne sie vergleicht
            // PhpSpreadsheet lose gegen null, wodurch jede 0 und jeder leere
            // String als leere Zelle statt als Wert geschrieben würde.
            $sheet->fromArray($table['headers'], strictNullComparison: true);
            $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);
            $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('EFEFEF');

            if ($table['rows'] !== []) {
                $sheet->fromArray($table['rows'], startCell: 'A2', strictNullComparison: true);
            }

            foreach (range('A', $sheet->getHighestColumn()) as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
        }

        $path = $this->filePath('xlsx');
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    /**
     * Erzeugt eine CSV-Datei und gibt den absoluten Dateipfad zurück.
     *
     * CSV kennt keine Arbeitsblätter; besteht ein Abschnitt aus mehreren
     * Tabellen, werden sie mit Titelzeile und Leerzeile untereinander
     * geschrieben. Semikolon und BOM sorgen dafür, dass Excel die Datei
     * unter Windows direkt korrekt öffnet.
     *
     * @param  array<string, mixed>  $statistics
     *
     * @throws Exception
     */
    public function csv(array $statistics, ?string $section = null): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $row = 1;

        foreach ($this->tables($statistics, $section) as $table) {
            $sheet->setCellValue("A$row", $table['title']);
            $sheet->getStyle("A$row")->getFont()->setBold(true);
            $row++;

            $sheet->fromArray($table['headers'], startCell: "A$row", strictNullComparison: true);
            $row++;

            if ($table['rows'] !== []) {
                $sheet->fromArray($table['rows'], startCell: "A$row", strictNullComparison: true);
                $row += count($table['rows']);
            }

            $row++; // Leerzeile zwischen den Tabellen
        }

        $path = $this->filePath('csv');

        $writer = new Csv($spreadsheet);
        $writer->setDelimiter(';');
        $writer->setUseBOM(true);
        $writer->save($path);

        return $path;
    }

    /** Dateiname für den Download, z.B. "jahresbericht-2024-vereine.csv". */
    public function downloadFilename(ReportConfiguration $config, string $extension, ?string $section = null): string
    {
        $suffix = $section === null ? '' : '-'.str_replace('_', '-', $section);

        return "jahresbericht-$config->year$suffix.$extension";
    }

    /**
     * Überführt die Abschnitte in exportierbare Tabellen.
     *
     * @param  array<string, mixed>  $statistics
     * @return Collection<int, array{title: string, headers: list<string>, rows: list<list<mixed>>}>
     */
    private function tables(array $statistics, ?string $section = null): Collection
    {
        $sections = $section === null
            ? $statistics
            : array_intersect_key($statistics, [$section => true]);

        $tables = collect();

        foreach ($sections as $key => $data) {
            foreach ($this->tablesForSection($key, $data) as $table) {
                $tables->push($table);
            }
        }

        return $tables;
    }

    /**
     * @return list<array{title: string, headers: list<string>, rows: list<list<mixed>>}>
     */
    private function tablesForSection(string $section, mixed $data): array
    {
        return match ($section) {
            'overview' => $this->overviewTables($data),
            'meets' => [$this->meetTable('Veranstaltungen', $data)],
            'participants' => [
                $this->table('Altersgruppen', ['Altersgruppe', 'Teilnehmer', 'Starts'], $data['by_age_group'],
                    fn (array $r): array => [$r['age_group_name'], $r['participants'], $r['starts']]),
                $this->table('Geschlecht', ['Geschlecht', 'Teilnehmer', 'Starts'], $data['by_gender'],
                    fn (array $r): array => [$r['gender'], $r['participants'], $r['starts']]),
                $this->table('Altersgruppe x Geschlecht',
                    ['Altersgruppe', 'Geschlecht', 'Teilnehmer', 'Starts'], $data['by_age_group_and_gender'],
                    fn (array $r): array => [$r['age_group_name'], $r['gender'], $r['participants'], $r['starts']]),
            ],
            'clubs' => [
                $this->table('Vereine', ['Rang', 'Verein', 'Nation', 'Teilnehmer', 'Starts'], $data,
                    fn (array $r): array => [$r['rank'], $r['club'], $r['nation'], $r['participants'], $r['starts']]),
            ],
            'athletes' => [$this->athleteTable('Sportler', $data)],
            'nations' => [
                $this->table('Nationen', ['Nation', 'Bezeichnung', 'Teilnehmer', 'Starts'], $data,
                    fn (array $r): array => [$r['nation'], $r['nation_name'], $r['participants'], $r['starts']]),
            ],
            'sport_classes' => [
                $this->table('Sportklassen', ['Sportklasse', 'Behinderungsgruppe', 'Teilnehmer', 'Starts'],
                    $data['by_sport_class'],
                    fn (array $r): array => [$r['sport_class'], $r['group_code'], $r['participants'], $r['starts']]),
                $this->table('Behinderungsgruppen', ['Gruppe', 'Code', 'Teilnehmer', 'Starts'],
                    $data['by_disability_group'],
                    fn (array $r): array => [$r['group_name'], $r['group_code'], $r['participants'], $r['starts']]),
            ],
            'records' => $this->recordTables($data),
            'cup' => [$this->cupTable($data)],
            'oebm' => $this->championshipTables('ÖBM', $data),
            'oejm' => $this->championshipTables('ÖJM', $data),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $overview
     * @return list<array{title: string, headers: list<string>, rows: list<list<mixed>>}>
     */
    private function overviewTables(array $overview): array
    {
        return [
            [
                'title' => 'Überblick',
                'headers' => ['Kennzahl', 'Wert'],
                'rows' => [
                    ['Veranstaltungen', $overview['meets']],
                    ['Teilnehmer', $overview['participants']],
                    ['Teilnahmen', $overview['participations']],
                    ['Starts', $overview['starts']],
                    ['Vereine (AUT)', $overview['clubs']],
                    ['Vereine (Ausland)', $overview['foreign_clubs']],
                    ["Sportler mit mind. {$overview['min_participations']} Teilnahmen",
                        $overview['athletes_with_min_participations']],
                ],
            ],
            [
                'title' => 'Ergebnisstatus',
                'headers' => ['Status', 'Anzahl'],
                'rows' => array_map(
                    static fn (string $status, int $count): array => [$status, $count],
                    array_keys($overview['status_breakdown']),
                    array_values($overview['status_breakdown']),
                ),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $records
     * @return list<array{title: string, headers: list<string>, rows: list<list<mixed>>}>
     */
    private function recordTables(array $records): array
    {
        return [
            [
                'title' => 'Rekorde',
                'headers' => ['Kennzahl', 'Wert'],
                'rows' => [
                    ['Gesamt', $records['overview']['total']],
                    ['Österreich', $records['overview']['austrian']],
                    ['Österreich Jugend', $records['overview']['austrian_junior']],
                    ['Staffel', $records['overview']['relay']],
                    ['Ohne Sportler', $records['overview']['without_athlete']],
                ],
            ],
            $this->table('Rekordarten', ['Rekordart', 'Anzahl'], $records['by_record_type'],
                fn (array $r): array => [$r['record_type'], $r['records']]),
            $this->table('Rekorde je Sportler', ['Rang', 'Sportler', 'Nation', 'Rekorde'], $records['by_athlete'],
                fn (array $r): array => [$r['rank'], $r['athlete'], $r['nation'], $r['records']]),
        ];
    }

    /**
     * Cup-Gesamtwertung als eine flache Tabelle: die Wertungskategorie steht
     * in eigenen Spalten, damit sich die Datei filtern und sortieren lässt.
     *
     * @param  Collection<int, array<string, mixed>>  $cup
     * @return array{title: string, headers: list<string>, rows: list<list<mixed>>}
     */
    private function cupTable(Collection $cup): array
    {
        $rows = [];

        foreach ($cup as $bracket) {
            foreach ($bracket['results'] as $result) {
                /** @var CupOverallResult $result */
                $rows[] = [
                    $bracket['group_name'],
                    $bracket['age_group_name'],
                    $bracket['gender'],
                    $result->rank,
                    $result->athlete?->display_name,
                    $result->club?->name,
                    $result->total_points,
                ];
            }
        }

        return [
            'title' => 'Cup Gesamtwertung',
            'headers' => ['Gruppe', 'Altersgruppe', 'Geschlecht', 'Rang', 'Sportler', 'Verein', 'Punkte'],
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $championship
     * @return list<array{title: string, headers: list<string>, rows: list<list<mixed>>}>
     */
    private function championshipTables(string $label, array $championship): array
    {
        if ($championship === []) {
            return [];
        }

        return [
            [
                'title' => "$label Überblick",
                'headers' => ['Kennzahl', 'Wert'],
                'rows' => [
                    ['Veranstaltungen', $championship['overview']['meets']],
                    ['Teilnehmer', $championship['overview']['participants']],
                    ['Teilnahmen', $championship['overview']['participations']],
                    ['Starts', $championship['overview']['starts']],
                    ['Vereine (AUT)', $championship['overview']['clubs']],
                ],
            ],
            $this->meetTable("$label Veranstaltungen", $championship['meets']),
            $this->athleteTable("$label Sportler", $championship['athletes']),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $meets
     * @return array{title: string, headers: list<string>, rows: list<list<mixed>>}
     */
    private function meetTable(string $title, Collection $meets): array
    {
        return $this->table($title, ['Veranstaltung', 'Datum', 'Teilnehmer', 'Starts'], $meets,
            fn (array $r): array => [$r['meet'], $r['start_date'], $r['participants'], $r['starts']]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $athletes
     * @return array{title: string, headers: list<string>, rows: list<list<mixed>>}
     */
    private function athleteTable(string $title, Collection $athletes): array
    {
        return $this->table(
            $title,
            ['Rang', 'Sportler', 'Nation', 'Teilnahmen', 'Starts'],
            $athletes,
            fn (array $r): array => [
                $r['rank'], $r['athlete'], $r['nation'], $r['participations'], $r['starts'],
            ],
        );
    }

    /**
     * @param  list<string>  $headers
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): list<mixed>  $map
     * @return array{title: string, headers: list<string>, rows: list<list<mixed>>}
     */
    private function table(string $title, array $headers, Collection $rows, callable $map): array
    {
        return [
            'title' => $title,
            'headers' => $headers,
            'rows' => $rows->map($map)->values()->all(),
        ];
    }

    /**
     * Excel erlaubt in Blattnamen weder Sonderzeichen noch Dubletten und
     * begrenzt sie auf 31 Zeichen.
     *
     * @param  list<string>  $usedTitles
     */
    private function uniqueSheetTitle(string $title, array &$usedTitles): string
    {
        $clean = preg_replace('/[\\\\\/*?:\[\]]/', '-', $title) ?? $title;
        $clean = mb_substr($clean, 0, self::MAX_SHEET_TITLE);

        $candidate = $clean;
        $counter = 2;

        while (in_array($candidate, $usedTitles, true)) {
            $suffix = " ($counter)";
            $candidate = mb_substr($clean, 0, self::MAX_SHEET_TITLE - mb_strlen($suffix)).$suffix;
            $counter++;
        }

        $usedTitles[] = $candidate;

        return $candidate;
    }

    /** Legt das Exportverzeichnis an und liefert einen eindeutigen Dateipfad. */
    private function filePath(string $extension): string
    {
        $directory = storage_path(self::EXPORT_DIRECTORY);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $directory.DIRECTORY_SEPARATOR.'statistics_'.uniqid().'.'.$extension;
    }
}
