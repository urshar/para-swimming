<?php

namespace App\Services;

use App\Models\StrokeType;
use App\Models\WpsPointParameter;
use App\Models\WpsPointVersion;
use App\Support\WpsImportPreview;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as SpreadsheetReaderException;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

/**
 * Importiert die offiziellen World-Para-Swimming-Point-Score-Dateien.
 *
 * Referenz: 2026_01_30__World_Para_Swimming_Points_Calculator.xlsx
 *
 * Aufbau der Datei:
 *   Blatt "Calculator"      — Zeit/Punkte-Rechner mit Formeln, wird ignoriert
 *   Blatt "Parameters"      — die Parametertabelle, einzige Importquelle
 *   Blatt "version control" — Version, Datum, Kommentar
 *
 * Blatt "Parameters", Kopfzeile in Zeile 1, Daten ab Zeile 2:
 *   A Gender  (Men/Women)   D a
 *   B Event   ("100 m Freestyle")   E b
 *   C Class   (S1-S14, SB1-SB14, SM1-SM14)   F c
 *   G p_ref — Spalte mit Formel (Zeit für 1000 Punkte), abgeleitet, wird NICHT gelesen
 *
 * Die Datei enthält ausschließlich Langbahn-Parameter; course wird deshalb auf LCM und
 * official auf true gesetzt. SCM-Parameter werden separat abgeleitet.
 */
final readonly class WpsParameterImportService
{
    private const string SHEET_PARAMETERS = 'Parameters';

    private const string SHEET_VERSION = 'version control';

    /** Erwartete Kopfzeile in den Spalten A–F. Weicht sie ab, ist es eine andere Datei. */
    private const array EXPECTED_HEADERS = ['gender', 'event', 'class', 'a', 'b', 'c'];

    /** @var array<string, string> Bezeichnung im Blatt → gender-Wert */
    private const array GENDER_MAP = [
        'men' => WpsPointParameter::GENDER_MALE,
        'women' => WpsPointParameter::GENDER_FEMALE,
    ];

    /** @var array<string, string> Stilbestandteil der Event-Bezeichnung → stroke_types.lenex_code */
    private const array STROKE_MAP = [
        'freestyle' => 'FREE',
        'backstroke' => 'BACK',
        'breaststroke' => 'BREAST',
        'butterfly' => 'FLY',
        'individual medley' => 'MEDLEY',
    ];

    /**
     * Liest und validiert die Datei, ohne etwas zu speichern.
     *
     * Der Service wirft ausschließlich RuntimeException mit deutschsprachiger Meldung.
     * Technische Ausnahmen von PhpSpreadsheet werden übersetzt, damit der Aufrufer eine
     * verwertbare Meldung erhält statt, eines Bibliotheks-Internums.
     *
     * @throws RuntimeException wenn die Datei nicht lesbar ist, das Arbeitsblatt fehlt
     *                          oder die Kopfzeile abweicht
     */
    public function parse(string $path): WpsImportPreview
    {
        try {
            $spreadsheet = IOFactory::load($path);
        } catch (SpreadsheetReaderException $e) {
            throw new RuntimeException(
                'Die Datei konnte nicht als Excel-Datei gelesen werden: '.$e->getMessage(),
                0,
                $e
            );
        }

        $sheet = $spreadsheet->getSheetByName(self::SHEET_PARAMETERS);

        if (! $sheet instanceof Worksheet) {
            throw new RuntimeException(
                'Das Arbeitsblatt "'.self::SHEET_PARAMETERS.'" fehlt. '.
                'Erwartet wird die offizielle WPS-Point-Score-Datei.'
            );
        }

        $this->assertHeaders($sheet);

        $rows = [];
        $errors = [];
        $seen = [];
        $strokeTypes = $this->strokeTypeIdsByLenexCode();

        // Bewusst eine Zählschleife statt getRowIterator(2): der Iterator wirft eine
        // Exception, sobald die Startzeile über der letzten belegten Zeile liegt — bei einer
        // Datei mit reiner Kopfzeile also immer.
        $lastRow = $sheet->getHighestDataRow();

        for ($number = 2; $number <= $lastRow; $number++) {
            $gender = $this->cell($sheet, 'A', $number);
            $event = $this->cell($sheet, 'B', $number);
            $class = $this->cell($sheet, 'C', $number);

            // Erste vollständig leere Zeile beendet den Datenbereich.
            if ($gender === '' && $event === '' && $class === '') {
                break;
            }

            $parsed = $this->parseRow($sheet, $number, $strokeTypes);

            if (is_string($parsed)) {
                $errors[] = "Zeile $number: $parsed";

                continue;
            }

            $key = implode('|', [
                $parsed['gender'],
                $parsed['stroke_type_id'],
                $parsed['distance'],
                $parsed['sport_class'],
            ]);

            if (isset($seen[$key])) {
                $errors[] = "Zeile $number: Kombination bereits in Zeile $seen[$key] enthalten.";

                continue;
            }

            $seen[$key] = $number;
            $rows[] = $parsed;
        }

        if ($rows === [] && $errors === []) {
            $errors[] = 'Die Datei enthält keine Datenzeilen.';
        }

        return new WpsImportPreview(
            rows: $rows,
            errors: $errors,
            metadata: $this->versionMetadata($spreadsheet->getSheetByName(self::SHEET_VERSION)),
            counts: $this->counts($rows),
        );
    }

    /**
     * Legt die Version an und schreibt die Parameter in einer Transaktion.
     *
     * Eine bereits vorhandene Kombination aus Jahr und Version wird abgelehnt statt ersetzt:
     * verweisen bereits Ergebnisse auf die Version, würde ein Ersetzen deren
     * wps_point_parameter_id ins Leere zeigen lassen und historische Punkte wären nicht mehr
     * nachvollziehbar. Der Administrator muss die alte Version bewusst archivieren oder löschen.
     *
     * @param  array<string, mixed>  $version
     * @return array{version_id: int, parameters: int}
     *
     * @throws RuntimeException bei fehlerhafter Vorschau, bereits vorhandener Version oder
     *                          fehlgeschlagener Transaktion
     */
    public function import(WpsImportPreview $preview, array $version): array
    {
        if (! $preview->isValid()) {
            throw new RuntimeException('Die Datei enthält Fehler und kann nicht importiert werden.');
        }

        $exists = WpsPointVersion::query()
            ->where('year', $version['year'])
            ->where('version', $version['version'] ?? null)
            ->exists();

        if ($exists) {
            throw new RuntimeException(
                "Für das Jahr {$version['year']} existiert bereits die Version ".
                '"'.($version['version'] ?? '—').'". '.
                'Bitte die bestehende Version zuerst archivieren oder löschen, oder eine '.
                'abweichende Versionsbezeichnung wählen.'
            );
        }

        try {
            return $this->persist($preview, $version);
        } catch (Throwable $e) {
            // Die Transaktion ist vollständig zurückgerollt — es bleibt keine halb
            // angelegte Version zurück. Nach außen bleibt es bei RuntimeException,
            // damit der Aufrufer nur einen Fehlertyp behandeln muss.
            throw new RuntimeException('Der Import ist fehlgeschlagen: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @param  array<string, mixed>  $version
     * @return array{version_id: int, parameters: int}
     *
     * @throws Throwable wird von import() gefangen und übersetzt
     */
    private function persist(WpsImportPreview $preview, array $version): array
    {
        return DB::transaction(function () use ($preview, $version): array {
            $created = WpsPointVersion::create([
                'label' => $version['label'],
                'year' => $version['year'],
                'version' => $version['version'] ?? null,
                'source' => $version['source'] ?? null,
                'official' => true,
                'status' => WpsPointVersion::STATUS_ACTIVE,
                'valid_from' => $version['valid_from'],
                'valid_until' => $version['valid_until'] ?? null,
            ]);

            $now = now();

            $records = array_map(
                static fn (array $row): array => $row + [
                    'wps_point_version_id' => $created->id,
                    'course' => WpsPointParameter::COURSE_LCM,
                    'relay_count' => 1,
                    'official' => true,
                    'source' => $version['source'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $preview->rows
            );

            // Stückweise einfügen: 384 Zeilen × 12 Spalten sprengen sonst das
            // Platzhalter-Limit mancher Treiber.
            foreach (array_chunk($records, 100) as $chunk) {
                WpsPointParameter::insert($chunk);
            }

            return ['version_id' => $created->id, 'parameters' => count($records)];
        });
    }

    // ── Parsen einzelner Zeilen ───────────────────────────────────────────────

    /**
     * @param  array<string, int>  $strokeTypes
     * @return array<string, mixed>|string Parametersatz oder Fehlermeldung
     */
    private function parseRow(Worksheet $sheet, int $number, array $strokeTypes): array|string
    {
        $gender = self::GENDER_MAP[strtolower($this->cell($sheet, 'A', $number))] ?? null;

        if ($gender === null) {
            return 'Unbekanntes Geschlecht "'.$this->cell($sheet, 'A', $number).'".';
        }

        $event = $this->parseEvent($this->cell($sheet, 'B', $number), $strokeTypes);

        if (is_string($event)) {
            return $event;
        }

        $sportClass = strtoupper(str_replace(' ', '', $this->cell($sheet, 'C', $number)));

        if (preg_match('/^(SB|SM|S)([1-9]|1[0-4])$/', $sportClass) !== 1) {
            return "Unbekannte Sportklasse \"$sportClass\".";
        }

        $parameters = [];

        foreach (['a' => 'D', 'b' => 'E', 'c' => 'F'] as $name => $column) {
            $value = $this->cell($sheet, $column, $number);

            if ($value === '' || ! is_numeric($value)) {
                return "Parameter $name fehlt oder ist nicht numerisch.";
            }

            $parameters[$name] = (float) $value;
        }

        if ($parameters['a'] <= 0) {
            return 'Parameter a muss größer als 0 sein.';
        }

        return [
            'gender' => $gender,
            'stroke_type_id' => $event['stroke_type_id'],
            'distance' => $event['distance'],
            'sport_class' => $sportClass,
            'parameter_a' => $parameters['a'],
            'parameter_b' => $parameters['b'],
            'parameter_c' => $parameters['c'],
            'notes' => null,
        ];
    }

    /**
     * "100 m Freestyle" → Distanz und Schwimmstil.
     *
     * @param  array<string, int>  $strokeTypes
     * @return array{distance: int, stroke_type_id: int}|string
     */
    private function parseEvent(string $event, array $strokeTypes): array|string
    {
        if (preg_match('/^\s*(\d+)\s*m\s+(.+?)\s*$/i', $event, $matches) !== 1) {
            return "Bewerb \"$event\" konnte nicht gelesen werden.";
        }

        $lenexCode = self::STROKE_MAP[strtolower($matches[2])] ?? null;

        if ($lenexCode === null) {
            return "Unbekannter Schwimmstil \"$matches[2]\".";
        }

        if (! isset($strokeTypes[$lenexCode])) {
            return "Schwimmstil \"$lenexCode\" ist im System nicht angelegt.";
        }

        return [
            'distance' => (int) $matches[1],
            'stroke_type_id' => $strokeTypes[$lenexCode],
        ];
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    private function assertHeaders(Worksheet $sheet): void
    {
        $actual = [];

        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $column) {
            $actual[] = strtolower(trim($this->cell($sheet, $column, 1)));
        }

        if ($actual !== self::EXPECTED_HEADERS) {
            throw new RuntimeException(
                'Unerwartetes Dateiformat. Erwartet werden in den Spalten A–F die Überschriften '.
                'Gender, Event, Class, a, b, c — gefunden wurde: '.implode(', ', $actual).'.'
            );
        }
    }

    private function cell(Worksheet $sheet, string $column, int $row): string
    {
        return trim((string) $sheet->getCell($column.$row)->getCalculatedValue());
    }

    /** @return array<string, int> lenex_code → id */
    private function strokeTypeIdsByLenexCode(): array
    {
        return StrokeType::query()
            ->whereIn('lenex_code', array_values(self::STROKE_MAP))
            ->pluck('id', 'lenex_code')
            ->all();
    }

    /**
     * Versionsangaben aus dem Blatt "version control" (Zeile 2: Version, Datum, Kommentar).
     *
     * Sie dienen ausschließlich der Vorbelegung im Preview und sind vom Administrator
     * korrigierbar — insbesondere valid_from, da das Veröffentlichungsdatum nicht zwingend
     * dem Gültigkeitsbeginn entspricht.
     *
     * @return array<string, mixed>
     */
    private function versionMetadata(?Worksheet $sheet): array
    {
        if (! $sheet instanceof Worksheet) {
            return [];
        }

        $version = $this->cell($sheet, 'A', 2);
        $date = $this->cell($sheet, 'B', 2);
        $comment = $this->cell($sheet, 'C', 2);

        return array_filter([
            'version' => $version !== '' ? $version : null,
            'date' => $date !== '' ? $date : null,
            'comment' => $comment !== '' ? $comment : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function counts(array $rows): array
    {
        return [
            'rows' => count($rows),
            'genders' => count(array_unique(array_column($rows, 'gender'))),
            'events' => count(array_unique(array_map(
                static fn (array $row): string => $row['stroke_type_id'].'-'.$row['distance'],
                $rows
            ))),
            'sport_classes' => count(array_unique(array_column($rows, 'sport_class'))),
        ];
    }
}
