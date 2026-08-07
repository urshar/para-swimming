<?php

namespace App\Services;

use App\Models\Result;
use App\Models\WpsPointParameter;
use App\Models\WpsPointVersion;
use App\Support\WpsPointResult;
use App\Support\WpsSportClass;
use Illuminate\Support\Collection;

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

    public function __construct(
        private WpsScmConversionService $conversionService
    ) {}

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

        // Bildet zugleich S21/SB21/SM21 auf die Gruppe 14 ab (Spec [S3]).
        $sportClass = WpsSportClass::mapToWps($result->sport_class);

        if ($sportClass === null) {
            return WpsPointResult::skipped('keine auswertbare Sportklasse');
        }

        $expectedCategory = $this->expectedCategory($event->strokeType?->lenex_code);

        if ($expectedCategory === null) {
            return WpsPointResult::skipped('Schwimmstil ohne WPS-Zuordnung');
        }

        if (WpsSportClass::category($sportClass) !== $expectedCategory) {
            return WpsPointResult::skipped(
                "Sportklasse $sportClass passt nicht zum Schwimmstil (erwartet: $expectedCategory)"
            );
        }

        $gender = $this->gender($result);

        if ($gender === null) {
            return WpsPointResult::skipped('kein auswertbares Geschlecht');
        }

        $distance = $event->distance;
        $stroke = $event->strokeType?->lenex_code ?? '?';

        // Zuerst ein offizieller Parametersatz für die tatsächliche Bahnlänge (Spec §9.5).
        $parameter = $this->findParameter(
            $version, $course, $gender, $event->stroke_type_id, $distance, $sportClass
        );

        $factor = null;
        $estimatedLcm = null;

        if ($parameter === null && $course === WpsPointParameter::COURSE_SCM) {
            // Keine offiziellen Kurzbahnwerte vorhanden: Zeit auf ein Langbahn-Äquivalent
            // umrechnen und die offizielle Tabelle anwenden (Spec [S1], §9.2).
            $factor = $this->conversionService->resolveFactor(
                $event->stroke_type_id,
                $distance,
                $sportClass,
                $gender,
            );

            if ($factor === null) {
                return WpsPointResult::skipped(
                    "kein Umrechnungsfaktor für SCM/$gender/$distance$stroke/$sportClass"
                );
            }

            $parameter = $this->findParameter(
                $version,
                WpsPointParameter::COURSE_LCM,
                $gender,
                $event->stroke_type_id,
                $distance,
                $sportClass,
            );

            if ($parameter !== null) {
                $estimatedLcm = $this->conversionService->convert($result->swim_time, $factor);
                $seconds = $estimatedLcm / 100;
            }
        }

        if ($parameter === null) {
            return WpsPointResult::skipped(
                "kein WPS-Parametersatz für $course/$gender/$distance$stroke/$sportClass"
            );
        }

        $points = $this->gompertz($seconds, $parameter);

        if ($points === null) {
            return WpsPointResult::skipped('Berechnung lieferte kein gültiges Ergebnis');
        }

        return WpsPointResult::calculated($points, $parameter, $version, $estimatedLcm, $factor);
    }

    /**
     * Punkte für eine frei stehende Zeit, ohne Ergebnisdatensatz.
     *
     * Gebraucht von wps-qualification §5.3: Neben einer Qualifikationsnorm soll die
     * zugehörige Punktzahl stehen, damit erkennbar ist, ob die Normen über die Bewerbe
     * hinweg gleich streng sind. Eine Norm ist kein Ergebnis — es gibt weder Meet noch
     * SwimEvent noch Athlet, calculate() ist dafür also nicht verwendbar.
     *
     * Bewusst OHNE die Kurzbahn-Umrechnung aus calculate(): Der Aufrufer gibt die
     * Bahnlänge ausdrücklich an, und eine Norm ohne passenden Parametersatz soll als
     * "keine Punktzahl" erscheinen statt still über einen Schätzfaktor zu laufen.
     *
     * Liefert null, wenn Zeit, Sportklasse oder Parametersatz nicht auswertbar sind.
     */
    public function pointsForTime(
        int $centiseconds,
        string $course,
        string $gender,
        int $strokeTypeId,
        int $distance,
        string $sportClass,
        WpsPointVersion $version,
    ): ?int {
        if ($centiseconds <= 0) {
            return null;
        }

        $seconds = $centiseconds / 100;

        if ($seconds < self::MIN_TIME_SECONDS) {
            return null;
        }

        if (! in_array($course, self::SUPPORTED_COURSES, true)
            || ! in_array($gender, WpsPointParameter::GENDERS, true)) {
            return null;
        }

        // Bildet zugleich S21/SB21/SM21 auf die Gruppe 14 ab (Spec [S3]).
        $mapped = WpsSportClass::mapToWps($sportClass);

        if ($mapped === null) {
            return null;
        }

        $parameter = $this->findParameter($version, $course, $gender, $strokeTypeId, $distance, $mapped);

        return $parameter === null ? null : $this->gompertz($seconds, $parameter);
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

    private function expectedCategory(?string $lenexCode): ?string
    {
        return $lenexCode !== null
            ? (self::STROKE_CATEGORY_MAP[$lenexCode] ?? null)
            : null;
    }

    /**
     * Sucht den Parametersatz im vorgeladenen Bestand.
     *
     * Bewusst keine Abfrage je Ergebnis: Bei einem Wettkampf mit 500 Ergebnissen wären das
     * 500 Abfragen, auf der Kurzbahn sogar bis zu 1000 (erst SCM, dann LCM). Die
     * Parametertabelle umfasst je Version rund 384 Zeilen und wird deshalb einmal geladen und
     * in PHP nachgeschlagen.
     */
    private function findParameter(
        WpsPointVersion $version,
        string $course,
        string $gender,
        int $strokeTypeId,
        int $distance,
        string $sportClass,
    ): ?WpsPointParameter {
        $schluessel = implode('|', [
            $course, $gender, $strokeTypeId, $distance, 1, $sportClass,
        ]);

        return $this->parameters()->get($version->id)?->get($schluessel);
    }

    /**
     * Alle Parametersätze, nach Version und Merkmalskombination indiziert.
     *
     * once() memoisiert je Service-Instanz und Aufrufstelle — die Tabelle wird während einer
     * Massenberechnung also einmal geladen, ohne dass der Service seinen readonly-Charakter
     * verliert.
     *
     * Der gesamte Bestand wird geladen, nicht nur eine Version: Bei rund 384 Zeilen je
     * Version und wenigen Versionen je Jahr bleibt das klein, und die Jahresberechnung
     * (recalculateForYear) greift ohnehin auf mehrere Versionen zu.
     *
     * @return Collection<int, Collection<string, WpsPointParameter>>
     */
    private function parameters(): Collection
    {
        return once(static fn (): Collection => WpsPointParameter::query()
            ->get()
            ->groupBy('wps_point_version_id')
            ->map(static fn (Collection $gruppe): Collection => $gruppe->keyBy(
                static fn (WpsPointParameter $p): string => implode('|', [
                    $p->course, $p->gender, $p->stroke_type_id,
                    $p->distance, $p->relay_count, $p->sport_class,
                ])
            )));
    }
}
