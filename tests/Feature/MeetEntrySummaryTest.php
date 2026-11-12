<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Entry;
use App\Models\Meet;
use App\Models\RelayEntry;
use App\Models\RelayEntryMember;
use App\Models\ScoringGroup;
use App\Models\SwimEvent;
use App\Models\User;
use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use App\Services\MeetEntrySummaryService;
use App\Support\MedalCount;
use App\Support\MedalRule;
use App\Support\MeetEntrySummary;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('meet-entry-summary-p1');

// Melde-Kennzahlen und Medaillenplanung (docs/specs/meet-entry-summary.md), Phase 1: Datenmodell und Berechnung.
// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php). makeMeet_p5(): LCM, 15.06.2025.

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function admin_mes(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function group_mes(SwimEvent $event, string $name, array $attrs = []): ScoringGroup
{
    return ScoringGroup::create([
        'swim_event_id' => $event->id, 'name' => $name, 'gender' => 'A',
        'title' => ScoringGroup::titleFromName($name),
        'sort_order' => $event->scoringGroups()->count() + 1,
        ...$attrs,
    ]);
}

/** Athlet mit Klassen und Geburtsdatum (null = unbekannt). */
function athlete_mes(Club $club, array $classes = ['S9'], ?string $birthDate = '2000-01-01', string $gender = 'M'): Athlete
{
    $athlete = makeAthlete_p5($club, $gender, $classes);
    $athlete->update(['birth_date' => $birthDate]);

    return $athlete;
}

function entry_mes(SwimEvent $event, Athlete $athlete, ?string $status = null, ?string $sportClass = null): Entry
{
    return Entry::create([
        'meet_id' => $event->meet_id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
        'club_id' => $athlete->club_id, 'status' => $status, 'sport_class' => $sportClass,
    ]);
}

/** Staffelmeldung mit den angegebenen Schwimmern (leer = keine eingetragen). */
function relay_mes(SwimEvent $event, Club $club, array $athletes, array $attrs = []): RelayEntry
{
    $relay = RelayEntry::create([
        'meet_id' => $event->meet_id, 'swim_event_id' => $event->id, 'club_id' => $club->id, 'relay_class' => 'S20',
        ...$attrs,
    ]);
    foreach (array_values($athletes) as $position => $athlete) {
        RelayEntryMember::create(['relay_entry_id' => $relay->id, 'athlete_id' => $athlete->id, 'position' => $position + 1]);
    }

    return $relay;
}

/** Bewerb mit n gemeldeten S9-Athleten und einer Gruppe. */
function eventWithEntries_mes(Meet $meet, string $groupName, int $entries, array $groupAttrs = []): SwimEvent
{
    $event = makeEvent_p5($meet);
    group_mes($event, $groupName, $groupAttrs);
    $club = makeClub_p5();
    for ($i = 0; $i < $entries; $i++) {
        entry_mes($event, athlete_mes($club));
    }

    return $event;
}

function summary_mes(Meet $meet): MeetEntrySummary
{
    return app(MeetEntrySummaryService::class)->summarize($meet->fresh());
}

/** @return array{0: int, 1: int, 2: int, 3: int} */
function counts_mes(MedalCount $medals): array
{
    return [$medals->gold, $medals->silver, $medals->bronze, $medals->recognition];
}

/** LENEX-Import einer Datei, optional in eine bestehende Veranstaltung (Exception → RuntimeException). */
function importLenex_mes(string $xml, ?int $meetId): void
{
    $path = tempnam(sys_get_temp_dir(), 'lenex_mes').'.lef';
    file_put_contents($path, $xml);

    try {
        (new LenexParserService)->import($path, new LenexResolverService, $meetId);
    } catch (Exception $e) {
        throw new RuntimeException('LENEX-Import fehlgeschlagen: '.$e->getMessage(), previous: $e);
    } finally {
        unlink($path);
    }
}

function championshipStructure_mes(): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="Jugend und B" city="Graz" nation="AUT" course="SCM">
<SESSIONS><SESSION number="1" date="2026-11-14"><EVENTS>
  <EVENT eventid="1" number="1" round="TIM"><SWIMSTYLE distance="50" relaycount="1" stroke="FREE" />
    <AGEGROUPS>
      <AGEGROUP agegroupid="11" agemax="18" agemin="-1" gender="M" name="ÖJM PI" />
      <AGEGROUP agegroupid="12" agemax="-1" agemin="-1" gender="M" name="ÖBM S01 - S10" handicap="1,2,3,4,5,6,7,8,9,10" />
      <AGEGROUP agegroupid="13" agemax="-1" agemin="-1" gender="M" name="ÖBSV Cup" />
    </AGEGROUPS>
  </EVENT>
</EVENTS></SESSION></SESSIONS></MEET></MEETS></LENEX>';
}

// ── Medaillenregel ────────────────────────────────────────────────────────────

it('vergibt die Medaillen nach Teilnehmerzahl mit und ohne Medaillenregel', function () {
    $on = fn (int $n): array => counts_mes(MedalRule::forParticipants($n, true));
    $off = fn (int $n): array => counts_mes(MedalRule::forParticipants($n, false));

    expect($on(0))->toBe([0, 0, 0, 0])
        ->and($on(1))->toBe([0, 0, 0, 1])
        ->and($on(2))->toBe([1, 0, 0, 1])
        ->and($on(3))->toBe([1, 1, 0, 1])
        ->and($on(4))->toBe([1, 1, 1, 0])
        ->and($on(9))->toBe([1, 1, 1, 0])
        ->and($off(0))->toBe([0, 0, 0, 0])
        ->and($off(1))->toBe([1, 0, 0, 0])
        ->and($off(2))->toBe([1, 1, 0, 0])
        ->and($off(3))->toBe([1, 1, 1, 0])
        ->and($off(4))->toBe([1, 1, 1, 0]);
});

it('rechnet und beschriftet Medaillenzahlen', function () {
    $sum = (new MedalCount(1, 1, 0, 1))->plus(new MedalCount(1, 0, 0, 1))->times(4);

    expect(counts_mes($sum))->toBe([8, 4, 0, 8])
        ->and($sum->total())->toBe(20)
        ->and($sum->label())->toBe('8 G, 4 S, 8 A')
        ->and(MedalCount::none()->label())->toBe('–');
});

// ── Titel und Medaillenwertung ────────────────────────────────────────────────

it('erkennt den Meisterschaftstitel aus dem Gruppennamen', function () {
    expect(ScoringGroup::titleFromName('ÖSTM: S01 - S08'))->toBe(ScoringGroup::TITLE_STATE)
        ->and(ScoringGroup::titleFromName('ÖM S09 - S10'))->toBe(ScoringGroup::TITLE_NATIONAL)
        ->and(ScoringGroup::titleFromName('ÖJM PI'))->toBe(ScoringGroup::TITLE_JUNIOR)
        ->and(ScoringGroup::titleFromName('Ö BM S01 - S10'))->toBe(ScoringGroup::TITLE_B)
        ->and(ScoringGroup::titleFromName('öbm s11'))->toBe(ScoringGroup::TITLE_B)
        ->and(ScoringGroup::titleFromName('ÖBSV Cup'))->toBeNull()
        ->and(ScoringGroup::titleFromName('Jugend'))->toBeNull()
        ->and(ScoringGroup::TITLES)->toHaveKeys(['OSTM', 'OM', 'OJM', 'OBM']);
});

it('belegt Titel und Medaillenwertung beim LENEX-Import vor und lässt einen gesetzten Wert beim erneuten Import stehen', function () {
    makeNation_p5();
    makeStrokeType_p5();

    importLenex_mes(championshipStructure_mes(), null);
    $groups = ScoringGroup::orderBy('sort_order')->get();
    $groups[0]->update(['awards_medals' => false]);
    $groups[2]->update(['awards_medals' => true]);

    importLenex_mes(championshipStructure_mes(), Meet::value('id'));
    $after = ScoringGroup::orderBy('sort_order')->get();

    expect($groups->pluck('title')->all())->toBe([ScoringGroup::TITLE_JUNIOR, ScoringGroup::TITLE_B, null])
        ->and($after)->toHaveCount(3)
        ->and($after->pluck('awards_medals')->all())->toBe([false, true, true]);

    // Frisch importiert: mit Titel ja, ohne nein.
    ScoringGroup::query()->delete();
    importLenex_mes(championshipStructure_mes(), Meet::value('id'));
    expect(ScoringGroup::orderBy('sort_order')->pluck('awards_medals')->all())->toBe([true, true, false]);
});

it('behält die Medaillenwertung beim Speichern des Bewerbsformulars und beim Kopieren', function () {
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet);
    $target = makeEvent_p5($meet, ['event_number' => 2]);
    group_mes($event, 'ÖBSV Cup', ['awards_medals' => false]);

    $this->actingAs(admin_mes())
        ->put(route('events.update', $event), [
            'stroke_type_id' => $event->stroke_type_id, 'session_number' => 1, 'gender' => 'M', 'round' => 'TIM',
            'distance' => 100, 'relay_count' => 1,
            'scoring_groups' => [
                ['name' => 'ÖBSV Cup', 'gender' => 'M', 'awards_medals' => '0'],
                ['name' => 'ÖJM PI', 'gender' => 'M'],
            ],
        ])
        ->assertRedirect();
    $saved = $event->scoringGroups()->get();

    $this->post(route('scoring-groups.copy', $saved[0]), ['target_event_ids' => [$target->id]])->assertRedirect();

    expect($saved->pluck('awards_medals')->all())->toBe([false, true])
        ->and($target->scoringGroups()->first()->awards_medals)->toBeFalse();
});

// ── Auswertung ────────────────────────────────────────────────────────────────

it('rechnet die Medaillen je Gruppe und Bewerb mit und ohne Medaillenregel', function () {
    $meet = makeMeet_p5();
    eventWithEntries_mes($meet, 'ÖJM PI', 3);
    eventWithEntries_mes($meet, 'ÖJM PI', 1);
    eventWithEntries_mes($meet, 'ÖJM PI', 5);

    $on = summary_mes($meet);
    $meet->update(['medal_reduction_rule' => false]);
    $off = summary_mes($meet);

    expect($on->reductionRule)->toBeTrue()
        ->and($on->medalRows)->toHaveCount(1)
        ->and($on->medalRows[0]->label)->toBe('ÖJM')
        ->and(counts_mes($on->medalRows[0]->medals))->toBe([2, 2, 1, 2])
        ->and(collect($on->details)->pluck('participants')->all())->toBe([3, 1, 5])
        ->and($off->reductionRule)->toBeFalse()
        ->and(counts_mes($off->medalRows[0]->medals))->toBe([3, 2, 2, 0])
        ->and(counts_mes($off->totalMedals()))->toBe([3, 2, 2, 0]);
});

it('zählt Meldungen mit EXH, WDR, SICK und RJC nicht als Teilnehmer', function () {
    $meet = makeMeet_p5();
    $event = eventWithEntries_mes($meet, 'ÖJM PI', 2);
    $club = makeClub_p5();
    foreach (['EXH', 'WDR', 'SICK', 'RJC'] as $status) {
        entry_mes($event, athlete_mes($club), $status);
    }
    relay_mes(makeEvent_p5($meet, ['event_number' => 2, 'relay_count' => 4, 'gender' => 'X']), $club, [], ['status' => 'WDR']);

    $summary = summary_mes($meet);

    expect($summary->details[0]->participants)->toBe(2)
        ->and($summary->athletes)->toBe(2)
        ->and($summary->unevaluableRelays)->toBe(0)
        ->and(counts_mes($summary->medalRows[0]->medals))->toBe([1, 0, 0, 1]);
});

it('lässt Gruppen ohne Medaillenwertung aus der Aufstellung und weist sie aus', function () {
    $meet = makeMeet_p5();
    $event = eventWithEntries_mes($meet, 'ÖBSV Cup', 4, ['awards_medals' => false]);
    group_mes($event, 'Österreichische Jugend', ['awards_medals' => true]);

    $summary = summary_mes($meet);

    expect($summary->groupsWithoutMedals)->toBe(['ÖBSV Cup'])
        ->and($summary->medalRows)->toHaveCount(1)
        ->and($summary->medalRows[0]->label)->toBe('Österreichische Jugend')
        ->and(counts_mes($summary->medalRows[0]->medals))->toBe([1, 1, 1, 0])
        ->and($summary->unassignedEntries)->toBe(0);
});

it('ordnet über die Klasse des Athleten passend zur Lage zu, wenn die Meldung keine Klasse hat', function () {
    $meet = makeMeet_p5();
    $breast = makeEvent_p5($meet, ['stroke_type_id' => makeStrokeType_p5('BREAST')->id]);
    group_mes($breast, 'ÖJM SB8', ['sport_classes' => '8']);
    $club = makeClub_p5();
    entry_mes($breast, athlete_mes($club, ['S9', 'SB8']));
    entry_mes($breast, athlete_mes($club, ['S9', 'SB9']));
    entry_mes($breast, athlete_mes($club, ['S9', 'SB9']), null, 'SB8');

    $summary = summary_mes($meet);

    expect($summary->details[0]->participants)->toBe(2)
        ->and($summary->unassignedEntries)->toBe(1);
});

it('zählt Jugendliche nach der Stichtagsregel je Person einmal', function () {
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet);
    $other = makeEvent_p5($meet, ['event_number' => 2]);
    group_mes($event, 'ÖJM PI');
    group_mes($other, 'ÖJM PI');
    $club = makeClub_p5();

    $eighteen = athlete_mes($club, ['S9'], '2007-12-31');
    entry_mes($event, $eighteen);
    entry_mes($other, $eighteen);
    entry_mes($event, athlete_mes($club, ['S9'], '2007-01-01'));
    entry_mes($event, athlete_mes($club, ['S9'], '2006-12-31'));
    entry_mes($event, athlete_mes($club, ['S9'], null));
    entry_mes($event, athlete_mes($club, ['S9'], '2012-05-05'), 'EXH');

    $summary = summary_mes($meet);

    expect($summary->athletes)->toBe(4)
        ->and($summary->youthAthletes)->toBe(2)
        ->and($summary->athletesWithoutBirthDate)->toBe(1);
});

it('zählt Sportler der B-Meisterschaft je Person einmal und nur mit ÖBM-Gruppe', function () {
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet);
    $other = makeEvent_p5($meet, ['event_number' => 2]);
    group_mes($event, 'ÖBM S01 - S10', ['sport_classes' => '1,2,3,4,5,6,7,8,9,10', 'levels' => 'B']);
    group_mes($other, 'ÖBM S01 - S10', ['sport_classes' => '1,2,3,4,5,6,7,8,9,10', 'levels' => 'B']);
    $club = makeClub_p5();

    $b = athlete_mes($club);
    $b->update(['level' => 'B']);
    entry_mes($event, $b);
    entry_mes($other, $b);
    $a = athlete_mes($club);
    $a->update(['level' => 'A']);
    entry_mes($event, $a);

    $withoutB = makeMeet_p5();
    eventWithEntries_mes($withoutB, 'ÖJM PI', 2);

    expect(summary_mes($meet)->bChampionshipAthletes)->toBe(1)
        ->and(summary_mes($meet)->unassignedEntries)->toBe(1)
        ->and(summary_mes($withoutB)->bChampionshipAthletes)->toBeNull();
});

it('rechnet Staffeln je Team mit der Zahl der Schwimmer und weist nicht auswertbare aus', function () {
    $meet = makeMeet_p5();
    eventWithEntries_mes($meet, 'ÖJM PI', 4);
    $relay = makeEvent_p5($meet, ['event_number' => 2, 'relay_count' => 4, 'gender' => 'X', 'sport_classes' => null]);
    group_mes($relay, 'ÖJM Staffel', ['gender' => 'X']);
    $noGroups = makeEvent_p5($meet, ['event_number' => 3, 'relay_count' => 4, 'gender' => 'X', 'sport_classes' => null]);
    $club = makeClub_p5();
    $swimmers = fn (): array => [athlete_mes($club, ['S9'], '2010-01-01'), athlete_mes($club), athlete_mes($club), athlete_mes($club)];

    relay_mes($relay, $club, $swimmers());
    relay_mes($relay, $club, $swimmers());
    relay_mes($relay, $club, $swimmers());
    relay_mes($relay, $club, $swimmers(), ['is_exhibition' => true]);
    relay_mes($relay, $club, []);
    relay_mes($noGroups, $club, $swimmers());

    $summary = summary_mes($meet);
    $relayRow = collect($summary->medalRows)->firstWhere('isRelay', true);

    expect($summary->medalRows)->toHaveCount(2)
        ->and($summary->medalRows[0]->isRelay)->toBeFalse()
        ->and($relayRow->label)->toBe('ÖJM')
        ->and(counts_mes($relayRow->medals))->toBe([4, 4, 0, 4])
        ->and(collect($summary->details)->firstWhere('isRelay', true)->participants)->toBe(3)
        ->and($summary->unevaluableRelays)->toBe(2)
        ->and($summary->eventsWithoutGroups)->toHaveCount(1)
        ->and($summary->athletes)->toBe(4 + 16)
        ->and($summary->youthAthletes)->toBe(4);
});

it('weist Bewerbe ohne Gruppen und nicht zugeordnete Meldungen aus und ordnet die Wertungen', function () {
    $meet = makeMeet_p5();
    eventWithEntries_mes($meet, 'ÖBM S01 - S10', 1);
    eventWithEntries_mes($meet, 'ÖJM PI', 2);
    eventWithEntries_mes($meet, 'Bundesländercup', 1);
    $plain = makeEvent_p5($meet, ['event_number' => 9]);
    entry_mes($plain, athlete_mes(makeClub_p5()));
    $narrow = eventWithEntries_mes($meet, 'ÖJM S1', 0, ['sport_classes' => '1']);
    entry_mes($narrow, athlete_mes(makeClub_p5()));

    $summary = summary_mes($meet);

    expect(collect($summary->medalRows)->pluck('label')->all())->toBe(['ÖJM', 'ÖBM', 'Bundesländercup'])
        ->and($summary->eventsWithoutGroups)->toBe([$plain->selection_label])
        ->and($summary->unassignedEntries)->toBe(1)
        ->and($summary->athletes)->toBe(6);
});
