<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Liest die Normtabelle aus der Word-Datei von World Para Swimming (.docx), z. B.
 * "2026_10_15_Mexico_2027_MQS_Final.docx" (Spec "WPS Qualification" §9.2).
 *
 * Liefert nur die Rohdaten — Absätze vor der Tabelle und die Zellen jeder Tabellenzeile in
 * ihrer Reihenfolge. Kopfzeilensuche, Validierung und Vorschau übernimmt
 * ChampionshipStandardImportService, gemeinsam mit dem Excel-Format.
 *
 * Aufbau der bekannten Datei:
 *
 *   Absätze     Titel und "Qualification Period - 1 January 2026 to 13 June 2027 (inclusive)"
 *   Tabelle     Events | Sport Class | Men | Women
 *                      |             | MQS | MET  | MQS | MET
 *               50m Freestyle | S3  | 00:53.11 | 00:58.00 | 01:14.69 | 01:36.89
 *               …
 *               Relays | MQS        (Staffelabschnitt, wird übersprungen)
 *
 * Drei Eigenheiten der Word-Tabelle:
 *
 * 1. Der Bewerb steht in VERTIKAL VERBUNDENEN Zellen (w:vMerge). Die Folgezellen sind leer —
 *    das Mitführen des Gruppenkopfs im Import greift also wie bei Excel.
 *
 * 2. Einzelne Zellen sind HORIZONTAL VERBUNDEN (w:gridSpan, z. B. jede MQS-Zelle über zwei
 *    Rasterspalten). Gelesen wird deshalb die Reihenfolge der Zellen (w:tc), nicht die
 *    Rasterposition — sonst verrutschen MQS und MET.
 *
 * 3. Eine Zelle kann mehrere Absätze enthalten ("100m Breaststroke" + leerer Absatz). Sie werden
 *    mit Leerzeichen verbunden und Leerraum zusammengefasst.
 */
final readonly class ChampionshipStandardDocxReader
{
    /**
     * Namespace von WordprocessingML. Ein fester Bezeichner, keine aufgerufene Adresse — er muss
     * exakt so lauten (mit "http"), sonst findet XPath kein einziges Element.
     *
     * @noinspection HttpUrlsUsage
     */
    public const string WORD_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const string DOCUMENT_ENTRY = 'word/document.xml';

    /** Größte plausible Zeilenzahl — Schutz vor einer übergroßen Tabelle. */
    private const int MAX_ROWS = 2000;

    /** Erkennt eine Word-Datei am Inhalt: ZIP-Archiv mit word/document.xml. */
    public static function isDocx(string $path): bool
    {
        if ((string) file_get_contents($path, false, null, 0, 2) !== 'PK') {
            return false;
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        $vorhanden = $zip->locateName(self::DOCUMENT_ENTRY) !== false;
        $zip->close();

        return $vorhanden;
    }

    /**
     * @return array{
     *     preamble: list<string>,
     *     rows: list<array{number: int, cells: list<string>}>,
     * }
     *
     * @throws RuntimeException wenn die Datei nicht lesbar ist oder keine Tabelle enthält
     */
    public function read(string $path): array
    {
        $xpath = $this->load($path);

        $tabelle = $xpath->query('//w:body/w:tbl')->item(0);

        if (! $tabelle instanceof DOMElement) {
            throw new RuntimeException('Die Word-Datei enthält keine Tabelle mit Normen.');
        }

        return [
            'preamble' => $this->preamble($xpath, $tabelle),
            'rows' => $this->rows($xpath, $tabelle),
        ];
    }

    /**
     * @throws RuntimeException
     */
    private function load(string $path): DOMXPath
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Die Datei konnte nicht als Word-Datei gelesen werden.');
        }

        $inhalt = $zip->getFromName(self::DOCUMENT_ENTRY);
        $zip->close();

        if ($inhalt === false) {
            throw new RuntimeException('Die Datei konnte nicht als Word-Datei gelesen werden.');
        }

        $dokument = new DOMDocument;

        // LIBXML_NONET: keine externen Ressourcen nachladen.
        if (! $dokument->loadXML($inhalt, LIBXML_NONET)) {
            throw new RuntimeException('Der Inhalt der Word-Datei ist kein gültiges XML.');
        }

        $xpath = new DOMXPath($dokument);
        $xpath->registerNamespace('w', self::WORD_NAMESPACE);

        return $xpath;
    }

    /**
     * Nicht leere Absätze vor der Tabelle — Titel und Qualifikationszeitraum.
     *
     * @return list<string>
     */
    private function preamble(DOMXPath $xpath, DOMElement $tabelle): array
    {
        $absaetze = [];

        foreach ($xpath->query('preceding-sibling::w:p', $tabelle) as $absatz) {
            $text = $this->normalize($absatz->textContent);

            if ($text !== '') {
                $absaetze[] = $text;
            }
        }

        return $absaetze;
    }

    /**
     * @return list<array{number: int, cells: list<string>}>
     */
    private function rows(DOMXPath $xpath, DOMElement $tabelle): array
    {
        $zeilen = [];
        $nummer = 0;

        foreach ($xpath->query('./w:tr', $tabelle) as $zeile) {
            $nummer++;

            if ($nummer > self::MAX_ROWS) {
                break;
            }

            $zellen = [];

            foreach ($xpath->query('./w:tc', $zeile) as $zelle) {
                $absaetze = [];

                foreach ($xpath->query('./w:p', $zelle) as $absatz) {
                    $absaetze[] = $absatz->textContent;
                }

                $zellen[] = $this->normalize(implode(' ', $absaetze));
            }

            $zeilen[] = ['number' => $nummer, 'cells' => $zellen];
        }

        return $zeilen;
    }

    private function normalize(string $text): string
    {
        // Word setzt gern geschützte Leerzeichen (U+00A0); sie zählen hier als normales Leerzeichen.
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? $text);
    }
}
