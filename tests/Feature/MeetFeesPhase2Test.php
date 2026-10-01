<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Entry;
use App\Models\Meet;
use App\Models\MeetFee;
use App\Models\RelayEntry;
use App\Models\RelayEntryMember;
use App\Models\SwimEvent;
use App\Models\User;
use App\Services\EntryFeeCalculator;
use App\Support\ClubFeeStatement;
use App\Support\FeeLine;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('meet-fees-p2');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php):
// makeMeet_p5(), makeClub_p5(), makeAthlete_p5(), makeEvent_p5().

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function admin_mf2(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function event_mf2(Meet $meet, int $session, int $number, int $relayCount, ?int $feeCents): SwimEvent
{
    return makeEvent_p5($meet, [
        'session_number' => $session, 'event_number' => $number, 'relay_count' => $relayCount, 'fee_cents' => $feeCents,
    ]);
}

function entry_mf2(SwimEvent $event, Athlete $athlete, Club $club, ?string $status): Entry
{
    return Entry::create([
        'meet_id' => $event->meet_id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
        'club_id' => $club->id, 'status' => $status,
    ]);
}

/** @param  array<int, Athlete>  $members */
function relay_mf2(SwimEvent $event, Club $club, string $status, array $members): RelayEntry
{
    $relay = RelayEntry::create([
        'meet_id' => $event->meet_id, 'swim_event_id' => $event->id, 'club_id' => $club->id,
        'relay_class' => 'S20', 'status' => $status,
    ]);
    foreach (array_values($members) as $i => $athlete) {
        RelayEntryMember::create([
            'relay_entry_id' => $relay->id, 'athlete_id' => $athlete->id, 'position' => $i + 1, 'sport_class' => 'S9',
        ]);
    }

    return $relay;
}

function meetFee_mf2(Meet $meet, ?int $session, string $type, int $cents): void
{
    MeetFee::create(['meet_id' => $meet->id, 'session_number' => $session, 'type' => $type, 'amount_cents' => $cents]);
}

function statement_mf2(Meet $meet, Club $club): ClubFeeStatement
{
    return app(EntryFeeCalculator::class)->statements($meet, $club->id)->sole();
}

/**
 * HTML der PDF-Vorlage (dieselbe Vorlage, die dompdf bekommt). View::render() deklariert Throwable; hier einmalig
 * abgefangen und als RuntimeException weitergereicht (Muster wie buildLenex_p7() in LenexRelayExportTest).
 *
 * @param  array<string, mixed>  $data
 */
function pdfHtml_mf2(array $data): string
{
    try {
        return view('pdf.entry-lists.meldegeld', $data)->render();
    } catch (Throwable $e) {
        throw new RuntimeException('PDF-Vorlage konnte nicht gerendert werden: '.$e->getMessage(), previous: $e);
    }
}

/** @return array<string, int> Pauschalen als Bezeichnung => Summe */
function flat_mf2(ClubFeeStatement $statement): array
{
    return $statement->flatFees->mapWithKeys(fn (FeeLine $l): array => [$l->label => $l->totalCents])->all();
}

// ── Berechnung ────────────────────────────────────────────────────────────────

it('berechnet Einzelstarts mit der Bewerbsgebühr und lässt nur abgelehnte Meldungen weg', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $ev1 = event_mf2($meet, 1, 1, 1, 500);
    $ev2 = event_mf2($meet, 1, 2, 1, 700);
    $ev3 = event_mf2($meet, 1, 3, 1, null);   // ohne Gebühr → 0
    $a = makeAthlete_p5($club);
    entry_mf2($ev1, $a, $club, null);
    entry_mf2($ev2, $a, $club, 'WDR');        // abgemeldet → zählt
    entry_mf2($ev3, $a, $club, 'EXH');        // außer Konkurrenz → zählt, aber 0 €
    $b = makeAthlete_p5($club);
    entry_mf2($ev1, $b, $club, 'SICK');       // krank → zählt
    entry_mf2($ev2, $b, $club, 'RJC');        // abgelehnt → zählt nicht

    $s = statement_mf2($meet, $club);

    expect($s->startCount)->toBe(4)
        // 5,00 (Bewerb 1) + 7,00 (Bewerb 2, WDR) + 0,00 (Bewerb 3 ohne Gebühr, EXH) + 5,00 (Bewerb 1, SICK)
        ->and($s->startsCents())->toBe(1700)
        ->and($s->totalCents)->toBe(1700)
        ->and($s->athletes)->toHaveCount(2);
});

it('nimmt für Staffeln die Bewerbsgebühr vor RELAY des Abschnitts vor RELAY der Veranstaltung', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $withFee = event_mf2($meet, 1, 1, 4, 1200);
    $session2 = event_mf2($meet, 2, 2, 4, null);
    $session3 = event_mf2($meet, 3, 3, 4, null);
    meetFee_mf2($meet, null, MeetFee::TYPE_RELAY, 800);
    meetFee_mf2($meet, 2, MeetFee::TYPE_RELAY, 1000);

    relay_mf2($withFee, $club, 'pending', []);
    relay_mf2($session2, $club, 'confirmed', []);
    relay_mf2($session3, $club, 'pending', []);
    relay_mf2($session3, $club, 'withdrawn', []);   // zurückgezogen → zählt nicht

    $s = statement_mf2($meet, $club);

    expect($s->relays->pluck('totalCents')->all())->toBe([1200, 1000, 800])
        ->and($s->totalCents)->toBe(3000);
});

it('berechnet Pauschalen je Verein und je Athlet, inklusive reiner Staffelschwimmer', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $single = event_mf2($meet, 1, 1, 1, 0);
    $relayEvent = event_mf2($meet, 1, 2, 4, 0);
    $a = makeAthlete_p5($club);
    $relayOnly = makeAthlete_p5($club);
    entry_mf2($single, $a, $club, null);
    relay_mf2($relayEvent, $club, 'pending', [$a, $relayOnly]);
    meetFee_mf2($meet, null, MeetFee::TYPE_CLUB, 2500);
    meetFee_mf2($meet, null, MeetFee::TYPE_ATHLETE, 1000);

    $s = statement_mf2($meet, $club);

    expect(flat_mf2($s))->toBe(['Gebühr je Verein' => 2500, 'Gebühr je Athlet' => 2000])
        ->and($s->athletes)->toHaveCount(2)
        ->and($s->athletes->firstWhere(fn ($af) => $af->athlete->is($relayOnly))->starts)->toBeEmpty()
        ->and($s->totalCents)->toBe(4500);
});

it('berechnet Abschnitts-Pauschalen nur für Abschnitte, in denen der Verein startet', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $s1 = event_mf2($meet, 1, 1, 1, 0);
    $s2 = event_mf2($meet, 2, 2, 1, 0);
    event_mf2($meet, 3, 3, 1, 0);              // Abschnitt 3 ohne Starts des Vereins
    $a = makeAthlete_p5($club);
    $b = makeAthlete_p5($club);
    entry_mf2($s1, $a, $club, null);
    entry_mf2($s2, $a, $club, null);
    entry_mf2($s2, $b, $club, null);
    foreach ([1, 2, 3] as $session) {
        meetFee_mf2($meet, $session, MeetFee::TYPE_CLUB, 300);
        meetFee_mf2($meet, $session, MeetFee::TYPE_ATHLETE, 100);
    }

    expect(flat_mf2(statement_mf2($meet, $club)))->toBe([
        'Gebühr je Verein – Abschnitt 1' => 300,
        'Gebühr je Athlet – Abschnitt 1' => 100,
        'Gebühr je Verein – Abschnitt 2' => 300,
        'Gebühr je Athlet – Abschnitt 2' => 200,
    ]);
});

it('berechnet TEAM und Nachmeldegebühren noch nicht', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    entry_mf2(event_mf2($meet, 1, 1, 1, 500), makeAthlete_p5($club), $club, null);
    meetFee_mf2($meet, null, MeetFee::TYPE_TEAM, 5000);
    meetFee_mf2($meet, null, MeetFee::TYPE_LATE_INDIVIDUAL, 5000);
    meetFee_mf2($meet, null, MeetFee::TYPE_LATE_RELAY, 5000);

    $s = statement_mf2($meet, $club);

    expect($s->flatFees)->toBeEmpty()
        ->and($s->totalCents)->toBe(500);
});

it('liefert je Verein eine Abrechnung, alphabetisch, und die Gesamtsumme', function () {
    $meet = makeMeet_p5();
    $event = event_mf2($meet, 1, 1, 1, 500);
    $clubB = makeClub_p5();
    $clubB->update(['short_name' => 'Zeta']);
    $clubA = makeClub_p5();
    $clubA->update(['short_name' => 'Alpha']);
    entry_mf2($event, makeAthlete_p5($clubB), $clubB, null);
    entry_mf2($event, makeAthlete_p5($clubA), $clubA, null);
    entry_mf2($event, makeAthlete_p5($clubA), $clubA, null);

    $all = app(EntryFeeCalculator::class)->statements($meet, null);

    expect($all->map(fn (ClubFeeStatement $s) => $s->club->display_name)->all())->toBe(['Alpha', 'Zeta'])
        ->and(EntryFeeCalculator::total($all))->toBe(1500)
        ->and(app(EntryFeeCalculator::class)->statements($meet, $clubB->id))->toHaveCount(1);
});

// ── Online + PDF ──────────────────────────────────────────────────────────────

it('zeigt dem Admin die Übersicht aller Vereine und das Detail je Verein', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $event = event_mf2($meet, 1, 1, 1, 550);
    $athlete = makeAthlete_p5($club);
    entry_mf2($event, $athlete, $club, null);
    $admin = admin_mf2();

    $this->actingAs($admin)->get(route('meets.fees.index', $meet))->assertOk()
        ->assertSee($club->display_name)
        ->assertSee('5,50 €')
        ->assertSee(route('meets.fees.club', [$meet, $club]));

    $this->actingAs($admin)->get(route('meets.fees.club', [$meet, $club]))->assertOk()
        ->assertSee($athlete->display_name)
        ->assertSee('Nr. 1')
        ->assertSee('5,50 €');
});

it('zeigt einem Vereinsnutzer nur die eigene Abrechnung', function () {
    $meet = makeMeet_p5();
    $own = makeClub_p5();
    $other = makeClub_p5();
    $event = event_mf2($meet, 1, 1, 1, 500);
    $ownAthlete = makeAthlete_p5($own);
    $otherAthlete = makeAthlete_p5($other);
    entry_mf2($event, $ownAthlete, $own, null);
    entry_mf2($event, $otherAthlete, $other, null);
    $user = User::factory()->create(['is_admin' => false, 'club_id' => $own->id]);

    $this->actingAs($user)->get(route('meets.fees.index', $meet))->assertOk()
        ->assertSee($ownAthlete->display_name)
        ->assertDontSee($otherAthlete->display_name);
    $this->actingAs($user)->get(route('meets.fees.club', [$meet, $other]))->assertForbidden();

    $noClub = User::factory()->create(['is_admin' => false, 'club_id' => null]);
    $this->actingAs($noClub)->get(route('meets.fees.index', $meet))->assertForbidden();
    $this->actingAs($noClub)->get(route('meets.entry-lists.meldegeld.pdf', $meet))->assertForbidden();
});

it('liefert das PDF für Admin und Verein', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    entry_mf2(event_mf2($meet, 1, 1, 1, 500), makeAthlete_p5($club), $club, null);

    $this->actingAs(admin_mf2())->get(route('meets.entry-lists.meldegeld.pdf', $meet))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->actingAs(User::factory()->create(['is_admin' => false, 'club_id' => $club->id]))
        ->get(route('meets.entry-lists.meldegeld.pdf', $meet))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('rendert im PDF die Übersicht nur für den Admin', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    entry_mf2(event_mf2($meet, 1, 1, 1, 500), makeAthlete_p5($club), $club, null);
    $statements = app(EntryFeeCalculator::class)->statements($meet, null);
    $data = ['meet' => $meet, 'statements' => $statements, 'totalCents' => 500, 'feeSchedule' => collect()];

    expect(pdfHtml_mf2($data + ['showOverview' => true]))
        ->toContain('Übersicht aller Vereine')
        ->toContain($club->display_name)
        // Mit Übersicht beginnt der erste Verein auf einer neuen Seite, ohne Übersicht nicht.
        ->toContain('class="page-break"')
        ->and(pdfHtml_mf2($data + ['showOverview' => false]))
        ->not->toContain('Übersicht aller Vereine')
        ->not->toContain('class="page-break"');
});

it('verlinkt die Abrechnung in den Listen-Dropdowns', function () {
    $meet = makeMeet_p5();

    $this->actingAs(admin_mf2())->get(route('meets.entries-overview', $meet))->assertOk()
        ->assertSee(route('meets.fees.index', $meet))
        ->assertSee(route('meets.entry-lists.meldegeld.pdf', $meet));
});

// ── Gebührenübersicht im PDF ──────────────────────────────────────────────────

it('listet nur befüllte, berechnete Gebühren und fasst gleiche Bewerbsgebühren zusammen', function () {
    $meet = makeMeet_p5();
    foreach ([1 => 1000, 2 => 1000, 3 => 1000, 4 => null, 5 => 1000, 6 => 1200] as $number => $fee) {
        event_mf2($meet, 1, $number, 1, $fee);
    }
    event_mf2($meet, 2, 7, 4, 2000);
    MeetFee::create(['meet_id' => $meet->id, 'session_number' => 2, 'type' => MeetFee::TYPE_CLUB, 'amount_cents' => 2500]);
    MeetFee::create(['meet_id' => $meet->id, 'session_number' => null, 'type' => MeetFee::TYPE_LATE_INDIVIDUAL, 'amount_cents' => 300]);
    MeetFee::create(['meet_id' => $meet->id, 'session_number' => null, 'type' => MeetFee::TYPE_ATHLETE, 'amount_cents' => 500]);
    MeetFee::create(['meet_id' => $meet->id, 'session_number' => null, 'type' => MeetFee::TYPE_TEAM, 'amount_cents' => 9900]);

    $lines = app(EntryFeeCalculator::class)->schedule($meet)
        ->map(fn ($l): string => $l->group.' | '.$l->label.' | '.$l->amountCents)
        ->all();

    expect($lines)->toBe([
        'Veranstaltung | Je Athlet | 500',
        'Veranstaltung | Nachmeldung je Einzelstart | 300',
        'Abschnitt 2 | Je Verein | 2500',
        'Bewerbe | Einzelbewerbe Nr. 1–3, 5 pro Start | 1000',
        'Bewerbe | Einzelbewerbe Nr. 6 pro Start | 1200',
        'Bewerbe | Staffelbewerbe pro Start | 2000',
    ]);
});

it('zeigt im PDF die Gebührenübersicht und die Spalte Nachmeldungen', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $event = event_mf2($meet, 1, 1, 1, 1000);
    Entry::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => makeAthlete_p5($club)->id, 'club_id' => $club->id, 'is_late_entry' => true]);
    MeetFee::create(['meet_id' => $meet->id, 'session_number' => null, 'type' => MeetFee::TYPE_LATE_INDIVIDUAL, 'amount_cents' => 300]);

    $calculator = app(EntryFeeCalculator::class);
    $statements = $calculator->statements($meet, null);
    $html = pdfHtml_mf2([
        'meet' => $meet,
        'statements' => $statements,
        'totalCents' => EntryFeeCalculator::total($statements),
        'feeSchedule' => $calculator->schedule($meet),
        'showOverview' => true,
    ]);

    expect($html)->toContain('Meldegebühren')
        ->toContain('Einzelbewerbe pro Start')
        ->toContain('Nachmeldung je Einzelstart')
        ->toContain('<th class="num">Nachmeldungen</th>')
        ->not->toContain('Je Verein');
});
