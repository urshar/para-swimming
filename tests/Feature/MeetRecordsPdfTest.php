<?php

use App\Enums\AccessScope;
use App\Enums\RoleTemplate;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\RelayResult;
use App\Models\RelayTeamMember;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use App\Models\User;
use App\Services\MeetRecordsService;
use App\Services\PermissionTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class)->group('meet-records-p2');

// Rekorde je Veranstaltung als PDF: Auslieferung, Inhalt der PDF-View und Zugriffsrechte.

function nation_mr2(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
}

function stroke_mr2(): StrokeType
{
    return StrokeType::firstOrCreate(['lenex_code' => 'FREE'],
        ['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'code' => 'FREE', 'is_active' => true]);
}

function club_mr2(): Club
{
    return Club::firstOrCreate(['name' => 'Testverein'], ['nation_id' => nation_mr2()->id, 'type' => 'CLUB']);
}

function meet_mr2(): Meet
{
    return Meet::query()->create(['name' => 'ÖSTM Kurzbahn', 'start_date' => '2026-05-05', 'end_date' => '2026-05-05',
        'course' => 'SCM', 'city' => 'Graz', 'nation_id' => nation_mr2()->id, 'timing' => 'AUTOMATIC']);
}

function event_mr2(Meet $meet, int $relayCount): SwimEvent
{
    return SwimEvent::query()->create(['meet_id' => $meet->id, 'stroke_type_id' => stroke_mr2()->id,
        'distance' => 50, 'relay_count' => $relayCount, 'gender' => 'M', 'session_number' => 1,
        'event_number' => SwimEvent::query()->where('meet_id', $meet->id)->count() + 1, 'round' => 'TIM']);
}

/** Einzelrekord mit Vorgänger und Staffelrekord mit Mitgliedern aus einer Veranstaltung. */
function seedRecords_mr2(Meet $meet): void
{
    $athlete = Athlete::query()->create(['first_name' => 'Max', 'last_name' => 'Rekordhalter', 'gender' => 'M',
        'birth_date' => '1990-01-01', 'nation_id' => nation_mr2()->id, 'club_id' => club_mr2()->id]);
    $result = Result::query()->create(['meet_id' => $meet->id, 'swim_event_id' => event_mr2($meet, 1)->id,
        'athlete_id' => $athlete->id, 'club_id' => club_mr2()->id, 'sport_class' => 'S9', 'swim_time' => 3012,
        'heat' => 1]);
    $relayResult = RelayResult::query()->create(['meet_id' => $meet->id, 'swim_event_id' => event_mr2($meet, 4)->id,
        'club_id' => club_mr2()->id, 'relay_number' => 1, 'gender' => 'M', 'relay_class' => '34',
        'swim_time' => 15000]);

    $base = ['stroke_type_id' => stroke_mr2()->id, 'nation_id' => nation_mr2()->id, 'gender' => 'M',
        'course' => 'SCM', 'distance' => 50, 'relay_count' => 1, 'is_current' => true, 'set_date' => '2026-05-05'];
    $previous = SwimRecord::create([...$base, 'record_type' => 'AUT', 'sport_class' => 'S9', 'swim_time' => 3150,
        'record_status' => 'APPROVED.HISTORY', 'is_current' => false, 'set_date' => '2020-01-01']);
    SwimRecord::create([...$base, 'record_type' => 'AUT', 'sport_class' => 'S9', 'swim_time' => 3012,
        'record_status' => 'APPROVED', 'result_id' => $result->id, 'athlete_id' => $athlete->id,
        'supersedes_id' => $previous->id]);
    SwimRecord::create([...$base, 'record_type' => 'AUT.JR', 'sport_class' => 'S10', 'swim_time' => 2990,
        'record_status' => 'PENDING', 'from_split' => true, 'result_id' => $result->id]);
    $relay = SwimRecord::create([...$base, 'record_type' => 'AUT', 'sport_class' => '34', 'relay_count' => 4,
        'swim_time' => 15000, 'record_status' => 'APPROVED', 'relay_result_id' => $relayResult->id]);
    foreach (['Anna', 'Berta', 'Clara', 'Dora'] as $position => $firstName) {
        RelayTeamMember::query()->create(['swim_record_id' => $relay->id, 'position' => $position + 1,
            'first_name' => $firstName, 'last_name' => 'Staffel', 'gender' => 'F']);
    }
}

/** Gerenderte PDF-View (HTML vor dompdf), für Inhaltsprüfungen. */
function pdfHtml_mr2(Meet $meet): string
{
    return view('pdf.meet-records', [
        'meet' => $meet,
        'groups' => app(MeetRecordsService::class)->grouped($meet),
        'statusLabels' => MeetRecordsService::STATUS_LABELS,
        'generatedAt' => now(),
    ])->render();
}

/**
 * @param  array<string, array{read: AccessScope, write: AccessScope}>  $matrix
 *
 * @throws ValidationException
 * @throws Throwable
 */
function user_mr2(array $matrix): User
{
    $user = User::factory()->create(['club_id' => club_mr2()->id, 'role_template' => RoleTemplate::Empty]);
    app(PermissionTemplateService::class)->setPermissions($user, $matrix);

    return $user->fresh();
}

it('liefert die Rekorde der Veranstaltung als PDF aus', function () {
    $meet = meet_mr2();
    seedRecords_mr2($meet);

    $this->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->get(route('meets.records.pdf', $meet))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('enthält im PDF je Rekordart die Rekorde mit Vorgängerzeit, Status und Staffelmitgliedern', function () {
    $meet = meet_mr2();
    seedRecords_mr2($meet);

    $html = pdfHtml_mr2($meet);

    expect($html)->toContain('Rekorde – ÖSTM Kurzbahn')
        ->toContain('Graz · 05.05.2026')
        ->toContain('3 Rekorde')
        ->and(strpos($html, 'Österreich <span'))->toBeLessThan(strpos($html, 'Österreich – Jugend'))
        ->and($html)->toContain('Rekordhalter')
        ->toContain('00:30.12')
        ->toContain('00:31.50')
        ->toContain('Anna Staffel, Berta Staffel, Clara Staffel, Dora Staffel')
        ->toContain('Zwischenzeit')
        ->toContain('Ausstehend')
        ->toContain('Seite');
});

it('verwendet in der fixen Fußzeile keinen float (dompdf-Fallstrick)', function () {
    expect(pdfHtml_mr2(meet_mr2()))->not->toMatch('/float\s*:\s*(left|right)/');
});

it('erzeugt auch ohne Rekorde ein PDF mit Hinweis', function () {
    $meet = meet_mr2();

    expect(pdfHtml_mr2($meet))->toContain('Bei dieser Veranstaltung wurden keine Rekorde aufgestellt.');

    $this->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->get(route('meets.records.pdf', $meet))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('verlinkt das PDF von der Rekordliste', function () {
    $meet = meet_mr2();

    $this->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->get(route('meets.records', $meet))
        ->assertOk()
        ->assertSee(route('meets.records.pdf', $meet));
});

it('verlangt für das PDF Leserecht auf Rekorde', /** @throws Throwable */ function () {
    $meet = meet_mr2();
    $all = ['read' => AccessScope::All, 'write' => AccessScope::None];

    $this->actingAs(user_mr2(['meets' => $all, 'records' => ['read' => AccessScope::None, 'write' => AccessScope::None]]))
        ->get(route('meets.records.pdf', $meet))
        ->assertForbidden();
    $this->actingAs(user_mr2(['meets' => $all, 'records' => $all]))
        ->get(route('meets.records.pdf', $meet))
        ->assertOk();
});
