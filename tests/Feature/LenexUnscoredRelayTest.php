<?php

use App\Models\Club;
use App\Models\Meet;
use App\Models\RelayResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class)->group('lenex-unscored-relays');

// Staffeln ohne Klassenangabe (z. B. eine Fun-Staffel, die nicht nach den Para-Swimming-Regeln geschwommen wird)
// werden wie Einzelbewerbe als mögliche Rahmenbewerbe abgefragt; Staffeln mit Klasse oder Punktegruppe nicht.
// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

function admin_lur(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

/** Bewerb 1: Staffel S14, Bewerb 2: Staffel "34 Punkte", Bewerb 3: Fun-Staffel ohne Klassen mit 7-stelligem Code. */
function relayXml_lur(Club $club): string
{
    $relay = fn (int $number, string $eventId, int $resultId) => '<RELAY number="'.$number.'" gender="X"><RESULTS>'
        .'<RESULT eventid="'.$eventId.'" resultid="'.$resultId.'" swimtime="00:03:00.00" /></RESULTS></RELAY>';

    return '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="Staffeltest" city="Wien" nation="AUT" course="SCM" startdate="2014-11-22">
<SESSIONS><SESSION number="1" date="2014-11-22"><EVENTS>
  <EVENT eventid="1085" number="1" round="TIM"><SWIMSTYLE distance="50" relaycount="4" stroke="FREE" />
    <AGEGROUPS><AGEGROUP agegroupid="1" name="S14" /></AGEGROUPS></EVENT>
  <EVENT eventid="2198" number="2" round="TIM"><SWIMSTYLE distance="50" relaycount="4" stroke="FREE" />
    <AGEGROUPS><AGEGROUP agegroupid="2" name="34 Punkte" calculate="TOTAL" /></AGEGROUPS></EVENT>
  <EVENT eventid="1342" number="3" round="TIM">
    <SWIMSTYLE distance="50" relaycount="4" swimstyleid="501" name="4 x 50m Fun Staffel" stroke="UNKNOWN" code="4x50fun" />
    <AGEGROUPS><AGEGROUP agegroupid="3" agemax="-1" agemin="-1" /></AGEGROUPS></EVENT>
</EVENTS></SESSION></SESSIONS>
<CLUBS><CLUB name="'.$club->name.'" code="'.$club->code.'" nation="AUT"><RELAYS>'
        .$relay(1, '1085', 501).$relay(2, '1342', 502).'
</RELAYS></CLUB></CLUBS></MEET></MEETS></LENEX>';
}

function uploadAndRun_lur(User $admin, string $xml): TestResponse
{
    $upload = test()->actingAs($admin)->post(route('lenex.import.store'), [
        'lenex_file' => UploadedFile::fake()->createWithContent('staffeln.lef', $xml),
    ]);
    parse_str((string) parse_url($upload->headers->get('Location'), PHP_URL_QUERY), $query);

    return test()->actingAs($admin)->post(route('lenex.import.run'), ['import_session' => $query['session'], 'meet_id' => '']);
}

it('fragt eine Staffel ohne Klassen als Rahmenbewerb ab und importiert ihre Ergebnisse nicht', function () {
    Storage::fake('local');
    makeStrokeType_p5();
    makeStrokeType_p5('UNKNOWN');
    $admin = admin_lur();
    $club = makeClub_p5();

    $run = uploadAndRun_lur($admin, relayXml_lur($club));
    parse_str((string) parse_url($run->headers->get('Location'), PHP_URL_QUERY), $query);

    $this->get($run->headers->get('Location'))
        ->assertOk()
        ->assertSee('Bewerbe ohne Wertungsklassen (1)')
        ->assertSee('Bewerb 3 – 4×50 m UNKNOWN (4 x 50m Fun Staffel)')
        ->assertDontSee('Bewerb 1 –')
        ->assertDontSee('Bewerb 2 –');

    $this->actingAs($admin)->post(route('lenex.import.resolve-events'), [
        'import_session' => $query['session'],
        'unscored_events' => ['3'],
    ])->assertRedirect(route('meets.index'));

    $fun = Meet::sole()->swimEvents()->where('event_number', 3)->sole();

    expect($fun->is_scored)->toBeFalse()
        ->and($fun->style_code)->toBe('4x50fun')
        ->and(Meet::sole()->swimEvents()->where('is_scored', true)->pluck('event_number')->sort()->values()->all())->toBe([1, 2])
        ->and(RelayResult::query()->with('swimEvent')->get()->pluck('swimEvent.event_number')->all())->toBe([1]);
});

it('nimmt im Disziplin-Formular einen Stilcode mit mehr als sechs Zeichen an', /** @throws JsonException */ function () {
    $meet = Meet::query()->create(['name' => 'Staffeltest', 'start_date' => '2014-11-22', 'course' => 'SCM', 'city' => 'Wien',
        'nation_id' => makeNation_p5()->id]);

    $this->actingAs(admin_lur())->post(route('meets.events.store', $meet), [
        'stroke_type_id' => makeStrokeType_p5('UNKNOWN')->id, 'session_number' => 2, 'gender' => 'X', 'round' => 'TIM',
        'distance' => 50, 'relay_count' => 4, 'style_code' => '4x50fun', 'style_name' => '4 x 50m Fun Staffel', 'is_scored' => '0',
    ])->assertSessionHasNoErrors();

    expect($meet->swimEvents()->sole()->style_code)->toBe('4x50fun');
});
