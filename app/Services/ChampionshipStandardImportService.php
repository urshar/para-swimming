<?php

namespace App\Services;

use App\Models\Championship;
use App\Models\ChampionshipStandard;
use App\Models\StrokeType;
use App\Support\ChampionshipStandardImportPreview;
use App\Support\SportClassValidator;
use App\Support\TimeParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as SpreadsheetReaderException;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

/**
 * Import der WPS-Normdatei (Spec "WPS Qualification" §9.2).
 *
 * Zweistufig wie WpsParameterImportService: parse() liest und validiert, ohne zu schreiben;
 * import() schreibt in einer Transaktion. Der Vorschauschritt ist verbindlich.
 *
 * Aufbau der bekannten Datei (geprüft an "Para Swimming European Open Championships"):
 *
 *   Zeile 1   Titel, enthält den Qualifikationszeitraum im Klartext
 *   Zeile 2   Kopf:      Events | Class | Men        | Women
 *   Zeile 3   Unterkopf:                | MQS | MET  | MQS | MET
 *   ab 4      Daten in den Spalten A–F
 *
 * Vier Eigenheiten dieser Dateien, die den Import sonst still verfälschen:
 *
 * 1. Die Zeiten stehen als TEXT ("01:00.94"), nicht als Excel-Zeitwerte. Gelesen wird
 *    deshalb primär über TimeParser; ein numerischer Wert wird als Excel-Serienwert
 *    behandelt, falls eine künftige Datei es anders hält.
 *
 * 2. Der Bewerbsname steht in VERBUNDENEN Zellen (A4:A13 …). PhpSpreadsheet liefert den Wert
 *    am Anker, die übrigen Zellen leer — das Mitführen des Gruppenkopfs greift also.
 *
 * 3. Es gibt auch verbundene ZEITZELLEN (C8:C9, E27:F29 …), Überbleibsel der
 *    PDF-Konvertierung, die jeweils leere Nachbarn überspannen. Verbundene Werte dürfen
 *    deshalb NICHT nach unten aufgelöst werden: In Zeile 9 bekäme die Klasse S8 sonst die
 *    Zeit von S7 — eine falsche Norm, die niemandem auffällt. Gelesen wird nur der Anker.
 *
 * 4. Zwischen den Bewerbsgruppen stehen LEERZEILEN. Eine leere Zeile darf den Datenbereich
 *    also nicht beenden (anders als bei den Punkteparametern). Schluss ist erst beim
 *    Staffelabschnitt.
 */
final readonly class ChampionshipStandardImportService
{
    /** Erste Datenzeile — darüber Titel, Kopf und Unterkopf. */
    private const int FIRST_DATA_ROW = 4;

    /** Erwartete Kopfzeile. Weicht sie ab, ist es eine andere Datei. */
    private const array EXPECTED_HEADERS = ['events', 'class', 'men', 'women'];

    /**
     * Spaltenpaare je Geschlecht: [MQS-Spalte, MET-Spalte].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const array GENDER_COLUMNS = [
        'M' => ['C', 'D'],
        'F' => ['E', 'F'],
    ];

    /** Stilbestandteil der Bewerbsbezeichnung → stroke_types.lenex_code. */
    private const array STROKE_MAP = [
        'freestyle' => 'FREE',
        'backstroke' => 'BACK',
        'breaststroke' => 'BREAST',
        'butterfly' => 'FLY',
        'individual medley' => 'MEDLEY',
    ];

    /**
     * Zeilen mit diesen Anfängen leiten den Staffelabschnitt ein.
     *
     * Staffelnormen sind in Punkten angegeben ("34 Points") und nicht Teil dieses Moduls.
     */
    private const array RELAY_MARKERS = ['relay', 'mixed'];

    /** Größte plausible Zeilenzahl — Schutz vor einer Datei mit Millionen Leerzeilen. */
    private const int MAX_ROWS = 2000;

    public function __construct(
        private ChampionshipStandardService $standardService
    ) {}

    /**
     * Liest und validiert die Datei, ohne etwas zu speichern.
     *
     * Wirft ausschließlich RuntimeException mit deutschsprachiger Meldung; technische
     * Ausnahmen von PhpSpreadsheet werden übersetzt.
     *
     * @throws RuntimeException wenn die Datei nicht lesbar ist oder die Kopfzeile abweicht
     */
    public function parse(string $path, ?Championship $championship): ChampionshipStandardImportPreview
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

        $sheet = $spreadsheet->getActiveSheet();

        $this->assertHeaders($sheet);

        $rows = [];
        $errors = [];
        $warnings = [];
        $gesehen = [];
        $strokeTypes = $this->strokeTypeIdsByLenexCode();

        $bewerb = '';
        $letzteZeile = min($sheet->getHighestDataRow(), self::MAX_ROWS);
        $staffelnAb = null;

        for ($nummer = self::FIRST_DATA_ROW; $nummer <= $letzteZeile; $nummer++) {
            $spalteA = $this->cell($sheet, 'A', $nummer);
            $klasse = $this->cell($sheet, 'B', $nummer);

            if ($this->isRelaySection($spalteA)) {
                $staffelnAb = $nummer;

                break;
            }

            // Gruppenkopf mitführen: Der Bewerbsname steht nur in der ersten Zeile einer
            // Gruppe (bzw. am Anker der verbundenen Zelle).
            if ($spalteA !== '') {
                $bewerb = $spalteA;
            }

            // Leerzeile zwischen zwei Bewerbsgruppen — kein Fehler, kein Abbruch.
            if ($klasse === '') {
                continue;
            }

            if ($bewerb === '') {
                $errors[] = "Zeile $nummer: Sportklasse \"$klasse\" ohne zugehörigen Bewerb.";

                continue;
            }

            $bewerbsdaten = $this->parseEvent($bewerb, $strokeTypes);

            if (is_string($bewerbsdaten)) {
                $errors[] = "Zeile $nummer: $bewerbsdaten";

                continue;
            }

            try {
                $sportClass = SportClassValidator::normalize($klasse);
            } catch (ValidationException $e) {
                $meldungen = $e->errors()['sport_class'] ?? ['Ungültige Sportklasse.'];
                $errors[] = "Zeile $nummer: ".implode(' ', $meldungen);

                continue;
            }

            foreach (self::GENDER_COLUMNS as $gender => $spalten) {
                $mqs = $this->parseTime($sheet, $spalten[0], $nummer);
                $met = $this->parseTime($sheet, $spalten[1], $nummer);

                if (is_string($mqs)) {
                    $errors[] = "Zeile $nummer, Spalte $spalten[0]: $mqs";

                    continue;
                }

                if (is_string($met)) {
                    $errors[] = "Zeile $nummer, Spalte $spalten[1]: $met";

                    continue;
                }

                // Leere Zellen bedeuten "nicht ausgeschrieben" und erzeugen keine Zeile.
                if ($mqs === null && $met === null) {
                    continue;
                }

                $schluessel = implode('|', [
                    $bewerbsdaten['stroke_type_id'], $bewerbsdaten['distance'], $gender, $sportClass,
                ]);

                if (isset($gesehen[$schluessel])) {
                    $errors[] = sprintf(
                        'Zeile %d: %d m %s / %s / %s kommt bereits in Zeile %d vor.',
                        $nummer,
                        $bewerbsdaten['distance'],
                        $bewerb,
                        $gender,
                        $sportClass,
                        $gesehen[$schluessel],
                    );

                    continue;
                }

                $gesehen[$schluessel] = $nummer;

                $rows[] = [
                    'stroke_type_id' => $bewerbsdaten['stroke_type_id'],
                    'distance' => $bewerbsdaten['distance'],
                    'gender' => $gender,
                    'sport_class' => $sportClass,
                    'mqs_centiseconds' => $mqs,
                    'met_centiseconds' => $met,
                    'event_label' => $bewerb,
                    'row_number' => $nummer,
                ];
            }
        }

        if ($staffelnAb !== null) {
            $warnings[] = sprintf(
                'Der Staffelabschnitt ab Zeile %d wurde übersprungen — Staffelnormen sind in '
                .'Punkten angegeben und nicht Teil dieses Moduls.',
                $staffelnAb,
            );
        }

        $warnings = array_merge($warnings, $this->missingRowWarnings($championship, $rows));

        return new ChampionshipStandardImportPreview(
            $rows,
            $errors,
            $warnings,
            $this->counts($rows),
            $this->parsePeriod($this->cell($sheet, 'A', 1)),
            $this->cell($sheet, 'A', 1) === '' ? null : $this->cell($sheet, 'A', 1),
        );
    }

    /**
     * Schreibt die Normen der Vorschau in die Meisterschaft.
     *
     * Füllt ausschließlich MQS und MET. ÖBSV-Prozentsätze und -Zeiten bleiben unberührt,
     * damit ein erneuter Import die eigenen Festlegungen nicht überschreibt (§9.2). Dafür
     * sorgt die Feld-Whitelist in ChampionshipStandardService::upsertStandard().
     *
     * Zeilen, die in der Datei fehlen, bleiben stehen. Sie zu löschen wäre bei einem
     * Formatfehler in der Datei ein stiller Datenverlust; die Vorschau weist sie aus.
     *
     * @return array{created: int, updated: int}
     *
     * @throws RuntimeException bei fehlerhafter Vorschau oder fehlgeschlagener Transaktion
     */
    public function import(Championship $championship, ChampionshipStandardImportPreview $preview): array
    {
        if (! $preview->isValid()) {
            throw new RuntimeException('Die Datei enthält Fehler und kann nicht importiert werden.');
        }

        try {
            return $this->persist($championship, $preview);
        } catch (Throwable $e) {
            // Die Transaktion ist vollständig zurückgerollt — es bleiben keine halb
            // importierten Normen zurück.
            throw new RuntimeException('Der Import ist fehlgeschlagen: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array{created: int, updated: int}
     *
     * @throws Throwable wird von import() gefangen und übersetzt
     */
    private function persist(Championship $championship, ChampionshipStandardImportPreview $preview): array
    {
        return DB::transaction(function () use ($championship, $preview): array {
            $neu = 0;
            $geaendert = 0;

            foreach ($preview->rows as $row) {
                $vorhanden = $championship->standards()
                    ->where('stroke_type_id', $row['stroke_type_id'])
                    ->where('distance', $row['distance'])
                    ->where('gender', $row['gender'])
                    ->where('sport_class', $row['sport_class'])
                    ->exists();

                $this->standardService->upsertStandard(
                    $championship,
                    $row['stroke_type_id'],
                    $row['distance'],
                    $row['gender'],
                    $row['sport_class'],
                    [
                        'mqs_centiseconds' => $row['mqs_centiseconds'],
                        'met_centiseconds' => $row['met_centiseconds'],
                    ],
                );

                $vorhanden ? $geaendert++ : $neu++;
            }

            return ['created' => $neu, 'updated' => $geaendert];
        });
    }

    /**
     * Prüft die Kopfzeilen 2 und 3.
     *
     * Geprüft werden die vier Beschriftungen aus Zeile 2 (Events, Class, Men, Women). Die
     * Unterüberschriften MQS/MET stehen in Zeile 3 und werden mitgeprüft, weil erst sie die
     * Spaltenzuordnung festlegen: Ohne sie wäre nicht erkennbar, ob Spalte D die MET der
     * Männer oder bereits die MQS der Frauen enthält.
     *
     * @throws RuntimeException
     */
    private function assertHeaders(Worksheet $sheet): void
    {
        $kopf = [
            strtolower($this->cell($sheet, 'A', 2)),
            strtolower($this->cell($sheet, 'B', 2)),
            strtolower($this->cell($sheet, 'C', 2)),
            strtolower($this->cell($sheet, 'E', 2)),
        ];

        $unterkopf = [
            strtolower($this->cell($sheet, 'C', 3)),
            strtolower($this->cell($sheet, 'D', 3)),
            strtolower($this->cell($sheet, 'E', 3)),
            strtolower($this->cell($sheet, 'F', 3)),
        ];

        if ($kopf !== self::EXPECTED_HEADERS || $unterkopf !== ['mqs', 'met', 'mqs', 'met']) {
            throw new RuntimeException(
                'Unerwartetes Dateiformat. Erwartet werden in Zeile 2 die Überschriften '
                .'Events, Class, Men, Women und in Zeile 3 die Unterüberschriften MQS, MET, '
                .'MQS, MET. Gefunden wurde: '.implode(', ', $kopf).' bzw. '
                .implode(', ', $unterkopf).'. '
                .'Das Format der WPS-Dateien ändert sich von Veröffentlichung zu '
                .'Veröffentlichung — die Normen lassen sich in diesem Fall über die '
                .'Normtabelle von Hand pflegen.'
            );
        }
    }

    /**
     * Liest eine Zeitzelle und liefert Hundertstelsekunden, null bei leerer Zelle, oder
     * eine Fehlermeldung als String.
     *
     * Ein Rückstrich kommt im Staffelabschnitt als Platzhalter vor und gilt als leer.
     */
    private function parseTime(Worksheet $sheet, string $column, int $row): int|string|null
    {
        $zelle = $sheet->getCell($column.$row);
        $wert = $zelle->getCalculatedValue();

        if ($wert === null || $wert === '') {
            return null;
        }

        // Excel-Zeitwert als Rückfallebene: Die bekannten Dateien führen Text, eine künftige
        // könnte echte Zeitwerte enthalten. Excel speichert eine Uhrzeit als Bruchteil eines
        // Tages — 0,5 ist zwölf Uhr mittags. Umgerechnet wird deshalb über 86400 Sekunden.
        if (! is_string($wert) && is_numeric($wert)) {
            $centiseconds = (int) round(((float) $wert) * 86400 * 100);

            return $centiseconds > 0 ? $centiseconds : null;
        }

        $text = trim((string) $wert);

        if ($text === '' || $text === '\\' || strtoupper($text) === 'NT') {
            return null;
        }

        $centiseconds = TimeParser::parse($text);

        if ($centiseconds === null) {
            return "\"$text\" ist keine lesbare Zeit. Erwartet wird MM:SS.hh, z.B. 01:13.19.";
        }

        return $centiseconds;
    }

    /**
     * Zerlegt "100m Freestyle" in Strecke und Schwimmstil.
     *
     * @param  array<string, int>  $strokeTypes
     * @return array{stroke_type_id: int, distance: int}|string Fehlermeldung als String
     */
    private function parseEvent(string $event, array $strokeTypes): array|string
    {
        // Mehrfache Leerzeichen kommen in den konvertierten Dateien vor.
        $bereinigt = strtolower(trim(preg_replace('/\s+/', ' ', $event) ?? $event));

        if (preg_match('/(\d+)\s*m\b/', $bereinigt, $treffer) !== 1) {
            return "Aus dem Bewerb \"$event\" ließ sich keine Strecke lesen.";
        }

        $distance = (int) $treffer[1];

        foreach (self::STROKE_MAP as $bezeichnung => $lenexCode) {
            if (! str_contains($bereinigt, $bezeichnung)) {
                continue;
            }

            if (! isset($strokeTypes[$lenexCode])) {
                return "Der Schwimmstil \"$lenexCode\" ist in den Stammdaten nicht angelegt.";
            }

            return ['stroke_type_id' => $strokeTypes[$lenexCode], 'distance' => $distance];
        }

        return "Der Bewerb \"$event\" enthält keinen bekannten Schwimmstil.";
    }

    /**
     * Liest den Qualifikationszeitraum aus der Titelzeile.
     *
     * Erwartet wird eine Formulierung wie "Qualification Period - 1 January 2023 to
     * 26 February 2024". Das Ergebnis dient ausschließlich als Vorschlag in der Vorschau;
     * schlägt das Lesen fehl, ist das kein Fehler.
     *
     * @return array{start: string, end: string}|null
     */
    private function parsePeriod(string $title): ?array
    {
        $muster = '/qualification period\s*[-–]\s*(.+?)\s+to\s+(.+?)(?:\r|\n|$)/i';

        if (preg_match($muster, $title, $treffer) !== 1) {
            return null;
        }

        $von = strtotime(trim($treffer[1]));
        $bis = strtotime(trim($treffer[2]));

        if ($von === false || $bis === false || $von > $bis) {
            return null;
        }

        return ['start' => date('Y-m-d', $von), 'end' => date('Y-m-d', $bis)];
    }

    /**
     * Normen, die in der Meisterschaft stehen, in der Datei aber fehlen.
     *
     * Sie werden nicht gelöscht — bei einem Formatfehler in der Datei wäre das ein stiller
     * Datenverlust. Stattdessen erscheinen sie als Hinweis, damit die Abweichung auffällt.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function missingRowWarnings(?Championship $championship, array $rows): array
    {
        if ($championship === null || $rows === []) {
            return [];
        }

        $inDatei = [];

        foreach ($rows as $row) {
            $inDatei[implode('|', [
                $row['stroke_type_id'], $row['distance'], $row['gender'], $row['sport_class'],
            ])] = true;
        }

        $fehlend = $championship->standards()
            ->with('strokeType')
            ->get()
            ->reject(fn (ChampionshipStandard $standard): bool => isset($inDatei[implode('|', [
                $standard->getAttribute('stroke_type_id'),
                $standard->getAttribute('distance'),
                $standard->getAttribute('gender'),
                $standard->getAttribute('sport_class'),
            ])]));

        if ($fehlend->isEmpty()) {
            return [];
        }

        return [sprintf(
            '%d bereits vorhandene Norm(en) kommen in der Datei nicht vor und bleiben '
            .'unverändert bestehen: %s.',
            $fehlend->count(),
            $fehlend->take(10)
                ->map(fn (ChampionshipStandard $s): string => $s->display_name)
                ->implode(', ').($fehlend->count() > 10 ? ' …' : ''),
        )];
    }

    private function isRelaySection(string $spalteA): bool
    {
        $bereinigt = strtolower(trim($spalteA));

        if ($bereinigt === '') {
            return false;
        }

        foreach (self::RELAY_MARKERS as $marker) {
            if (str_starts_with($bereinigt, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function cell(Worksheet $sheet, string $column, int $row): string
    {
        return trim((string) $sheet->getCell($column.$row)->getCalculatedValue());
    }

    /**
     * @return array<string, int> lenex_code → id
     */
    private function strokeTypeIdsByLenexCode(): array
    {
        return StrokeType::query()
            ->whereIn('lenex_code', array_values(self::STROKE_MAP))
            ->pluck('id', 'lenex_code')
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function counts(array $rows): array
    {
        return [
            'rows' => count($rows),
            'events' => count(array_unique(array_column($rows, 'event_label'))),
            'male' => count(array_filter($rows, static fn (array $r): bool => $r['gender'] === 'M')),
            'female' => count(array_filter($rows, static fn (array $r): bool => $r['gender'] === 'F')),
            'with_met' => count(array_filter($rows, static fn (array $r): bool => $r['met_centiseconds'] !== null)),
        ];
    }
}
