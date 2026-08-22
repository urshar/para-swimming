<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\WpsPointParameter;
use App\Services\WpsPointCalculator;
use App\Services\WpsPointVersionResolver;
use App\Support\QueryParam;
use App\Support\TimeParser;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public\WpsPointCalculatorController
 *
 * Zweiter öffentlicher Punkterechner (Rückmeldung zu Phase 6): rechnet mit der offiziellen
 * WPS-Tabelle (Gompertz-Formel, WpsPointCalculator) statt der ÖBSV-Basiswerte — ein eigenes
 * Punktesystem mit eigener Datengrundlage, siehe PointCalculatorController für den anderen. Nur
 * LCM: die WPS-Tabelle liegt nur für die 50m-Bahn vor (Rückmeldung), keine Kurzbahn-Option wie
 * beim ÖBSV-Rechner. Dieselbe self-submitting-GET-ohne-validate()-Bauweise wie dort — siehe die
 * Erklärung im Klassenkommentar dort.
 */
class WpsPointCalculatorController extends Controller
{
    private const array MODES = ['time_to_points', 'points_to_time'];

    private const array GENDERS = ['M', 'F'];

    /** S1–S14 und S21 (Gruppe 14, Spec [S3]) — dieselbe Grenze wie WpsSportClass::normalize(). */
    private const array SPORT_CLASS_NUMBERS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 21];

    /** Schwimmart → WPS-Sportklassen-Kategorie — dieselbe Zuordnung wie WpsPointCalculator::STROKE_CATEGORY_MAP. */
    private const array STROKE_CATEGORY_MAP = [
        'FREE' => 'S', 'BACK' => 'S', 'FLY' => 'S', 'BREAST' => 'SB', 'MEDLEY' => 'SM',
    ];

    /** MM:SS.cs mit gültigem Sekundenanteil (00–59). */
    private const string TIME_REGEX = '/^\d{1,2}:[0-5]\d\.\d{2}$/';

    public function __construct(
        private readonly WpsPointCalculator $calculator,
        private readonly WpsPointVersionResolver $versionResolver,
    ) {}

    public function index(Request $request): View
    {
        // Nur Disziplinen, für die tatsächlich ein WPS-Parametersatz (LCM, Einzelbewerb)
        // existiert — anders als bei den ÖBSV-Basiswerten gibt es dafür keine eigene
        // Bewerbs-Stammtabelle, die Parametertabelle selbst ist die Quelle.
        $disciplines = WpsPointParameter::where('course', WpsPointParameter::COURSE_LCM)
            ->where('relay_count', 1)
            ->with('strokeType')
            ->get(['stroke_type_id', 'distance'])
            ->unique(fn (WpsPointParameter $p) => "$p->stroke_type_id|$p->distance")
            ->filter(fn (WpsPointParameter $p) => $p->strokeType !== null
                && array_key_exists($p->strokeType->lenex_code, self::STROKE_CATEGORY_MAP))
            ->sortBy(fn (WpsPointParameter $p) => sprintf('%02d|%010d', self::strokeOrder($p->strokeType->lenex_code), $p->distance))
            ->values();

        $mode = QueryParam::pick($request, 'mode', self::MODES, 'time_to_points');
        $gender = QueryParam::pick($request, 'gender', self::GENDERS, 'M');

        $disciplineId = $request->query('discipline_id');
        $sportClassNumber = $request->query('sport_class');
        $timeInput = trim((string) $request->query('time'));
        $pointsRaw = $request->query('points');

        // Kein eigenes Bewerbs-Model wie bei den Basiswerten — die WPS-Parametertabelle selbst
        // ist die Quelle (s.o.), das <select> trägt deshalb "stroke_type_id:distance" als Wert.
        /** @var ?WpsPointParameter $discipline */
        $discipline = null;
        if ($disciplineId !== null && str_contains((string) $disciplineId, ':')) {
            [$strokeTypeId, $distance] = explode(':', (string) $disciplineId, 2);
            $discipline = $disciplines->first(fn (WpsPointParameter $p) => $p->stroke_type_id === (int) $strokeTypeId
                && $p->distance === (int) $distance);
        }

        $version = $this->versionResolver->resolveForDate(now()->toDateString());

        $result = null;
        $errorCode = null;
        $calculatedValue = null; // int|null — rohe Punkte oder rohe Hundertstelsekunden, je nach $mode

        $hasAttempt = $version && $disciplineId !== null && $sportClassNumber !== null
            && ($mode === 'time_to_points' ? $timeInput !== '' : $pointsRaw !== null && $pointsRaw !== '');

        if ($hasAttempt) {
            if (! $discipline) {
                $errorCode = 'no_discipline';
            } else {
                $category = self::STROKE_CATEGORY_MAP[$discipline->strokeType->lenex_code] ?? null;
                $sportClass = $category ? $category.$sportClassNumber : null;

                if ($mode === 'time_to_points') {
                    $swimTime = preg_match(self::TIME_REGEX, $timeInput) === 1 ? TimeParser::parse($timeInput) : null;

                    if ($swimTime === null) {
                        $errorCode = 'invalid_time';
                    } elseif ($sportClass) {
                        $calculatedValue = $this->calculator->pointsForTime(
                            $swimTime, WpsPointParameter::COURSE_LCM, $gender,
                            $discipline->stroke_type_id, $discipline->distance, $sportClass, $version
                        );
                    }
                } else {
                    $targetPoints = filter_var($pointsRaw, FILTER_VALIDATE_INT);

                    if ($targetPoints === false || $targetPoints <= 0) {
                        $errorCode = 'invalid_points';
                    } elseif ($sportClass) {
                        $calculatedValue = $this->calculator->timeForPoints(
                            $targetPoints, WpsPointParameter::COURSE_LCM, $gender,
                            $discipline->stroke_type_id, $discipline->distance, $sportClass, $version
                        );
                    }
                }

                // Beide Rechenrichtungen liefern hier ein rohes int|null statt eines Wertobjekts
                // (WpsPointCalculator::pointsForTime()/timeForPoints() — bestehende Signatur,
                // unverändert) — Ergebnis-/Fehlercode-Ableitung deshalb einmal hier statt in
                // beiden Zweigen oben dupliziert.
                if (! $errorCode) {
                    $result = $calculatedValue !== null
                        ? ($mode === 'time_to_points' ? (string) $calculatedValue : TimeParser::display($calculatedValue))
                        : null;
                    $errorCode = $result === null ? 'no_parameter' : null;
                }
            }
        }

        return view('public.wps-point-calculator.index', [
            'mode' => $mode,
            'gender' => $gender,
            'version' => $version,
            'disciplines' => $disciplines,
            'sportClassNumbers' => self::SPORT_CLASS_NUMBERS,
            'selectedDisciplineId' => $disciplineId,
            'selectedSportClass' => $sportClassNumber,
            'time' => $timeInput,
            'pointsInput' => $pointsRaw,
            'result' => $result,
            'error' => $errorCode ? __('public.wps_point_calculator.errors.'.$errorCode) : null,
        ]);
    }

    private static function strokeOrder(string $lenexCode): int
    {
        return match ($lenexCode) {
            'FREE' => 1, 'BACK' => 2, 'BREAST' => 3, 'FLY' => 4, 'MEDLEY' => 5, default => 99,
        };
    }
}
