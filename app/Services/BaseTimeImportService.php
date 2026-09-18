<?php

namespace App\Services;

use App\Models\BaseTime;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * BaseTimeImportService
 *
 * Importiert die World-Aquatics-Basiswert-Excel-Datei (LC/SC Men/Women, SC/LC Mixed).
 * Die Persistierung erbt diese Klasse von AbstractBaseTimeImportService; hier liegt nur
 * das Excel-spezifische Parsen.
 *
 * Aufbau je Arbeitsblatt:
 *   - Zeile 1 (ab Spalte B): Sportklassen-Spalten
 *   - Spalte A (ab Zeile 2): Bewerbs-Code (z.B. "50FR", "4x100ME") bis zur ersten leeren Zeile
 *   - darunter: Hilfsbereich mit "X to Y"-Zeilen, die den in $B$NN referenzierten
 *     Durchschnitts-Wachstumsfaktor für ein Bewerbs-Paar beschriften
 *
 * Zellwerte:
 *   - Literal, Wert 0            → NOT_APPLICABLE (Bewerb existiert für diese Klasse nicht)
 *   - Literal, Wert > 0          → MANUAL (offizieller Weltrekord, editierbar)
 *   - Formel "=X*(1+ratio)" o.ä. → CALCULATED (automatisch hergeleitet, nicht editierbar)
 *   - leere Zelle                → wird ignoriert (Kombination existiert nicht in der Quelle)
 *
 * Die Formel-Referenz (welches Bewerbs-Paar, welcher Ratio-Wert) wird automatisch erkannt
 * und als base_time_derivation_rules-Zeile gespeichert — nichts ist hartkodiert.
 */
class BaseTimeImportService extends AbstractBaseTimeImportService
{
    /** Bewerbs-Suffix → stroke_types.lenex_code. "IM" (Einzel-Lagen) und "ME" (Staffel-Lagen) teilen sich denselben Stroke-Type. */
    private const array STROKE_SUFFIX_MAP = [
        'FR' => 'FREE',
        'BK' => 'BACK',
        'BR' => 'BREAST',
        'BF' => 'FLY',
        'IM' => 'MEDLEY',
        'ME' => 'MEDLEY',
    ];

    /** Sportklassen-Codes aus der Excel-Datei, die im System unter anderem Namen geführt werden. */
    private const array SPORT_CLASS_ALIASES = [
        'R20' => 'S20',
        'R34' => 'S34',
        'R49' => 'S49',
    ];

    private const array SHEET_NAMES = ['LC Men', 'LC Women', 'SC Men', 'SC Women', 'SC Mixed', 'LC Mixed'];

    /**
     * Erkennt Zellen der Form "=F5*(1+$B$41)" oder "=C21/(1+'LC Men'!$B$39)".
     * refcol/refrow: Bewerb, aus dem hergeleitet wird (immer gleiche Spalte = gleiche Sportklasse).
     * ratiosheet/ratiorow: Zelle mit dem Durchschnitts-Wachstumsfaktor (ggf. anderes Arbeitsblatt).
     */
    private const string FORMULA_PATTERN = '/^=(?:\'(?<refsheet>[^\']+)\'!)?(?<refcol>[A-Z]+)(?<refrow>\d+)(?<op>[*\/])\(1\+(?:\'(?<ratiosheet>[^\']+)\'!)?\$B\$(?<ratiorow>\d+)\)$/';

    /**
     * Liest die Excel-Datei und liefert eine strukturierte Vorschau, ohne die Datenbank zu ändern.
     *
     * @throws Exception wenn die Datei nicht gelesen werden kann
     */
    public function parse(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);

        $categories = [];
        $disciplines = [];
        $sportClasses = [];
        $cells = [];
        $warnings = [];

        $mainTables = [];
        $labelRows = [];
        $classColumns = [];

        foreach (self::SHEET_NAMES as $sheetName) {
            $sheet = $spreadsheet->getSheetByName($sheetName);
            if (! $sheet) {
                $warnings[] = "Arbeitsblatt \"$sheetName\" wurde in der Datei nicht gefunden — übersprungen.";

                continue;
            }

            $categories[$this->categoryCode($sheetName)] ??= $this->categoryAttributes($sheetName);
            $mainTables[$sheetName] = $this->readMainTableRows($sheet);
            $classColumns[$sheetName] = $this->readSportClassColumns($sheet);

            $lastRow = $mainTables[$sheetName] === [] ? 1 : max(array_keys($mainTables[$sheetName]));
            $labelRows[$sheetName] = $this->readLabelRows($sheet, $lastRow + 1);

            foreach ($classColumns[$sheetName] as $rawClassCode) {
                $classCode = self::SPORT_CLASS_ALIASES[$rawClassCode] ?? $rawClassCode;
                $sportClasses[$classCode] ??= ['sort_order' => count($sportClasses)];
            }

            foreach ($mainTables[$sheetName] as $code) {
                if (! isset($disciplines[$code])) {
                    $attrs = $this->disciplineAttributes($code, $warnings);
                    if ($attrs !== null) {
                        $disciplines[$code] = $attrs;
                    }
                }
            }
        }

        foreach (self::SHEET_NAMES as $sheetName) {
            $sheet = $spreadsheet->getSheetByName($sheetName);
            if (! $sheet || ! isset($mainTables[$sheetName])) {
                continue;
            }

            foreach ($mainTables[$sheetName] as $row => $disciplineCode) {
                if (! isset($disciplines[$disciplineCode])) {
                    continue; // Bewerbs-Code konnte nicht geparst werden, bereits als Warnung erfasst
                }

                foreach ($classColumns[$sheetName] as $col => $rawClassCode) {
                    $classCode = self::SPORT_CLASS_ALIASES[$rawClassCode] ?? $rawClassCode;
                    $coordinate = Coordinate::stringFromColumnIndex($col).$row;
                    $cell = $sheet->getCell($coordinate);
                    $raw = $cell->getValue();

                    if ($raw === null || $raw === '') {
                        continue;
                    }

                    $result = $this->parseCell(
                        $cell,
                        $raw,
                        $sheetName,
                        $disciplineCode,
                        $mainTables,
                        $labelRows,
                        $disciplines,
                        $warnings,
                    );

                    if ($result === null) {
                        continue;
                    }

                    $cells[] = $result + [
                        'category_code' => $this->categoryCode($sheetName),
                        'discipline_code' => $disciplineCode,
                        'sport_class_code' => $classCode,
                    ];
                }
            }
        }

        return compact('categories', 'disciplines', 'sportClasses', 'cells', 'warnings');
    }

    private function categoryCode(string $sheetName): string
    {
        return strtoupper(str_replace(' ', '_', $sheetName));
    }

    /** Leitet Kurs (LCM/SCM) und Geschlecht (M/F/X) aus dem Arbeitsblatt-Namen ab. */
    private function categoryAttributes(string $sheetName): array
    {
        [$courseWord, $genderWord] = array_pad(explode(' ', $sheetName, 2), 2, '');

        return [
            'course' => match (strtoupper($courseWord)) {
                'LC' => 'LCM',
                'SC' => 'SCM',
                default => null,
            },
            'gender' => match (strtoupper($genderWord)) {
                'MEN' => 'M',
                'WOMEN' => 'F',
                'MIXED' => 'X',
                default => null,
            },
            'label' => $sheetName,
        ];
    }

    // ── Sheet-Struktur lesen ──────────────────────────────────────────────────

    /** Liest Spalte A ab Zeile 2, bis zur ersten leeren Zeile. Gibt [row ⇒ Bewerbs-Code] zurück. */
    private function readMainTableRows(Worksheet $sheet): array
    {
        $rows = [];
        $row = 2;

        while (true) {
            $value = $sheet->getCell('A'.$row)->getValue();
            if ($value === null || trim((string) $value) === '') {
                break;
            }
            $rows[$row] = trim((string) $value);
            $row++;
        }

        return $rows;
    }

    /** Liest Zeile 1 ab Spalte B. Gibt [Spaltenindex ⇒ Sportklassen-Code] zurück. */
    private function readSportClassColumns(Worksheet $sheet): array
    {
        $columns = [];
        $highestCol = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        for ($col = 2; $col <= $highestCol; $col++) {
            $value = $sheet->getCell(Coordinate::stringFromColumnIndex($col).'1')->getValue();
            if (is_string($value) && trim($value) !== '') {
                $columns[$col] = trim($value);
            }
        }

        return $columns;
    }

    /**
     * Liest den Hilfsbereich unterhalb der Haupttabelle: Zeilen, deren Spalte A
     * ein "X to Y"-Label enthält (z.B. "400FR to 800FR"). Gibt [row ⇒ [shorterCode, longerCode]] zurück.
     */
    private function readLabelRows(Worksheet $sheet, int $startRow): array
    {
        $labels = [];
        $highestRow = $sheet->getHighestRow();

        for ($row = $startRow; $row <= $highestRow; $row++) {
            $value = $sheet->getCell('A'.$row)->getValue();
            if (! is_string($value)) {
                continue;
            }
            if (! preg_match('/^(.+?)\s+to\s+(.+)$/i', trim($value), $m)) {
                continue;
            }
            $labels[$row] = [
                $this->normalizeCode($m[1]),
                $this->normalizeCode($m[2]),
            ];
        }

        return $labels;
    }

    private function normalizeCode(string $code): string
    {
        return preg_replace('/\s+/', '', $code);
    }

    // ── Kategorie / Bewerb parsen ─────────────────────────────────────────────

    /** Parst einen Bewerbs-Code wie "50FR" oder "4x100ME" in Distanz/Staffel-Anzahl/Schwimmart. */
    private function disciplineAttributes(string $code, array &$warnings): ?array
    {
        if (! preg_match('/^(?:(\d+)x)?(\d+)([A-Za-z]+)$/', $code, $m)) {
            $warnings[] = "Bewerbs-Code \"$code\" konnte nicht geparst werden (erwartetes Format: \"50FR\" oder \"4x100ME\") — übersprungen.";

            return null;
        }

        $suffix = strtoupper($m[3]);
        if (! isset(self::STROKE_SUFFIX_MAP[$suffix])) {
            $warnings[] = "Unbekanntes Schwimmart-Kürzel \"$suffix\" in Bewerbs-Code \"$code\" — übersprungen.";

            return null;
        }

        return [
            'relay_count' => $m[1] !== '' ? (int) $m[1] : 1,
            'distance' => (int) $m[2],
            'stroke_lenex_code' => self::STROKE_SUFFIX_MAP[$suffix],
        ];
    }

    private function parseCell(
        Cell $cell,
        mixed $raw,
        string $sheetName,
        string $disciplineCode,
        array $mainTables,
        array $labelRows,
        array $disciplines,
        array &$warnings,
    ): ?array {
        if (is_string($raw) && str_starts_with($raw, '=')) {
            return $this->parseFormulaCell(
                $cell, $raw, $sheetName, $disciplineCode, $mainTables, $labelRows, $disciplines, $warnings
            );
        }

        if (! is_numeric($raw)) {
            $warnings[] = "Unerwarteter Zellwert \"$raw\" in $sheetName!{$cell->getCoordinate()} — übersprungen.";

            return null;
        }

        $value = (float) $raw;

        return [
            'value_centiseconds' => (int) round($value * 100),
            'value_type' => $value == 0.0 ? BaseTime::TYPE_NOT_APPLICABLE : BaseTime::TYPE_MANUAL,
            'shorter_code' => null,
            'longer_code' => null,
            'ratio_category_code' => null,
            'ratio_shorter_code' => null,
            'ratio_longer_code' => null,
        ];
    }

    private function parseFormulaCell(
        Cell $cell,
        string $formula,
        string $sheetName,
        string $disciplineCode,
        array $mainTables,
        array $labelRows,
        array $disciplines,
        array &$warnings,
    ): array {
        // Gecachten, von Excel selbst berechneten Wert übernehmen (keine eigene Neuberechnung
        // beim Import — das garantiert identische Werte zur Quelldatei für diese Version).
        $calculated = $cell->getOldCalculatedValue();
        if (! is_numeric($calculated)) {
            $calculated = $cell->getCalculatedValue();
        }

        $base = [
            'value_centiseconds' => is_numeric($calculated) ? (int) round(((float) $calculated) * 100) : 0,
            'value_type' => BaseTime::TYPE_CALCULATED,
            'shorter_code' => null,
            'longer_code' => null,
            'ratio_category_code' => null,
            'ratio_shorter_code' => null,
            'ratio_longer_code' => null,
        ];

        if (! preg_match(self::FORMULA_PATTERN, $formula, $m)) {
            $warnings[] = "Formel \"$formula\" in $sheetName!{$cell->getCoordinate()} entspricht keinem ".
                'bekannten Muster — Wert wurde übernommen, aber ohne Herleitungs-Regel.';

            return $base;
        }

        $refSheet = $m['refsheet'] !== '' ? $m['refsheet'] : $sheetName;
        $refRow = (int) $m['refrow'];
        $sourceCode = $mainTables[$refSheet][$refRow] ?? null;

        $ratioSheet = $m['ratiosheet'] !== '' ? $m['ratiosheet'] : $sheetName;
        $ratioRow = (int) $m['ratiorow'];
        $label = $labelRows[$ratioSheet][$ratioRow] ?? null;

        if ($sourceCode === null || $label === null) {
            $warnings[] = "Formel \"$formula\" in $sheetName!{$cell->getCoordinate()} konnte nicht vollständig ".
                'aufgelöst werden (Referenz-Bewerb oder Ratio-Label fehlt) — Wert wurde übernommen, aber ohne Herleitungs-Regel.';

            return $base;
        }

        // Eigenes Bewerbs-Paar (dieser Bewerb + der referenzierte Bewerb derselben Sportklasse).
        [$ownShorter, $ownLonger] = $this->sortByDistance($disciplineCode, $sourceCode, $disciplines);
        if ($ownShorter === null) {
            $warnings[] = "Bewerbs-Paar \"$disciplineCode\"/\"$sourceCode\" in $sheetName!{$cell->getCoordinate()} ".
                'konnte nicht einsortiert werden — Wert wurde übernommen, aber ohne Herleitungs-Regel.';

            return $base;
        }

        // Bewerbs-Paar, dessen Durchschnitts-Wachstumsfaktor tatsächlich verwendet wird (per Label).
        $labelCodeA = $this->resolveDisciplineCode($label[0], $disciplines, $warnings);
        $labelCodeB = $this->resolveDisciplineCode($label[1], $disciplines, $warnings);

        if ($labelCodeA === null || $labelCodeB === null) {
            $warnings[] = "Ratio-Label \"$label[0] to $label[1]\" ($ratioSheet!B$ratioRow) konnte nicht ".
                'vollständig auf bekannte Bewerbe abgebildet werden — Wert wurde übernommen, aber ohne Herleitungs-Regel.';

            return $base;
        }

        [$ratioShorter, $ratioLonger] = $this->sortByDistance($labelCodeA, $labelCodeB, $disciplines);

        // Zwei unabhängige Overrides: die Kategorie, aus der die MANUAL-Werte für den
        // Durchschnitt gelesen werden, und das Bewerbs-Paar, dessen Wachstum gemessen wird.
        // Ein cross-sheet-Bezug auf dasselbe Bewerbs-Paar setzt nur die Kategorie, kein Paar-Override.
        $sameAsOwnPair = $ratioShorter === $ownShorter && $ratioLonger === $ownLonger;
        $sameSheet = $ratioSheet === $sheetName;

        // WICHTIG: array_merge statt des Array-Union-Operators (+) — sonst würden die bereits in
        // $base gesetzten null-Platzhalter für diese Schlüssel Vorrang behalten und die hier
        // berechneten Werte stillschweigend verwerfen.
        return array_merge($base, [
            'shorter_code' => $ownShorter,
            'longer_code' => $ownLonger,
            'ratio_category_code' => $sameSheet ? null : $this->categoryCode($ratioSheet),
            'ratio_shorter_code' => $sameAsOwnPair ? null : $ratioShorter,
            'ratio_longer_code' => $sameAsOwnPair ? null : $ratioLonger,
        ]);
    }

    private function sortByDistance(string $codeA, string $codeB, array $disciplines): array
    {
        $distA = $this->totalDistance($codeA, $disciplines);
        $distB = $this->totalDistance($codeB, $disciplines);

        if ($distA === null || $distB === null) {
            return [null, null];
        }

        return $distA <= $distB ? [$codeA, $codeB] : [$codeB, $codeA];
    }

    private function totalDistance(string $code, array $disciplines): ?int
    {
        if (! isset($disciplines[$code])) {
            return null;
        }

        return $disciplines[$code]['distance'] * $disciplines[$code]['relay_count'];
    }

    /**
     * Löst einen Bewerbs-Code auf, inkl. Fallback für die IM/ME-Verwechslung
     * in der Quelldatei (z.B. Referenz-Label "4x50IM", tatsächlicher Code "4x50ME").
     */
    private function resolveDisciplineCode(string $code, array $disciplines, array &$warnings): ?string
    {
        if (isset($disciplines[$code])) {
            return $code;
        }

        $swapped = null;
        if (str_ends_with($code, 'IM')) {
            $swapped = substr($code, 0, -2).'ME';
        } elseif (str_ends_with($code, 'ME')) {
            $swapped = substr($code, 0, -2).'IM';
        }

        if ($swapped !== null && isset($disciplines[$swapped])) {
            $warnings[] = "Bewerbs-Code \"$code\" aus einem Ratio-Referenz-Label wurde nicht gefunden, ".
                "als \"$swapped\" interpretiert (IM/ME-Abweichung in der Quelldatei). Bitte prüfen.";

            return $swapped;
        }

        return null;
    }
}
