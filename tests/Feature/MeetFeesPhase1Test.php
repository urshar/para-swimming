<?php

use App\Models\Meet;
use App\Models\MeetFee;
use App\Models\SwimEvent;
use App\Models\User;
use App\Services\LenexExportService;
use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('meet-fees-p1');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php):
// makeMeet_p5(), makeClub_p5(), makeEvent_p5(), makeStrokeType_p5().

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function admin_mf1(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function event_mf1(Meet $meet, int $session, int $number, int $relayCount): SwimEvent
{
    return makeEvent_p5($meet, ['session_number' => $session, 'event_number' => $number, 'relay_count' => $relayCount]);
}

function fee_mf1(Meet $meet, ?int $session, string $type): ?int
{
    return MeetFee::where(['meet_id' => $meet->id, 'session_number' => $session, 'type' => $type])->value('amount_cents');
}

/** LENEX-Export (DOMException → RuntimeException, Muster wie buildLenex_p7() in LenexRelayExportTest). */
function lenex_mf1(Meet $meet): SimpleXMLElement
{
    try {
        return simplexml_load_string((new LenexExportService)->build($meet, 'entries'));
    } catch (DOMException $e) {
        throw new RuntimeException('LENEX-Export fehlgeschlagen: '.$e->getMessage(), previous: $e);
    }
}

/** LENEX-Import in eine bestehende Veranstaltung (Exception → RuntimeException). */
function importLenex_mf1(string $xml, Meet $meet): void
{
    $path = tempnam(sys_get_temp_dir(), 'lenex_mf1').'.lef';
    file_put_contents($path, $xml);

    try {
        (new LenexParserService)->import($path, new LenexResolverService, $meet->id);
    } catch (Exception $e) {
        throw new RuntimeException('LENEX-Import fehlgeschlagen: '.$e->getMessage(), previous: $e);
    } finally {
        unlink($path);
    }
}

// ── Money ─────────────────────────────────────────────────────────────────────

it('rechnet Euro-Eingaben in Cent um und formatiert sie wieder', function () {
    expect(Money::toCents('10'))->toBe(1000)
        ->and(Money::toCents('10,5'))->toBe(1050)
        ->and(Money::toCents('10.50'))->toBe(1050)
        ->and(Money::toCents('0,05'))->toBe(5)
        ->and(Money::toCents(' '))->toBeNull()
        ->and(Money::toInput(1050))->toBe('10,50')
        ->and(Money::toInput(null))->toBe('')
        ->and(Money::format(123450))->toBe('1.234,50 €');
});

// ── Pflege-Seite ──────────────────────────────────────────────────────────────

it('zeigt die Meldegeld-Seite mit Typen, Abschnitten und Bewerben', function () {
    $meet = makeMeet_p5();
    event_mf1($meet, 1, 1, 1);
    event_mf1($meet, 2, 2, 4)->update(['fee_cents' => 1200]);
    MeetFee::create(['meet_id' => $meet->id, 'session_number' => null, 'type' => MeetFee::TYPE_ATHLETE, 'amount_cents' => 1000]);

    $this->actingAs(admin_mf1())
        ->get(route('meets.fees.edit', $meet))
        ->assertOk()
        ->assertSee('Je Athlet')
        ->assertSee('Nachmeldung je Einzelstart')
        ->assertSee('wird noch nicht berechnet')
        ->assertSee('Abschnitt 2')
        ->assertSee('value="10,00"', false)
        ->assertSee('value="12,00"', false);
});

it('ist nur für Admins erreichbar', function () {
    $meet = makeMeet_p5();
    $clubUser = User::factory()->create(['is_admin' => false, 'club_id' => makeClub_p5()->id]);

    $this->actingAs($clubUser)->get(route('meets.fees.edit', $meet))->assertForbidden();
    $this->actingAs($clubUser)->put(route('meets.fees.update', $meet))->assertForbidden();
});

it('speichert Gebühren je Veranstaltung, je Abschnitt und je Bewerb und entfernt geleerte', function () {
    $meet = makeMeet_p5();
    $single = event_mf1($meet, 1, 1, 1);
    $relay = event_mf1($meet, 2, 2, 4);
    MeetFee::create(['meet_id' => $meet->id, 'session_number' => null, 'type' => MeetFee::TYPE_TEAM, 'amount_cents' => 900]);

    $this->actingAs(admin_mf1())
        ->put(route('meets.fees.update', $meet), [
            'fees' => [
                'meet' => ['CLUB' => '25', 'ATHLETE' => '10,50', 'TEAM' => '', 'LATEENTRY_INDIVIDUAL' => '3'],
                'session' => [2 => ['CLUB' => '5']],
            ],
            'events' => [$single->id => '5', $relay->id => '12,5'],
        ])
        ->assertRedirect(route('meets.show', $meet));

    expect(fee_mf1($meet, null, MeetFee::TYPE_CLUB))->toBe(2500)
        ->and(fee_mf1($meet, null, MeetFee::TYPE_ATHLETE))->toBe(1050)
        ->and(fee_mf1($meet, null, MeetFee::TYPE_LATE_INDIVIDUAL))->toBe(300)
        ->and(fee_mf1($meet, null, MeetFee::TYPE_TEAM))->toBeNull()
        ->and(fee_mf1($meet, 2, MeetFee::TYPE_CLUB))->toBe(500)
        ->and($single->fresh()->fee_cents)->toBe(500)
        ->and($relay->fresh()->fee_cents)->toBe(1250);
});

it('überschreibt mit den Sammelbeträgen alle Einzel- bzw. Staffelbewerbe', function () {
    $meet = makeMeet_p5();
    $single1 = event_mf1($meet, 1, 1, 1);
    $single2 = event_mf1($meet, 1, 2, 1);
    $relay = event_mf1($meet, 1, 3, 4);

    $this->actingAs(admin_mf1())
        ->put(route('meets.fees.update', $meet), [
            'events' => [$single1->id => '99', $single2->id => '', $relay->id => '7'],
            'bulk_individual' => '6',
            'bulk_relay' => '',
        ])
        ->assertRedirect(route('meets.show', $meet));

    expect($single1->fresh()->fee_cents)->toBe(600)
        ->and($single2->fresh()->fee_cents)->toBe(600)
        ->and($relay->fresh()->fee_cents)->toBe(700);
});

it('lehnt ungültige Beträge ab', function () {
    $meet = makeMeet_p5();
    $event = event_mf1($meet, 1, 1, 1);

    $this->actingAs(admin_mf1())
        ->put(route('meets.fees.update', $meet), ['events' => [$event->id => '10,505']])
        ->assertSessionHasErrors(['events.'.$event->id => 'Bitte einen Betrag in Euro angeben, z. B. 10 oder 10,50.']);

    expect($event->fresh()->fee_cents)->toBeNull();
});

// ── LENEX ─────────────────────────────────────────────────────────────────────

it('exportiert Gebühren an MEET, SESSION und EVENT', function () {
    $meet = makeMeet_p5();
    event_mf1($meet, 1, 1, 1)->update(['fee_cents' => 500]);
    event_mf1($meet, 2, 2, 4);
    MeetFee::create(['meet_id' => $meet->id, 'session_number' => null, 'type' => MeetFee::TYPE_ATHLETE, 'amount_cents' => 1000]);
    MeetFee::create(['meet_id' => $meet->id, 'session_number' => 2, 'type' => MeetFee::TYPE_LATE_RELAY, 'amount_cents' => 1500]);

    $meetXml = lenex_mf1($meet)->MEETS->MEET;
    $sessions = $meetXml->SESSIONS->SESSION;

    expect((string) $meetXml->FEES->FEE['type'])->toBe('ATHLETE')
        ->and((string) $meetXml->FEES->FEE['value'])->toBe('1000')
        ->and((string) $meetXml->FEES->FEE['currency'])->toBe('EUR')
        ->and(isset($sessions[0]->FEES))->toBeFalse()
        ->and((string) $sessions[1]->FEES->FEE['type'])->toBe('LATEENTRY.RELAY')
        ->and((string) $sessions[1]->FEES->FEE['value'])->toBe('1500')
        ->and((string) $sessions[0]->EVENTS->EVENT->FEE['value'])->toBe('500')
        ->and(isset($sessions[1]->EVENTS->EVENT->FEE))->toBeFalse();
});

it('übernimmt beim LENEX-Import Gebühren an MEET, SESSION und EVENT', function () {
    $meet = makeMeet_p5();
    makeStrokeType_p5();
    // Vorhandene Bewerbsgebühr bleibt stehen, wenn die Datei für den Bewerb keine FEE enthält.
    event_mf1($meet, 1, 2, 1)->update(['fee_cents' => 800]);

    importLenex_mf1(<<<'XML'
        <LENEX version="3.0">
          <MEETS>
            <MEET name="Testmeet" course="LCM" startdate="2025-06-15">
              <FEES>
                <FEE currency="EUR" type="ATHLETE" value="1000"/>
                <FEE currency="EUR" type="LATEENTRY.INDIVIDUAL" value="300"/>
                <FEE currency="EUR" type="UNBEKANNT" value="100"/>
                <FEE currency="EUR" type="CLUB" value="abc"/>
              </FEES>
              <SESSIONS>
                <SESSION number="1" date="2025-06-15">
                  <FEES>
                    <FEE currency="EUR" type="CLUB" value="2500"/>
                  </FEES>
                  <EVENTS>
                    <EVENT eventid="1" number="1" gender="M" round="TIM">
                      <SWIMSTYLE distance="100" relaycount="1" stroke="FREE"/>
                      <FEE currency="EUR" value="500"/>
                    </EVENT>
                    <EVENT eventid="2" number="2" gender="F" round="TIM">
                      <SWIMSTYLE distance="50" relaycount="1" stroke="FREE"/>
                    </EVENT>
                  </EVENTS>
                </SESSION>
              </SESSIONS>
            </MEET>
          </MEETS>
        </LENEX>
        XML, $meet);

    $events = $meet->swimEvents()->orderBy('event_number')->get();

    expect(fee_mf1($meet, null, MeetFee::TYPE_ATHLETE))->toBe(1000)
        ->and(fee_mf1($meet, null, MeetFee::TYPE_LATE_INDIVIDUAL))->toBe(300)
        ->and(fee_mf1($meet, null, MeetFee::TYPE_CLUB))->toBeNull()
        ->and(MeetFee::where('meet_id', $meet->id)->where('type', 'UNBEKANNT')->exists())->toBeFalse()
        ->and(fee_mf1($meet, 1, MeetFee::TYPE_CLUB))->toBe(2500)
        ->and($events[0]->fee_cents)->toBe(500)
        ->and($events[1]->fee_cents)->toBe(800);
});
