<?php

use App\Models\Club;
use App\Models\Meet;
use App\Models\User;
use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class)->group('lenex-club-review-filter');

// Ein unbekannter Verein kommt nur auf die Klärungsseite, wenn aus ihm etwas Gewertetes käme: ein Ergebnis eines
// Schwimmers oder einer Staffel in einem gewerteten Bewerb, oder ein Schwimmer, der in einer gewerteten Staffel startet.
// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

/**
 * Bewerb 1: Staffel S14 (gewertet), Bewerb 2: 50 m Freistil S9 (gewertet), Bewerb 3: Fun-Staffel ohne Klassen.
 *
 * @param  string  $unattachedEvent  Bewerb des Ergebnisses der vereinslosen Schwimmerin
 */
function xml_lcrf(string $unattachedEvent = '2'): string
{
    $officials = '<OFFICIALS><OFFICIAL officialid="900" firstname="Karla" lastname="Kampf" /></OFFICIALS>';

    return '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="Klärungsfilter" city="Wien" nation="AUT" course="SCM" startdate="2014-11-22">
<SESSIONS><SESSION number="1" date="2014-11-22"><EVENTS>
  <EVENT eventid="11" number="1" round="TIM"><SWIMSTYLE distance="50" relaycount="4" stroke="FREE" />
    <AGEGROUPS><AGEGROUP agegroupid="1" name="S14" /></AGEGROUPS></EVENT>
  <EVENT eventid="22" number="2" round="TIM"><SWIMSTYLE distance="50" relaycount="1" stroke="FREE" />
    <AGEGROUPS><AGEGROUP agegroupid="2" gender="F" handicap="9" /></AGEGROUPS></EVENT>
  <EVENT eventid="33" number="3" round="TIM">
    <SWIMSTYLE distance="50" relaycount="4" name="4 x 50m Fun Staffel" stroke="UNKNOWN" code="4x50fun" />
    <AGEGROUPS><AGEGROUP agegroupid="3" /></AGEGROUPS></EVENT>
</EVENTS></SESSION></SESSIONS>
<CLUBS>
  <CLUB type="REGION" code="LV" name="Landesverband" nation="AUT">'.$officials.'</CLUB>
  <CLUB code="KV" name="Bekannter Verein" nation="AUT">'.$officials.'</CLUB>
  <CLUB code="FUN" name="Fun" nation="AUT"><RELAYS><RELAY number="1" gender="X"><RESULTS>
    <RESULT eventid="33" resultid="301" swimtime="00:03:09.16"><RELAYPOSITIONS>
      <RELAYPOSITION athleteid="40" number="1" /></RELAYPOSITIONS></RESULT>
  </RESULTS></RELAY></RELAYS></CLUB>
  <CLUB name="Startgemeinschaft" nation="AUT"><RELAYS><RELAY number="1" gender="M"><RESULTS>
    <RESULT eventid="11" resultid="101" swimtime="00:02:34.29"><RELAYPOSITIONS>
      <RELAYPOSITION athleteid="10" number="1" /></RELAYPOSITIONS></RESULT>
  </RESULTS></RELAY></RELAYS></CLUB>
  <CLUB code="HV" name="Heimverein" nation="AUT"><ATHLETES>
    <ATHLETE athleteid="10" firstname="Hans" lastname="Heim" gender="M" birthdate="2000-01-01" />
  </ATHLETES></CLUB>
  <CLUB type="UNATTACHED"><ATHLETES>
    <ATHLETE athleteid="30" firstname="Vera" lastname="Vereinslos" gender="F" birthdate="2001-01-01"><RESULTS>
      <RESULT eventid="'.($unattachedEvent === '3' ? '33' : '22').'" resultid="201" swimtime="00:00:40.00" />
    </RESULTS></ATHLETE>
    <ATHLETE athleteid="40" firstname="Fanny" lastname="Fun" gender="F" />
  </ATHLETES></CLUB>
</CLUBS></MEET></MEETS></LENEX>';
}

/** @throws Exception */
function import_lcrf(string $xml): LenexResolverService
{
    makeStrokeType_p5();
    makeStrokeType_p5('UNKNOWN');
    $path = tempnam(sys_get_temp_dir(), 'lenex_lcrf').'.lef';
    file_put_contents($path, $xml);
    $resolver = new LenexResolverService;

    (new LenexParserService)->import($path, $resolver, null, ['unscored_events' => [3]]);

    return $resolver;
}

it('lässt Vereine ohne gewerteten Inhalt weg und behält Startgemeinschaft, Heimverein und Vereinslose', /** @throws Exception */ function () {
    makeNation_p5();

    $unresolved = import_lcrf(xml_lcrf())->getUnresolvedClubs();

    expect(array_column($unresolved, 'name'))->toBe(['Startgemeinschaft', 'Heimverein', ''])
        ->and(array_column($unresolved, 'unattached'))->toBe([false, false, true]);
});

it('lässt Vereinslose weg, die nur in Rahmenbewerben starten', /** @throws Exception */ function () {
    makeNation_p5();

    $unresolved = import_lcrf(xml_lcrf('3'))->getUnresolvedClubs();

    expect(array_column($unresolved, 'name'))->toBe(['Startgemeinschaft', 'Heimverein']);
});

it('ordnet einen bekannten Verein ohne gewerteten Inhalt weiterhin dem Wettkampf zu', /** @throws Exception */ function () {
    $known = Club::query()->create(['name' => 'Bekannter Verein', 'code' => 'KV', 'nation_id' => makeNation_p5()->id, 'type' => 'CLUB']);

    import_lcrf(xml_lcrf());

    expect(Meet::sole()->clubs()->pluck('clubs.id')->all())->toBe([$known->id]);
});

it('bietet für Vereinslose kein "Neu anlegen" an und legt auch auf Anfrage keinen namenlosen Verein an', /** @throws JsonException */ function () {
    Storage::fake('local');
    makeNation_p5();
    makeStrokeType_p5();
    makeStrokeType_p5('UNKNOWN');
    $admin = User::factory()->create(['is_admin' => true, 'club_id' => null]);

    $upload = $this->actingAs($admin)->post(route('lenex.import.store'), [
        'lenex_file' => UploadedFile::fake()->createWithContent('klaerung.lef', xml_lcrf()),
    ]);
    parse_str((string) parse_url($upload->headers->get('Location'), PHP_URL_QUERY), $query);
    $events = $this->actingAs($admin)->post(route('lenex.import.run'), ['import_session' => $query['session'], 'meet_id' => '']);
    parse_str((string) parse_url($events->headers->get('Location'), PHP_URL_QUERY), $query);
    $clubs = $this->actingAs($admin)->post(route('lenex.import.resolve-events'), [
        'import_session' => $query['session'], 'unscored_events' => ['3'],
    ]);
    parse_str((string) parse_url($clubs->headers->get('Location'), PHP_URL_QUERY), $query);

    $page = $this->get($clubs->headers->get('Location'))->assertOk()
        ->assertSee('Unbekannte Vereine (3)')
        ->assertSee('Vereinslose Schwimmer (ohne Verein in der Datei)');
    expect(substr_count($page->getContent(), 'value="new"'))->toBe(2);

    $this->actingAs($admin)->post(route('lenex.import.resolve-clubs'), [
        'import_session' => $query['session'],
        'clubs' => [['selection' => 'new'], ['selection' => 'new'], ['selection' => 'new']],
    ])->assertSessionHasNoErrors();

    expect(Club::query()->orderBy('name')->pluck('name')->all())->toBe(['Heimverein', 'Startgemeinschaft']);
});
