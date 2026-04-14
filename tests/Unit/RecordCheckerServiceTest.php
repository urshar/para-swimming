<?php

use App\Models\Athlete;
use App\Models\AthleteSportClass;
use App\Models\Club;
use App\Models\Entry;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use App\Services\RecordCheckerService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function setupBase(?string $associationCode = null): array
{
    $aut = Nation::forceCreate([
        'code' => 'AUT', 'name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true,
    ]);

    $club = Club::create([
        'name' => 'Testverein',
        'nation_id' => $aut->id,
        'type' => 'CLUB',
        'regional_association' => $associationCode,
    ]);

    $meet = Meet::create([
        'name' => 'Testbewerb 2025',
        'start_date' => '2025-03-15',
        'end_date' => '2025-03-15',
        'course' => 'LCM',
        'city' => 'Wien',
        'nation_id' => $aut->id,
    ]);

    $stroke = StrokeType::create([
        'name_de' => 'Freistil',
        'name_en' => 'Freestyle',
        'lenex_code' => 'FREE',
        'code' => 'FREE',
    ]);

    return compact('aut', 'club', 'meet', 'stroke');
}

function makeRelayEvent(Meet $meet, StrokeType $stroke, int $relayCount = 4): SwimEvent
{
    return SwimEvent::create([
        'meet_id' => $meet->id,
        'stroke_type_id' => $stroke->id,
        'distance' => 100,
        'relay_count' => $relayCount,
        'gender' => 'M',
        'session_number' => 1,
        'event_number' => 1,
        'round' => 'TIM',
    ]);
}

function makeRelayMember(
    Nation $nation,
    Club $club,
    SwimEvent $event,
    string $sportClass,
    int $birthYear = 2000,
): Athlete {
    preg_match('/^(SB|SM|S)(\d+)$/', $sportClass, $m);
    $category = $m[1] ?? 'S';
    $number = $m[2] ?? '1';

    $athlete = Athlete::create([
        'first_name' => 'Test',
        'last_name' => 'Athlet',
        'gender' => 'M',
        'birth_date' => $birthYear.'-06-01',
        'nation_id' => $nation->id,
        'club_id' => $club->id,
    ]);

    AthleteSportClass::create([
        'athlete_id' => $athlete->id,
        'category' => $category,
        'class_number' => $number,
        'sport_class' => $sportClass,
    ]);

    Entry::create([
        'meet_id' => $event->meet_id,
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
        'sport_class' => $sportClass,
    ]);

    return $athlete;
}

function makeRelayResult(Meet $meet, SwimEvent $event, Club $club, int $swimTime = 5000): Result
{
    // results.athlete_id ist NOT NULL in der DB — Staffeln bekommen einen Placeholder-Athleten.
    // Der RecordCheckerService liest den Club aus result->club_id, nicht aus athlete->club.
    $placeholder = Athlete::firstOrCreate(
        [
            'last_name' => '__relay__', 'first_name' => '__placeholder__', 'gender' => 'M',
            'nation_id' => $club->nation_id,
        ],
        ['birth_date' => '2000-01-01', 'club_id' => $club->id]
    );

    return Result::create([
        'meet_id' => $meet->id,
        'swim_event_id' => $event->id,
        'athlete_id' => $placeholder->id,
        'club_id' => $club->id,
        'swim_time' => $swimTime,
        'sport_class' => null,
        'status' => null,
    ]);
}

// ── Hilfsfunktion: alle Typen aus einem checkMeet()-Ergebnis flach extrahieren ──

function recordTypes(array $checkResult): array
{
    return collect($checkResult['new_records'])
        ->pluck('types')
        ->flatten()
        ->all();
}

// ── RecordCheckerService::checkMeet (Staffeln) ────────────────────────────────

describe('RecordCheckerService — Staffel-Rekorde', function () {

    beforeEach(function () {
        $this->service = app(RecordCheckerService::class);
    })->group('relay-checker');

    // ── Staffelklassen korrekt erkannt ────────────────────────────────────────

    it('legt S20-Nationalrekord an bei Summe ≤ 20', function () {
        ['aut' => $aut, 'club' => $club, 'meet' => $meet, 'stroke' => $stroke] = setupBase();

        $event = makeRelayEvent($meet, $stroke);
        makeRelayMember($aut, $club, $event, 'S5');
        makeRelayMember($aut, $club, $event, 'S5');
        makeRelayMember($aut, $club, $event, 'S5');
        makeRelayMember($aut, $club, $event, 'S5'); // Summe = 20
        makeRelayResult($meet, $event, $club);

        $checkResult = $this->service->checkMeet($meet);

        expect($checkResult['new_records'])->toHaveCount(1)
            ->and($checkResult['new_records'][0]['types'])->toBe(['AUT'])
            ->and(SwimRecord::where('sport_class', 'S20')->where('record_type', 'AUT')->count())->toBe(1);
    })->group('relay-checker');

    it('legt S34-Rekord an bei Summe 21–34', function () {
        ['aut' => $aut, 'club' => $club, 'meet' => $meet, 'stroke' => $stroke] = setupBase();

        $event = makeRelayEvent($meet, $stroke);
        makeRelayMember($aut, $club, $event, 'S8');
        makeRelayMember($aut, $club, $event, 'S8');
        makeRelayMember($aut, $club, $event, 'S9');
        makeRelayMember($aut, $club, $event, 'S9'); // Summe = 34
        makeRelayResult($meet, $event, $club);

        $this->service->checkMeet($meet);

        expect(SwimRecord::where('sport_class', 'S34')->where('record_type', 'AUT')->count())->toBe(1);
    })->group('relay-checker');

    it('legt S49-Rekord an bei S11–S13 Athleten', function () {
        ['aut' => $aut, 'club' => $club, 'meet' => $meet, 'stroke' => $stroke] = setupBase();

        $event = makeRelayEvent($meet, $stroke);
        makeRelayMember($aut, $club, $event, 'S11');
        makeRelayMember($aut, $club, $event, 'S12');
        makeRelayMember($aut, $club, $event, 'S13');
        makeRelayMember($aut, $club, $event, 'S11');
        makeRelayResult($meet, $event, $club);

        $this->service->checkMeet($meet);

        expect(SwimRecord::where('sport_class', 'S49')->where('record_type', 'AUT')->count())->toBe(1);
    })->group('relay-checker');

    it('legt S21-Rekord an wenn alle Athleten S21 sind', function () {
        ['aut' => $aut, 'club' => $club, 'meet' => $meet, 'stroke' => $stroke] = setupBase();

        $event = makeRelayEvent($meet, $stroke);
        makeRelayMember($aut, $club, $event, 'S21');
        makeRelayMember($aut, $club, $event, 'S21');
        makeRelayMember($aut, $club, $event, 'S21');
        makeRelayMember($aut, $club, $event, 'S21');
        makeRelayResult($meet, $event, $club);

        $this->service->checkMeet($meet);

        expect(SwimRecord::where('sport_class', 'S21')->where('record_type', 'AUT')->count())->toBe(1);
    })->group('relay-checker');

    it('legt S14-Rekord an bei Mix aus S14 und S21', function () {
        ['aut' => $aut, 'club' => $club, 'meet' => $meet, 'stroke' => $stroke] = setupBase();

        $event = makeRelayEvent($meet, $stroke);
        makeRelayMember($aut, $club, $event, 'S14');
        makeRelayMember($aut, $club, $event, 'S21');
        makeRelayMember($aut, $club, $event, 'S14');
        makeRelayMember($aut, $club, $event, 'S21');
        makeRelayResult($meet, $event, $club);

        $this->service->checkMeet($meet);

        expect(SwimRecord::where('sport_class', 'S14')->where('record_type', 'AUT')->count())->toBe(1);
    })->group('relay-checker');

    // ── ungültige Kombinationen → kein Rekord ────────────────────────────────

    it('legt keinen Rekord an wenn Summe > 34', function () {
        ['aut' => $aut, 'club' => $club, 'meet' => $meet, 'stroke' => $stroke] = setupBase();

        $event = makeRelayEvent($meet, $stroke);
        makeRelayMember($aut, $club, $event, 'S9');
        makeRelayMember($aut, $club, $event, 'S9');
        makeRelayMember($aut, $club, $event, 'S9');
        makeRelayMember($aut, $club, $event, 'S9'); // Summe = 36
        makeRelayResult($meet, $event, $club);

        $checkResult = $this->service->checkMeet($meet);

        expect($checkResult['new_records'])->toHaveCount(0)
            ->and(SwimRecord::count())->toBe(0);
    })->group('relay-checker');

    it('legt keinen Rekord an wenn S16 in der Staffel ist', function () {
        ['aut' => $aut, 'club' => $club, 'meet' => $meet, 'stroke' => $stroke] = setupBase();

        $event = makeRelayEvent($meet, $stroke);
        makeRelayMember($aut, $club, $event, 'S16');
        makeRelayMember($aut, $club, $event, 'S5');
        makeRelayMember($aut, $club, $event, 'S5');
        makeRelayMember($aut, $club, $event, 'S5');
        makeRelayResult($meet, $event, $club);

        $checkResult = $this->service->checkMeet($meet);

        expect($checkResult['new_records'])->toHaveCount(0)
            ->and(SwimRecord::count())->toBe(0);
    })->group('relay-checker');

    it('legt keinen Rekord an wenn S11 mit Physical gemischt', function () {
        ['aut' => $aut, 'club' => $club, 'meet' => $meet, 'stroke' => $stroke] = setupBase();

        $event = makeRelayEvent($meet, $stroke);
        makeRelayMember($aut, $club, $event, 'S11');
        makeRelayMember($aut, $club, $event, 'S5');
        makeRelayMember($aut, $club, $event, 'S5');
        makeRelayMember($aut, $club, $event, 'S5');
        makeRelayResult($meet, $event, $club);

        $checkResult = $this->service->checkMeet($meet);

        expect($checkResult['new_records'])->toHaveCount(0)
            ->and(SwimRecord::count())->toBe(0);
    })->group('relay-checker');

    it('legt keinen Rekord an wenn keine Entries vorhanden', function () {
        ['club' => $club, 'meet' => $meet, 'stroke' => $stroke] = setupBase();

        $event = makeRelayEvent($meet, $stroke);
        makeRelayResult($meet, $event, $club);

        $checkResult = $this->service->checkMeet($meet);

        expect($checkResult['new_records'])->toHaveCount(0)
            ->and(SwimRecord::count())->toBe(0);
    })->group('relay-checker');

    // ── Jugendrekord ──────────────────────────────────────────────────────────

    it('legt Jugendrekord an wenn alle Mitglieder Jahrgangsalter ≤ 18', function () {
        // Meet 2025, Jahrgang 2007 → Alter 18
        ['aut' => $aut, 'club' => $club, 'meet' => $meet, 'stroke' => $stroke] = setupBase();

        $event = makeRelayEvent($meet, $stroke);
        makeRelayMember($aut, $club, $event, 'S5', birthYear: 2007);
        makeRelayMember($aut, $club, $event, 'S5', birthYear: 2007);
        makeRelayMember($aut, $club, $event, 'S5', birthYear: 2007);
        makeRelayMember($aut, $club, $event, 'S5', birthYear: 2007);
        makeRelayResult($meet, $event, $club);

        $types = recordTypes($this->service->checkMeet($meet));

        expect($types)->toContain('AUT')
            ->and($types)->toContain('AUT.JR')
            ->and(SwimRecord::where('record_type', 'AUT.JR')->count())->toBe(1);
    })->group('relay-checker');

    it('legt keinen Jugendrekord an wenn ein Mitglied Alter 19 hat', function () {
        ['aut' => $aut, 'club' => $club, 'meet' => $meet, 'stroke' => $stroke] = setupBase();

        $event = makeRelayEvent($meet, $stroke);
        makeRelayMember($aut, $club, $event, 'S5', birthYear: 2007); // 18
        makeRelayMember($aut, $club, $event, 'S5', birthYear: 2006); // 19 → kein Junior
        makeRelayMember($aut, $club, $event, 'S5', birthYear: 2007);
        makeRelayMember($aut, $club, $event, 'S5', birthYear: 2007);
        makeRelayResult($meet, $event, $club);

        $types = recordTypes($this->service->checkMeet($meet));

        expect($types)->toContain('AUT')
            ->and($types)->not->toContain('AUT.JR')
            ->and(SwimRecord::where('record_type', 'AUT.JR')->count())->toBe(0);
    })->group('relay-checker');

    // ── Regionalrekord ────────────────────────────────────────────────────────

    it('legt Regionalrekord an wenn Club regional_association hat', function () {
        ['aut' => $aut, 'club' => $club, 'meet' => $meet, 'stroke' => $stroke] = setupBase('WBSV');

        $event = makeRelayEvent($meet, $stroke);
        makeRelayMember($aut, $club, $event, 'S5');
        makeRelayMember($aut, $club, $event, 'S5');
        makeRelayMember($aut, $club, $event, 'S5');
        makeRelayMember($aut, $club, $event, 'S5');
        makeRelayResult($meet, $event, $club);

        $types = recordTypes($this->service->checkMeet($meet));

        expect($types)->toContain('AUT')
            ->and($types)->toContain('AUT.WBSV')
            ->and(SwimRecord::where('record_type', 'AUT.WBSV')->count())->toBe(1);
    })->group('relay-checker');

    it('legt regionalen Jugendrekord an wenn Club regional und alle unter 18', function () {
        ['aut' => $aut, 'club' => $club, 'meet' => $meet, 'stroke' => $stroke] = setupBase('WBSV');

        $event = makeRelayEvent($meet, $stroke);
        makeRelayMember($aut, $club, $event, 'S5', birthYear: 2007);
        makeRelayMember($aut, $club, $event, 'S5', birthYear: 2007);
        makeRelayMember($aut, $club, $event, 'S5', birthYear: 2007);
        makeRelayMember($aut, $club, $event, 'S5', birthYear: 2007);
        makeRelayResult($meet, $event, $club);

        $types = recordTypes($this->service->checkMeet($meet));

        expect($types)->toContain('AUT.WBSV')
            ->and($types)->toContain('AUT.WBSV.JR')
            ->and(SwimRecord::where('record_type', 'AUT.WBSV.JR')->count())->toBe(1);
    })->group('relay-checker');

    it('legt keinen Regionalrekord an wenn Club keine regional_association hat', function () {
        ['aut' => $aut, 'club' => $club, 'meet' => $meet, 'stroke' => $stroke] = setupBase();

        $event = makeRelayEvent($meet, $stroke);
        makeRelayMember($aut, $club, $event, 'S5');
        makeRelayMember($aut, $club, $event, 'S5');
        makeRelayMember($aut, $club, $event, 'S5');
        makeRelayMember($aut, $club, $event, 'S5');
        makeRelayResult($meet, $event, $club);

        $types = recordTypes($this->service->checkMeet($meet));

        expect($types)->toContain('AUT')
            ->and($types)->not->toContain('AUT.WBSV')
            ->and(SwimRecord::count())->toBe(1); // nur AUT
    })->group('relay-checker');

    // ── Bestehender Rekord ────────────────────────────────────────────────────

    it('überschreibt bestehenden Rekord wenn neue Zeit besser', function () {
        ['aut' => $aut, 'club' => $club, 'meet' => $meet, 'stroke' => $stroke] = setupBase();

        SwimRecord::create([
            'record_type' => 'AUT',
            'stroke_type_id' => $stroke->id,
            'sport_class' => 'S20',
            'gender' => 'M',
            'course' => 'LCM',
            'distance' => 100,
            'relay_count' => 4,
            'swim_time' => 6000,
            'is_current' => true,
            'record_status' => 'APPROVED',
        ]);

        $event = makeRelayEvent($meet, $stroke);
        makeRelayMember($aut, $club, $event, 'S5');
        makeRelayMember($aut, $club, $event, 'S5');
        makeRelayMember($aut, $club, $event, 'S5');
        makeRelayMember($aut, $club, $event, 'S5');
        makeRelayResult($meet, $event, $club);

        $checkResult = $this->service->checkMeet($meet);

        expect($checkResult['new_records'])->toHaveCount(1)
            ->and(SwimRecord::where('record_type', 'AUT')->where('is_current', true)->first()->swim_time)->toBe(5000)
            ->and(SwimRecord::where('record_type', 'AUT')->where('is_current', false)->count())->toBe(1);
    })->group('relay-checker');

    it('legt keinen Rekord an wenn bestehender Rekord schneller ist', function () {
        ['aut' => $aut, 'club' => $club, 'meet' => $meet, 'stroke' => $stroke] = setupBase();

        SwimRecord::create([
            'record_type' => 'AUT',
            'stroke_type_id' => $stroke->id,
            'sport_class' => 'S20',
            'gender' => 'M',
            'course' => 'LCM',
            'distance' => 100,
            'relay_count' => 4,
            'swim_time' => 4000,
            'is_current' => true,
            'record_status' => 'APPROVED',
        ]);

        $event = makeRelayEvent($meet, $stroke);
        makeRelayMember($aut, $club, $event, 'S5');
        makeRelayMember($aut, $club, $event, 'S5');
        makeRelayMember($aut, $club, $event, 'S5');
        makeRelayMember($aut, $club, $event, 'S5');
        makeRelayResult($meet, $event, $club);

        $checkResult = $this->service->checkMeet($meet);

        expect($checkResult['new_records'])->toHaveCount(0)
            ->and(SwimRecord::count())->toBe(1);
    })->group('relay-checker');

});
