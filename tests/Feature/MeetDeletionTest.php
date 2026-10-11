<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Entry;
use App\Models\ForeignStart;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Qualification;
use App\Models\QualifyingTime;
use App\Models\QualifyingTimeList;
use App\Models\RelayResult;
use App\Models\RelayResultMember;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('meet-deletion');

// Wettkampf löschen: endgültig, samt Meldungen, Ergebnissen, Gaststarts, Qualifikationen und daraus entstandenen
// Rekorden (MeetDeletionService).

function nation_md(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
}

function stroke_md(): StrokeType
{
    return StrokeType::firstOrCreate(['lenex_code' => 'FREE'], ['code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle']);
}

function athlete_md(): Athlete
{
    $club = Club::firstOrCreate(['name' => 'Heimverein'], ['nation_id' => nation_md()->id]);

    return Athlete::create(['first_name' => 'Anna', 'last_name' => 'Hummel', 'gender' => 'F', 'birth_date' => '2000-01-01',
        'nation_id' => nation_md()->id, 'club_id' => $club->id]);
}

/** Wettkampf mit Bewerb, Meldung, Einzel- und Staffelergebnis, Gaststart und Qualifikation. */
function fullMeet_md(string $name, string $date, Athlete $athlete): array
{
    $meet = Meet::create(['name' => $name, 'nation_id' => nation_md()->id, 'course' => 'SCM', 'start_date' => $date]);
    $event = SwimEvent::create(['meet_id' => $meet->id, 'stroke_type_id' => stroke_md()->id, 'distance' => 50, 'gender' => 'F',
        'event_number' => 1, 'relay_count' => 1]);
    $relayEvent = SwimEvent::create(['meet_id' => $meet->id, 'stroke_type_id' => stroke_md()->id, 'distance' => 50,
        'gender' => 'F', 'event_number' => 2, 'relay_count' => 4]);

    Entry::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
        'club_id' => $athlete->club_id]);
    $result = Result::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
        'club_id' => $athlete->club_id, 'sport_class' => 'S9', 'swim_time' => 3300]);
    $relay = RelayResult::create(['meet_id' => $meet->id, 'swim_event_id' => $relayEvent->id, 'club_id' => $athlete->club_id,
        'gender' => 'F', 'swim_time' => 15000]);
    RelayResultMember::create(['relay_result_id' => $relay->id, 'position' => 1, 'athlete_id' => $athlete->id]);
    ForeignStart::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'club_name' => 'Swiss Club',
        'person_key' => 'x|y|', 'swim_time' => 3200]);

    $list = QualifyingTimeList::firstOrCreate(['year' => 2026], ['is_active' => true,
        'qualification_period_start' => '2026-01-01', 'qualification_period_end' => '2026-12-31']);
    // Je Wettkampf eine eigene Richtzeit (eigene Strecke): Je Liste, Athlet und Richtzeit ist nur eine Qualifikation
    // erlaubt, und die Richtzeit selbst ist je Liste, Lage, Strecke, Geschlecht und Klasse eindeutig.
    static $distance = 0;
    $distance += 50;
    $qualifyingTime = QualifyingTime::create(['qualifying_time_list_id' => $list->id, 'stroke_type_id' => stroke_md()->id,
        'distance' => $distance, 'gender' => 'F', 'sport_class' => 'S9', 'value_centiseconds' => 4000,
        'source' => QualifyingTime::SOURCE_CALCULATED]);
    Qualification::create(['meet_id' => $meet->id, 'qualifying_time_list_id' => $list->id,
        'qualifying_time_id' => $qualifyingTime->id, 'athlete_id' => $athlete->id, 'result_id' => $result->id,
        'club_id' => $athlete->club_id, 'sport_class' => 'S9', 'swim_time_centiseconds' => 3300, 'qualified_at' => now()]);

    return [$meet, $result];
}

/** Rekord 50 m Freistil S9 Damen; optional aus einem Ergebnis, als Nachfolger von $previous. */
function record_md(int $time, string $date, ?Result $result, ?SwimRecord $previous): SwimRecord
{
    $record = SwimRecord::create(['stroke_type_id' => stroke_md()->id, 'nation_id' => nation_md()->id, 'record_type' => 'AUT',
        'sport_class' => 'S9', 'gender' => 'F', 'course' => 'SCM', 'distance' => 50, 'relay_count' => 1,
        'swim_time' => $time, 'record_status' => 'APPROVED', 'is_current' => true, 'set_date' => $date,
        'result_id' => $result?->id, 'supersedes_id' => $previous?->id]);
    $previous?->markAsSupersededBy($record);

    return $record;
}

it('löscht einen Wettkampf endgültig samt allem, was daran hängt, und lässt andere unberührt', function () {
    $athlete = athlete_md();
    [$meet, $result] = fullMeet_md('Staatsmeisterschaften', '2026-05-10', $athlete);
    [$other] = fullMeet_md('Landesmeisterschaften', '2026-06-01', $athlete);
    $listRecord = record_md(3500, '2020-01-01', null, null);
    record_md(3300, '2026-05-10', $result, $listRecord);

    $this->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->delete(route('meets.destroy', $meet))
        ->assertSessionHas('success', fn (string $message) => str_contains($message, '1 Ergebnisse, 1 Staffelergebnisse, 1 Gaststarts, 1 Meldungen, 0 Staffelmeldungen, 1 Qualifikationen, 1 Rekorde'));

    expect(Meet::withTrashed()->whereKey($meet->id)->exists())->toBeFalse()
        ->and(SwimEvent::where('meet_id', $meet->id)->count())->toBe(0)
        ->and(Result::where('meet_id', $meet->id)->count())->toBe(0)
        ->and(RelayResult::where('meet_id', $meet->id)->count())->toBe(0)
        ->and(RelayResultMember::count())->toBe(1)
        ->and(Entry::where('meet_id', $meet->id)->count())->toBe(0)
        ->and(ForeignStart::where('meet_id', $meet->id)->count())->toBe(0)
        ->and(Qualification::where('meet_id', $meet->id)->count())->toBe(0)
        ->and(Qualification::where('meet_id', $other->id)->count())->toBe(1)
        ->and(Result::where('meet_id', $other->id)->count())->toBe(1)
        ->and(SwimRecord::pluck('id')->all())->toBe([$listRecord->id])
        ->and($listRecord->fresh()->is_current)->toBeTrue()
        ->and($listRecord->fresh()->record_status)->toBe('APPROVED');
});

it('verknüpft beim Löschen Vorgänger und Nachfolger eines Rekords aus dem Wettkampf', function () {
    $athlete = athlete_md();
    [$meet, $result] = fullMeet_md('Staatsmeisterschaften', '2026-05-10', $athlete);
    [, $laterResult] = fullMeet_md('Landesmeisterschaften', '2026-06-01', $athlete);
    $first = record_md(3500, '2020-01-01', null, null);
    $fromMeet = record_md(3300, '2026-05-10', $result, $first);
    $later = record_md(3200, '2026-06-01', $laterResult, $fromMeet);

    $this->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->delete(route('meets.destroy', $meet));

    expect(SwimRecord::find($fromMeet->id))->toBeNull()
        ->and($later->fresh()->supersedes_id)->toBe($first->id)
        ->and($later->fresh()->is_current)->toBeTrue()
        ->and($first->fresh()->superseded_by_id)->toBe($later->id);
});

it('löscht als gelöscht markierte Wettkämpfe per Kommando, mit Probelauf', function () {
    $athlete = athlete_md();
    [$meet] = fullMeet_md('Alt', '2012-05-05', $athlete);
    [$kept] = fullMeet_md('Aktiv', '2026-05-10', $athlete);
    $meet->delete();

    $this->artisan('meets:purge-deleted', ['--dry-run' => true])
        ->expectsOutputToContain('Probelauf')
        ->assertSuccessful();
    expect(Meet::withTrashed()->whereKey($meet->id)->exists())->toBeTrue();

    $this->artisan('meets:purge-deleted')
        ->expectsConfirmation('1 Wettkampf/Wettkämpfe endgültig löschen?', 'yes')
        ->assertSuccessful();

    expect(Meet::withTrashed()->whereKey($meet->id)->exists())->toBeFalse()
        ->and(Result::where('meet_id', $meet->id)->count())->toBe(0)
        ->and(Result::where('meet_id', $kept->id)->count())->toBe(1);
});
