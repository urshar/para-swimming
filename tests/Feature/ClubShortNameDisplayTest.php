<?php

use App\Models\Club;
use App\Models\Result;
use App\Models\SwimRecord;
use App\Models\User;
use App\Services\ParticipationStatisticsService;
use App\Support\ReportConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('club-short-names');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

function admin_csn(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function club_csn(string $name, ?string $shortName): Club
{
    $club = makeClub_p5();
    $club->update(['name' => $name, 'short_name' => $shortName]);

    return $club;
}

function record_csn(Club $club): SwimRecord
{
    return SwimRecord::create([
        'stroke_type_id' => makeStrokeType_p5()->id,
        'athlete_id' => makeAthlete_p5($club)->id,
        'club_id' => $club->id,
        'record_type' => 'AUT',
        'sport_class' => 'S9',
        'gender' => 'M',
        'course' => 'LCM',
        'distance' => 50,
        'relay_count' => 1,
        'swim_time' => 6000,
        'record_status' => 'APPROVED',
        'is_current' => true,
        'set_date' => '2025-01-01',
    ]);
}

// ── Model ─────────────────────────────────────────────────────────────────────

it('zeigt den Kurznamen und fällt ohne Kurznamen auf den vollen Namen zurück', function () {
    $short = club_csn('Schwimmverein Behindertensport Wien', 'SVB Wien');
    $empty = club_csn('Para Linz', '');
    $none = club_csn('Para Graz', null);

    expect($short->display_name)->toBe('SVB Wien')
        ->and($short->full_name_hint)->toBe('Schwimmverein Behindertensport Wien')
        ->and($empty->display_name)->toBe('Para Linz')
        ->and($empty->full_name_hint)->toBeNull()
        ->and($none->display_name)->toBe('Para Graz')
        ->and($none->full_name_hint)->toBeNull();
});

it('sortiert Vereine nach dem Anzeigenamen', function () {
    club_csn('Zeller Schwimmclub', 'Aqua Zell');
    club_csn('Behindertensport Bregenz', null);
    club_csn('Club Mödling', '');

    expect(Club::query()->orderByDisplayName()->get()->pluck('display_name')->all())
        ->toBe(['Aqua Zell', 'Behindertensport Bregenz', 'Club Mödling']);
});

// ── Views ─────────────────────────────────────────────────────────────────────

it('zeigt in der Rekordliste und im Rekord den Kurznamen mit vollem Namen als Tooltip', function () {
    $club = club_csn('Schwimmverein Behindertensport Wien', 'SVB Wien');
    $record = record_csn($club);
    $admin = admin_csn();

    $this->actingAs($admin)
        ->get(route('records.index', ['type' => 'AUT', 'sport_class' => 'S9,SB9,SM9', 'course' => 'LCM']))
        ->assertOk()
        ->assertSee('SVB Wien')
        ->assertSee('title="Schwimmverein Behindertensport Wien"', false);

    $this->actingAs($admin)
        ->get(route('records.show', $record))
        ->assertOk()
        ->assertSee('SVB Wien')
        ->assertSee('title="Schwimmverein Behindertensport Wien"', false);
});

it('zeigt in der Vereinsauswahl den Kurznamen mit vollem Namen als Beschreibung', function () {
    $club = club_csn('Schwimmverein Behindertensport Wien', 'SVB Wien');
    makeAthlete_p5($club);
    $meet = makeMeet_p5();

    $this->actingAs(admin_csn())
        ->get(route('meets.result-clubs', $meet))
        ->assertOk()
        ->assertSeeInOrder(['SVB Wien', 'Schwimmverein Behindertensport Wien']);
});

it('behält auf der Vereins-Detailseite den vollen Namen in der Überschrift', function () {
    $club = club_csn('Schwimmverein Behindertensport Wien', 'SVB Wien');

    $this->actingAs(admin_csn())
        ->get(route('clubs.show', $club))
        ->assertOk()
        ->assertSee('Schwimmverein Behindertensport Wien</h1>', false);
});

it('liefert in der Statistik je Verein den Kurznamen', function () {
    $club = club_csn('Schwimmverein Behindertensport Wien', 'SVB Wien');
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet, ['event_number' => 1]);
    Result::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => makeAthlete_p5($club)->id,
        'club_id' => $club->id, 'swim_time' => 6000, 'sport_class' => 'S9',
    ]);
    $config = new ReportConfiguration(
        2025,
        CarbonImmutable::parse('2025-01-01'),
        CarbonImmutable::parse('2025-12-31'),
        [$meet->id],
        ReportConfiguration::SECTION_KEYS,
    );

    $row = app(ParticipationStatisticsService::class)->byClub($config)->sole();

    expect($row['club'])->toBe('SVB Wien')
        ->and($row['club_full_name'])->toBe('Schwimmverein Behindertensport Wien');
});
