<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\BaseTimeDiscipline;
use App\Models\BaseTimeSportClass;
use App\Services\PointConversionService;
use App\Support\QueryParam;
use App\Support\TimeParser;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Rekonstruiert den Filterstand ausschließlich aus der Query-String, ohne $request->validate()
 * — ein self-submitting GET-Formular, das bei einer ValidationException auf die vorherige URL
 * zurückspringt, wirkt für Nutzer wie "die Seite tut gar nichts" (Rückmeldung aus der
 * Review-Runde: genau das wurde bei ungültiger Zeit beobachtet, ohne jede Fehlermeldung).
 * Unbekannte/leere Werte fallen still auf einen Standard zurück (wie PublicRecordFilter), eine
 * tatsächlich versuchte, aber ungültige Berechnung zeigt stattdessen einen Fehlertext in
 * derselben Antwort (200, kein Redirect) — siehe errorCode/point_calculator.errors.*.
 */
class PointCalculatorController extends Controller
{
    private const array MODES = ['time_to_points', 'points_to_time'];

    private const array COURSES = ['LCM', 'SCM'];

    private const array GENDERS = ['M', 'F'];

    /** MM:SS.cs mit gültigem Sekundenanteil (00–59) — reines \d{2} ließe z.B. "99:99.99" durch. */
    private const string TIME_REGEX = '/^\d{1,2}:[0-5]\d\.\d{2}$/';

    public function __construct(
        private readonly PointConversionService $points
    ) {}

    public function index(Request $request): View
    {
        // Nur Einzelbewerbe (relay_count = 1) — dieselbe Einschränkung wie das bestehende
        // Richtzeiten-Modul (QualifyingTimeCalculationService), die Formel gilt dort ebenso nicht
        // sinnvoll für Staffeln im öffentlichen Rechner.
        $disciplines = BaseTimeDiscipline::where('relay_count', 1)->with('strokeType')->get()
            ->filter(fn (BaseTimeDiscipline $d) => $d->strokeType !== null)
            // Zusammengesetzter Sortierschlüssel statt sortBy() mit Closure-Array (CLAUDE.md).
            ->sortBy(fn (BaseTimeDiscipline $d) => sprintf(
                '%02d|%010d',
                PointConversionService::STROKE_ORDER[$d->strokeType->lenex_code] ?? 99,
                $d->distance
            ))
            ->values();

        $sportClasses = BaseTimeSportClass::all()
            ->sortBy(fn (BaseTimeSportClass $sc) => PointConversionService::classNumber($sc->code))
            ->values();

        $mode = QueryParam::pick($request, 'mode', self::MODES, 'time_to_points');
        $course = QueryParam::pick($request, 'course', self::COURSES, 'LCM');
        $gender = QueryParam::pick($request, 'gender', self::GENDERS, 'M');

        $disciplineId = $request->query('discipline_id');
        $sportClassCode = $request->query('sport_class');
        $timeInput = trim((string) $request->query('time'));
        $pointsRaw = $request->query('points');

        /** @var ?BaseTimeDiscipline $discipline */
        $discipline = $disciplineId !== null
            ? $disciplines->firstWhere('id', (int) $disciplineId)
            : null;

        $version = $this->points->currentVersion();

        $result = null;
        $errorCode = null;
        $calculation = null;

        $hasAttempt = $version && $disciplineId !== null && $sportClassCode !== null
            && ($mode === 'time_to_points' ? $timeInput !== '' : $pointsRaw !== null && $pointsRaw !== '');

        if ($hasAttempt) {
            if (! $discipline) {
                $errorCode = 'no_discipline';
            } elseif ($mode === 'time_to_points') {
                $swimTime = preg_match(self::TIME_REGEX, $timeInput) === 1 ? TimeParser::parse($timeInput) : null;

                if ($swimTime === null) {
                    $errorCode = 'invalid_time';
                } else {
                    $calculation = $this->points->timeToPoints(
                        $version, $course, $gender, $discipline->stroke_type_id, $discipline->distance,
                        $sportClassCode, $swimTime
                    );
                }
            } else {
                $targetPoints = filter_var($pointsRaw, FILTER_VALIDATE_INT);

                if ($targetPoints === false || $targetPoints <= 0) {
                    $errorCode = 'invalid_points';
                } else {
                    $calculation = $this->points->pointsToTime(
                        $version, $course, $gender, $discipline->stroke_type_id, $discipline->distance,
                        $sportClassCode, $targetPoints
                    );
                }
            }
        }

        // Beide Rechenrichtungen liefern dasselbe Wertobjekt (PointCalculationResult) — Ergebnis-
        // und Fehlercode-Extraktion hier einmal statt in beiden Zweigen oben dupliziert.
        if ($calculation) {
            $errorCode = $calculation->errorCode ?: null;
            $result = $calculation->failed()
                ? null
                : ($mode === 'time_to_points' ? (string) $calculation->value : TimeParser::display($calculation->value));
        }

        return view('public.point-calculator.index', [
            'mode' => $mode,
            'course' => $course,
            'gender' => $gender,
            'version' => $version,
            'disciplines' => $disciplines,
            'sportClasses' => $sportClasses,
            'selectedDisciplineId' => $disciplineId,
            'selectedSportClass' => $sportClassCode,
            'time' => $timeInput,
            'pointsInput' => $pointsRaw,
            'result' => $result,
            'error' => $errorCode ? __('public.point_calculator.errors.'.$errorCode) : null,
        ]);
    }
}
