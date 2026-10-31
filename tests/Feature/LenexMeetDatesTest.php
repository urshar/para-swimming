<?php

use App\Models\Meet;
use App\Models\User;
use App\Services\LenexParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class)->group('lenex-meet-dates');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

/**
 * Ergebnisdatei nach dem Muster der WM Madeira 2022: MEET ohne startdate/stopdate, Daten nur an den Sessions.
 * Die Sessions stehen bewusst nicht in zeitlicher Reihenfolge.
 */
function meetXml_lmd(string $meetDates = ''): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="World Para Swimming Championships" city="Funchal" nation="POR" course="LCM"'.$meetDates.'>
<SESSIONS>
  <SESSION number="2" date="2022-06-14"><EVENTS>
    <EVENT eventid="2" number="2" round="FIN"><SWIMSTYLE distance="50" relaycount="1" stroke="FREE" /></EVENT>
  </EVENTS></SESSION>
  <SESSION number="3" date="2022-06-18"><EVENTS>
    <EVENT eventid="3" number="3" round="FIN"><SWIMSTYLE distance="50" relaycount="1" stroke="FREE" /></EVENT>
  </EVENTS></SESSION>
  <SESSION number="1" date="2022-06-12"><EVENTS>
    <EVENT eventid="1" number="1" round="FIN"><SWIMSTYLE distance="50" relaycount="1" stroke="FREE" /></EVENT>
  </EVENTS></SESSION>
</SESSIONS>
<CLUBS><CLUB name="Austria" nation="AUT" type="NATIONALTEAM"><ATHLETES>
  <ATHLETE athleteid="1" firstname="Janina" lastname="Falk" birthdate="2003-01-01" gender="F">
    <RESULTS><RESULT eventid="1" resultid="101" swimtime="00:00:30.00" /></RESULTS>
  </ATHLETE>
</ATHLETES></CLUB></CLUBS>
</MEET></MEETS></LENEX>';
}

/** @throws Exception */
function metaFor_lmd(string $xml): array
{
    $path = tempnam(sys_get_temp_dir(), 'lmd').'.lef';
    file_put_contents($path, $xml);

    try {
        return app(LenexParserService::class)->extractMeetMeta($path);
    } finally {
        unlink($path);
    }
}

// ── Metadaten ─────────────────────────────────────────────────────────────────

it('nimmt ohne startdate/stopdate das früheste und späteste Session-Datum', /** @throws Exception */ function () {
    $meta = metaFor_lmd(meetXml_lmd());

    expect($meta['start_date'])->toBe('2022-06-12')
        ->and($meta['end_date'])->toBe('2022-06-18');
});

it('bevorzugt startdate/stopdate am MEET vor den Session-Daten', /** @throws Exception */ function () {
    $meta = metaFor_lmd(meetXml_lmd(' startdate="2022-06-11" stopdate="2022-06-19"'));

    expect($meta['start_date'])->toBe('2022-06-11')
        ->and($meta['end_date'])->toBe('2022-06-19');
});

// ── Anzeige in der Wettkampf-Auswahl ──────────────────────────────────────────

it('zeigt in der Wettkampf-Auswahl den Zeitraum aus der Datei und den der Kandidaten', function () {
    Storage::fake('local');
    $admin = User::factory()->create(['is_admin' => true, 'club_id' => null]);
    Meet::create([
        'name' => 'World Para Swimming Championships', 'city' => 'Funchal', 'course' => 'LCM',
        'nation_id' => makeNation_p5()->id, 'start_date' => '2022-06-12', 'end_date' => '2022-06-18',
    ]);

    $upload = $this->actingAs($admin)->post(route('lenex.import.store'), [
        'lenex_file' => UploadedFile::fake()->createWithContent('wm.lef', meetXml_lmd()),
    ]);

    $this->actingAs($admin)->get($upload->headers->get('Location'))
        ->assertOk()
        ->assertSee('Erkannt in der LENEX-Datei')
        ->assertSeeInOrder(['Erkannt in der LENEX-Datei', '12.06.2022', '– 18.06.2022'])
        ->assertSee('12.06.2022 – 18.06.2022');
});
