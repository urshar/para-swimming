<?php

namespace App\Services;

use App\Models\StrokeType;
use App\Models\WpsPointParameter;
use App\Support\WpsImportPreview;
use RuntimeException;

/**
 * Liest WPS-Punktetabellen im Textformat (vermutlich Export aus Splash Meet Manager).
 *
 * Referenz: docs/snippets/WPS_2017.txt ("WPP 2017")
 *
 * Aufbau der Datei:
 *   Kopfblock mit Key=Value-Zeilen  — Formula, Id, Name, Options, ShortNameVersion, Version
 *   Zeile "<BASETIMES>"              — Beginn der Parametertabelle
 *   Spaltenüberschrift, danach Datenzeilen mit ";" als Trenner:
 *     GENDER;RELAYCOUNT;DISTANCE;STROKE;HANDICAP;GOMPERTZA;GOMPERTZB;GOMPERTZC
 *
 * GOMPERTZA/B/C sind dieselben Parameter a, b, c wie in der Excel-Datei
 * (q = a * e^(-e^(b - c/p))). Die Datei enthält keine Bahnlänge; wie beim Excel-Import
 * gelten die Parameter als Langbahn-Parameter (course und official setzt
 * WpsParameterImportService::persist()).
 *
 * Fehlermeldungen nennen die Zeilennummer in der Datei, damit sie sich im Editor direkt
 * finden lassen.
 */
final readonly class WpsPointTableTextParser
{
    private const string SECTION_BASETIMES = '<BASETIMES>';

    /** Erwartete Spaltenüberschrift nach "<BASETIMES>". Weicht sie ab, ist es eine andere Datei. */
    private const array EXPECTED_HEADERS = [
        'gender', 'relaycount', 'distance', 'stroke', 'handicap', 'gompertza', 'gompertzb', 'gompertzc',
    ];

    /** @var array<string, string> Pflichtangaben im Kopfblock (Schlüssel → erwarteter Wert) */
    private const array REQUIRED_HEADER_VALUES = [
        'formula' => 'GOMPERTZ',
        'options' => 'HANDICAP',
    ];

    /** @var array<string, string> Kopfblock-Schlüssel → Schlüssel in der Vorschau */
    private const array METADATA_KEYS = [
        'name' => 'name',
        'shortnameversion' => 'short_name',
        'version' => 'version',
        'id' => 'id',
    ];

    /** @var array<string, string> Geschlecht in der Datei → gender-Wert */
    private const array GENDER_MAP = [
        'M' => WpsPointParameter::GENDER_MALE,
        'W' => WpsPointParameter::GENDER_FEMALE,
    ];

    /** Die Datei verwendet direkt die LENEX-Codes der Schwimmstile. */
    private const array STROKE_CODES = ['FREE', 'BACK', 'BREAST', 'FLY', 'MEDLEY'];

    /**
     * Liest und validiert die Datei, ohne etwas zu speichern.
     *
     * @throws RuntimeException wenn die Datei nicht lesbar ist, der Kopfblock nicht passt,
     *                          der Abschnitt "<BASETIMES>" fehlt oder die Spaltenüberschrift abweicht
     */
    public function parse(string $path): WpsImportPreview
    {
        $content = is_readable($path) ? file_get_contents($path) : false;

        if ($content === false) {
            throw new RuntimeException('Die Datei konnte nicht gelesen werden.');
        }

        // Ein UTF-8-BOM am Dateianfang würde sonst den ersten Schlüssel verfälschen.
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = preg_split('/\R/', $content);

        $header = [];
        $index = 0;
        $lineCount = count($lines);

        for (; $index < $lineCount; $index++) {
            $line = trim($lines[$index]);

            if (strtoupper($line) === self::SECTION_BASETIMES) {
                break;
            }

            if ($line !== '' && str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $header[strtolower(trim($key))] = trim($value);
            }
        }

        if ($index >= $lineCount) {
            throw new RuntimeException(
                'Unerwartetes Dateiformat: Der Abschnitt "'.self::SECTION_BASETIMES.'" fehlt. '.
                'Erwartet wird eine WPS-Punktetabelle im Textformat.'
            );
        }

        $this->assertHeaderValues($header);

        // Spaltenüberschrift: erste nicht leere Zeile nach "<BASETIMES>".
        $index++;

        while ($index < $lineCount && trim($lines[$index]) === '') {
            $index++;
        }

        $this->assertColumnHeaders($index < $lineCount ? $lines[$index] : '');

        $parsedRows = [];
        $strokeTypes = $this->strokeTypeIdsByLenexCode();

        for ($index++; $index < $lineCount; $index++) {
            $line = trim($lines[$index]);

            if ($line === '') {
                continue;
            }

            // Ein weiterer Abschnitt beendet die Parametertabelle.
            if (str_starts_with($line, '<')) {
                break;
            }

            // Schlüssel ist die Zeilennummer in der Datei (1-basiert).
            $parsedRows[$index + 1] = $this->parseRow($line, $strokeTypes);
        }

        return WpsImportPreview::fromParsedRows($parsedRows, $this->metadata($header));
    }

    // ── Parsen einzelner Zeilen ───────────────────────────────────────────────

    /**
     * @param  array<string, int>  $strokeTypes
     * @return array<string, mixed>|string Parametersatz oder Fehlermeldung
     */
    private function parseRow(string $line, array $strokeTypes): array|string
    {
        $fields = array_map('trim', explode(';', $line));

        if (count($fields) !== count(self::EXPECTED_HEADERS)) {
            return 'Erwartet werden '.count(self::EXPECTED_HEADERS).' Felder, gefunden wurden '.count($fields).'.';
        }

        [$genderValue, $relayCount, $distance, $stroke, $sportClass, $a, $b, $c] = $fields;

        $gender = self::GENDER_MAP[strtoupper($genderValue)] ?? null;

        if ($gender === null) {
            return "Unbekanntes Geschlecht \"$genderValue\".";
        }

        if ($relayCount !== '1') {
            return "Staffel-Parameter (RELAYCOUNT $relayCount) werden nicht unterstützt.";
        }

        if (! ctype_digit($distance) || (int) $distance <= 0) {
            return "Ungültige Strecke \"$distance\".";
        }

        $stroke = strtoupper($stroke);

        if (! in_array($stroke, self::STROKE_CODES, true)) {
            return "Unbekannter Schwimmstil \"$stroke\".";
        }

        if (! isset($strokeTypes[$stroke])) {
            return "Schwimmstil \"$stroke\" ist im System nicht angelegt.";
        }

        $sportClass = strtoupper(str_replace(' ', '', $sportClass));

        if (preg_match('/^(SB|SM|S)([1-9]|1[0-4])$/', $sportClass) !== 1) {
            return "Unbekannte Sportklasse \"$sportClass\".";
        }

        $parameters = [];

        foreach (['a' => $a, 'b' => $b, 'c' => $c] as $name => $value) {
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
            'stroke_type_id' => $strokeTypes[$stroke],
            'distance' => (int) $distance,
            'sport_class' => $sportClass,
            'parameter_a' => $parameters['a'],
            'parameter_b' => $parameters['b'],
            'parameter_c' => $parameters['c'],
            'notes' => null,
        ];
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    /**
     * Eine andere Formel oder Tabellenart (z. B. World-Aquatics-Punkte ohne HANDICAP) hätte
     * Parameter mit anderer Bedeutung — die Datei wird dann vollständig abgelehnt.
     *
     * @param  array<string, string>  $header
     */
    private function assertHeaderValues(array $header): void
    {
        foreach (self::REQUIRED_HEADER_VALUES as $key => $expected) {
            $actual = $header[$key] ?? '';

            if (strtoupper($actual) !== $expected) {
                throw new RuntimeException(
                    'Unerwartetes Dateiformat: Im Kopfblock wird "'.ucfirst($key).'='.$expected.'" erwartet, '.
                    'gefunden wurde "'.($actual !== '' ? $actual : '—').'".'
                );
            }
        }
    }

    private function assertColumnHeaders(string $line): void
    {
        $actual = array_map(
            static fn (string $value): string => strtolower(trim($value)),
            explode(';', $line)
        );

        if ($actual !== self::EXPECTED_HEADERS) {
            throw new RuntimeException(
                'Unerwartetes Dateiformat. Erwartet wird nach "'.self::SECTION_BASETIMES.'" die Überschrift '.
                strtoupper(implode(';', self::EXPECTED_HEADERS)).' — gefunden wurde: '.trim($line).'.'
            );
        }
    }

    /**
     * Angaben aus dem Kopfblock für die Vorschau. Wie beim Excel-Import nur zur Anzeige —
     * die Versionsangaben selbst legt der Administrator im Formular fest.
     *
     * @param  array<string, string>  $header
     * @return array<string, string>
     */
    private function metadata(array $header): array
    {
        $metadata = [];

        foreach (self::METADATA_KEYS as $source => $target) {
            if (($header[$source] ?? '') !== '') {
                $metadata[$target] = $header[$source];
            }
        }

        return $metadata;
    }

    /** @return array<string, int> lenex_code → id */
    private function strokeTypeIdsByLenexCode(): array
    {
        return StrokeType::query()
            ->whereIn('lenex_code', self::STROKE_CODES)
            ->pluck('id', 'lenex_code')
            ->all();
    }
}
