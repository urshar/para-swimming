<?php

namespace App\Services;

use App\Models\BaseTime;
use App\Support\TimeParser;
use RuntimeException;

/**
 * BaseTimeTextImportService
 *
 * Importiert die von Splash MeetManager/Hy-Tek exportierte "Points"-Basiswert-Textdatei
 * (z.B. die OeBSV-Tabelle "502-para-2021.txt"). Die Persistierung erbt diese Klasse von
 * AbstractBaseTimeImportService; hier liegt nur das Parsen des Textformats.
 *
 * Aufbau der Datei:
 *   - Kopf-Metadaten (Formula/Id/Name/…) bis zur Zeile "<BASETIMES>" — wird ignoriert.
 *   - eine Spaltenkopf-Zeile "COURSE;GENDER;RELAYCOUNT;DISTANCE;STROKE;HANDICAP;MINTIME;MAXTIME".
 *   - danach ";"-getrennte Datenzeilen (in der Praxis nur 7 Felder — MAXTIME fehlt durchgängig).
 *
 * Abbildung auf das bestehende Datenmodell (bewusst code-kompatibel zum Excel-Import, damit
 * Text-Basiswerte auf denselben Kategorie-/Bewerbs-/Sportklassen-Zeilen landen):
 *   - COURSE (SCM/LCM) + GENDER (F/M/X) → dieselben Kategorie-Codes wie der Excel-Import
 *     (SC_WOMEN, SC_MEN, SC_MIXED, LC_*).
 *   - RELAYCOUNT/DISTANCE/STROKE → Excel-kompatibler Bewerbs-Code ("25FR", "4x25ME", "150IM");
 *     Einzel-Lagen → "IM", Staffel-Lagen → "ME".
 *   - HANDICAP: Einzel als reine Zahl ("1".."21") → "S"-Präfix ergänzen; Staffeln bereits mit
 *     "S"-Präfix ("S14"…). Sonderwert "X" (Einzel) bzw. "SX" (Staffel) = WA-1000-Punkte-Basiswerte
 *     ohne Behinderung → Zeile wird übersprungen (Achtung: GENDER "X" ist davon unberührt und
 *     bezeichnet eine gültige Mixed-Staffel).
 *   - MINTIME (MM:SS.cs) → Basiswert; Sentinel "99:99.99" → NOT_APPLICABLE.
 *   - MAXTIME → ignoriert (gehört zur Rudolph-/DSV-Tabelle, im Behindertensport nicht verwendet).
 *
 * Das Format kennt keine Herleitungs-Formeln — alle Werte sind MANUAL bzw. NOT_APPLICABLE,
 * es entstehen keine base_time_derivation_rules.
 */
final class BaseTimeTextImportService extends AbstractBaseTimeImportService
{
    /** COURSE-Wert der Datei → Kurs-Präfix des Excel-kompatiblen Kategorie-Codes. */
    private const array COURSE_MAP = [
        'SCM' => 'SC',
        'LCM' => 'LC',
    ];

    /** GENDER-Wert der Datei → Geschlechts-Wort des Excel-kompatiblen Kategorie-Codes. */
    private const array GENDER_MAP = [
        'F' => 'WOMEN',
        'M' => 'MEN',
        'X' => 'MIXED',
    ];

    /** STROKE-Wert (= stroke_types.lenex_code) → Bewerbs-Code-Suffix. MEDLEY: Einzel→IM, Staffel→ME. */
    private const array STROKE_SUFFIX_MAP = [
        'FREE' => 'FR',
        'BACK' => 'BK',
        'BREAST' => 'BR',
        'FLY' => 'BF',
        'MEDLEY' => 'ME',
    ];

    /** MINTIME-Sentinel für "nicht anwendbar". */
    private const string NOT_APPLICABLE_TIME = '99:99.99';

    /**
     * Liest die Textdatei und liefert eine strukturierte Vorschau, ohne die Datenbank zu ändern.
     *
     * @throws RuntimeException wenn die Datei nicht gelesen werden kann oder keine Datenkopfzeile enthält
     */
    public function parse(string $filePath): array
    {
        $contents = @file_get_contents($filePath);
        if ($contents === false) {
            throw new RuntimeException('Datei konnte nicht gelesen werden.');
        }

        $categories = [];
        $disciplines = [];
        $sportClasses = [];
        $cells = [];
        $warnings = [];
        $seen = [];

        $lines = preg_split('/\r\n|\r|\n/', $contents);
        $lines[0] = $this->stripBom($lines[0] ?? '');

        $inData = false;

        foreach ($lines as $lineNo => $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }

            // Kopf-Metadaten und alles bis inkl. der Spaltenkopf-Zeile überspringen.
            if (! $inData) {
                if (str_starts_with(strtoupper($line), 'COURSE;')) {
                    $inData = true;
                }

                continue;
            }

            $fields = explode(';', $line);
            if (count($fields) < 7) {
                $warnings[] = 'Zeile '.($lineNo + 1)." hat weniger als 7 Felder (\"$line\") — übersprungen.";

                continue;
            }

            [$course, $gender, $relayCountRaw, $distanceRaw, $stroke, $handicap, $minTime] = array_map(
                'trim', array_slice($fields, 0, 7)
            );

            // WA-1000-Punkte-Basiswerte (ohne Behinderung) überspringen — nur der HANDICAP-Wert,
            // nicht das GENDER "X" (das ist eine gültige Mixed-Staffel).
            $handicapUpper = strtoupper($handicap);
            if ($handicapUpper === 'X' || $handicapUpper === 'SX') {
                continue;
            }

            $categoryCode = $this->categoryCode($course, $gender);
            if ($categoryCode === null) {
                $warnings[] = 'Zeile '.($lineNo + 1).": unbekannte Kombination COURSE=\"$course\"/".
                    "GENDER=\"$gender\" — übersprungen.";

                continue;
            }

            $strokeUpper = strtoupper($stroke);
            if (! isset(self::STROKE_SUFFIX_MAP[$strokeUpper])) {
                $warnings[] = 'Zeile '.($lineNo + 1).": unbekannte Schwimmart STROKE=\"$stroke\" — übersprungen.";

                continue;
            }

            $relayCount = (int) $relayCountRaw;
            $distance = (int) $distanceRaw;
            if ($relayCount < 1 || $distance < 1) {
                $warnings[] = 'Zeile '.($lineNo + 1).': ungültige RELAYCOUNT/DISTANCE '.
                    "(\"$relayCountRaw\"/\"$distanceRaw\") — übersprungen.";

                continue;
            }

            $sportClassCode = $this->sportClassCode($handicapUpper);
            if ($sportClassCode === null) {
                $warnings[] = 'Zeile '.($lineNo + 1).": unbekannter HANDICAP-Wert \"$handicap\" — übersprungen.";

                continue;
            }

            $value = $this->parseValue($minTime);
            if ($value === null) {
                $warnings[] = 'Zeile '.($lineNo + 1).": MINTIME \"$minTime\" konnte nicht als Zeit gelesen ".
                    'werden — übersprungen.';

                continue;
            }

            $disciplineCode = $this->disciplineCode($relayCount, $distance, $strokeUpper);

            $dedupeKey = $categoryCode.'|'.$disciplineCode.'|'.$sportClassCode;
            if (isset($seen[$dedupeKey])) {
                $warnings[] = 'Zeile '.($lineNo + 1).": doppelter Basiswert für $categoryCode/".
                    "$disciplineCode/$sportClassCode — nur der erste wird übernommen.";

                continue;
            }
            $seen[$dedupeKey] = true;

            $categories[$categoryCode] ??= $this->categoryAttributes($course, $gender);
            $disciplines[$disciplineCode] ??= [
                'stroke_lenex_code' => $strokeUpper,
                'distance' => $distance,
                'relay_count' => $relayCount,
            ];
            $sportClasses[$sportClassCode] ??= ['sort_order' => count($sportClasses)];

            $cells[] = $value + [
                'shorter_code' => null,
                'longer_code' => null,
                'ratio_category_code' => null,
                'ratio_shorter_code' => null,
                'ratio_longer_code' => null,
                'category_code' => $categoryCode,
                'discipline_code' => $disciplineCode,
                'sport_class_code' => $sportClassCode,
            ];
        }

        if (! $inData) {
            throw new RuntimeException('Keine Datenkopfzeile ("COURSE;GENDER;…") gefunden — kein gültiges Textformat.');
        }

        return compact('categories', 'disciplines', 'sportClasses', 'cells', 'warnings');
    }

    /** Baut den zum Excel-Import identischen Kategorie-Code (z.B. "SC_WOMEN"), oder null bei unbekannter Kombination. */
    private function categoryCode(string $course, string $gender): ?string
    {
        $coursePrefix = self::COURSE_MAP[strtoupper($course)] ?? null;
        $genderWord = self::GENDER_MAP[strtoupper($gender)] ?? null;

        if ($coursePrefix === null || $genderWord === null) {
            return null;
        }

        return $coursePrefix.'_'.$genderWord;
    }

    /** Kategorie-Attribute im selben Format wie der Excel-Import (course LCM/SCM, gender M/F/X, Label "SC Women"). */
    private function categoryAttributes(string $course, string $gender): array
    {
        $coursePrefix = self::COURSE_MAP[strtoupper($course)];
        $genderWord = self::GENDER_MAP[strtoupper($gender)];

        return [
            'course' => strtoupper($course),
            'gender' => strtoupper($gender),
            'label' => $coursePrefix.' '.ucfirst(strtolower($genderWord)),
        ];
    }

    /** Baut den Excel-kompatiblen Bewerbs-Code ("25FR", "4x25ME", "150IM"). */
    private function disciplineCode(int $relayCount, int $distance, string $stroke): string
    {
        $suffix = self::STROKE_SUFFIX_MAP[$stroke];
        if ($stroke === 'MEDLEY' && $relayCount === 1) {
            $suffix = 'IM';
        }

        $prefix = $relayCount > 1 ? $relayCount.'x' : '';

        return $prefix.$distance.$suffix;
    }

    /**
     * Normalisiert den HANDICAP-Wert auf einen Sportklassen-Code ("14"→"S14", "S14"→"S14"),
     * oder null bei unbekanntem Format. "X"/"SX" werden bereits vorher aussortiert.
     */
    private function sportClassCode(string $handicapUpper): ?string
    {
        if (! preg_match('/^S?(\d+)$/', $handicapUpper, $m)) {
            return null;
        }

        return 'S'.$m[1];
    }

    /** MINTIME → Zell-Wert. Sentinel "99:99.99" → NOT_APPLICABLE; sonst geparste Zeit oder null bei Fehler. */
    private function parseValue(string $minTime): ?array
    {
        if ($minTime === self::NOT_APPLICABLE_TIME) {
            return [
                'value_centiseconds' => 0,
                'value_type' => BaseTime::TYPE_NOT_APPLICABLE,
            ];
        }

        $centiseconds = TimeParser::parse($minTime);
        if ($centiseconds === null) {
            return null;
        }

        return [
            'value_centiseconds' => $centiseconds,
            'value_type' => BaseTime::TYPE_MANUAL,
        ];
    }

    private function stripBom(string $line): string
    {
        return str_starts_with($line, "\xEF\xBB\xBF") ? substr($line, 3) : $line;
    }
}
