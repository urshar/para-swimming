<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\ForeignStart;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\User;
use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class)->group('foreign-starts-p1');

// Gäste (Vereine außerhalb Österreichs): Starts nur in foreign_starts, ohne Vereins- und Athletendatensatz.
// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

/** Österreichischer Verein mit bekannter Schwimmerin (Lizenz LIC1). */
function homeClub_lfs(): Club
{
    makeStrokeType_p5();
    $club = makeClub_p5();
    $athlete = makeAthlete_p5($club, 'F');
    $athlete->update(['last_name' => 'Hummel', 'first_name' => 'Anna', 'license' => 'LIC1']);

    return $club;
}

/**
 * Ergebnisdatei einer österreichischen Veranstaltung mit einem Schweizer Gastverein: zwei Schwimmerinnen (eine davon
 * Österreicherin — es zählt der Verein), eine Schwimmerin mit zwei Starts, eine Staffel. Im österreichischen Verein
 * startet zusätzlich eine Deutsche, die regulär importiert wird.
 */
function resultXml_lfs(Club $club): string
{
    $result = fn (string $event, int $id, string $time) => '<RESULT eventid="'.$event.'" resultid="'.$id.'" swimtime="'.$time.'" />';

    return '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="Staatsmeisterschaften" city="Dornbirn" nation="AUT" course="SCM">
<SESSIONS><SESSION number="1" date="2026-05-10"><EVENTS>
  <EVENT eventid="1" number="1" round="TIM"><SWIMSTYLE distance="50" relaycount="1" stroke="FREE" />
    <AGEGROUPS><AGEGROUP agegroupid="10" agemax="-1" agemin="-1" gender="F" handicap="9"><RANKINGS>
      <RANKING order="1" place="1" resultid="502" /><RANKING order="2" place="2" resultid="501" />
    </RANKINGS></AGEGROUP></AGEGROUPS></EVENT>
  <EVENT eventid="2" number="2" round="TIM"><SWIMSTYLE distance="100" relaycount="1" stroke="FREE" /></EVENT>
  <EVENT eventid="3" number="3" round="TIM"><SWIMSTYLE distance="50" relaycount="4" stroke="FREE" /></EVENT>
</EVENTS></SESSION></SESSIONS>
<CLUBS>
  <CLUB name="'.$club->name.'" code="'.$club->code.'" nation="AUT"><ATHLETES>
    <ATHLETE athleteid="1" lastname="Hummel" firstname="Anna" gender="F" birthdate="2000-01-01" license="LIC1">
      <HANDICAP free="9" /><RESULTS>'.$result('1', 501, '00:00:33.00').'</RESULTS></ATHLETE>
  </ATHLETES></CLUB>
  <CLUB name="Swiss Paralympic Club" code="SPC" nation="SUI"><ATHLETES>
    <ATHLETE athleteid="2" lastname="Muster" firstname="Heidi" gender="F" birthdate="1999-03-04">
      <HANDICAP free="9" /><RESULTS>'.$result('1', 502, '00:00:32.00').$result('2', 503, '00:01:10.00').'</RESULTS></ATHLETE>
    <ATHLETE athleteid="3" lastname="Austria" firstname="Eva" gender="F" birthdate="2001-01-01" nation="AUT">
      <HANDICAP free="8" /><RESULTS><RESULT eventid="1" resultid="504" swimtime="00:00:40.00" status="DSQ" /></RESULTS></ATHLETE>
  </ATHLETES>
  <RELAYS><RELAY number="1" gender="F" handicap="34"><RESULTS>'.$result('3', 505, '00:02:40.00').'</RESULTS></RELAY></RELAYS>
  </CLUB>
</CLUBS></MEET></MEETS></LENEX>';
}

/** LENEX-Import ohne Nationenfilter; liefert die Statistik (Exception → RuntimeException). */
function import_lfs(string $xml, ?int $meetId): array
{
    $path = tempnam(sys_get_temp_dir(), 'lenex_lfs').'.lef';
    file_put_contents($path, $xml);

    try {
        return (new LenexParserService)->import($path, new LenexResolverService, $meetId)['stats'];
    } catch (Exception $e) {
        throw new RuntimeException('LENEX-Import fehlgeschlagen: '.$e->getMessage(), previous: $e);
    } finally {
        unlink($path);
    }
}

beforeEach(function () {
    makeNation_p5();
    Nation::firstOrCreate(['code' => 'SUI'], ['name_de' => 'Schweiz', 'name_en' => 'Switzerland', 'is_active' => true]);
});

it('speichert die Starts eines Gastvereins nur als foreign_starts, ohne Verein und Athleten', function () {
    $club = homeClub_lfs();

    $stats = import_lfs(resultXml_lfs($club), null);

    $individual = ForeignStart::where('is_relay', false)->orderBy('lenex_result_id')->get();
    $relay = ForeignStart::where('is_relay', true)->sole();

    expect(Result::count())->toBe(1)
        ->and(Club::count())->toBe(1)
        ->and(Athlete::count())->toBe(1)
        ->and(Meet::sole()->clubs()->pluck('clubs.id')->all())->toBe([$club->id])
        ->and($stats['foreign_clubs'])->toBe(1)
        ->and($stats['foreign_starts'])->toBe(4)
        ->and($individual)->toHaveCount(3)
        ->and($individual[0]->club_name)->toBe('Swiss Paralympic Club')
        ->and($individual[0]->club_code)->toBe('SPC')
        ->and($individual[0]->nation->code)->toBe('SUI')
        ->and($individual[0]->person_key)->toBe('muster|heidi|1999-03-04')
        ->and($individual[0]->sport_class)->toBe('S9')
        ->and($individual[0]->swim_time)->toBe(3200)
        ->and($individual[0]->place)->toBe(1)
        ->and($individual[2]->last_name)->toBe('Austria')
        ->and($individual[2]->status)->toBe('DSQ')
        ->and($relay->gender)->toBe('F')
        ->and($relay->sport_class)->toBe('S34')
        ->and($relay->person_key)->toBeNull();
});

it('ersetzt die Gaststarts bei einem erneuten Import, statt sie zu verdoppeln', function () {
    $club = homeClub_lfs();

    import_lfs(resultXml_lfs($club), null);
    import_lfs(resultXml_lfs($club), Meet::sole()->id);

    expect(ForeignStart::count())->toBe(4)
        ->and(Result::count())->toBe(1);
});

it('übernimmt Gäste aus einer Meldedatei gar nicht', function () {
    $club = homeClub_lfs();
    $entries = str_replace(
        ['<RESULTS>', '</RESULTS>', '<RESULT ', 'swimtime='],
        ['<ENTRIES>', '</ENTRIES>', '<ENTRY ', 'entrytime='],
        resultXml_lfs($club),
    );

    import_lfs($entries, null);

    expect(ForeignStart::count())->toBe(0)
        ->and(Club::count())->toBe(1)
        ->and(Athlete::count())->toBe(1);
});

it('wählt auf der Bestätigungsseite für eine Veranstaltung in Österreich "Alle Nationen" vor', function () {
    Storage::fake('local');
    $admin = User::factory()->create(['is_admin' => true, 'club_id' => null]);
    $upload = $this->actingAs($admin)->post(route('lenex.import.store'), [
        'lenex_file' => UploadedFile::fake()->createWithContent('staats.lef', resultXml_lfs(homeClub_lfs())),
    ]);

    $html = $this->actingAs($admin)->get($upload->headers->get('Location'))
        ->assertOk()
        ->assertSee('als Gäste')
        ->getContent();

    // Öffnendes Tag der Option je Wert; die Vorauswahl steht als selected-Attribut darin.
    preg_match_all('/<ui-option\b[^>]*>/', $html, $tags);
    $option = fn (string $value) => collect($tags[0])->first(fn (string $tag) => str_contains($tag, 'value="'.$value.'"'));

    expect($option('ALL'))->toContain('selected')
        ->and($option('AUT'))->not->toContain('selected');
});
