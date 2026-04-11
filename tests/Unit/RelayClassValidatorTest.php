<?php

use App\Services\RelayClassValidator;
use Illuminate\Support\Carbon;

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Erstellt ein minimales Entry-Objekt mit sport_class für resolveRelayClass-Tests.
 */
function makeEntry(?string $sportClass = null, ?int $birthYear = null): object
{
    $athlete = null;
    if ($birthYear !== null) {
        $athlete = (object) [
            'birth_date' => Carbon::create($birthYear, 6),
            'sportClasses' => collect(),
        ];
    }

    return (object) [
        'sport_class' => $sportClass,
        'athlete' => $athlete,
    ];
}

/**
 * Erstellt ein minimales SwimEvent-Objekt mit einem StrokeType.
 */
function makeEvent(string $lenexCode): object
{
    return (object) [
        'strokeType' => (object) ['lenex_code' => $lenexCode],
    ];
}

// ── resolveRelayClass ─────────────────────────────────────────────────────────

describe('RelayClassValidator::resolveRelayClass', function () {

    beforeEach(function () {
        $this->validator = new RelayClassValidator;
    })->group('relay-validator');

    // ── Leere/ungültige Eingaben ──────────────────────────────────────────────

    it('gibt null zurück für leeres Array', function () {
        expect($this->validator->resolveRelayClass([]))->toBeNull();
    })->group('relay-validator');

    it('gibt null zurück bei SB-Klassen (kein reines S)', function () {
        expect($this->validator->resolveRelayClass(['SB6', 'SB7', 'SB8', 'SB9']))->toBeNull();
    })->group('relay-validator');

    it('gibt null zurück bei SM-Klassen', function () {
        expect($this->validator->resolveRelayClass(['SM6', 'SM7', 'SM8', 'SM9']))->toBeNull();
    })->group('relay-validator');

    it('gibt null zurück bei Mischung S und SB', function () {
        expect($this->validator->resolveRelayClass(['S6', 'SB7', 'S8', 'S9']))->toBeNull();
    })->group('relay-validator');

    // ── S21 (Trisomie) ────────────────────────────────────────────────────────

    it('erkennt S21-Staffel wenn alle S21 sind', function () {
        expect($this->validator->resolveRelayClass(['S21', 'S21', 'S21', 'S21']))->toBe('S21');
    })->group('relay-validator');

    it('gibt nicht S21 zurück wenn andere Klassen dabei sind', function () {
        expect($this->validator->resolveRelayClass(['S21', 'S21', 'S14', 'S21']))->toBe('S14');
    })->group('relay-validator');

    // ── S14 (Intellectual) ────────────────────────────────────────────────────

    it('erkennt S14-Staffel wenn alle S14 sind', function () {
        expect($this->validator->resolveRelayClass(['S14', 'S14', 'S14', 'S14']))->toBe('S14');
    })->group('relay-validator');

    it('erkennt S14-Staffel wenn Mix aus S14 und S21', function () {
        expect($this->validator->resolveRelayClass(['S14', 'S21', 'S14', 'S21']))->toBe('S14');
    })->group('relay-validator');

    it('gibt nicht S14 zurück wenn andere Klassen dabei sind', function () {
        expect($this->validator->resolveRelayClass(['S14', 'S14', 'S6', 'S14']))->toBeNull();
    })->group('relay-validator');

    // ── S15 (Deaf) ────────────────────────────────────────────────────────────

    it('erkennt S15-Staffel wenn alle S15 sind', function () {
        expect($this->validator->resolveRelayClass(['S15', 'S15', 'S15', 'S15']))->toBe('S15');
    })->group('relay-validator');

    it('gibt nicht S15 zurück wenn andere Klassen dabei sind', function () {
        expect($this->validator->resolveRelayClass(['S15', 'S15', 'S14', 'S15']))->toBeNull();
    })->group('relay-validator');

    // ── S49 (Visual) ─────────────────────────────────────────────────────────

    it('erkennt S49-Staffel wenn alle S11 sind', function () {
        expect($this->validator->resolveRelayClass(['S11', 'S11', 'S11', 'S11']))->toBe('S49');
    })->group('relay-validator');

    it('erkennt S49-Staffel bei Mix aus S11, S12, S13', function () {
        expect($this->validator->resolveRelayClass(['S11', 'S12', 'S13', 'S11']))->toBe('S49');
    })->group('relay-validator');

    it('gibt nicht S49 zurück wenn andere Klassen dabei sind', function () {
        expect($this->validator->resolveRelayClass(['S11', 'S12', 'S14', 'S13']))->toBeNull();
    })->group('relay-validator');

    // ── S20 (Physical) ────────────────────────────────────────────────────────

    it('erkennt S20-Staffel wenn Summe exakt 20', function () {
        // 5 + 5 + 5 + 5 = 20
        expect($this->validator->resolveRelayClass(['S5', 'S5', 'S5', 'S5']))->toBe('S20');
    })->group('relay-validator');

    it('erkennt S20-Staffel wenn Summe unter 20', function () {
        // 1 + 2 + 3 + 4 = 10
        expect($this->validator->resolveRelayClass(['S1', 'S2', 'S3', 'S4']))->toBe('S20');
    })->group('relay-validator');

    it('erkennt S20-Staffel mit ungleichen Klassen unter 20', function () {
        // 4 + 5 + 6 + 4 = 19 — alle S1-S10
        expect($this->validator->resolveRelayClass(['S4', 'S5', 'S6', 'S4']))->toBe('S20');
    })->group('relay-validator');

    it('erkennt S20-Staffel mit S10 Athleten (Summe ≤ 20)', function () {
        // 5 + 5 + 5 + 4 = 19 — S10 ist erlaubt
        expect($this->validator->resolveRelayClass(['S5', 'S5', 'S5', 'S4']))->toBe('S20');
    })->group('relay-validator');

    // ── S34 (Physical) ────────────────────────────────────────────────────────

    it('erkennt S34-Staffel wenn Summe exakt 34', function () {
        // 8 + 8 + 9 + 9 = 34
        expect($this->validator->resolveRelayClass(['S8', 'S8', 'S9', 'S9']))->toBe('S34');
    })->group('relay-validator');

    it('erkennt S34-Staffel wenn Summe zwischen 21 und 34', function () {
        // 6 + 6 + 7 + 7 = 26
        expect($this->validator->resolveRelayClass(['S6', 'S6', 'S7', 'S7']))->toBe('S34');
    })->group('relay-validator');

    it('erkennt S34-Staffel wenn Summe exakt 21', function () {
        // 5 + 5 + 5 + 6 = 21
        expect($this->validator->resolveRelayClass(['S5', 'S5', 'S5', 'S6']))->toBe('S34');
    })->group('relay-validator');

    it('erkennt S34-Staffel mit S10 Athleten (Summe ≤ 34)', function () {
        // 10 + 8 + 8 + 8 = 34
        expect($this->validator->resolveRelayClass(['S10', 'S8', 'S8', 'S8']))->toBe('S34');
    })->group('relay-validator');

    // ── Ungültig > 34 ────────────────────────────────────────────────────────

    it('gibt null zurück wenn Summe exakt 35 (alles S1-S10)', function () {
        // 9 + 9 + 9 + 8 = 35
        expect($this->validator->resolveRelayClass(['S9', 'S9', 'S9', 'S8']))->toBeNull();
    })->group('relay-validator');

    it('gibt null zurück wenn vier S10 Athleten (Summe 40)', function () {
        // 10 + 10 + 10 + 10 = 40
        expect($this->validator->resolveRelayClass(['S10', 'S10', 'S10', 'S10']))->toBeNull();
    })->group('relay-validator');

    // ── Klassen außerhalb S1-S10 in Physical → ungültig ─────────────────────

    it('gibt null zurück wenn S16 in der Staffel ist', function () {
        expect($this->validator->resolveRelayClass(['S5', 'S5', 'S16', 'S5']))->toBeNull();
    })->group('relay-validator');

    it('gibt null zurück wenn S17 in der Staffel ist', function () {
        expect($this->validator->resolveRelayClass(['S6', 'S6', 'S17', 'S5']))->toBeNull();
    })->group('relay-validator');

    it('gibt null zurück wenn S18 in der Staffel ist', function () {
        expect($this->validator->resolveRelayClass(['S5', 'S18', 'S5', 'S5']))->toBeNull();
    })->group('relay-validator');

    it('gibt null zurück wenn S20 als Sportklasse in der Staffel ist', function () {
        // S20 ist eine Staffelklasse, keine Einzelklasse — ungültig
        expect($this->validator->resolveRelayClass(['S5', 'S20', 'S5', 'S5']))->toBeNull();
    })->group('relay-validator');

});

// ── isJuniorRelay ─────────────────────────────────────────────────────────────

describe('RelayClassValidator::isJuniorRelay', function () {

    beforeEach(function () {
        $this->validator = new RelayClassValidator;
    })->group('relay-validator');

    it('gibt true zurück wenn alle Mitglieder unter 18 sind', function () {
        $entries = collect([
            makeEntry(birthYear: 2010),
            makeEntry(birthYear: 2009),
            makeEntry(birthYear: 2008),
            makeEntry(birthYear: 2007),
        ]);

        // Wettkampfjahr 2025: Alter 15, 16, 17, 18 → alle ≤ 18
        expect($this->validator->isJuniorRelay($entries, 2025))->toBeTrue();
    })->group('relay-validator');

    it('gibt true zurück wenn Alter exakt 18', function () {
        $entries = collect([
            makeEntry(birthYear: 2007),
            makeEntry(birthYear: 2007),
            makeEntry(birthYear: 2007),
            makeEntry(birthYear: 2007),
        ]);

        expect($this->validator->isJuniorRelay($entries, 2025))->toBeTrue();
    })->group('relay-validator');

    it('gibt false zurück wenn ein Mitglied 19 ist', function () {
        $entries = collect([
            makeEntry(birthYear: 2010),
            makeEntry(birthYear: 2009),
            makeEntry(birthYear: 2006), // Alter 19
            makeEntry(birthYear: 2008),
        ]);

        expect($this->validator->isJuniorRelay($entries, 2025))->toBeFalse();
    })->group('relay-validator');

    it('gibt false zurück wenn kein Mitglied ein Geburtsdatum hat', function () {
        $entries = collect([
            makeEntry(),
            makeEntry(),
            makeEntry(),
            makeEntry(),
        ]);

        expect($this->validator->isJuniorRelay($entries, 2025))->toBeFalse();
    })->group('relay-validator');

    it('ignoriert Mitglieder ohne Geburtsdatum bei der Prüfung', function () {
        // 3 mit Geburtsdatum (alle junioren), 1 ohne → gilt als Jugend
        $entries = collect([
            makeEntry(birthYear: 2008),
            makeEntry(birthYear: 2009),
            makeEntry(),               // kein Geburtsdatum → wird ignoriert
            makeEntry(birthYear: 2007),
        ]);

        expect($this->validator->isJuniorRelay($entries, 2025))->toBeTrue();
    })->group('relay-validator');

    it('gibt false wenn Mitglieder ohne Geburtsdatum und einer zu alt ist', function () {
        $entries = collect([
            makeEntry(birthYear: 2008),
            makeEntry(birthYear: 2005), // Alter 20 → kein Junior
            makeEntry(),
            makeEntry(birthYear: 2007),
        ]);

        expect($this->validator->isJuniorRelay($entries, 2025))->toBeFalse();
    })->group('relay-validator');

});

// ── extractMemberClasses ──────────────────────────────────────────────────────

describe('RelayClassValidator::extractMemberClasses', function () {

    beforeEach(function () {
        $this->validator = new RelayClassValidator;
    })->group('relay-validator');

    it('liest sport_class direkt aus Entry', function () {
        $entries = collect([
            makeEntry('S6'),
            makeEntry('S7'),
            makeEntry('S8'),
            makeEntry('S9'),
        ]);

        $event = makeEvent('FREE');

        expect($this->validator->extractMemberClasses($entries, $event))
            ->toBe(['S6', 'S7', 'S8', 'S9']);
    })->group('relay-validator');

    it('liest Klasse aus AthleteSportClass wenn Entry keine sport_class hat', function () {
        $athleteEntry = (object) [
            'sport_class' => null,
            'athlete' => (object) [
                'sportClasses' => collect([
                    (object) ['category' => 'S', 'class_number' => '7'],
                ]),
            ],
        ];

        $entries = collect([$athleteEntry]);
        $event = makeEvent('FREE');

        expect($this->validator->extractMemberClasses($entries, $event))
            ->toBe(['S7']);
    })->group('relay-validator');

    it('bevorzugt Entry.sport_class vor AthleteSportClass', function () {
        $athleteEntry = (object) [
            'sport_class' => 'S6',           // direkt gesetzt
            'athlete' => (object) [
                'sportClasses' => collect([
                    (object) ['category' => 'S', 'class_number' => '7'], // würde S7 liefern
                ]),
            ],
        ];

        $entries = collect([$athleteEntry]);
        $event = makeEvent('FREE');

        expect($this->validator->extractMemberClasses($entries, $event))
            ->toBe(['S6']);                  // Entry-Wert gewinnt
    })->group('relay-validator');

    it('verwendet Kategorie SB bei BREAST-Stroke', function () {
        $athleteEntry = (object) [
            'sport_class' => null,
            'athlete' => (object) [
                'sportClasses' => collect([
                    (object) ['category' => 'SB', 'class_number' => '9'],
                ]),
            ],
        ];

        $entries = collect([$athleteEntry]);
        $event = makeEvent('BREAST');

        expect($this->validator->extractMemberClasses($entries, $event))
            ->toBe(['SB9']);
    })->group('relay-validator');

    it('verwendet Kategorie SM bei MEDLEY-Stroke', function () {
        $athleteEntry = (object) [
            'sport_class' => null,
            'athlete' => (object) [
                'sportClasses' => collect([
                    (object) ['category' => 'SM', 'class_number' => '11'],
                ]),
            ],
        ];

        $entries = collect([$athleteEntry]);
        $event = makeEvent('MEDLEY');

        expect($this->validator->extractMemberClasses($entries, $event))
            ->toBe(['SM11']);
    })->group('relay-validator');

    it('überspringt Entries ohne sport_class und ohne Athleten', function () {
        $entries = collect([
            makeEntry('S6'),
            (object) ['sport_class' => null, 'athlete' => null], // kein Athlet
            makeEntry('S8'),
        ]);

        $event = makeEvent('FREE');

        expect($this->validator->extractMemberClasses($entries, $event))
            ->toBe(['S6', 'S8']);
    })->group('relay-validator');

});

// ── isNationalOnlyClass ───────────────────────────────────────────────────────

describe('RelayClassValidator::isNationalOnlyClass', function () {

    beforeEach(function () {
        $this->validator = new RelayClassValidator;
    })->group('relay-validator');

    it('gibt true für S21 zurück (nur national gültig)', function () {
        expect($this->validator->isNationalOnlyClass('S21'))->toBeTrue();
    })->group('relay-validator');

    it('gibt false für alle anderen Staffelklassen zurück', function () {
        foreach (['S14', 'S15', 'S20', 'S34', 'S49'] as $class) {
            expect($this->validator->isNationalOnlyClass($class))
                ->toBeFalse('Erwartet false für '.$class);
        }
    })->group('relay-validator');

});
