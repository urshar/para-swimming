<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\RelayResult;
use App\Models\Result;
use App\Models\ScoringGroup;
use App\Models\SwimEvent;
use App\Models\User;
use App\Services\LenexExportService;
use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use App\Services\ScoringGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('scoring-groups');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php). makeMeet_p5(): LCM, 15.06.2025.

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function admin_sg(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function group_sg(
    SwimEvent $event,
    string $name,
    string $gender,
    ?string $classes,
    int $order,
    ?int $ageMax
): ScoringGroup {
    return ScoringGroup::create([
        'swim_event_id' => $event->id,
        'name' => $name,
        'gender' => $gender,
        'sport_classes' => $classes,
        'age_max' => $ageMax,
        'sort_order' => $order,
    ]);
}

function athlete_sg(Club $club, string $gender, string $class, string $birthDate, string $lastName): Athlete
{
    $athlete = makeAthlete_p5($club, $gender, [$class]);
    $athlete->update(['birth_date' => $birthDate, 'last_name' => $lastName]);

    return $athlete;
}

function result_sg(Meet $meet, SwimEvent $event, Athlete $athlete, int $time, ?int $points): Result
{
    return Result::create([
        'meet_id' => $meet->id,
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $athlete->club_id,
        'swim_time' => $time,
        'points' => $points,
        'sport_class' => $athlete->sportClasses()->first()->sport_class,
    ]);
}

/** @return list<array{group: ?ScoringGroup, gender: ?string, name: string, label: string, rows: list<array{place: ?int, result: Result|RelayResult}>}> */
function ranked_sg(SwimEvent $event, int $meetYear): array
{
    $results = $event->relay_count > 1
        ? RelayResult::where('swim_event_id', $event->id)->with('members')->get()
        : Result::where('swim_event_id', $event->id)->with('athlete')->get();

    return app(ScoringGroupService::class)->rankedGroups($event->fresh(), $results, $meetYear);
}

/** LENEX-Import einer Datei, optional in eine bestehende Veranstaltung (Exception → RuntimeException). */
function importLenex_sg(string $xml, ?int $meetId): void
{
    $path = tempnam(sys_get_temp_dir(), 'lenex_sg').'.lef';
    file_put_contents($path, $xml);

    try {
        (new LenexParserService)->import($path, new LenexResolverService, $meetId);
    } catch (Exception $e) {
        throw new RuntimeException('LENEX-Import fehlgeschlagen: '.$e->getMessage(), previous: $e);
    } finally {
        unlink($path);
    }
}

/** LENEX-Export (DOMException → RuntimeException). */
function lenex_sg(Meet $meet, string $type): SimpleXMLElement
{
    try {
        return simplexml_load_string((new LenexExportService)->build($meet, $type));
    } catch (DOMException $e) {
        throw new RuntimeException('LENEX-Export fehlgeschlagen: '.$e->getMessage(), previous: $e);
    }
}

/** Strukturdatei nach dem Muster der ÖSTM 2025: 50 m Freistil, gemeinsam ausgeschrieben, getrennte Wertung. */
function ostmStructure_sg(): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="Gruppentest" city="Kapfenberg" nation="AUT" course="SCM">
<SESSIONS><SESSION number="1" date="2025-05-10"><EVENTS>
  <EVENT eventid="1059" number="1" round="TIM"><SWIMSTYLE distance="50" relaycount="1" stroke="FREE" />
    <AGEGROUPS>
      <AGEGROUP agegroupid="1060" agemax="-1" agemin="-1" gender="F" name="ÖSTM: S01 - S08" handicap="1,2,3,4,5,6,7,8" />
      <AGEGROUP agegroupid="1093" agemax="-1" agemin="-1" gender="F" name="ÖM: S09 - S10" handicap="9,10" />
      <AGEGROUP agegroupid="1097" agemax="-1" agemin="-1" gender="M" name="ÖSTM: S01 - S10" handicap="1,2,3,4,5,6,7,8,9,10" />
      <AGEGROUP agegroupid="1199" agemax="18" agemin="-1" gender="M" name="Jugend" />
    </AGEGROUPS>
  </EVENT>
</EVENTS></SESSION></SESSIONS></MEET></MEETS></LENEX>';
}

// ── Zuordnung und Wertung ─────────────────────────────────────────────────────

it('prüft Geschlecht, Klassen und Alter einer Wertungsgruppe', function () {
    $group = new ScoringGroup(['gender' => 'F', 'sport_classes' => '1,2,3,4,5,6,7,8', 'age_max' => 18]);
    $open = new ScoringGroup(['gender' => 'A', 'sport_classes' => null]);

    expect($group->matches('F', 4, 17))->toBeTrue()
        ->and($group->matches('M', 4, 17))->toBeFalse()
        ->and($group->matches('F', 9, 17))->toBeFalse()
        ->and($group->matches('F', 4, 19))->toBeFalse()
        ->and($group->matches('F', 4, null))->toBeFalse()
        ->and($open->matches('M', null, null))->toBeTrue()
        ->and(ScoringGroup::parseClassNumbers('S1 S2, SB4;10'))->toBe([1, 2, 4, 10]);
});

it('wertet gemeinsam geschwommene Bewerbe getrennt nach Wertungsgruppen und Punkten, auch mehrfach, und zeigt nicht gruppierte',
    function () {
        $meet = makeMeet_p5();
        $club = makeClub_p5();
        $event = makeEvent_p5($meet, ['distance' => 50, 'gender' => 'A']);
        group_sg($event, 'ÖSTM: S01 - S08', 'F', '1,2,3,4,5,6,7,8', 1, null);
        group_sg($event, 'ÖSTM: S01 - S10', 'M', '1,2,3,4,5,6,7,8,9,10', 2, null);
        group_sg($event, 'Jugend', 'M', null, 3, 18);

        $s4 = result_sg($meet, $event, athlete_sg($club, 'F', 'S4', '1990-01-01', 'DameS4'), 5000, 800);
        $s7 = result_sg($meet, $event, athlete_sg($club, 'F', 'S7', '1990-01-01', 'DameS7'), 4000, 600);
        $herr = result_sg($meet, $event, athlete_sg($club, 'M', 'S9', '2010-01-01', 'Jugendlicher'), 3500, 500);
        $s14 = result_sg($meet, $event, athlete_sg($club, 'F', 'S14', '1990-01-01', 'DameS14'), 3200, 700);

        $groups = ranked_sg($event, 2025);
        $byLabel = collect($groups)->keyBy('label');

        expect(array_column($groups, 'label'))->toBe([
            'Damen – ÖSTM: S01 - S08', 'Herren – ÖSTM: S01 - S10', 'Herren – Jugend',
            ScoringGroupService::UNASSIGNED_LABEL,
        ])
            ->and(array_map(fn ($r) => [$r['result']->id, $r['place']], $byLabel['Damen – ÖSTM: S01 - S08']['rows']))
            ->toBe([[$s4->id, 1], [$s7->id, 2]]) // nach Punkten, nicht nach Zeit
            ->and($byLabel['Herren – Jugend']['rows'][0]['result']->id)->toBe($herr->id)
            ->and($byLabel['Herren – ÖSTM: S01 - S10']['rows'][0]['result']->id)->toBe($herr->id)
            ->and($byLabel[ScoringGroupService::UNASSIGNED_LABEL]['rows'][0]['result']->id)->toBe($s14->id);
    });

it('wertet Staffeln nach Wertung und Staffelklasse der Gruppe', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $event = makeEvent_p5($meet, ['distance' => 50, 'relay_count' => 4, 'gender' => 'A']);
    group_sg($event, 'ÖM: MI', 'F', '14', 1, null);
    group_sg($event, 'ÖM: MI', 'M', '14', 2, null);
    $herren = RelayResult::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'club_id' => $club->id, 'gender' => 'M',
        'relay_class' => 'S14', 'swim_time' => 15000, 'points' => 400,
    ]);
    $damen = RelayResult::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'club_id' => $club->id, 'gender' => 'F',
        'relay_class' => 'S14', 'swim_time' => 17000, 'points' => 300,
    ]);

    $groups = ranked_sg($event, 2025);

    expect(array_column($groups, 'label'))->toBe(['Damen – ÖM: MI', 'Herren – ÖM: MI'])
        ->and($groups[0]['rows'][0]['result']->id)->toBe($damen->id)
        ->and($groups[0]['rows'][0]['place'])->toBe(1)
        ->and($groups[1]['rows'][0]['result']->id)->toBe($herren->id);
});

it('vergibt mit Wertungsgruppen ohne Punkte keinen Platz und zählt diese Ergebnisse', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $event = makeEvent_p5($meet, ['distance' => 50, 'gender' => 'A']);
    group_sg($event, 'ÖSTM: S01 - S10', 'M', '1,2,3,4,5,6,7,8,9,10', 1, null);
    $withPoints = result_sg($meet, $event, athlete_sg($club, 'M', 'S5', '1990-01-01', 'MitPunkten'), 3300, 859);
    $tie = result_sg($meet, $event, athlete_sg($club, 'M', 'S9', '1990-01-01', 'Gleichstand'), 3000, 859);
    $without = result_sg($meet, $event, athlete_sg($club, 'M', 'S10', '1990-01-01', 'OhnePunkte'), 2895, null);

    $group = ranked_sg($event, 2025)[0];

    expect($group['missingPoints'])->toBe(1)
        ->and(array_column($group['rows'], 'place'))->toBe([1, 1, null])
        ->and($group['rows'][2]['result']->id)->toBe($without->id)
        ->and(collect($group['rows'])->pluck('result.id')->take(2)->sort()->values()->all())
        ->toBe(collect([$withPoints->id, $tie->id])->sort()->values()->all());
});

it('wertet ohne Wertungsgruppen weiterhin nach der Zeit', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $event = makeEvent_p5($meet, ['distance' => 50, 'gender' => 'A']);
    $slow = result_sg($meet, $event, athlete_sg($club, 'M', 'S9', '1990-01-01', 'Langsam'), 4000, 900);
    $fast = result_sg($meet, $event, athlete_sg($club, 'M', 'S9', '1990-01-01', 'Schnell'), 3000, null);

    $group = ranked_sg($event, 2025)[0];

    expect($group['label'])->toBe('Herren – S9')
        ->and($group['missingPoints'])->toBe(0)
        ->and(array_map(fn ($r) => [$r['result']->id, $r['place']], $group['rows']))->toBe([[$fast->id, 1], [$slow->id, 2]]);
});

// ── LENEX ─────────────────────────────────────────────────────────────────────

it('legt Wertungsgruppen aus den AGEGROUPs an und verdoppelt sie beim erneuten Import nicht', function () {
    makeNation_p5();
    makeStrokeType_p5();

    importLenex_sg(ostmStructure_sg(), null);
    importLenex_sg(ostmStructure_sg(), Meet::value('id'));

    $event = SwimEvent::with('scoringGroups')->first();
    $first = $event->scoringGroups->first();

    expect($event->scoringGroups)->toHaveCount(4)
        ->and($first->name)->toBe('ÖSTM: S01 - S08')
        ->and($first->gender)->toBe('F')
        ->and($first->sport_classes)->toBe('1,2,3,4,5,6,7,8')
        ->and($first->title)->toBe(ScoringGroup::TITLE_STATE)
        ->and($first->age_min)->toBeNull()
        ->and($event->scoringGroups[1]->title)->toBe(ScoringGroup::TITLE_NATIONAL)
        ->and($event->scoringGroups[3]->age_max)->toBe(18)
        ->and($event->scoringGroups[3]->sport_classes)->toBeNull();
});

it('exportiert Wertungsgruppen als AGEGROUPs mit Ranglisten', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $event = makeEvent_p5($meet, ['distance' => 50, 'gender' => 'A']);
    group_sg($event, 'ÖSTM: S01 - S08', 'F', '1,2,3,4,5,6,7,8', 1, null);
    $result = result_sg($meet, $event, athlete_sg($club, 'F', 'S4', '1990-01-01', 'Dame'), 5000, 650);

    $ageGroup = lenex_sg($meet, 'results')->MEETS->MEET->SESSIONS->SESSION->EVENTS->EVENT->AGEGROUPS->AGEGROUP;

    expect((string) $ageGroup['name'])->toBe('ÖSTM: S01 - S08')
        ->and((string) $ageGroup['gender'])->toBe('F')
        ->and((string) $ageGroup['handicap'])->toBe('1,2,3,4,5,6,7,8')
        ->and((string) $ageGroup->RANKINGS->RANKING['resultid'])->toBe((string) $result->id)
        ->and((string) $ageGroup->RANKINGS->RANKING['place'])->toBe('1');
});

// ── Pflege ────────────────────────────────────────────────────────────────────

it('speichert Wertungsgruppen im Bewerbsformular und setzt die Sportklassen des Bewerbs daraus', function () {
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet, ['distance' => 50, 'gender' => 'A']);

    $this->actingAs(admin_sg())
        ->put(route('events.update', $event), [
            'stroke_type_id' => $event->stroke_type_id,
            'session_number' => 1,
            'gender' => 'A',
            'round' => 'TIM',
            'distance' => 50,
            'relay_count' => 1,
            'scoring_groups' => [
                ['name' => 'ÖSTM: S01 - S08', 'gender' => 'F', 'sport_classes' => 'S1 S2 S3 S8', 'title' => 'OSTM'],
                ['name' => 'Herren', 'gender' => 'M', 'sport_classes' => '9,10'],
            ],
        ])
        ->assertRedirect();

    $event->refresh();
    expect($event->scoringGroups)->toHaveCount(2)
        ->and($event->scoringGroups[0]->sport_classes)->toBe('1,2,3,8')
        ->and($event->scoringGroups[0]->title)->toBe('OSTM')
        ->and($event->sport_classes)->toBe('1 2 3 8 9 10');
});

it('speichert den Bewerb nicht, wenn eine Wertungsgruppe ungültig ist', function () {
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet, ['distance' => 50]);

    $this->actingAs(admin_sg())
        ->put(route('events.update', $event), [
            'stroke_type_id' => $event->stroke_type_id, 'session_number' => 1, 'gender' => 'A', 'round' => 'TIM',
            'distance' => 100, 'relay_count' => 1,
            'scoring_groups' => [['name' => '', 'gender' => 'Q']],
        ])
        ->assertSessionHasErrors(['scoring_groups.0.name', 'scoring_groups.0.gender']);

    expect($event->fresh()->distance)->toBe(50);
});

it('übernimmt Wertungsgruppen auf Bewerbe derselben Klassenkategorie', function () {
    $meet = makeMeet_p5();
    $source = makeEvent_p5($meet, ['event_number' => 1, 'distance' => 50]);
    $back = makeEvent_p5($meet,
        ['event_number' => 2, 'distance' => 100, 'stroke_type_id' => makeStrokeType_p5('BACK')->id]);
    $breast = makeEvent_p5($meet,
        ['event_number' => 3, 'distance' => 100, 'stroke_type_id' => makeStrokeType_p5('BREAST')->id]);
    $relay = makeEvent_p5($meet, ['event_number' => 4, 'relay_count' => 4]);
    group_sg($source, 'ÖSTM: S14', 'M', '14', 1, null);
    group_sg($back, 'Alt', 'A', null, 1, null);

    $this->actingAs(admin_sg())
        ->post(route('events.scoring-groups.copy', $source))
        ->assertRedirect(route('events.edit', $source));

    expect($back->scoringGroups()->pluck('name')->all())->toBe(['ÖSTM: S14'])
        ->and($back->fresh()->sport_classes)->toBe('14')
        ->and($breast->scoringGroups()->count())->toBe(0)
        ->and($relay->scoringGroups()->count())->toBe(0);
});

// ── Anzeige ───────────────────────────────────────────────────────────────────

it('gliedert Sammelansicht und öffentliche Ergebnisseite nach Wertungsgruppen', function () {
    $meet = makeMeet_p5();
    $meet->update(['is_published' => true]);
    $club = makeClub_p5();
    $event = makeEvent_p5($meet, ['distance' => 50, 'gender' => 'A']);
    group_sg($event, 'ÖSTM: S01 - S08', 'F', '1,2,3,4,5,6,7,8', 1, null);
    group_sg($event, 'ÖSTM: S01 - S10', 'M', '1,2,3,4,5,6,7,8,9,10', 2, null);
    result_sg($meet, $event, athlete_sg($club, 'F', 'S4', '1990-01-01', 'Dame'), 5000, 650);
    result_sg($meet, $event, athlete_sg($club, 'M', 'S9', '1990-01-01', 'Herr'), 4000, 550);

    $this->actingAs(admin_sg())
        ->get(route('meets.results-overview', $meet))
        ->assertOk()
        ->assertSeeInOrder(['Damen – ÖSTM: S01 - S08', 'Dame', 'Herren – ÖSTM: S01 - S10', 'Herr']);

    $this->get(route('public.meets.results', ['locale' => 'en', 'meet' => $meet]))
        ->assertOk()
        ->assertSeeInOrder(['Women – ÖSTM: S01 - S08', 'Men – ÖSTM: S01 - S10']);
});

// ── Gespeicherter Platz ───────────────────────────────────────────────────────

it('speichert den berechneten Platz beim Erfassen und zeigt ihn im Bearbeiten-Formular', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $event = makeEvent_p5($meet, ['distance' => 50, 'gender' => 'A']);
    group_sg($event, 'ÖSTM: S01 - S10', 'M', '1,2,3,4,5,6,7,8,9,10', 1, null);
    $fast = result_sg($meet, $event, athlete_sg($club, 'M', 'S10', '1990-01-01', 'Schnell'), 2900, 466);
    $admin = admin_sg();

    // Manuell erfasst, mehr Punkte als der Schnellere → Platz 1, der Schnellere rutscht auf 2.
    $this->actingAs($admin)->post(route('meets.results.store', $meet), [
        'swim_event_id' => $event->id,
        'athlete_id' => athlete_sg($club, 'M', 'S5', '1990-01-01', 'Manuell')->id,
        'club_id' => $club->id,
        'swim_time' => '00:33.15',
        'points' => '859',
    ])->assertRedirect();

    $manual = Result::where('swim_event_id', $event->id)->whereKeyNot($fast->id)->first();
    expect($manual->place)->toBe(1)
        ->and($fast->fresh()->place)->toBe(2);

    $this->get(route('results.edit', $manual))
        ->assertOk()
        ->assertSeeInOrder(['Platz', '1.', 'Herren – ÖSTM: S01 - S10']);
});

it('setzt die Plätze nach der Punkte-Neuberechnung', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $event = makeEvent_p5($meet, ['distance' => 50, 'gender' => 'A']);
    group_sg($event, 'ÖSTM: S01 - S10', 'M', '1,2,3,4,5,6,7,8,9,10', 1, null);
    $result = result_sg($meet, $event, athlete_sg($club, 'M', 'S9', '1990-01-01', 'Ohne'), 3000, null);
    $result->update(['points' => 500]); // z. B. nachträglich gerechnet, Platz noch leer

    $this->actingAs(admin_sg())
        ->post(route('meets.recalculate-points', $meet))
        ->assertRedirect();

    // Ohne Basiswerte bleiben die Punkte; der Platz wird aus ihnen gesetzt.
    expect($result->fresh()->place)->toBe(1);
});
