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

uses(RefreshDatabase::class)->group('meet-records-p1');

// Rekorde je Veranstaltung (Liste in der Verwaltung): alle Rekorde aus Einzel- und Staffelergebnissen der
// Veranstaltung, je Rekordart gruppiert, ohne ungültige Rekorde und Zielzeiten.

function nation_mr1(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
}

function stroke_mr1(string $code = 'FREE', string $name = 'Freistil'): StrokeType
{
    return StrokeType::firstOrCreate(['lenex_code' => $code],
        ['name_de' => $name, 'name_en' => $name, 'code' => $code, 'is_active' => true]);
}

function club_mr1(): Club
{
    return Club::firstOrCreate(['name' => 'Testverein'], ['nation_id' => nation_mr1()->id, 'type' => 'CLUB']);
}

function meet_mr1(string $name = 'ÖSTM Kurzbahn'): Meet
{
    return Meet::query()->create(['name' => $name, 'start_date' => '2026-05-05', 'end_date' => '2026-05-05',
        'course' => 'SCM', 'city' => 'Graz', 'nation_id' => nation_mr1()->id, 'timing' => 'AUTOMATIC']);
}

function athlete_mr1(string $lastName): Athlete
{
    return Athlete::query()->create(['first_name' => 'Max', 'last_name' => $lastName, 'gender' => 'M',
        'birth_date' => '1990-01-01', 'nation_id' => nation_mr1()->id, 'club_id' => club_mr1()->id]);
}

function result_mr1(Meet $meet, Athlete $athlete): Result
{
    $event = SwimEvent::query()->create(['meet_id' => $meet->id, 'stroke_type_id' => stroke_mr1()->id,
        'distance' => 50, 'relay_count' => 1, 'gender' => 'M', 'session_number' => 1,
        'event_number' => SwimEvent::query()->where('meet_id', $meet->id)->count() + 1, 'round' => 'TIM']);

    return Result::query()->create(['meet_id' => $meet->id, 'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id, 'club_id' => club_mr1()->id, 'sport_class' => 'S9', 'swim_time' => 3000,
        'heat' => 1]);
}

function relayResult_mr1(Meet $meet): RelayResult
{
    $event = SwimEvent::query()->create(['meet_id' => $meet->id, 'stroke_type_id' => stroke_mr1()->id,
        'distance' => 50, 'relay_count' => 4, 'gender' => 'M', 'session_number' => 1,
        'event_number' => SwimEvent::query()->where('meet_id', $meet->id)->count() + 1, 'round' => 'TIM']);

    return RelayResult::query()->create(['meet_id' => $meet->id, 'swim_event_id' => $event->id,
        'club_id' => club_mr1()->id, 'relay_number' => 1, 'gender' => 'M', 'relay_class' => '34',
        'swim_time' => 15000]);
}

/** Rekord 50 m Freistil SCM; die Sportklasse dient in den Tests als eindeutige Kennung der Zeile. */
function record_mr1(string $sportClass, array $attributes = []): SwimRecord
{
    return SwimRecord::create([
        'stroke_type_id' => stroke_mr1()->id, 'nation_id' => nation_mr1()->id, 'record_type' => 'AUT',
        'sport_class' => $sportClass, 'gender' => 'M', 'course' => 'SCM', 'distance' => 50, 'relay_count' => 1,
        'swim_time' => 3000, 'record_status' => 'APPROVED', 'is_current' => true, 'set_date' => '2026-05-05',
        ...$attributes,
    ]);
}

function admin_mr1(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

/**
 * @param  array<string, array{read: AccessScope, write: AccessScope}>  $matrix
 *
 * @throws ValidationException
 * @throws Throwable
 */
function user_mr1(array $matrix): User
{
    $user = User::factory()->create(['club_id' => club_mr1()->id, 'role_template' => RoleTemplate::Empty]);
    app(PermissionTemplateService::class)->setPermissions($user, $matrix);

    return $user->fresh();
}

/** Sportklassen in der Reihenfolge, in der die Liste sie zeigt (je Gruppe). */
function listedClasses_mr1(Meet $meet): array
{
    return app(MeetRecordsService::class)->grouped($meet)
        ->map(fn (array $group) => [$group['type'], $group['records']->pluck('sport_class')->all()])
        ->all();
}

it('zeigt Rekorde aus Einzel- und Staffelergebnissen der Veranstaltung, auch aus Zwischenzeiten', function () {
    $meet = meet_mr1();
    $result = result_mr1($meet, athlete_mr1('Einzel'));
    record_mr1('S1', ['result_id' => $result->id, 'athlete_id' => $result->athlete_id]);
    record_mr1('S2', ['result_id' => $result->id, 'from_split' => true, 'record_status' => 'PENDING']);
    record_mr1('S3', ['result_id' => $result->id, 'record_status' => 'APPROVED.HISTORY', 'is_current' => false]);
    record_mr1('S4', ['result_id' => $result->id, 'record_status' => 'PENDING.HISTORY', 'is_current' => false]);
    record_mr1('S20', ['relay_result_id' => relayResult_mr1($meet)->id, 'relay_count' => 4, 'swim_time' => 15000]);

    expect(listedClasses_mr1($meet))->toBe([['AUT', ['S1', 'S2', 'S3', 'S4', 'S20']]]);
});

it('lässt ungültige Rekorde, Zielzeiten, fremde Veranstaltungen und Rekorde ohne Ergebnis weg', function () {
    $meet = meet_mr1();
    $result = result_mr1($meet, athlete_mr1('Einzel'));
    $otherResult = result_mr1(meet_mr1('Anderer Wettkampf'), athlete_mr1('Fremd'));
    record_mr1('S1', ['result_id' => $result->id]);
    record_mr1('S5', ['result_id' => $result->id, 'record_status' => 'INVALID', 'is_current' => false]);
    record_mr1('S6', ['result_id' => $result->id, 'record_status' => 'TARGETTIME']);
    record_mr1('S7', ['result_id' => $otherResult->id]);
    record_mr1('S8');

    expect(listedClasses_mr1($meet))->toBe([['AUT', ['S1']]])
        ->and(app(MeetRecordsService::class)->count($meet))->toBe(1);
});

it('ordnet Rekordarten national, Jugend, Landesverbände, dann übrige; Einzel vor Staffel, nach Lage', function () {
    $meet = meet_mr1();
    $result = result_mr1($meet, athlete_mr1('Einzel'));
    $relay = relayResult_mr1($meet);
    $back = stroke_mr1('BACK', 'Rücken');
    record_mr1('S9', ['result_id' => $result->id, 'record_type' => 'WR']);
    record_mr1('S9', ['result_id' => $result->id, 'record_type' => 'AUT.WBSV.JR']);
    record_mr1('S9', ['result_id' => $result->id, 'record_type' => 'AUT.WBSV']);
    record_mr1('S9', ['result_id' => $result->id, 'record_type' => 'AUT.BBSV']);
    record_mr1('S9', ['result_id' => $result->id, 'record_type' => 'AUT.JR']);
    record_mr1('S20', ['relay_result_id' => $relay->id, 'relay_count' => 4]);
    record_mr1('S10', ['result_id' => $result->id, 'stroke_type_id' => $back->id]);
    record_mr1('S9', ['result_id' => $result->id, 'distance' => 100]);
    record_mr1('S10', ['result_id' => $result->id]);
    record_mr1('S9', ['result_id' => $result->id]);

    $groups = app(MeetRecordsService::class)->grouped($meet);

    expect($groups->pluck('type')->all())->toBe(['AUT', 'AUT.JR', 'AUT.BBSV', 'AUT.WBSV', 'AUT.WBSV.JR', 'WR'])
        ->and($groups->pluck('label')->all())
        ->toBe(['Österreich', 'Österreich – Jugend', 'BBSV Burgenland', 'WBSV Wien', 'WBSV Wien – Jugend', 'WR'])
        ->and($groups->first()['records']->map(fn (SwimRecord $r) => $r->distance.' '.$r->strokeType->lenex_code
            .' '.$r->sport_class)->all())
        ->toBe(['50 FREE S9', '50 FREE S10', '100 FREE S9', '50 BACK S10', '50 FREE S20']);
});

it('zeigt die Liste mit Status, Vorgängerzeit und Zwischenzeit-Badge', function () {
    $meet = meet_mr1();
    $result = result_mr1($meet, athlete_mr1('Rekordhalter'));
    $previous = record_mr1('S9', ['swim_time' => 3150, 'record_status' => 'APPROVED.HISTORY', 'is_current' => false,
        'set_date' => '2020-01-01']);
    record_mr1('S9', ['result_id' => $result->id, 'athlete_id' => $result->athlete_id, 'swim_time' => 3012,
        'supersedes_id' => $previous->id]);
    record_mr1('S11', ['result_id' => $result->id, 'from_split' => true, 'record_status' => 'PENDING']);

    $html = $this->actingAs(admin_mr1())->get(route('meets.records', $meet))
        ->assertOk()
        ->assertSee('ÖSTM Kurzbahn')
        ->assertSeeInOrder(['Österreich', 'S9', 'Rekordhalter', '00:30.12', '00:31.50', 'Bestätigt', 'S11', 'Ausstehend'])
        ->getContent();

    expect($html)->toMatch('/data-flux-badge[^>]*>\s*Zwischenzeit\s*</');
});

it('zeigt einen Hinweis, wenn bei der Veranstaltung keine Rekorde aufgestellt wurden', function () {
    $this->actingAs(admin_mr1())->get(route('meets.records', meet_mr1()))
        ->assertOk()
        ->assertSee('Bei dieser Veranstaltung wurden keine Rekorde aufgestellt.');
});

it('verlinkt die Liste mit Anzahl von der Wettkampfseite', function () {
    $meet = meet_mr1();
    record_mr1('S9', ['result_id' => result_mr1($meet, athlete_mr1('Einzel'))->id]);

    $this->actingAs(admin_mr1())->get(route('meets.show', $meet))
        ->assertOk()
        ->assertSee(route('meets.records', $meet))
        ->assertSee('Rekorde (1)');
});

it('verlangt Leserecht auf Rekorde', /** @throws Throwable */ function () {
    $meet = meet_mr1();
    $none = ['read' => AccessScope::None, 'write' => AccessScope::None];
    $all = ['read' => AccessScope::All, 'write' => AccessScope::None];
    $meetsOnly = user_mr1(['meets' => $all, 'records' => $none]);
    $withRecords = user_mr1(['meets' => $all, 'records' => $all]);

    $this->actingAs($meetsOnly)->get(route('meets.records', $meet))->assertForbidden();
    $this->actingAs($meetsOnly)->get(route('meets.show', $meet))->assertOk()
        ->assertDontSee(route('meets.records', $meet));
    $this->actingAs($withRecords)->get(route('meets.records', $meet))->assertOk();
    $this->actingAs($withRecords)->get(route('meets.show', $meet))->assertOk()
        ->assertSee(route('meets.records', $meet));
});
