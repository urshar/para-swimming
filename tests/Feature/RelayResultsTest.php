<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\RelayEntry;
use App\Models\RelayEntryMember;
use App\Models\RelayResult;
use App\Models\RelayResultMember;
use App\Models\Result;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use App\Models\User;
use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use App\Services\MeetResultListService;
use App\Services\RecordCheckerService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('relay-results');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function admin_rr(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

/** Veranstaltung mit 4x50 m Freistil (Bewerbsgeschlecht unspezifiziert, wie bei LENEX-Importen). */
function setup_rr(): array
{
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $event = makeEvent_p5($meet, ['event_number' => 8, 'distance' => 50, 'relay_count' => 4, 'gender' => 'A']);

    return [$meet, $club, $event];
}

/** @param  list<string>  $genders  je Position ein Athlet des Vereins mit diesem Geschlecht und S14 */
function athletes_rr(Club $club, array $genders): array
{
    return array_map(fn (string $g): Athlete => makeAthlete_p5($club, $g, ['S14']), $genders);
}

/** @param  list<Athlete>  $athletes */
function relay_rr(Meet $meet, SwimEvent $event, Club $club, string $gender, array $athletes, int $time, ?string $status): RelayResult
{
    $relay = RelayResult::create([
        'meet_id' => $meet->id,
        'swim_event_id' => $event->id,
        'club_id' => $club->id,
        'gender' => $gender,
        'swim_time' => $time,
        'status' => $status,
    ]);
    foreach ($athletes as $i => $athlete) {
        RelayResultMember::create([
            'relay_result_id' => $relay->id,
            'position' => $i + 1,
            'athlete_id' => $athlete->id,
            'sport_class' => 'S14',
        ]);
    }

    return $relay->load(['members.athlete', 'swimEvent']);
}

/** LENEX-Import einer Ergebnisdatei, optional in eine bestehende Veranstaltung (Exception → RuntimeException). */
function importLenex_rr(string $xml, ?int $meetId): void
{
    $path = tempnam(sys_get_temp_dir(), 'lenex_rr').'.lef';
    file_put_contents($path, $xml);

    try {
        (new LenexParserService)->import($path, new LenexResolverService, $meetId);
    } catch (Exception $e) {
        throw new RuntimeException('LENEX-Import fehlgeschlagen: '.$e->getMessage(), previous: $e);
    } finally {
        unlink($path);
    }
}

/** Rekord-Check einer Veranstaltung (Throwable → RuntimeException). */
function check_rr(Meet $meet): array
{
    try {
        return app(RecordCheckerService::class)->checkMeet($meet);
    } catch (Throwable $e) {
        throw new RuntimeException('Rekord-Check fehlgeschlagen: '.$e->getMessage(), previous: $e);
    }
}

/** Die Athleten der Testdatei in der Datenbank (Zuordnung im Import über die Lizenz LIC<n>). */
function lenexAthletes_rr(Club $club, Club $otherClub): void
{
    foreach ([1, 2, 3, 4, 5, 6, 9] as $n) {
        $athlete = makeAthlete_p5($n === 9 ? $otherClub : $club, 'M', ['S14']);
        $athlete->update(['last_name' => 'Schwimmer'.$n, 'license' => 'LIC'.$n]);
    }
}

/**
 * Ausschnitt nach dem Muster der ÖSTM-2025-Datei: Staffelbewerb mit AGEGROUP handicap 14, zwei Vereine, eine
 * reguläre Herrenstaffel mit Zwischenzeit, eine AK-Staffel mit vereinsfremdem Schwimmer (aus dem zweiten Verein),
 * eine Staffel ohne Zeit und Status (wird übersprungen) und eine zurückgezogene (WDR).
 */
function ostmXml_rr(Club $club, Club $otherClub): string
{
    $athlete = fn (int $n, string $gender) => '<ATHLETE athleteid="'.$n.'" lastname="Schwimmer'.$n.'" firstname="Max" gender="'.$gender
        .'" birthdate="2000-01-01" license="LIC'.$n.'"><HANDICAP free="14" breast="14" medley="14" />'
        .($n === 1 ? '<RESULTS><RESULT eventid="1001" resultid="501" swimtime="00:00:40.00" /></RESULTS>' : '')
        .'</ATHLETE>';
    $positions = fn (array $ids) => '<RELAYPOSITIONS>'.implode('', array_map(
        fn (int $id, int $i) => '<RELAYPOSITION athleteid="'.$id.'" number="'.($i + 1).'" />', $ids, array_keys($ids)
    )).'</RELAYPOSITIONS>';

    return '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="Staffeltest" city="Kapfenberg" nation="AUT" course="SCM">
<SESSIONS><SESSION number="1" date="2025-05-10"><EVENTS>
  <EVENT eventid="1001" number="1" round="TIM"><SWIMSTYLE distance="50" relaycount="1" stroke="FREE" /></EVENT>
  <EVENT eventid="1073" number="8" round="TIM"><SWIMSTYLE distance="50" relaycount="4" stroke="FREE" />
    <AGEGROUPS><AGEGROUP agegroupid="1244" agemax="-1" agemin="-1" gender="M" name="ÖM: MI" handicap="14">
      <RANKINGS><RANKING order="1" place="1" resultid="1919" /></RANKINGS>
    </AGEGROUP></AGEGROUPS>
  </EVENT>
</EVENTS></SESSION></SESSIONS>
<CLUBS>
  <CLUB name="'.$club->name.'" code="'.$club->code.'" nation="AUT"><ATHLETES>'
        .$athlete(1, 'M').$athlete(2, 'M').$athlete(3, 'M').$athlete(4, 'M').$athlete(5, 'M').$athlete(6, 'M').'</ATHLETES>
    <RELAYS>
      <RELAY agemax="-1" agemin="-1" gender="M" number="1"><RESULTS>
        <RESULT eventid="1073" resultid="1919" points="371" swimtime="00:02:29.49" heatid="1" lane="3">
          <SPLITS><SPLIT distance="50" swimtime="00:00:33.72" /></SPLITS>'.$positions([1, 2, 3, 4]).'
        </RESULT></RESULTS></RELAY>
      <RELAY agemax="-1" agemin="-1" gender="M" number="2"><RESULTS>
        <RESULT eventid="1073" resultid="1920" status="EXH" swimtime="00:03:00.00">'.$positions([5, 6, 2, 9]).'</RESULT>
      </RESULTS></RELAY>
      <RELAY agemax="-1" agemin="-1" gender="X" number="3"><RESULTS>
        <RESULT eventid="1073" resultid="1921" swimtime="00:00:00.00" />
      </RESULTS></RELAY>
      <RELAY agemax="-1" agemin="-1" gender="M" number="4"><RESULTS>
        <RESULT eventid="1073" resultid="1922" status="WDR" swimtime="00:00:00.00" />
      </RESULTS></RELAY>
    </RELAYS>
  </CLUB>
  <CLUB name="'.$otherClub->name.'" code="'.$otherClub->code.'" nation="AUT"><ATHLETES>'.$athlete(9, 'M').'</ATHLETES></CLUB>
</CLUBS></MEET></MEETS></LENEX>';
}

// ── Geschlecht und Zusammensetzung ────────────────────────────────────────────

it('leitet das Staffel-Geschlecht aus den Mitgliedern ab: nur Damen = D, 2 + 2 = Mixed, sonst Herren', function () {
    expect(RelayResult::genderFromMembers(['F', 'F', 'F', 'F']))->toBe('F')
        ->and(RelayResult::genderFromMembers(['M', 'F', 'F', 'M']))->toBe('X')
        ->and(RelayResult::genderFromMembers(['M', 'M', 'M', 'F']))->toBe('M')
        ->and(RelayResult::genderFromMembers(['M', 'F', 'F', 'F']))->toBe('M')
        ->and(RelayResult::genderFromMembers(['M', 'M', 'M', 'M']))->toBe('M');
});

it('erkennt rekordfähige Zusammensetzungen', function () {
    [$meet, $club, $event] = setup_rr();

    $herren = relay_rr($meet, $event, $club, 'M', athletes_rr($club, ['M', 'M', 'M', 'M']), 15000, null);
    $mixed = relay_rr($meet, $event, $club, 'X', athletes_rr($club, ['M', 'F', 'M', 'F']), 15000, null);
    $damenBeteiligung = relay_rr($meet, $event, $club, 'M', athletes_rr($club, ['M', 'M', 'M', 'F']), 15000, null);
    $unvollstaendig = relay_rr($meet, $event, $club, 'M', athletes_rr($club, ['M', 'M', 'M']), 15000, null);

    expect($herren->hasRecordComposition())->toBeTrue()
        ->and($mixed->hasRecordComposition())->toBeTrue()
        ->and($damenBeteiligung->hasRecordComposition())->toBeFalse()
        ->and($unvollstaendig->hasRecordComposition())->toBeFalse();
});

// ── LENEX-Import ──────────────────────────────────────────────────────────────

it('importiert Staffelergebnisse mit Wertung, Staffelklasse, Platz, Schwimmern und Zwischenzeiten', function () {
    makeStrokeType_p5();
    $club = makeClub_p5();
    $otherClub = makeClub_p5();
    lenexAthletes_rr($club, $otherClub);

    importLenex_rr(ostmXml_rr($club, $otherClub), null);

    $regular = RelayResult::where('lenex_result_id', '1919')->with(['members.athlete', 'splits'])->first();
    $exhibition = RelayResult::where('lenex_result_id', '1920')->with('members.athlete')->first();

    expect(RelayResult::count())->toBe(3)
        ->and(RelayResult::where('lenex_result_id', '1921')->exists())->toBeFalse()
        ->and(RelayResult::where('lenex_result_id', '1922')->value('status'))->toBe('WDR')
        ->and($regular->gender)->toBe('M')
        ->and($regular->relay_class)->toBe('S14')
        ->and($regular->place)->toBe(1)
        ->and($regular->swim_time)->toBe(14949)
        ->and($regular->points)->toBe(371)
        ->and($regular->club_id)->toBe($club->id)
        ->and($regular->members->pluck('athlete.last_name')->all())->toBe(['Schwimmer1', 'Schwimmer2', 'Schwimmer3', 'Schwimmer4'])
        ->and($regular->members->first()->sport_class)->toBe('S14')
        ->and($regular->splits->pluck('split_time')->all())->toBe([3372])
        ->and($exhibition->status)->toBe('EXH')
        ->and($exhibition->members->last()->athlete->club_id)->toBe($otherClub->id);
});

it('legt beim erneuten Import nichts doppelt an', function () {
    makeStrokeType_p5();
    $club = makeClub_p5();
    $otherClub = makeClub_p5();
    lenexAthletes_rr($club, $otherClub);
    $xml = ostmXml_rr($club, $otherClub);

    importLenex_rr($xml, null);
    importLenex_rr($xml, Meet::value('id'));

    expect(RelayResult::count())->toBe(3)
        ->and(RelayResultMember::count())->toBe(8)
        ->and(Result::count())->toBe(1)
        ->and(Meet::count())->toBe(1);
});

// ── Rekorde ───────────────────────────────────────────────────────────────────

it('legt für eine reine Herrenstaffel einen Staffelrekord mit den echten Schwimmern an', function () {
    [$meet, $club, $event] = setup_rr();
    $relay = relay_rr($meet, $event, $club, 'M', athletes_rr($club, ['M', 'M', 'M', 'M']), 15000, null);

    check_rr($meet);

    $record = SwimRecord::where('relay_result_id', $relay->id)->where('record_type', 'AUT')->first();
    expect($record)->not->toBeNull()
        ->and($record->sport_class)->toBe('S14')
        ->and($record->gender)->toBe('M')
        ->and($record->relayTeam()->count())->toBe(4)
        ->and($relay->fresh()->is_national_record)->toBeTrue();
});

it('legt für Herrenstaffeln mit Damenbeteiligung und vereinsfremden Schwimmern keinen Rekord an', function () {
    [$meet, $club, $event] = setup_rr();
    relay_rr($meet, $event, $club, 'M', athletes_rr($club, ['M', 'M', 'M', 'F']), 15000, null);
    $foreign = athletes_rr($club, ['M', 'M', 'M']);
    $foreign[] = makeAthlete_p5(makeClub_p5(), 'M', ['S14']);
    relay_rr($meet, $event, $club, 'M', $foreign, 15000, null);

    check_rr($meet);

    expect(SwimRecord::count())->toBe(0);
});

// ── Statistik-unabhängige Anzeige: Sammelansicht und PDF ─────────────────────

it('zeigt Staffelergebnisse in der Sammelansicht und gliedert die Ergebnisliste nach Wertung und Staffelklasse', function () {
    [$meet, $club, $event] = setup_rr();
    $herren = relay_rr($meet, $event, $club, 'M', athletes_rr($club, ['M', 'M', 'M', 'M']), 16000, null);
    $herren->update(['relay_class' => 'S14']);
    $schneller = relay_rr($meet, $event, $club, 'M', athletes_rr($club, ['M', 'M', 'M', 'M']), 15000, null);
    $schneller->update(['relay_class' => 'S14', 'relay_number' => 2]);
    relay_rr($meet, $event, $club, 'X', athletes_rr($club, ['M', 'F', 'M', 'F']), 17000, null)->update(['relay_class' => 'S14']);

    $this->actingAs(admin_rr())
        ->get(route('meets.results-overview', $meet))
        ->assertOk()
        ->assertSee($club->display_name.' 2')
        ->assertSee('Mixed')
        ->assertSee('Staffelergebnis erfassen');

    $groups = app(MeetResultListService::class)->byEvent($meet, null)->first()['groups'];

    expect(array_column($groups, 'label'))->toBe(['Herren – S14', 'Mixed – S14'])
        ->and($groups[0]['rows'][0]['result']->id)->toBe($schneller->id)
        ->and($groups[0]['rows'][0]['place'])->toBe(1);
});

// ── Erfassen ──────────────────────────────────────────────────────────────────

it('legt ein Staffelergebnis an und leitet Wertung und Staffelklasse aus den Schwimmern ab', function () {
    [$meet, $club, $event] = setup_rr();
    $members = athletes_rr($club, ['M', 'F', 'F', 'M']);

    $this->actingAs(admin_rr())
        ->post(route('meets.relay-results.store', $meet), [
            'swim_event_id' => $event->id,
            'club_id' => $club->id,
            'members' => array_map(fn (Athlete $a) => $a->id, $members),
            'swim_time' => '02:30.00',
        ])
        ->assertRedirect(route('meets.results-overview', $meet));

    $relay = RelayResult::with('members')->first();
    expect($relay->gender)->toBe('X')
        ->and($relay->relay_class)->toBe('S14')
        ->and($relay->swim_time)->toBe(15000)
        ->and($relay->members->pluck('athlete_id')->all())->toBe(array_map(fn (Athlete $a) => $a->id, $members));
});

it('belegt im Formular die Schwimmer aus der Staffelmeldung vor', function () {
    [$meet, $club, $event] = setup_rr();
    $members = athletes_rr($club, ['M', 'M', 'M', 'M']);
    $entry = RelayEntry::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'club_id' => $club->id]);
    foreach ($members as $i => $athlete) {
        RelayEntryMember::create(['relay_entry_id' => $entry->id, 'athlete_id' => $athlete->id, 'position' => $i + 1]);
    }

    $this->actingAs(admin_rr())
        ->get(route('meets.relay-results.create', ['meet' => $meet, 'swim_event_id' => $event->id]))
        ->assertOk()
        ->assertSee('"'.$event->id.'-'.$club->id.'":["'.$members[0]->id.'","'.$members[1]->id.'"', false);
});

it('lehnt doppelte Schwimmer und Einzelbewerbe ab', function () {
    [$meet, $club, $event] = setup_rr();
    $single = makeEvent_p5($meet, ['event_number' => 1]);
    $athlete = makeAthlete_p5($club, 'M', ['S14']);
    $admin = admin_rr();

    $this->actingAs($admin)
        ->post(route('meets.relay-results.store', $meet), [
            'swim_event_id' => $event->id, 'club_id' => $club->id, 'members' => [$athlete->id, $athlete->id],
        ])
        ->assertSessionHasErrors('members');
    $this->post(route('meets.relay-results.store', $meet), [
        'swim_event_id' => $single->id, 'club_id' => $club->id,
    ])->assertSessionHasErrors('swim_event_id');

    expect(RelayResult::count())->toBe(0);
});

it('löscht ein Staffelergebnis samt Schwimmern', function () {
    [$meet, $club, $event] = setup_rr();
    $relay = relay_rr($meet, $event, $club, 'M', athletes_rr($club, ['M', 'M', 'M', 'M']), 15000, null);

    $this->actingAs(admin_rr())->delete(route('relay-results.destroy', $relay))->assertRedirect();

    expect(RelayResult::count())->toBe(0)
        ->and(RelayResultMember::count())->toBe(0);
});

it('sperrt die Staffelergebnis-Erfassung für Vereinsnutzer', function () {
    [$meet, $club, $event] = setup_rr();
    $relay = relay_rr($meet, $event, $club, 'M', athletes_rr($club, ['M', 'M', 'M', 'M']), 15000, null);
    $clubUser = User::factory()->create(['is_admin' => false, 'club_id' => $club->id]);

    $this->actingAs($clubUser);
    $this->get(route('meets.relay-results.create', $meet))->assertForbidden();
    $this->get(route('relay-results.edit', $relay))->assertForbidden();
    $this->delete(route('relay-results.destroy', $relay))->assertForbidden();
});
