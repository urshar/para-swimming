<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\ForeignStart;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\User;
use App\Services\MeetResultListService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('foreign-starts-p3');

// Gäste (foreign_starts) in den Ergebnislisten: eigener Block je Bewerb, ohne Platz und Wertung.

function nation_fs3(string $code): Nation
{
    return Nation::firstOrCreate(['code' => $code], ['name_de' => $code, 'name_en' => $code, 'is_active' => true]);
}

function admin_fs3(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

/** Veröffentlichte Veranstaltung mit einem 50-m-Freistil-Bewerb. */
function meetWithEvent_fs3(): array
{
    $meet = Meet::create(['name' => 'Staatsmeisterschaften', 'city' => 'Dornbirn', 'nation_id' => nation_fs3('AUT')->id,
        'course' => 'SCM', 'start_date' => '2026-05-10', 'is_published' => true]);
    $stroke = StrokeType::firstOrCreate(['lenex_code' => 'FREE'], ['code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle']);
    $event = SwimEvent::create(['meet_id' => $meet->id, 'stroke_type_id' => $stroke->id, 'distance' => 50, 'gender' => 'F',
        'session_number' => 1, 'event_number' => 1, 'relay_count' => 1]);

    return [$meet, $event];
}

function austrian_fs3(Meet $meet, SwimEvent $event): Result
{
    $club = Club::create(['name' => 'Heimverein', 'nation_id' => nation_fs3('AUT')->id]);
    $athlete = Athlete::create(['first_name' => 'Anna', 'last_name' => 'Hummel', 'gender' => 'F', 'birth_date' => '2000-01-01',
        'nation_id' => nation_fs3('AUT')->id, 'club_id' => $club->id]);

    return Result::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
        'club_id' => $club->id, 'sport_class' => 'S9', 'swim_time' => 3300]);
}

function guest_fs3(Meet $meet, SwimEvent $event, array $attributes): ForeignStart
{
    return ForeignStart::create(array_merge([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'is_relay' => false,
        'club_name' => 'Swiss Paralympic Club', 'nation_id' => nation_fs3('SUI')->id,
        'person_key' => 'muster|heidi|1999-03-04', 'last_name' => 'Muster', 'first_name' => 'Heidi',
        'birth_date' => '1999-03-04', 'gender' => 'F', 'sport_class' => 'S9', 'swim_time' => 3200, 'place' => 1,
    ], $attributes));
}

it('zeigt Gäste öffentlich in einem eigenen Block ohne Platz, die österreichische Platzierung bleibt', function () {
    [$meet, $event] = meetWithEvent_fs3();
    austrian_fs3($meet, $event);
    guest_fs3($meet, $event, []);
    guest_fs3($meet, $event, ['person_key' => 'b|b|', 'last_name' => 'Zweite', 'first_name' => 'Zora', 'swim_time' => null,
        'status' => 'DSQ', 'place' => null]);

    $html = $this->get(route('public.meets.results', ['locale' => 'de', 'meet' => $meet]))
        ->assertOk()
        ->assertSee('Gäste (ohne Wertung)')
        ->assertSee('Swiss Paralympic Club (SUI)')
        ->assertSeeInOrder(['Muster Heidi', 'Zweite Zora'])
        ->getContent();

    // Die Österreicherin ist trotz der schnelleren Schweizerin Erste; Gäste stehen erst danach, mit "Gast".
    expect($html)->toMatch('/<td[^>]*>\s*1\s*<\/td>\s*<td[^>]*>\s*Hummel Anna/')
        ->and(substr_count($html, '>Gast<'))->toBe(2);
});

it('zeigt eine Veranstaltung mit nur Gaststarts öffentlich mit Ergebnissen', function () {
    [$meet, $event] = meetWithEvent_fs3();
    guest_fs3($meet, $event, []);

    $this->get(route('public.meets.results', ['locale' => 'de', 'meet' => $meet]))
        ->assertOk()
        ->assertSee('Muster Heidi')
        ->assertDontSee('noch keine Ergebnisse');
});

it('zeigt Gäste in der Ergebnisübersicht; Vereinsfilter blendet sie aus, die Suche findet sie', function () {
    [$meet, $event] = meetWithEvent_fs3();
    $result = austrian_fs3($meet, $event);
    guest_fs3($meet, $event, []);
    $admin = admin_fs3();

    $this->actingAs($admin)->get(route('meets.results-overview', $meet))
        ->assertOk()
        ->assertSee('ohne Wertung')
        ->assertSee('Swiss Paralympic Club (SUI)')
        ->assertSee('2 Ergebnisse');

    $this->actingAs($admin)->get(route('meets.results-overview', ['meet' => $meet, 'club_id' => $result->club_id]))
        ->assertOk()
        ->assertDontSee('Swiss Paralympic Club (SUI)');

    $this->actingAs($admin)->get(route('meets.results-overview', ['meet' => $meet, 'search' => 'heidi']))
        ->assertOk()
        ->assertSee('Muster Heidi')
        ->assertDontSee('Hummel Anna');
});

it('liefert die Gäste für das PDF der Ergebnisliste mit', function () {
    [$meet, $event] = meetWithEvent_fs3();
    guest_fs3($meet, $event, []);

    $block = app(MeetResultListService::class)->byEvent($meet, null)->sole();

    expect($block['groups'])->toBe([])
        ->and($block['guests']->pluck('display_name')->all())->toBe(['Muster Heidi']);

    $this->actingAs(admin_fs3())->get(route('meets.results-overview.pdf', $meet))->assertOk();
});

it('löscht beim Löschen aller Ergebnisse einer Disziplin auch die Gaststarts', function () {
    [$meet, $event] = meetWithEvent_fs3();
    austrian_fs3($meet, $event);
    guest_fs3($meet, $event, []);

    $this->actingAs(admin_fs3())
        ->delete(route('meets.results-overview.destroy-event', ['meet' => $meet, 'swimEvent' => $event]))
        ->assertSessionHas('success', fn (string $message) => str_starts_with($message, '2 Ergebnisse'));

    expect(ForeignStart::count())->toBe(0)
        ->and(Result::count())->toBe(0);
});
