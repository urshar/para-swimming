<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Exception;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * MeetEntryListExportService
 *
 * Excel-Fassung der meldebasierten Listen (Teilnehmerliste, Sportpasskontrolle),
 * nachgebildet an die verbindlichen Vorlagen der Bundesorganisationen:
 *
 *   - TeilnehmerInnenliste (Sport Austria) — Logo oben rechts, ein Blatt je Verein.
 *   - Liste für Sportpasskontrolle (ÖBSV) — ÖBSV-Logo/Briefkopf, über alle Vereine.
 *
 * Layout, Spaltenbreiten, Überschriften und Logos folgen den Originalen; die
 * Handausfüll-Spalten (Wohnort, Unterschrift bzw. Datum, Anmerkung, FAUS) bleiben
 * leer. Datei in storage ablegen, Pfad zurückgeben (Muster wie
 * StatisticsExportService); der Controller liefert sie aus und löscht sie danach.
 */
final readonly class MeetEntryListExportService
{
    private const string EXPORT_DIRECTORY = 'app/entry-list-exports';

    public function __construct(private MeetEntryListService $lists) {}

    /**
     * Teilnehmerliste: ein Arbeitsblatt je Verein (Sport-Austria-Vorlage).
     *
     * @param  Collection<int, array{club: Club, participants: Collection<int, Athlete>}>  $byClub
     *
     * @throws Exception
     */
    public function teilnehmerXlsx(Meet $meet, Collection $byClub): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        if ($byClub->isEmpty()) {
            $spreadsheet->createSheet()->setTitle('Keine Teilnehmer');
        }

        $days = $this->lists->days($meet);
        $usedTitles = [];

        foreach ($byClub as $group) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($this->uniqueSheetTitle($group['club']->display_name, $usedTitles));
            $this->buildTeilnehmerSheet($sheet, $meet, $group['club'], $group['participants'], $days);
        }

        return $this->save($spreadsheet);
    }

    /**
     * Sportpasskontrolle: eine flache Liste über alle Vereine (ÖBSV-Vorlage).
     *
     * @param  Collection<int, array{athlete: Athlete, club: Club}>  $participants
     *
     * @throws Exception
     */
    public function sportpassXlsx(Meet $meet, Collection $participants): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sportpasskontrolle');
        $this->buildSportpassSheet($sheet, $meet, $participants);

        return $this->save($spreadsheet);
    }

    /** Dateiname für den Download. */
    public function downloadFilename(Meet $meet, string $kind): string
    {
        $slug = str($meet->name)->slug()->limit(40, '')->value() ?: 'wettkampf';

        return "$kind-$slug.xlsx";
    }

    /**
     * @param  Collection<int, Athlete>  $participants
     */
    private function buildTeilnehmerSheet(
        Worksheet $sheet,
        Meet $meet,
        Club $club,
        Collection $participants,
        int $days,
    ): void {
        foreach (['A' => 4.7, 'B' => 8.7, 'C' => 15.7, 'D' => 12.7, 'E' => 10.7, 'F' => 11.7, 'G' => 5.7, 'H' => 22.7] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getRowDimension(1)->setRowHeight(46);
        $sheet->getRowDimension(9)->setRowHeight(30);

        // Titel (A1:F1) + Sport-Austria-Logo oben rechts (G1).
        $sheet->mergeCells('A1:F1');
        $sheet->setCellValue('A1', 'T E I L N E H M E R I N N E N L I S T E');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15);
        $sheet->getStyle('A1')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $this->addLogo($sheet, 'sport-austria-logo.png', 'G1', 200);

        $sheet->setCellValue('A3', 'BETRIFFT:');
        $sheet->mergeCells('C3:F3');
        $sheet->setCellValue('C3', $meet->name);
        $sheet->setCellValue('G3', 'ORT:');
        $sheet->setCellValue('H3', $meet->city);

        $sheet->mergeCells('C4:F4');
        $sheet->setCellValue('C4', '(Wettkampf / Lehrgang / Seminar usw.)');
        $sheet->setCellValue('H4', '(im Ausland auch Staat)');
        $sheet->getStyle('C4:H4')->getFont()->setSize(7)->setItalic(true);

        $sheet->setCellValue('A5', 'ZEITRAUM:');
        $sheet->setCellValue('C5', 'am / vom:');
        $sheet->setCellValue('D5', $meet->start_date->format('d.m.Y'));
        $sheet->setCellValue('E5', 'bis:');
        $sheet->setCellValue('F5', $meet->end_date?->format('d.m.Y'));
        $sheet->setCellValue('G5', ' = ');
        $sheet->setCellValueExplicit('H5', $days, DataType::TYPE_NUMERIC);
        $sheet->setCellValue('H6', 'TAGE');
        $sheet->getStyle('H6')->getFont()->setSize(7);

        $sheet->mergeCells('A7:C7');
        $sheet->setCellValue('A7', 'ANZAHL DER PERSONEN:');
        $sheet->setCellValueExplicit('D7', $participants->count(), DataType::TYPE_NUMERIC);

        // VEREIN: nicht im Original-Formular (das ist ein generischer Vordruck), hier
        // aber ergänzt, damit beim Ausdruck erkennbar ist, zu welchem Verein die Liste
        // gehört (die Liste wird pro Verein erzeugt).
        $sheet->setCellValue('F7', 'VEREIN:');
        $sheet->mergeCells('G7:H7');
        $sheet->setCellValue('G7', $club->display_name);

        $sheet->setCellValue('H8', 'Bitte in Block- oder Druckschrift ausfüllen');
        $sheet->getStyle('H8')->getFont()->setSize(7)->setItalic(true);

        foreach (['A3', 'G3', 'A5', 'A7', 'F7'] as $labelCell) {
            $sheet->getStyle($labelCell)->getFont()->setBold(true);
        }

        // Kopfzeile der Tabelle (Zeile 9).
        $headerRow = 9;
        $sheet->setCellValue("A$headerRow", 'lfd. Nr.');
        $sheet->mergeCells("B$headerRow:C$headerRow");
        $sheet->setCellValue("B$headerRow", 'FAMILIEN- und VORNAME');
        $sheet->mergeCells("D$headerRow:E$headerRow");
        $sheet->setCellValue("D$headerRow", 'WOHNORT');
        $sheet->setCellValue("F$headerRow", 'TAGE');
        $sheet->mergeCells("G$headerRow:H$headerRow");
        $sheet->setCellValue("G$headerRow", 'UNTERSCHRIFT');

        $row = $headerRow + 1;
        $number = 1;

        foreach ($participants as $athlete) {
            $sheet->getRowDimension($row)->setRowHeight(20);
            $sheet->setCellValueExplicit("A$row", $number, DataType::TYPE_NUMERIC);
            $sheet->mergeCells("B$row:C$row");
            $sheet->setCellValue("B$row", MeetEntryListService::personName($athlete));
            $sheet->mergeCells("D$row:E$row"); // WOHNORT bleibt leer (Handausfüllen)
            $sheet->setCellValueExplicit("F$row", $days, DataType::TYPE_NUMERIC);
            $sheet->mergeCells("G$row:H$row"); // UNTERSCHRIFT bleibt leer
            $row++;
            $number++;
        }

        $lastRow = max($row - 1, $headerRow);
        $this->styleTable($sheet, $headerRow, $lastRow);
        $sheet->getStyle('A'.($headerRow + 1).":A$lastRow")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('F'.($headerRow + 1).":F$lastRow")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A'.($lastRow + 2), 'TeilnehmerInnenliste');
        $sheet->getStyle('A'.($lastRow + 2))->getFont()->setSize(7)->setItalic(true);

        $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
    }

    /**
     * @param  Collection<int, array{athlete: Athlete, club: Club}>  $participants
     */
    private function buildSportpassSheet(Worksheet $sheet, Meet $meet, Collection $participants): void
    {
        foreach (['A' => 4.6, 'B' => 0.9, 'C' => 22.9, 'D' => 9.1, 'E' => 21, 'F' => 12.7, 'G' => 19.1, 'H' => 9] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getRowDimension(1)->setRowHeight(56);
        $sheet->getRowDimension(6)->setRowHeight(30);
        $sheet->getRowDimension(13)->setRowHeight(38);

        // ÖBSV-Logo oben (Spalte E) + Briefkopf.
        $this->addLogo($sheet, 'oebsv-logo.png', 'E1', 130);
        $sheet->setCellValue('A1', 'Bitte in Block- oder');
        $sheet->setCellValue('A2', 'Maschinschrift ausfüllen');
        $sheet->getStyle('A1:A2')->getFont()->setSize(8)->setItalic(true);

        $sheet->setCellValue('E2', 'Österreichischer');
        $sheet->setCellValue('E3', 'Behindertensportverband');
        $sheet->setCellValue('E4', 'Brigittenauer Lände 42');
        $sheet->setCellValue('E5', 'A-1200 Wien');
        $sheet->getStyle('E2')->getFont()->setBold(true);

        $sheet->setCellValue('G1', 'Die Kontrolle wurde durchgeführt von:');
        $sheet->setCellValue('G2', 'Name:');
        $sheet->setCellValue('G4', '.............................................');
        $sheet->setCellValue('G5', 'Unterschrift');
        $sheet->getStyle('G5')->getFont()->setSize(8);

        $sheet->mergeCells('A6:H6');
        $sheet->setCellValue('A6', 'LISTE FÜR SPORTPASSKONTROLLE');
        $sheet->getStyle('A6')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A6')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);

        $sheet->setCellValue('A7', 'Betrifft:');
        $sheet->mergeCells('B7:D7');
        $sheet->setCellValue('B7', $meet->name);
        $sheet->setCellValue('F7', 'Ort:');
        $sheet->setCellValue('G7', $meet->city);

        $sheet->setCellValue('A10', 'in der Zeit vom');
        $sheet->setCellValue('B10', $meet->start_date->format('d.m.Y'));
        $sheet->setCellValue('D10', 'bis');
        $sheet->setCellValue('E10', $meet->end_date?->format('d.m.Y') ?? $meet->start_date->format('d.m.Y'));
        $sheet->setCellValue('F9', 'Gesamtzahl');
        $sheet->setCellValue('F10', 'd. Teilnehmer:');
        $sheet->setCellValueExplicit('G10', $participants->count(), DataType::TYPE_NUMERIC);
        $sheet->getStyle('G10')->getFont()->setBold(true);

        foreach (['A7', 'F7', 'A10'] as $labelCell) {
            $sheet->getStyle($labelCell)->getFont()->setBold(true);
        }

        // Kopfzeile der Tabelle (Zeile 13).
        $headerRow = 13;
        $sheet->setCellValue("A$headerRow", 'lfd. Nr.');
        $sheet->mergeCells("B$headerRow:D$headerRow");
        $sheet->setCellValue("B$headerRow", 'ZU- und VORNAME der TEILNEHMER');
        $sheet->setCellValue("E$headerRow", 'DATUM der letzten UNTERSUCHUNG');
        $sheet->setCellValue("F$headerRow", 'SPORTPASS Nummer');
        $sheet->setCellValue("G$headerRow", 'ANMERKUNG');
        $sheet->setCellValue("H$headerRow", 'FAUS');

        $row = $headerRow + 1;
        $number = 1;

        foreach ($participants as $entry) {
            $sheet->getRowDimension($row)->setRowHeight(24);
            $sheet->setCellValue("A$row", $number.'.');
            $sheet->mergeCells("B$row:D$row");
            $sheet->setCellValue("B$row", $this->nameWithClub($entry['athlete'], $entry['club']));
            $sheet->getStyle("B$row")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
            // E (Datum letzte Untersuchung) bleibt leer
            $sheet->setCellValue("F$row", $entry['athlete']->license);
            // G (Anmerkung), H (FAUS) bleiben leer
            $row++;
            $number++;
        }

        $lastRow = max($row - 1, $headerRow);
        $this->styleTable($sheet, $headerRow, $lastRow);
        $sheet->getStyle('A'.($headerRow + 1).":A$lastRow")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);

        $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
    }

    /** Namenszelle der Sportpassliste: "Nachname Vorname" + Verein als kleinere zweite Zeile. */
    private function nameWithClub(Athlete $athlete, Club $club): RichText
    {
        $rich = new RichText;
        $rich->createText(MeetEntryListService::personName($athlete)."\n");
        $clubRun = $rich->createTextRun($club->display_name);
        $clubRun->getFont()->setSize(8)->getColor()->setRGB('666666');

        return $rich;
    }

    /** Bettet ein Logo aus resources/images an der angegebenen Zelle ein. */
    private function addLogo(Worksheet $sheet, string $file, string $coordinates, int $width): void
    {
        $path = resource_path('images/'.$file);

        if (! is_file($path)) {
            return;
        }

        $drawing = new Drawing;
        $drawing->setPath($path);
        $drawing->setCoordinates($coordinates);
        $drawing->setWidth($width);
        $drawing->setResizeProportional(true);
        $drawing->setWorksheet($sheet);
    }

    /**
     * Rahmen um Kopf- und Datenzeilen; Kopfzeile fett und grau hinterlegt.
     * Beide Listen (Teilnehmer, Sportpass) reichen bis Spalte H.
     */
    private function styleTable(Worksheet $sheet, int $headerRow, int $lastRow): void
    {
        $lastColumn = 'H';
        $range = "A$headerRow:$lastColumn$lastRow";
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        $headerRange = "A$headerRow:$lastColumn$headerRow";
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
        $sheet->getStyle($headerRange)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('EFEFEF');
    }

    /** Excel erlaubt in Blattnamen keine Sonderzeichen/Dubletten, max. 31 Zeichen. */
    private function uniqueSheetTitle(string $title, array &$usedTitles): string
    {
        $clean = preg_replace('/[\\\\\/*?:\[\]]/', '-', $title) ?? $title;
        $clean = mb_substr(trim($clean) ?: 'Verein', 0, 31);

        $candidate = $clean;
        $counter = 2;

        while (in_array($candidate, $usedTitles, true)) {
            $suffix = " ($counter)";
            $candidate = mb_substr($clean, 0, 31 - mb_strlen($suffix)).$suffix;
            $counter++;
        }

        $usedTitles[] = $candidate;

        return $candidate;
    }

    /**
     * @throws Exception
     */
    private function save(Spreadsheet $spreadsheet): string
    {
        $directory = storage_path(self::EXPORT_DIRECTORY);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = $directory.DIRECTORY_SEPARATOR.'entrylist_'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
