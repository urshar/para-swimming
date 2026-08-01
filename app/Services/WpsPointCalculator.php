<?php

namespace App\Services;

use App\Models\Result;
use App\Models\SwimEvent;
use App\Models\WpsPointParameter;
use App\Models\WpsPointVersion;
use App\Support\WpsPointResult;

/**
 * Berechnet die World-Para-Swimming-Punkte eines Ergebnisses.
 *
 *   q = a * e^(-e^(b - c/p))     p = Zeit in Sekunden
 *
 * Der Service ist reinlesend — er speichert nichts. Das Persistieren übernimmt der
 * WpsPointCalculationService. Diese Trennung ist notwendig, damit Ranglisten und spätere
 * Wertungen mit einer bestimmten Version rechnen können, ohne gespeicherte Werte zu verändern.
 */
final readonly class WpsPointCalculator
{
    /** Kleinste verwertbare Zeit — entspricht einer Hundertstelsekunde. */
    private const float MIN_TIME_SECONDS = 0.01;

    /**
     * Klemmgrenze für den inneren Exponenten (b - c/p).
     *
     * exp(709) ist der größte in PHP darstellbare double; darüber liefert exp() INF und das
     * äußere exp(-INF) ergibt 0. Das Klemmen vermeidet den Zwischenschritt über INF und damit
     * plattformabhängige Warnungen — das Ergebnis ist dasselbe: sehr großer Exponent → q → 0,
     * sehr kleiner Exponent → q → a.
     */
    private const float EXPONENT_LIMIT = 700.0;

    /**
     * Zuordnung Schwimmstil → erwartete Sportklassen-Kategorie.
     *
     * WPS führt getrennte Parametertabellen für S (Freistil, Rücken, Schmetterling),
     * SB (Brust) und SM (Lagen). Die im World-Aquatics-Modul verwendete Normalisierung
     * (SB9 → S9) darf hier auf keinen Fall greifen — sie lieferte systematisch den falschen
     * Parametersatz.
     *
     * @var array<string, string>
     */
    private const array STROKE_CATEGORY_MAP = [
        'FREE' => 'S',
        'BACK' => 'S',
        'FLY' => 'S',
        'BREAST' => 'SB',
        'MEDLEY' => 'SM',
    ];

    /** Ergebnisstatus, die keine wertbare Leistung darstellen. EXH fehlt bewusst. */
    private const array NON_SCORING_STATUSES = ['DNS', 'DNF', 'DSQ', 'SICK', 'WDR'];

    /** Bahnlängen, für die WPS-Parameter existieren können. */
    private const array SUPPORTED_COURSES = [
        WpsPointParameter::COURSE_LCM,
        WpsPointParameter::COURSE_SCM,
    ];

    /**
     * Berechnet die Punkte eines Ergebnisses mit der übergebenen Version.
     *
     * Liefert nie eine Exception für fachliche Nicht-Fälle — ein DSQ-Ergebnis oder ein
     * fehlender Parametersatz sind normale Zustände und werden als übersprungen zurückgegeben.
     */
    public function calculate(Result $result, WpsPointVersion $version): WpsPointResult
    {
        $event = $result->swimEvent;

        if ($event === null) {
            return WpsPointResult::skipped('kein Bewerb zugeordnet');
        }

        if ($result->status !== null && in_array($result->status, self::NON_SCORING_STATUSES, true)) {
            return WpsPointResult::skipped("Ergebnisstatus $result->status");
        }

        $seconds = $this->timeInSeconds($result);

        if ($seconds === null) {
            return WpsPointResult::skipped('keine gültige Schwimmzeit');
        }

        if ($event->relay_count > 1) {
            return WpsPointResult::skipped('Staffel — keine WPS-Parameter veröffentlicht');
        }

        $course = $this->course($result);

        if ($course === null) {
            $meetCourse = $result->meet?->course ?? 'unbekannt';

            return WpsPointResult::skipped("Bahnlänge $meetCourse wird nicht unterstützt");
        }

        $sportClass = $this->normalizeSportClass($result->sport_class);

        if ($sportClass === null) {
            return WpsPointResult::skipped('keine auswertbare Sportklasse');
        }

        $expectedCategory = $this->expectedCategory($event->strokeType?->lenex_code);

        if ($expectedCategory === null) {
            return WpsPointResult::skipped('Schwimmstil ohne WPS-Zuordnung');
        }

        if ($this->categoryOf($sportClass) !== $expectedCategory) {
            return WpsPointResult::skipped(
                "Sportklasse $sportClass passt nicht zum Schwimmstil (erwartet: $expectedCategory)"
            );
        }

        $gender = $this->gender($result);

        if ($gender === null) {
            return WpsPointResult::skipped('kein auswertbares Geschlecht');
        }

        $parameter = $this->findParameter($version, $course, $gender, $event, $sportClass);

        if ($parameter === null) {
            $distance = $event->distance;
            $stroke = $event->strokeType?->lenex_code ?? '?';

            return WpsPointResult::skipped(
                "kein WPS-Parametersatz für $course/$gender/$distance$stroke/$sportClass"
            );
        }

        $points = $this->gompertz($seconds, $parameter);

        if ($points === null) {
            return WpsPointResult::skipped('Berechnung lieferte kein gültiges Ergebnis');
        }

        return WpsPointResult::calculated($points, $parameter, $version);
    }

    /**
     * Die Gompertz-Funktion.
     *
     * Das Ergebnis wird ABGERUNDET, nicht kaufmännisch gerundet — so schreibt es die
     * offizielle WPS-Rechenvorschrift vor ("rounded down"), und so rechnet die
     * Referenzdatei (FLOOR). round() lieferte bei rund der Hälfte aller Ergebnisse
     * einen Punkt zu viel.
     */
    private function gompertz(float $seconds, WpsPointParameter $parameter): ?int
    {
        $exponent = $parameter->parameter_b - ($parameter->parameter_c / $seconds);

        $exponent = max(-self::EXPONENT_LIMIT, min(self::EXPONENT_LIMIT, $exponent));

        $points = $parameter->parameter_a * exp(-exp($exponent));

        if (! is_finite($points)) {
            return null;
        }

        return max(0, (int) floor($points));
    }

    /**
     * Schwimmzeit in Sekunden.
     *
     * results.swim_time ist in HUNDERTSTELSEKUNDEN gespeichert, die Formel erwartet Sekunden.
     * Ein Verwechseln erzeugt keinen Fehler, sondern still falsche Punkte.
     */
    private function timeInSeconds(Result $result): ?float
    {
        if ($result->swim_time === null || $result->swim_time <= 0) {
            return null;
        }

        $seconds = $result->swim_time / 100;

        return $seconds >= self::MIN_TIME_SECONDS ? $seconds : null;
    }

    /**
     * Die Bahnlänge des Wettkampfs, sofern WPS sie kennt.
     *
     * meets.course führt elf Werte (LCM, SCM, SCY, SCM16/20/33, SCY20/27/33/36, OPEN);
     * WPS-Parameter gibt es nur für LCM und SCM.
     */
    private function course(Result $result): ?string
    {
        $course = $result->meet?->course;

        return $course !== null && in_array($course, self::SUPPORTED_COURSES, true)
            ? $course
            : null;
    }

    /**
     * Das für die Parametersuche maßgebliche Geschlecht.
     *
     * Bei Einzelbewerben zählt das Geschlecht des Athleten, nicht das des Bewerbs — manche
     * Meets schreiben Einzelbewerbe organisatorisch als "Mixed" aus. Dieselbe Regel wendet
     * der WorldAquaticsPointsService an.
     */
    private function gender(Result $result): ?string
    {
        $gender = $result->athlete?->gender;

        return in_array($gender, WpsPointParameter::GENDERS, true) ? $gender : null;
    }

    /**
     * Bringt die Sportklasse auf Großbuchstaben ohne Leerzeichen und weist alles ab, was
     * keinem WPS-Parametersatz entsprechen kann.
     *
     * Aussortiert werden dabei die nicht-numerischen nationalen Klassen (GER.AB, GER.GB)
     * und die Staffelklassen (S20, S34, S49) — für beide gibt es keine WPS-Parameter.
     */
    private function normalizeSportClass(?string $sportClass): ?string
    {
        if ($sportClass === null) {
            return null;
        }

        $normalized = strtoupper(str_replace(' ', '', trim($sportClass)));

        // Alternation absteigend nach Länge: /^(S|SB|SM)/ träfe bei "SB9" zuerst auf "S".
        // Hier fängt der Anker $ das noch ab, in categoryOf() nicht — deshalb überall gleich.
        return preg_match('/^(SB|SM|S)([1-9]|1[0-4])$/', $normalized) === 1
            ? $normalized
            : null;
    }

    /**
     * "SB8" → "SB". Setzt eine bereits normalisierte Klasse voraus.
     *
     * Die Reihenfolge der Alternation ist entscheidend: Reguläre Ausdrücke prüfen die
     * Alternativen von links nach rechts und nehmen den ERSTEN Treffer. Mit /^(S|SB|SM)/
     * liefert "SB8" die Kategorie "S" — ohne nachfolgenden Anker gibt es kein Backtracking,
     * das den Fehler noch korrigieren würde. Die längeren Präfixe müssen deshalb vorn stehen.
     */
    private function categoryOf(string $sportClass): string
    {
        preg_match('/^(SB|SM|S)/', $sportClass, $matches);

        return $matches[1];
    }

    private function expectedCategory(?string $lenexCode): ?string
    {
        return $lenexCode !== null
            ? (self::STROKE_CATEGORY_MAP[$lenexCode] ?? null)
            : null;
    }

    private function findParameter(
        WpsPointVersion $version,
        string $course,
        string $gender,
        SwimEvent $event,
        string $sportClass,
    ): ?WpsPointParameter {
        return WpsPointParameter::query()
            ->where('wps_point_version_id', $version->id)
            ->where('course', $course)
            ->where('gender', $gender)
            ->where('stroke_type_id', $event->stroke_type_id)
            ->where('distance', $event->distance)
            ->where('relay_count', 1)
            ->where('sport_class', $sportClass)
            ->first();
    }
}
