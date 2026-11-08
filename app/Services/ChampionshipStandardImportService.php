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
use RuntimeException;
use Throwable;

/**
 * Import der WPS-Normdatei (Spec "WPS Qualification" §9.2).
 *
 * Zweistufig wie WpsParameterImportService: parse() liest und validiert, ohne zu schreiben;
 * import() schreibt in einer Transaktion. Der Vorschauschritt ist verbindlich.
 *
 * Drei Formate, am Inhalt erkannt: die Excel-Datei (unten), die Word-Datei von World Para
 * Swimming (ChampionshipStandardDocxReader) und die SDMS-Ranglistendatei als XML
 * (ChampionshipStandardXmlParser). Excel und Word liefern dieselbe Rohtabelle (Zellen A–F je
 * Zeile) und laufen durch dieselbe Auswertung (parseTable()); alle drei durch dieselbe
 * Vorschau und denselben Import. Bei Word sind die Zeilennummern in Meldungen
 * Tabellenzeilen.
 *
 * Aufbau der bekannten Excel-Datei (geprüft an "Para Swimming European Open Championships"):
 *
 *   Zeile 1   Titel, enthält den Qualifikationszeitraum im Klartext
 *   Zeile 2   Kopf:      Events | Class | Men        | Women
 *   Zeile 3   Unterkopf:                | MQS | MET  | MQS | MET
 *   ab 4      Daten in den Spalten A–F
 *
 * Die Lage der Kopfzeile ist nicht fest: Aus dem PDF selbst erstellte Dateien haben oft keine
 * Titelzeile (Kopf in Zeile 1, Daten ab Zeile 3). Gesucht wird deshalb in den ersten
 * HEADER_SEARCH_ROWS Zeilen; alles darüber gilt als Titel. Die Spalten A–F bleiben fest.
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
    /** In so vielen Zeilen am Dateianfang wird die Kopfzeile gesucht. */
    private const int HEADER_SEARCH_ROWS = 10;

    /** Erwartete Kopfzeile. Weicht sie ab, ist es eine andere Datei. */
    private const array EXPECTED_HEADERS = ['events', 'class', 'men', 'women'];

    /** Erwartete Unterkopfzeile direkt unter der Kopfzeile (Spalten C–F). */
    private const array EXPECTED_SUBHEADERS = ['mqs', 'met', 'mqs', 'met'];

    /** Gelesene Spalten. Die Position in dieser Liste ist der Zellindex der Rohtabelle. */
    private const array COLUMNS = ['A', 'B', 'C', 'D', 'E', 'F'];

    /**
     * Zellindizes je Geschlecht: [MQS, MET] — entspricht den Spalten C/D bzw. E/F.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private const array GENDER_COLUMNS = [
        'M' => [2, 3],
        'F' => [4, 5],
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

    /** Hinweis am Ende jeder Formatmeldung. */
    private const string MANUAL_HINT = 'Das Format der WPS-Dateien ändert sich von Veröffentlichung '
        .'zu Veröffentlichung — die Normen lassen sich in diesem Fall über die Normtabelle von '
        .'Hand pflegen.';

    /** Größte plausible Zeilenzahl — Schutz vor einer Datei mit Millionen Leerzeilen. */
    private const int MAX_ROWS = 2000;

    public function __construct(
        private ChampionshipStandardService $standardService,
        private ChampionshipStandardXmlParser $xmlParser,
        private ChampionshipStandardDocxReader $docxReader,
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
        if (ChampionshipStandardXmlParser::isXml($path)) {
            return $this->parseXml($path, $championship);
        }

        $tabelle = ChampionshipStandardDocxReader::isDocx($path)
            ? $this->docxReader->read($path)
            : $this->readSpreadsheet($path);

        return $this->parseTable($tabelle, $championship);
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
     * Liest die Excel-Datei als Rohtabelle: je Zeile die Werte der Spalten A–F.
     *
     * Zeitzellen kommen als Text oder als Excel-Zeitwert (Zahl) zurück; parseTime() kann beides.
     *
     * @return array{preamble: list<string>, rows: list<array{number: int, cells: list<mixed>}>}
     *
     * @throws RuntimeException wenn die Datei keine lesbare Excel-Datei ist
     */
    private function readSpreadsheet(string $path): array
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
        $letzteZeile = min($sheet->getHighestDataRow(), self::MAX_ROWS);
        $zeilen = [];

        for ($nummer = 1; $nummer <= $letzteZeile; $nummer++) {
            $zeilen[] = [
                'number' => $nummer,
                'cells' => array_map(
                    fn (string $spalte): mixed => $sheet->getCell($spalte.$nummer)->getCalculatedValue(),
                    self::COLUMNS,
                ),
            ];
        }

        return ['preamble' => [], 'rows' => $zeilen];
    }

    /**
     * Gemeinsame Auswertung für Excel und Word.
     *
     * @param  array{preamble: list<string>, rows: list<array{number: int, cells: list<mixed>}>}  $tabelle
     *
     * @throws RuntimeException wenn die Kopfzeile fehlt oder abweicht
     */
    private function parseTable(array $tabelle, ?Championship $championship): ChampionshipStandardImportPreview
    {
        $zeilen = $tabelle['rows'];
        $kopfIndex = $this->locateHeaderRow($zeilen);
        $titel = $this->title($tabelle['preamble'], array_slice($zeilen, 0, $kopfIndex));

        $rows = [];
        $errors = [];
        $warnings = [];
        $gesehen = [];
        $strokeTypes = $this->strokeTypeIdsByLenexCode();

        $bewerb = '';
        $staffelnAb = null;

        // Daten beginnen unter Kopf- und Unterkopfzeile.
        foreach (array_slice($zeilen, $kopfIndex + 2) as $zeile) {
            $nummer = $zeile['number'];
            $zellen = $zeile['cells'];
            $spalteA = $this->text($zellen[0] ?? null);
            $klasse = $this->text($zellen[1] ?? null);

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
                $mqs = $this->parseTime($zellen[$spalten[0]] ?? null);
                $met = $this->parseTime($zellen[$spalten[1]] ?? null);

                if (is_string($mqs)) {
                    $errors[] = "Zeile $nummer, Spalte ".self::COLUMNS[$spalten[0]].": $mqs";

                    continue;
                }

                if (is_string($met)) {
                    $errors[] = "Zeile $nummer, Spalte ".self::COLUMNS[$spalten[1]].": $met";

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
                'Der Staffelabschnitt ab Zeile %d wurde übersprungen — Staffelnormen sind '
                .'nicht Teil dieses Moduls.',
                $staffelnAb,
            );
        }

        $warnings = array_merge($warnings, $this->missingRowWarnings($championship, $rows));

        return new ChampionshipStandardImportPreview(
            $rows,
            $errors,
            $warnings,
            $this->counts($rows),
            $titel === '' ? null : $this->parsePeriod($titel),
            $titel === '' ? null : $titel,
        );
    }

    /**
     * Vorschau aus der SDMS-Ranglistendatei (XML). Zeitraum und Titel stehen dort strukturiert
     * im Kopf und werden wie beim Excel-Import nur als Vorschlag angeboten.
     *
     * @throws RuntimeException wenn die Datei kein lesbares SDMS-Ranking mit Normen ist
     */
    private function parseXml(string $path, ?Championship $championship): ChampionshipStandardImportPreview
    {
        $daten = $this->xmlParser->parse($path, $this->strokeTypeIdsByLenexCode());

        return new ChampionshipStandardImportPreview(
            $daten['rows'],
            $daten['errors'],
            array_merge($daten['warnings'], $this->missingRowWarnings($championship, $daten['rows'])),
            $this->counts($daten['rows']),
            $daten['period'],
            $daten['title'],
        );
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
     * Sucht die Kopfzeile in den ersten HEADER_SEARCH_ROWS Zeilen und liefert ihren Index in
     * der Rohtabelle.
     *
     * Kopfzeile: Ihre nicht leeren Zellen lauten der Reihe nach Events, Class (bzw. "Sport
     * Class" in der Word-Datei), Men, Women. Die Position von Men/Women ist bewusst offen —
     * in Excel stehen sie in C und E (verbundene Zellen), in Word in der 3. und 4. Zelle.
     *
     * Unterkopf direkt darunter: MQS, MET, MQS, MET in den Spalten C–F. Er ist Pflicht und
     * positionsgenau, weil erst er die Spaltenzuordnung festlegt: Ohne ihn wäre nicht
     * erkennbar, ob Spalte D die MET der Männer oder bereits die MQS der Frauen enthält.
     *
     * @param  list<array{number: int, cells: list<mixed>}>  $zeilen
     *
     * @throws RuntimeException wenn keine Kopfzeile gefunden wird oder der Unterkopf abweicht
     */
    private function locateHeaderRow(array $zeilen): int
    {
        foreach (array_slice($zeilen, 0, self::HEADER_SEARCH_ROWS) as $index => $zeile) {
            if ($this->headerLabels($zeile['cells']) !== self::EXPECTED_HEADERS) {
                continue;
            }

            $unterkopf = $this->subHeaderCells($zeilen[$index + 1]['cells'] ?? []);

            if ($unterkopf !== self::EXPECTED_SUBHEADERS) {
                throw new RuntimeException(sprintf(
                    'Unerwartetes Dateiformat. Die Überschriften Events, Class, Men, Women stehen '
                    .'in Zeile %d, direkt darunter in Zeile %d werden aber die Unterüberschriften '
                    .'MQS, MET, MQS, MET (Spalten C–F) erwartet. Gefunden wurde: %s. %s',
                    $zeile['number'],
                    $zeile['number'] + 1,
                    implode(', ', $unterkopf),
                    self::MANUAL_HINT,
                ));
            }

            return $index;
        }

        throw new RuntimeException(sprintf(
            'Unerwartetes Dateiformat. In den ersten %d Zeilen wurde keine Zeile mit den '
            .'Überschriften Events, Class, Men, Women gefunden, auf die die Unterüberschriften '
            .'MQS, MET, MQS, MET folgen. Die erste Zeile enthält: %s. %s',
            self::HEADER_SEARCH_ROWS,
            implode(', ', $this->headerLabels($zeilen[0]['cells'] ?? [])),
            self::MANUAL_HINT,
        ));
    }

    /**
     * Nicht leere Zellen einer Zeile in Kleinbuchstaben; "Sport Class" zählt als "Class".
     *
     * @param  list<mixed>  $zellen
     * @return list<string>
     */
    private function headerLabels(array $zellen): array
    {
        $beschriftungen = [];

        foreach ($zellen as $zelle) {
            $text = strtolower($this->text($zelle));

            if ($text !== '') {
                $beschriftungen[] = $text === 'sport class' ? 'class' : $text;
            }
        }

        return $beschriftungen;
    }

    /**
     * @param  list<mixed>  $zellen
     * @return list<string> Spalten C–F in Kleinbuchstaben
     */
    private function subHeaderCells(array $zellen): array
    {
        return array_map(
            fn (int $index): string => strtolower($this->text($zellen[$index] ?? null)),
            [2, 3, 4, 5],
        );
    }

    /**
     * Titel aus den Absätzen vor der Tabelle (Word) und Spalte A aller Zeilen oberhalb der
     * Kopfzeile (Excel); leer, wenn es nichts davon gibt.
     *
     * @param  list<string>  $preamble
     * @param  list<array{number: int, cells: list<mixed>}>  $zeilenDarueber
     */
    private function title(array $preamble, array $zeilenDarueber): string
    {
        $teile = $preamble;

        foreach ($zeilenDarueber as $zeile) {
            $text = $this->text($zeile['cells'][0] ?? null);

            if ($text !== '') {
                $teile[] = $text;
            }
        }

        return implode("\n", $teile);
    }

    /**
     * Liest einen Zeitwert und liefert Hundertstelsekunden, null bei leerer Zelle, oder
     * eine Fehlermeldung als String.
     *
     * Ein Rückstrich kommt im Staffelabschnitt als Platzhalter vor und gilt als leer.
     */
    private function parseTime(mixed $wert): int|string|null
    {
        if ($wert === null || $wert === '') {
            return null;
        }

        // Excel-Zeitwert: Excel speichert eine Uhrzeit als Bruchteil eines Tages — 0,5 ist
        // zwölf Uhr mittags. Umgerechnet wird deshalb über 86400 Sekunden. Die Word-Datei und
        // ältere Excel-Dateien führen Text und landen unten beim TimeParser.
        if (! is_string($wert) && is_numeric($wert)) {
            $centiseconds = (int) round(((float) $wert) * 86400 * 100);

            return $centiseconds > 0 ? $centiseconds : null;
        }

        $text = $this->text($wert);

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
     * 26 February 2024". Ein Klammerzusatz am Ende ("13 June 2027 (inclusive)" in der
     * Word-Datei) wird ignoriert. Das Ergebnis dient ausschließlich als Vorschlag in der
     * Vorschau; schlägt das Lesen fehl, ist das kein Fehler.
     *
     * @return array{start: string, end: string}|null
     */
    private function parsePeriod(string $title): ?array
    {
        $muster = '/qualification period\s*[-–]\s*(.+?)\s+to\s+(.+?)(?:\s*\([^)]*\))?\s*(?:\r|\n|$)/im';

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

    private function text(mixed $wert): string
    {
        return is_scalar($wert) ? trim((string) $wert) : '';
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
