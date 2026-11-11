<?php

use App\Enums\AccessScope;
use App\Enums\RoleTemplate;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\RelayResult;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use App\Models\User;
use App\Services\MeetRecordsService;
use App\Services\PermissionTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class)->group('meet-records-check-status-p2');

// Veranstaltungsliste: Knopf "Rekorde prüfen" je Zeile und Prüfstatus-Badge neben I/E/R, das auf die Rekorde der
// Veranstaltung verlinkt.

function nation_mcs2(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
}

function club_mcs2(): Club
{
    return Club::firstOrCreate(['name' => 'Testverein'], ['nation_id' => nation_mcs2()->id, 'type' => 'CLUB']);
}

function meet_mcs2(string $name = 'ÖSTM Langbahn'): Meet
{
    return Meet::query()->create(['name' => $name, 'start_date' => '2026-05-05', 'end_date' => '2026-05-05',
        'course' => 'LCM', 'city' => 'Graz', 'nation_id' => nation_mcs2()->id, 'timing' => 'AUTOMATIC']);
}

function event_mcs2(Meet $meet, int $relayCount): SwimEvent
{
    $stroke = StrokeType::firstOrCreate(['lenex_code' => 'FREE'],
        ['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'code' => 'FREE', 'is_active' => true]);

    return SwimEvent::query()->create(['meet_id' => $meet->id, 'stroke_type_id' => $stroke->id, 'distance' => 100,
        'relay_count' => $relayCount, 'gender' => 'M', 'session_number' => 1,
        'event_number' => SwimEvent::query()->where('meet_id', $meet->id)->count() + 1, 'round' => 'TIM']);
}

function result_mcs2(Meet $meet): Result
{
    $athlete = Athlete::query()->create(['first_name' => 'Max', 'last_name' => 'Muster', 'gender' => 'M',
        'birth_date' => '1990-01-01', 'nation_id' => nation_mcs2()->id, 'club_id' => club_mcs2()->id]);

    return Result::query()->create(['meet_id' => $meet->id, 'swim_event_id' => event_mcs2($meet, 1)->id,
        'athlete_id' => $athlete->id, 'club_id' => club_mcs2()->id, 'sport_class' => 'S9', 'swim_time' => 7000,
        'heat' => 1]);
}

function relayResult_mcs2(Meet $meet): RelayResult
{
    return RelayResult::query()->create(['meet_id' => $meet->id, 'swim_event_id' => event_mcs2($meet, 4)->id,
        'club_id' => club_mcs2()->id, 'relay_number' => 1, 'gender' => 'M', 'relay_class' => '34',
        'swim_time' => 30000]);
}

function record_mcs2(array $attributes): SwimRecord
{
    return SwimRecord::create([
        'stroke_type_id' => StrokeType::query()->where('lenex_code', 'FREE')->value('id'),
        'nation_id' => nation_mcs2()->id, 'record_type' => 'AUT', 'sport_class' => 'S9', 'gender' => 'M',
        'course' => 'LCM', 'distance' => 100, 'relay_count' => 1, 'swim_time' => 7000,
        'record_status' => 'APPROVED', 'is_current' => true, 'set_date' => '2026-05-05',
        ...$attributes,
    ]);
}

function admin_mcs2(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

/**
 * @param  array<string, AccessScope>  $read
 * @param  array<string, AccessScope>  $write
 *
 * @throws ValidationException
 * @throws Throwable
 */
function user_mcs2(array $read, array $write): User
{
    $user = User::factory()->create(['club_id' => club_mcs2()->id, 'role_template' => RoleTemplate::Empty]);
    $matrix = [];
    foreach ($read as $module => $scope) {
        $matrix[$module] = ['read' => $scope, 'write' => $write[$module] ?? AccessScope::None];
    }
    app(PermissionTemplateService::class)->setPermissions($user, $matrix);

    return $user->fresh();
}

// ── Anzahl je Veranstaltung ───────────────────────────────────────────────────

it('zählt die Rekorde mehrerer Veranstaltungen auf einmal wie count()', function () {
    $meet = meet_mcs2();
    $other = meet_mcs2('Andere');
    $empty = meet_mcs2('Ohne Rekorde');
    $result = result_mcs2($meet);
    $relay = relayResult_mcs2($meet);
    $otherResult = result_mcs2($other);

    record_mcs2(['result_id' => $result->id]);
    record_mcs2(['result_id' => $result->id, 'record_type' => 'AUT.JR']);
    record_mcs2(['relay_result_id' => $relay->id, 'relay_count' => 4, 'sport_class' => '34']);
    record_mcs2(['result_id' => $result->id, 'record_status' => 'INVALID']);
    record_mcs2(['result_id' => $otherResult->id, 'record_status' => 'PENDING']);

    $service = app(MeetRecordsService::class);

    expect($service->countsFor([$meet->id, $other->id, $empty->id]))
        ->toEqual([$meet->id => 3, $other->id => 1])
        ->and($service->countsFor([$meet->id])[$meet->id])->toBe($service->count($meet))
        ->and($service->countsFor([]))->toBe([]);
});

// ── Prüfstatus-Badge ──────────────────────────────────────────────────────────

it('zeigt ungeprüfte und geprüfte Veranstaltungen mit Anzahl und Link auf die Rekorde', function () {
    $unchecked = meet_mcs2('Ungeprüft');
    $checked = meet_mcs2('Geprüft');
    record_mcs2(['result_id' => result_mcs2($checked)->id]);
    $this->travelTo('2026-11-11 10:15:00');
    $checked->markRecordsChecked();

    $this->actingAs(admin_mcs2())->get(route('meets.index'))
        ->assertOk()
        ->assertSee('Noch nicht auf Rekorde geprüft')
        ->assertSee('Rekorde geprüft am 11.11.2026 10:15 – 1 Rekord')
        ->assertSee(route('meets.records', $checked))
        ->assertSee(route('meets.records', $unchecked));
});

it('zeigt ohne Leserecht auf Rekorde den Status ohne Anzahl und ohne Link', /** @throws Throwable */ function () {
    $meet = meet_mcs2();
    record_mcs2(['result_id' => result_mcs2($meet)->id]);
    $this->travelTo('2026-11-11 10:15:00');
    $meet->markRecordsChecked();

    $this->actingAs(user_mcs2(['meets' => AccessScope::All], []))->get(route('meets.index'))
        ->assertOk()
        ->assertSee('Rekorde geprüft am 11.11.2026 10:15')
        ->assertDontSee('1 Rekord')
        ->assertDontSee(route('meets.records', $meet));
});

// ── Knopf "Rekorde prüfen" ────────────────────────────────────────────────────

it('zeigt den Knopf nur mit Schreibrecht auf Wettkämpfe', /** @throws Throwable */ function () {
    $meet = meet_mcs2();

    $this->actingAs(admin_mcs2())->get(route('meets.index'))
        ->assertSee(route('records.check', $meet))
        ->assertSee('Rekorde prüfen');

    $reader = user_mcs2(['meets' => AccessScope::All, 'records' => AccessScope::All], []);
    $this->actingAs($reader)->get(route('meets.index'))
        ->assertOk()
        ->assertDontSee(route('records.check', $meet));

    $this->actingAs($reader)->post(route('records.check', $meet), ['from' => 'list'])->assertForbidden();
    expect($meet->fresh()->records_checked_at)->toBeNull();
});

it('prüft aus der Liste und kehrt in die gemerkte Liste zurück', function () {
    $meet = meet_mcs2();
    result_mcs2($meet);
    $admin = admin_mcs2();

    // Liste mit Filter aufrufen, damit die Middleware remember.list:meets die URL merkt.
    $this->actingAs($admin)->get(route('meets.index', ['course' => 'LCM', 'year' => '2026']))->assertOk();

    $this->actingAs($admin)->post(route('records.check', $meet), ['from' => 'list'])
        ->assertRedirect(route('meets.index', ['course' => 'LCM', 'year' => '2026']))
        ->assertSessionHas('success', '1 Ergebnis(se) geprüft, 1 neuer Rekord')
        ->assertSessionMissing('record_check_result');

    expect($meet->fresh()->records_checked_at)->not->toBeNull();
});

it('kehrt ohne from=list weiterhin zur aufrufenden Seite mit dem ausführlichen Prüfergebnis zurück', function () {
    $meet = meet_mcs2();
    result_mcs2($meet);

    $this->actingAs(admin_mcs2())
        ->from(route('meets.show', $meet))
        ->post(route('records.check', $meet))
        ->assertRedirect(route('meets.show', $meet))
        ->assertSessionHas('record_check_result');
});
