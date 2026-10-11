<?php

/** @noinspection PhpUnhandledExceptionInspection Pest-Test-Closures fangen Exceptions selbst ab. */

use App\Models\AgeGroup;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\ForeignStart;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Services\GroupResolverService;
use App\Services\ParticipationStatisticsService;
use App\Support\ReportConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('foreign-starts-p2');

// Gaststarts (foreign_starts) in der Statistik: in Summen, Veranstaltungen, Nationen, Status, Sportklassen,
// Geschlecht, Altersgruppen und Staffeln — nicht in den Vereins- und Sportler-Ranglisten.

function service_fs2(): ParticipationStatisticsService
{
    return new ParticipationStatisticsService(new GroupResolverService);
}

function nation_fs2(string $code): Nation
{
    return Nation::firstOrCreate(['code' => $code], ['name_de' => $code, 'name_en' => $code, 'is_active' => true]);
}

function event_fs2(Meet $meet, int $relayCount): SwimEvent
{
    $stroke = StrokeType::firstOrCreate(['code' => 'FREE'],
        ['lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle', 'category' => 'standard', 'is_active' => true]);

    return SwimEvent::create(['meet_id' => $meet->id, 'stroke_type_id' => $stroke->id, 'distance' => 50, 'gender' => 'A',
        'relay_count' => $relayCount]);
}

function guest_fs2(Meet $meet, array $attributes): ForeignStart
{
    return ForeignStart::create(array_merge([
        'meet_id' => $meet->id,
        'swim_event_id' => event_fs2($meet, 1)->id,
        'is_relay' => false,
        'club_name' => 'Swiss Paralympic Club',
        'nation_id' => nation_fs2('SUI')->id,
        'person_key' => 'muster|heidi|2010-03-04',
        'last_name' => 'Muster',
        'first_name' => 'Heidi',
        'birth_date' => '2010-03-04',
        'gender' => 'F',
        'sport_class' => 'S9',
        'swim_time' => 3200,
    ], $attributes));
}

/**
 * Eine Veranstaltung 2024: Österreicher (Jg. 1990, S9) mit 2 Starts; Schweizer Gast Heidi (Jg. 2010, S9) mit 2 Starts,
 * Gast Anna mit einem DNS (zählt nicht als Start) und eine Schweizer Mixed-Staffel (4 Schwimmer).
 */
beforeEach(function () {
    AgeGroup::create(['code' => 'JUGEND', 'name_de' => 'Jugend', 'min_age' => null, 'max_age' => 18, 'sort_order' => 1]);
    AgeGroup::create(['code' => 'OFFEN', 'name_de' => 'Offen', 'min_age' => 19, 'max_age' => null, 'sort_order' => 2]);

    $this->meet = Meet::create(['name' => 'Staatsmeisterschaften', 'nation_id' => nation_fs2('AUT')->id, 'course' => 'SCM',
        'start_date' => '2024-06-01']);
    $this->club = Club::create(['name' => 'Heimverein', 'nation_id' => nation_fs2('AUT')->id]);
    $this->athlete = Athlete::create(['first_name' => 'Max', 'last_name' => 'Muster', 'gender' => 'M',
        'birth_date' => '1990-01-01', 'nation_id' => nation_fs2('AUT')->id, 'is_active' => true]);

    foreach ([1, 2] as $ignored) {
        Result::create(['meet_id' => $this->meet->id, 'swim_event_id' => event_fs2($this->meet, 1)->id,
            'athlete_id' => $this->athlete->id, 'club_id' => $this->club->id, 'sport_class' => 'S9', 'swim_time' => 6000]);
    }

    guest_fs2($this->meet, []);
    guest_fs2($this->meet, ['swim_time' => 7000]);
    guest_fs2($this->meet, ['person_key' => 'gast|anna|2000-01-01', 'first_name' => 'Anna', 'status' => 'DNS',
        'swim_time' => null]);
    guest_fs2($this->meet, ['swim_event_id' => event_fs2($this->meet, 4)->id, 'is_relay' => true, 'person_key' => null,
        'gender' => 'X', 'sport_class' => 'S34', 'birth_date' => null]);

    $this->config = ReportConfiguration::fromArray(['year' => 2024]);
});

it('zählt Gäste in der Übersicht mit, als ausländische Vereine', function () {
    expect(service_fs2()->overview($this->config))->toBe([
        'meets' => 1,
        'participants' => 2,
        'clubs' => 1,
        'foreign_clubs' => 1,
        'starts' => 4,
        'participations' => 2,
    ])->and(service_fs2()->meetsWithStarts($this->config))->toBe(1);
});

it('zählt Gäste je Veranstaltung, Nation und Status mit', function () {
    $byNation = service_fs2()->byNation($this->config)->keyBy('nation');
    $status = service_fs2()->statusBreakdown($this->config);

    expect(service_fs2()->byMeet($this->config)->sole())->toMatchArray(['participants' => 2, 'starts' => 4])
        ->and($byNation['AUT'])->toMatchArray(['participants' => 1, 'starts' => 2])
        ->and($byNation['SUI'])->toMatchArray(['participants' => 1, 'starts' => 2])
        ->and($status['regular'])->toBe(4)
        ->and($status['DNS'])->toBe(1)
        ->and(service_fs2()->statusByMeet($this->config)->sole()['total'])->toBe(5)
        ->and(service_fs2()->statusForMeets([$this->meet->id])->sole()['statuses']['DNS'])->toBe(1);
});

it('zählt Gäste bei Sportklasse, Geschlecht und Altersgruppe mit', function () {
    $byGender = service_fs2()->byGender($this->config)->keyBy('gender');
    $byAge = service_fs2()->byAgeGroup($this->config)->keyBy('age_group_code');

    expect(service_fs2()->bySportClass($this->config)->sole())->toMatchArray(['sport_class' => 'S9', 'participants' => 2, 'starts' => 4])
        ->and($byGender['M'])->toMatchArray(['participants' => 1, 'starts' => 2])
        ->and($byGender['F'])->toMatchArray(['participants' => 1, 'starts' => 2])
        ->and($byAge['OFFEN'])->toMatchArray(['participants' => 1, 'starts' => 2])
        ->and($byAge['JUGEND'])->toMatchArray(['participants' => 1, 'starts' => 2]);
});

it('zählt Gaststaffeln mit der Staffelgröße des Bewerbs', function () {
    expect(service_fs2()->relayStartsByEventGender($this->config))->toBe(['M' => 0, 'F' => 0, 'X' => 4]);
});

it('lässt Gäste in den Vereins- und Sportler-Ranglisten weg', function () {
    expect(service_fs2()->byClub($this->config)->pluck('club')->all())->toBe(['Heimverein'])
        ->and(service_fs2()->byAthlete($this->config)->pluck('athlete_id')->all())->toBe([$this->athlete->id]);
});

it('berücksichtigt nur Gaststarts im Auswertungszeitraum', function () {
    expect(service_fs2()->overview(ReportConfiguration::fromArray(['year' => 2025]))['starts'])->toBe(0);
});
