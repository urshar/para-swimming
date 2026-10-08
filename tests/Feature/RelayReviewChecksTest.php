<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\ImportReviewItem;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\RelayResult;
use App\Models\RelayTeamMember;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use App\Models\User;
use App\Services\RecordImportReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('relay-review-checks');

// Prüfliste: "Regionalrekord: falscher Verband" und "Nationalität nicht AUT" auch für Staffelrekorde.

function nation_rrv(string $code): Nation
{
    return Nation::firstOrCreate(['code' => $code], ['name_de' => $code, 'name_en' => $code, 'is_active' => true]);
}

function club_rrv(?string $association): Club
{
    return Club::create(['name' => 'Verein '.($association ?? 'ohne'), 'nation_id' => nation_rrv('AUT')->id,
        'type' => 'CLUB', 'regional_association' => $association]);
}

/** Staffelrekord 4×50 m Freistil S14 Herren SCM des Vereins; $previous wird zur Historie. */
function relay_rrv(string $type, Club $club, int $time, ?SwimRecord $previous): SwimRecord
{
    $stroke = StrokeType::firstOrCreate(['lenex_code' => 'FREE'],
        ['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'code' => 'FREE']);
    $record = SwimRecord::create([
        'stroke_type_id' => $stroke->id, 'nation_id' => nation_rrv('AUT')->id, 'record_type' => $type,
        'sport_class' => 'S14', 'gender' => 'M', 'course' => 'SCM', 'distance' => 50, 'relay_count' => 4,
        'swim_time' => $time, 'record_status' => 'APPROVED', 'is_current' => true, 'set_date' => '2024-05-04',
        'club_id' => $club->id, 'supersedes_id' => $previous?->id,
    ]);
    $previous?->markAsSupersededBy($record);

    return $record;
}

/** Verknüpft ein Mitglied mit der angegebenen Nationalität an Position $position. */
function member_rrv(SwimRecord $record, int $position, string $nation, string $lastName): Athlete
{
    $athlete = Athlete::create(['first_name' => 'Max', 'last_name' => $lastName, 'gender' => 'M',
        'birth_date' => '2000-01-01', 'nation_id' => nation_rrv($nation)->id, 'club_id' => $record->club_id]);
    RelayTeamMember::create(['swim_record_id' => $record->id, 'position' => $position, 'athlete_id' => $athlete->id,
        'last_name' => $lastName, 'first_name' => 'Max']);

    return $athlete;
}

function scan_rrv(): int
{
    return (new RecordImportReviewService)->scanExisting();
}

it('meldet einen regionalen Staffelrekord, der nicht zum Verband des Staffelvereins passt', function () {
    $record = relay_rrv('AUT.KBSV', club_rrv('WBSV'), 12000, null);

    scan_rrv();
    $item = ImportReviewItem::where('type', ImportReviewItem::TYPE_REGIONAL)->sole();

    expect($item->swim_record_id)->toBe($record->id)
        ->and($item->athlete_id)->toBeNull()
        ->and($item->details['expected'])->toBe('AUT.WBSV')
        ->and($item->details['label'])->toContain('4×50m')
        ->and($item->details['label'])->toContain('Herren');
});

it('meldet passende Staffeln und Vereine ohne Landesverband nicht', function () {
    relay_rrv('AUT.WBSV', club_rrv('WBSV'), 12000, null);
    relay_rrv('AUT.KBSV.JR', club_rrv(null), 12000, null);

    scan_rrv();

    expect(ImportReviewItem::where('type', ImportReviewItem::TYPE_REGIONAL)->count())->toBe(0);
});

it('meldet einen Staffelrekord mit einem Mitglied, das nicht AUT ist', function () {
    $record = relay_rrv('AUT', club_rrv('WBSV'), 12000, null);
    member_rrv($record, 1, 'AUT', 'Heimisch');
    $foreign = member_rrv($record, 2, 'UKR', 'Komarov');

    scan_rrv();
    $item = ImportReviewItem::where('type', ImportReviewItem::TYPE_NATIONALITY)->sole();

    expect($item->swim_record_id)->toBe($record->id)
        ->and($item->athlete_id)->toBe($foreign->id)
        ->and($item->details['nation'])->toBe('UKR')
        ->and($item->details['members'])->toContain('(UKR)')
        ->and($item->details['members'])->not->toContain('Heimisch');
});

it('entfernt den Staffelrekord, macht den Vorgänger aktuell und setzt das Flag am Staffelergebnis zurück', function () {
    $club = club_rrv('WBSV');
    $previous = relay_rrv('AUT', $club, 13000, null);
    $record = relay_rrv('AUT', $club, 12000, $previous);
    $meet = Meet::create(['name' => 'ÖSTM', 'start_date' => '2024-05-04', 'course' => 'SCM',
        'nation_id' => nation_rrv('AUT')->id]);
    $event = SwimEvent::create(['meet_id' => $meet->id, 'stroke_type_id' => $record->stroke_type_id, 'distance' => 50,
        'relay_count' => 4, 'gender' => 'M', 'session_number' => 1, 'event_number' => 1, 'round' => 'TIM']);
    $relay = RelayResult::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'club_id' => $club->id,
        'gender' => 'M', 'swim_time' => 12000, 'is_national_record' => true]);
    $record->update(['relay_result_id' => $relay->id]);
    member_rrv($record, 1, 'UKR', 'Komarov');
    scan_rrv();
    $item = ImportReviewItem::where('type', ImportReviewItem::TYPE_NATIONALITY)->sole();

    $this->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->post(route('records.import-review.apply', $item))
        ->assertSessionHas('success', fn (string $message) => str_contains($message, '4×50m'));

    expect(SwimRecord::find($record->id))->toBeNull()
        ->and($previous->fresh()->is_current)->toBeTrue()
        ->and($previous->fresh()->record_status)->toBe('APPROVED')
        ->and($relay->fresh()->is_national_record)->toBeFalse()
        ->and($item->fresh()->status)->toBe(ImportReviewItem::STATUS_APPLIED);
});

it('zeigt beide Staffel-Befunde in der Prüfliste an', function () {
    $record = relay_rrv('AUT.KBSV', club_rrv('WBSV'), 12000, null);
    member_rrv($record, 1, 'UKR', 'Komarov');

    expect(scan_rrv())->toBe(2);

    $this->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->get(route('records.import-review.index'))
        ->assertOk()
        ->assertSee('Staffelmitglieder:')
        ->assertSee('Komarov, Max (UKR)')
        ->assertSee('AUT.WBSV');
});
