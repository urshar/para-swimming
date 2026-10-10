<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\ImportReviewItem;
use App\Models\Meet;
use App\Models\Result;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use App\Models\User;
use App\Services\RecordCheckerService;
use App\Services\RecordImportReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('regional-record-club');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

function club_rrc(?string $association): Club
{
    $club = makeClub_p5();
    $club->update(['regional_association' => $association]);

    return $club;
}

/** Ergebnis für $resultClub; der Athlet ist inzwischen bei $currentClub (Vereinswechsel nach dem Start). */
function result_rrc(Meet $meet, SwimEvent $event, Athlete $athlete, Club $resultClub, int $time): Result
{
    return Result::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
        'club_id' => $resultClub->id, 'swim_time' => $time, 'sport_class' => 'S9',
    ]);
}

/** Rekord-Check einer Veranstaltung (Throwable → RuntimeException, Muster wie check_ak()). */
function check_rrc(Meet $meet): array
{
    try {
        return app(RecordCheckerService::class)->checkMeet($meet);
    } catch (Throwable $e) {
        throw new RuntimeException('Rekord-Check fehlgeschlagen: '.$e->getMessage(), previous: $e);
    }
}

function regionalTypes_rrc(): array
{
    return SwimRecord::where('record_type', 'like', 'AUT.%')->where('record_type', '!=', 'AUT.JR')
        ->pluck('record_type')->sort()->values()->all();
}

// ── Rekordprüfung ─────────────────────────────────────────────────────────────

describe('Rekordprüfung: Landesverband aus dem Verein im Ergebnis', function () {

    it('legt den Regionalrekord für den Verband des Ergebnis-Vereins an, nicht für den aktuellen Verein', function () {
        $wien = club_rrc('WBSV');
        $noe = club_rrc('NOEVSV');
        $athlete = makeAthlete_p5($noe);
        $meet = makeMeet_p5();
        result_rrc($meet, makeEvent_p5($meet), $athlete, $wien, 6000);

        check_rrc($meet);

        expect(regionalTypes_rrc())->toBe(['AUT.WBSV'])
            ->and(SwimRecord::where('record_type', 'AUT.WBSV')->sole()->club_id)->toBe($wien->id);
    });

    it('legt keinen Regionalrekord an, wenn der Ergebnis-Verein keinem Landesverband angehört', function () {
        $national = club_rrc(null);
        $athlete = makeAthlete_p5(club_rrc('TBSV'));
        $meet = makeMeet_p5();
        result_rrc($meet, makeEvent_p5($meet), $athlete, $national, 6000);

        check_rrc($meet);

        expect(regionalTypes_rrc())->toBe([])
            ->and(SwimRecord::where('record_type', 'AUT')->count())->toBe(1);
    });
});

// ── Migration KLSV → KBSV ─────────────────────────────────────────────────────

it('benennt die alten Kärntner Rekordtypen auf KBSV um', function () {
    $stroke = makeStrokeType_p5();
    foreach (['AUT.KLSV', 'AUT.KLSV.JR', 'AUT.WBSV'] as $type) {
        SwimRecord::create(['stroke_type_id' => $stroke->id, 'record_type' => $type, 'sport_class' => 'S9',
            'gender' => 'M', 'course' => 'LCM', 'distance' => 100, 'relay_count' => 1, 'swim_time' => 6000,
            'record_status' => 'APPROVED', 'is_current' => true]);
    }

    $migration = require database_path('migrations/2026_10_07_100002_rename_klsv_regional_record_types.php');
    $migration->up();

    expect(regionalTypes_rrc())->toBe(['AUT.KBSV', 'AUT.KBSV.JR', 'AUT.WBSV']);
});

// ── Import Prüfliste ──────────────────────────────────────────────────────────

describe('Import Prüfliste: Regionalrekord mit falschem Verband', function () {

    it('findet den falschen Regionalrekord; nach dem Entfernen legt die erneute Prüfung den richtigen an', function () {
        $wien = club_rrc('WBSV');
        $athlete = makeAthlete_p5(club_rrc('NOEVSV'));
        $meet = makeMeet_p5();
        $result = result_rrc($meet, makeEvent_p5($meet), $athlete, $wien, 6000);
        // So hat die alte Prüfung den Rekord angelegt: Typ nach dem aktuellen Verein, Verein aus dem Ergebnis.
        $wrong = SwimRecord::create(['stroke_type_id' => makeStrokeType_p5()->id, 'record_type' => 'AUT.NOEVSV',
            'sport_class' => 'S9', 'gender' => 'M', 'course' => 'LCM', 'distance' => 100, 'relay_count' => 1,
            'swim_time' => 6000, 'record_status' => 'APPROVED', 'is_current' => true, 'athlete_id' => $athlete->id,
            'club_id' => $wien->id, 'result_id' => $result->id, 'set_date' => '2025-06-15']);
        $result->update(['is_regional_record' => true]);

        $created = (new RecordImportReviewService)->scanExisting();
        $item = ImportReviewItem::where('type', ImportReviewItem::TYPE_REGIONAL)->sole();

        // Nur der falsche Regionalrekord: Der Athlet hat einen Stammverein (Niederösterreich), der bleibt — kein
        // Vereinskonflikt, obwohl der Rekord Wien trägt.
        expect($created)->toBe(1)
            ->and(ImportReviewItem::where('type', ImportReviewItem::TYPE_CLUB_CONFLICT)->count())->toBe(0)
            ->and($item->swim_record_id)->toBe($wrong->id)
            ->and($item->details['expected'])->toBe('AUT.WBSV')
            ->and($item->details['meet_id'])->toBe($meet->id);

        $this->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
            ->from(route('records.import-review.index'))
            ->post(route('records.import-review.apply', $item))
            ->assertSessionHas('success');

        expect(SwimRecord::find($wrong->id))->toBeNull()
            ->and($result->fresh()->is_regional_record)->toBeFalse();

        check_rrc($meet);

        expect(regionalTypes_rrc())->toBe(['AUT.WBSV'])
            ->and($result->fresh()->is_regional_record)->toBeTrue()
            ->and((new RecordImportReviewService)->scanExisting())->toBe(0);
    });

    it('bewertet Vereine ohne Landesverband und unbekannte Rekordtypen nicht', function () {
        $athlete = makeAthlete_p5(club_rrc('WBSV'));
        $stroke = makeStrokeType_p5();
        foreach ([[club_rrc(null), 'AUT.WBSV'], [club_rrc('TBSV'), 'AUT.IND']] as [$club, $type]) {
            SwimRecord::create(['stroke_type_id' => $stroke->id, 'record_type' => $type, 'sport_class' => 'S9',
                'gender' => 'M', 'course' => 'LCM', 'distance' => 100, 'relay_count' => 1, 'swim_time' => 6000,
                'record_status' => 'APPROVED', 'is_current' => true, 'athlete_id' => $athlete->id,
                'club_id' => $club->id]);
        }

        expect((new RecordImportReviewService)->logRegionalMismatches('test'))->toBe(0);
    });
});
