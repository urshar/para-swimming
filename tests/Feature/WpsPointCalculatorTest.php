<?php

use App\Models\Result;
use App\Models\WpsPointParameter;
use App\Models\WpsPointVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('wps-points-p2');

// Helper: tests/helpers_wps2.php

// ── Referenzwerte aus der offiziellen Datei ──────────────────────────────────

describe('Gompertz-Formel', function () {
    /**
     * Entnommen dem Blatt "Calculator" der Datei
     * 2026_01_30__World_Para_Swimming_Points_Calculator.xlsx (Men, 50 m Freestyle).
     *
     * Der Fall S2 ist der entscheidende: exakt 939,9101 Punkte. Mit round() ergäbe sich 940 —
     * die offizielle Vorschrift rundet ab.
     */
    it('reproduziert die offiziellen Referenzwerte', function (
        string $sportClass,
        float $parameterC,
        int $centiseconds,
        int $expected,
    ) {
        parameter_wps2([
            'sport_class' => $sportClass,
            'parameter_c' => $parameterC,
        ]);

        $result = result_wps2([
            'swim_time' => $centiseconds,
            'sport_class' => $sportClass,
        ]);

        $calculated = calculator_wps2()->calculate($result, version_wps2());

        expect($calculated->wasCalculated())->toBeTrue()
            ->and($calculated->points)->toBe($expected);
    })->with([
        'S1 — 65,00 s' => ['S1', 515.385, 6500, 1006],
        'S2 — 57,00 s (rundet ab, nicht auf)' => ['S2', 433.181, 5700, 939],
        'S3 — 43,13 s' => ['S3', 333.674, 4313, 969],
        'S4 — 37,08 s' => ['S4', 268.021, 3708, 842],
    ]);

    it('rechnet Hundertstel in Sekunden um', function () {
        parameter_wps2(['sport_class' => 'S2', 'parameter_c' => 433.181]);

        // 5700 Hundertstel = 57,00 s. Würde die Zeit als Sekunden interpretiert,
        // ergäbe sich ein völlig anderer Wert.
        $result = result_wps2(['swim_time' => 5700, 'sport_class' => 'S2']);

        expect(calculator_wps2()->calculate($result, version_wps2())->points)->toBe(939);
    });

    it('erlaubt Punkte über 1000 — die Asymptote a beträgt 1200', function () {
        parameter_wps2();

        $result = result_wps2(['swim_time' => 1500]);
        $calculated = calculator_wps2()->calculate($result, version_wps2());

        expect($calculated->points)->toBeGreaterThan(1000)
            ->and($calculated->points)->toBeLessThanOrEqual(1200);
    });

    it('erzeugt bei Extremwerten weder Overflow noch NaN', function (int $centiseconds) {
        parameter_wps2();

        $calculated = calculator_wps2()->calculate(
            result_wps2(['swim_time' => $centiseconds]),
            version_wps2()
        );

        expect($calculated->wasCalculated())->toBeTrue()
            ->and($calculated->points)->toBeGreaterThanOrEqual(0)
            ->and($calculated->points)->toBeLessThanOrEqual(1200);
    })->with([
        'eine Hundertstelsekunde' => [1],
        'sehr langsam' => [9_000_000],
    ]);
});

// ── Sportklassen-Kategorien ──────────────────────────────────────────────────

describe('Sportklassen-Zuordnung', function () {
    it('verlangt die zum Schwimmstil passende Kategorie', function (
        string $stroke,
        string $sportClass,
        bool $shouldCalculate,
    ) {
        parameter_wps2([
            'stroke_type_id' => stroke_wps2($stroke)->id,
            'sport_class' => $sportClass,
        ]);

        $result = result_wps2(
            result: ['sport_class' => $sportClass],
            event: ['stroke_type_id' => stroke_wps2($stroke)->id]
        );

        expect(calculator_wps2()->calculate($result, version_wps2())->wasCalculated())
            ->toBe($shouldCalculate);
    })->with([
        'Freistil + S' => ['FREE', 'S10', true],
        'Rücken + S' => ['BACK', 'S10', true],
        'Schmetterling + S' => ['FLY', 'S10', true],
        'Brust + SB' => ['BREAST', 'SB9', true],
        'Lagen + SM' => ['MEDLEY', 'SM10', true],
        'Freistil + SB — unzulässig' => ['FREE', 'SB9', false],
        'Brust + S — unzulässig' => ['BREAST', 'S10', false],
        'Lagen + S — unzulässig' => ['MEDLEY', 'S10', false],
    ]);

    it('normalisiert Schreibweise und Leerzeichen', function () {
        parameter_wps2();

        $result = result_wps2(['sport_class' => ' s10 ']);

        expect(calculator_wps2()->calculate($result, version_wps2())->wasCalculated())->toBeTrue();
    });

    it('überspringt nicht auswertbare Sportklassen', function (?string $sportClass) {
        parameter_wps2();

        $calculated = calculator_wps2()->calculate(
            result_wps2(['sport_class' => $sportClass]),
            version_wps2()
        );

        expect($calculated->wasCalculated())->toBeFalse()
            ->and($calculated->skipReason)->toContain('Sportklasse');
    })->with([
        'nationale Klasse' => ['GER.AB'],
        'Staffelklasse S20' => ['S20'],
        'Staffelklasse S34' => ['S34'],
        'nicht vergebene Klasse S15' => ['S15'],
        'fehlend' => [null],
    ]);
});

// ── Übersprungene Ergebnisse ────────────────────────────────────────────────

describe('Nicht berechenbare Ergebnisse', function () {
    it('überspringt Ergebnisse ohne wertbare Leistung', function (string $status) {
        parameter_wps2();

        $calculated = calculator_wps2()->calculate(
            result_wps2(['status' => $status]),
            version_wps2()
        );

        expect($calculated->wasCalculated())->toBeFalse()
            ->and($calculated->skipReason)->toContain($status);
    })->with(['DNS', 'DNF', 'DSQ', 'SICK', 'WDR']);

    it('berechnet EXH-Ergebnisse — es liegt eine gültige Zeit vor', function () {
        parameter_wps2();

        $calculated = calculator_wps2()->calculate(
            result_wps2(['status' => 'EXH']),
            version_wps2()
        );

        expect($calculated->wasCalculated())->toBeTrue();
    });

    it('überspringt Ergebnisse ohne gültige Zeit', function (?int $swimTime) {
        parameter_wps2();

        $calculated = calculator_wps2()->calculate(
            result_wps2(['swim_time' => $swimTime]),
            version_wps2()
        );

        expect($calculated->wasCalculated())->toBeFalse()
            ->and($calculated->skipReason)->toBe('keine gültige Schwimmzeit');
    })->with(['keine Zeit' => [null], 'null' => [0]]);

    it('überspringt Staffeln', function () {
        parameter_wps2();

        $calculated = calculator_wps2()->calculate(
            result_wps2(event: ['relay_count' => 4, 'distance' => 100]),
            version_wps2()
        );

        expect($calculated->wasCalculated())->toBeFalse()
            ->and($calculated->skipReason)->toContain('Staffel');
    });

    it('überspringt nicht unterstützte Bahnlängen', function (string $course) {
        parameter_wps2();

        $calculated = calculator_wps2()->calculate(
            result_wps2(course: $course),
            version_wps2()
        );

        expect($calculated->wasCalculated())->toBeFalse()
            ->and($calculated->skipReason)->toContain('Bahnlänge');
    })->with(['SCY', 'SCM33', 'OPEN']);

    it('überspringt, wenn kein Parametersatz existiert', function () {
        $calculated = calculator_wps2()->calculate(result_wps2(), version_wps2());

        expect($calculated->wasCalculated())->toBeFalse()
            ->and($calculated->skipReason)->toContain('kein WPS-Parametersatz');
    });

    it('findet keinen Parametersatz einer fremden Version', function () {
        parameter_wps2();

        $fremde = WpsPointVersion::create([
            'label' => 'WPS 2024', 'year' => 2024, 'version' => '1',
            'valid_from' => '2024-01-01',
        ]);

        expect(calculator_wps2()->calculate(result_wps2(), $fremde)->wasCalculated())->toBeFalse();
    });
});

// ── Geschlecht und Kurs ─────────────────────────────────────────────────────

describe('Auflösung von Geschlecht und Bahnlänge', function () {
    it('nimmt das Geschlecht des Athleten, nicht das des Bewerbs', function () {
        parameter_wps2(['gender' => WpsPointParameter::GENDER_FEMALE]);

        // Bewerb als "Mixed" ausgeschrieben, Athletin weiblich.
        $result = result_wps2(event: ['gender' => 'X'], athleteGender: 'F');

        expect(calculator_wps2()->calculate($result, version_wps2())->wasCalculated())->toBeTrue();
    });

    it('unterscheidet die Parametersätze nach Bahnlänge', function () {
        parameter_wps2(['course' => WpsPointParameter::COURSE_SCM, 'official' => false]);

        $lcm = calculator_wps2()->calculate(result_wps2(), version_wps2());
        $scm = calculator_wps2()->calculate(result_wps2(course: 'SCM'), version_wps2());

        expect($lcm->wasCalculated())->toBeFalse()
            ->and($scm->wasCalculated())->toBeTrue();
    });
});

// ── Berechnungstyp ──────────────────────────────────────────────────────────

describe('Berechnungstyp', function () {
    it('leitet den Typ aus dem Parametersatz ab, nicht aus der Bahnlänge', function (
        bool $official,
        string $course,
        string $expected,
    ) {
        parameter_wps2(['course' => $course, 'official' => $official]);

        $calculated = calculator_wps2()->calculate(result_wps2(course: $course), version_wps2());

        expect($calculated->calculationType)->toBe($expected);
    })->with([
        'offizielle LCM-Parameter' => [true, 'LCM', Result::WPS_TYPE_OFFICIAL],
        'abgeleitete SCM-Parameter' => [false, 'SCM', Result::WPS_TYPE_ESTIMATED],
        'offizielle SCM-Parameter — falls WPS sie je veröffentlicht' => [true, 'SCM', Result::WPS_TYPE_OFFICIAL],
    ]);

    it('markiert abgeleitete Punkte als geschätzt', function () {
        parameter_wps2(['course' => WpsPointParameter::COURSE_SCM, 'official' => false]);

        $calculated = calculator_wps2()->calculate(result_wps2(course: 'SCM'), version_wps2());

        expect($calculated->isEstimated())->toBeTrue();
    });
});
