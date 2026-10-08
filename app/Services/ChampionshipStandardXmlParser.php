<?php

namespace App\Services;

use App\Support\SportClassValidator;
use App\Support\TimeParser;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use SimpleXMLElement;

/**
 * Liest MQS/MET aus einer SDMS-Ranglistendatei von World Para Swimming (DT_FED_RANKING),
 * z.B. "LA28 Paralympic Games - MQS ONLY APPLIED" (Spec "WPS Qualification" §9.2).
 *
 * Aufbau:
 *
 *   <SdmsBody DocumentType="DT_FED_RANKING">
 *     <Sport>
 *       <ExtendedInfos>   PERIOD START/END (Qualifikationszeitraum), LABEL NAME (Titel)
 *       <Rankings Code="SWMM50MFR---03030-----" Description="Men's 50 m Freestyle S3">
 *         <ExtendedInfos>
 *           CLASSES LABEL    = Bewerbsklasse (S3)
 *           CLASSES ELIGIBLE = startberechtigte Klassen (S1-3)
 *           STANDARDS MQS / MET = Normzeiten ("0:50.27")
 *         <Ranking ...>   Weltrangliste — wird nicht gebraucht
 *
 * Kombinierte Bewerbe: Ein Bewerb "S3" für S1-3 hat EINE Norm, die für alle startberechtigten
 * Klassen gilt. Sie wird deshalb je startberechtigter Klasse angelegt (S1, S2, S3), damit die
 * Qualifikationsprüfung auch Schwimmer der niedrigeren Klassen bewertet, statt "ohne Norm
 * ausgeschrieben" zu melden.
 *
 * Liefert die Rohdaten; Vorschau und Import baut ChampionshipStandardImportService daraus.
 */
final readonly class ChampionshipStandardXmlParser
{
    /** Stilkürzel im Bewerbscode → stroke_types.lenex_code. */
    public const array STROKE_MAP = [
        'FR' => 'FREE',
        'BA' => 'BACK',
        'BR' => 'BREAST',
        'BF' => 'FLY',
        'IM' => 'MEDLEY',
    ];

    /** Bewerbscode: Geschlecht (M/W/X), Strecke, Stil. Staffeln enthalten "4X" und passen nicht. */
    private const string EVENT_CODE_PATTERN = '/^SWM([MWX])(\d+)M(FR|BA|BR|BF|IM)/';

    /** Geschlechtskürzel im Bewerbscode → Geschlecht der Norm. */
    private const array GENDER_MAP = ['M' => 'M', 'W' => 'F'];

    /** Erkennt eine XML-Datei am Inhalt, nicht an der Endung. */
    public static function isXml(string $path): bool
    {
        $anfang = (string) file_get_contents($path, false, null, 0, 512);

        return str_starts_with(ltrim($anfang, "\xEF\xBB\xBF \t\r\n"), '<');
    }

    /**
     * @param  array<string, int>  $strokeTypes  lenex_code → stroke_types.id
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     errors: list<string>,
     *     warnings: list<string>,
     *     period: array{start: string, end: string}|null,
     *     title: string|null,
     * }
     *
     * @throws RuntimeException wenn die Datei kein lesbares SDMS-Ranking mit Normen ist
     */
    public function parse(string $path, array $strokeTypes): array
    {
        $xml = $this->load($path);

        $rows = [];
        $errors = [];
        $warnings = [];
        $gesehen = [];
        $staffeln = 0;
        $mitNorm = 0;

        $nummer = 0;

        foreach ($xml->Sport->Rankings as $block) {
            $nummer++;
            $code = (string) $block['Code'];
            $bewerb = trim((string) $block['Description']) ?: $code;
            $info = $this->extendedInfos($block);

            if (! isset($info['STANDARDS']['MQS']) && ! isset($info['STANDARDS']['MET'])) {
                continue;
            }

            $mitNorm++;

            if (preg_match(self::EVENT_CODE_PATTERN, $code, $teile) !== 1) {
                $staffeln++;

                continue;
            }

            if (! isset(self::GENDER_MAP[$teile[1]])) {
                $staffeln++;

                continue;
            }

            $lenexCode = self::STROKE_MAP[$teile[3]];

            if (! isset($strokeTypes[$lenexCode])) {
                $errors[] = "$bewerb: Der Schwimmstil \"$lenexCode\" ist in den Stammdaten nicht angelegt.";

                continue;
            }

            $mqs = $this->parseTime($info['STANDARDS']['MQS'] ?? null);
            $met = $this->parseTime($info['STANDARDS']['MET'] ?? null);

            if (is_string($mqs) || is_string($met)) {
                $errors[] = "$bewerb: ".(is_string($mqs) ? $mqs : $met);

                continue;
            }

            $bewerbsklasse = $info['CLASSES']['LABEL'] ?? '';
            $startberechtigt = $info['CLASSES']['ELIGIBLE'] ?? $bewerbsklasse;
            $klassen = $this->expandClasses($startberechtigt);

            if ($klassen === null) {
                $errors[] = "$bewerb: Die startberechtigten Klassen \"$startberechtigt\" sind nicht lesbar.";

                continue;
            }

            $gender = self::GENDER_MAP[$teile[1]];
            $label = $startberechtigt !== $bewerbsklasse ? "$bewerb ($startberechtigt)" : $bewerb;

            foreach ($klassen as $klasse) {
                try {
                    $sportClass = SportClassValidator::normalize($klasse);
                } catch (ValidationException $e) {
                    $errors[] = "$bewerb: ".implode(' ', $e->errors()['sport_class'] ?? ['Ungültige Sportklasse.']);

                    continue;
                }

                $schluessel = implode('|', [$strokeTypes[$lenexCode], (int) $teile[2], $gender, $sportClass]);

                if (isset($gesehen[$schluessel])) {
                    $errors[] = "$bewerb: Die Klasse $sportClass ist bereits über \"$gesehen[$schluessel]\" "
                        .'startberechtigt — die Norm ist nicht eindeutig.';

                    continue;
                }

                $gesehen[$schluessel] = $bewerb;

                $rows[] = [
                    'stroke_type_id' => $strokeTypes[$lenexCode],
                    'distance' => (int) $teile[2],
                    'gender' => $gender,
                    'sport_class' => $sportClass,
                    'mqs_centiseconds' => $mqs,
                    'met_centiseconds' => $met,
                    'event_label' => $label,
                    'row_number' => $nummer,
                ];
            }
        }

        if ($mitNorm === 0) {
            throw new RuntimeException(
                'Die XML-Datei enthält keine Normen (MQS/MET) — vermutlich eine reine Weltrangliste. '
                .'Benötigt wird die Qualifikationsliste mit Normen, z.B. "MQS ONLY APPLIED".'
            );
        }

        if ($staffeln > 0) {
            $warnings[] = sprintf(
                '%d Staffelbewerb(e) wurden übersprungen — Staffelnormen sind nicht Teil dieses Moduls.',
                $staffeln,
            );
        }

        $kopf = $this->extendedInfos($xml->Sport);

        return [
            'rows' => $rows,
            'errors' => $errors,
            'warnings' => $warnings,
            'period' => $this->period($kopf['PERIOD']['START'] ?? null, $kopf['PERIOD']['END'] ?? null),
            'title' => ($kopf['LABEL']['NAME'] ?? '') ?: null,
        ];
    }

    /**
     * @throws RuntimeException
     */
    private function load(string $path): SimpleXMLElement
    {
        $vorher = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($path, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($vorher);

        if ($xml === false) {
            throw new RuntimeException('Die Datei ist keine gültige XML-Datei.');
        }

        if ($xml->getName() !== 'SdmsBody' || (string) $xml['DocumentType'] !== 'DT_FED_RANKING') {
            throw new RuntimeException(
                'Unbekanntes XML-Format. Erwartet wird eine Ranglistendatei von World Para Swimming '
                .'(SDMS, DocumentType "DT_FED_RANKING") mit Normen.'
            );
        }

        return $xml;
    }

    /**
     * ExtendedInfo-Einträge eines Knotens als [Type][Code] → Value.
     *
     * @return array<string, array<string, string>>
     */
    private function extendedInfos(SimpleXMLElement $node): array
    {
        $info = [];

        foreach ($node->ExtendedInfos->ExtendedInfo as $eintrag) {
            $info[(string) $eintrag['Type']][(string) $eintrag['Code']] = trim((string) $eintrag['Value']);
        }

        return $info;
    }

    /**
     * Löst "S1-3", "SB12-13" oder "S5" in einzelne Klassen auf.
     *
     * @return list<string>|null null, wenn die Angabe nicht lesbar ist
     */
    private function expandClasses(string $classes): ?array
    {
        if (preg_match('/^(S|SB|SM)(\d+)(?:-(\d+))?$/i', trim($classes), $teile) !== 1) {
            return null;
        }

        $von = (int) $teile[2];
        $bis = isset($teile[3]) ? (int) $teile[3] : $von;

        if ($bis < $von) {
            return null;
        }

        $prefix = strtoupper($teile[1]);

        return array_map(static fn (int $n): string => $prefix.$n, range($von, $bis));
    }

    /** Hundertstelsekunden, null bei fehlender Angabe, sonst die Fehlermeldung als String. */
    private function parseTime(?string $value): int|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return TimeParser::parse($value) ?? "\"$value\" ist keine lesbare Zeit.";
    }

    /** @return array{start: string, end: string}|null */
    private function period(?string $start, ?string $end): ?array
    {
        $von = $start === null ? false : strtotime($start);
        $bis = $end === null ? false : strtotime($end);

        if ($von === false || $bis === false || $von > $bis) {
            return null;
        }

        return ['start' => date('Y-m-d', $von), 'end' => date('Y-m-d', $bis)];
    }
}
