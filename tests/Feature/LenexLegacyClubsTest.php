<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Nation;
use App\Models\RelayResult;
use App\Models\Result;
use App\Models\User;
use App\Services\LenexResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class)->group('lenex-2-p4');

// LENEX 2.0 (docs/specs/lenex-2.0-import.md, Phase 4): Vereine ohne clubid und code, Nationalteam "NPC Austria" mit
// Staffel über die Klärungsseite, Rückmeldung mit Format. Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

function athlete_l2p4(Club $club, string $first, string $last, string $gender, string $birthDate): Athlete
{
    return Athlete::create([
        'first_name' => $first, 'last_name' => $last, 'gender' => $gender, 'birth_date' => $birthDate,
        'nation_id' => makeNation_p5()->id, 'club_id' => $club->id,
    ]);
}

it('unterscheidet Vereine ohne clubid und code nach dem Namen', /** @throws Exception */ function () {
    $nation = makeNation_p5();
    $known = Club::create(['name' => 'Bekannter Verein', 'nation_id' => $nation->id]);
    $resolver = new LenexResolverService;

    $first = $resolver->resolveClub(new SimpleXMLElement('<CLUB name="Bekannter Verein" nation="AUT" />'), $nation->id);
    $second = $resolver->resolveClub(new SimpleXMLElement('<CLUB name="Unbekannter Verein" nation="AUT" />'), $nation->id);

    expect($first?->id)->toBe($known->id)
        ->and($second)->toBeNull()
        ->and(array_column($resolver->getUnresolvedClubs(), 'name'))->toBe(['Unbekannter Verein'])
        ->and($resolver->getUnresolvedClubs()[0]['cache_key'])->toBe('name:'.$nation->id.':unbekannter verein');
});

it('klärt ein Nationalteam mit Staffel und meldet das LENEX-2.0-Format', function () {
    Storage::fake('local');
    foreach (['FREE', 'BACK', 'BREAST', 'FLY', 'MEDLEY'] as $stroke) {
        makeStrokeType_p5($stroke);
    }
    Nation::create(['code' => 'GER', 'name_de' => 'Deutschland', 'name_en' => 'Germany', 'is_active' => true]);
    $admin = User::factory()->create(['is_admin' => true, 'club_id' => null]);
    $home = makeClub_p5();
    $federation = Club::create(['name' => 'ÖBSV', 'nation_id' => makeNation_p5()->id, 'type' => Club::TYPE_VERBAND]);
    $muster = athlete_l2p4($home, 'Max', 'Muster', 'M', '1975-01-01');
    athlete_l2p4($home, 'Jörg', 'Beispiel', 'M', '2002-01-01');
    athlete_l2p4($home, 'Lena', 'Probe', 'F', '1990-01-01');
    athlete_l2p4($home, 'Sara', 'Versuch', 'F', '1994-01-01');

    $upload = test()->actingAs($admin)->post(route('lenex.import.store'), [
        'lenex_file' => UploadedFile::fake()->createWithContent('idm.lef', file_get_contents(base_path('tests/Fixtures/lenex/sem2004-results.lef'))),
    ]);
    preg_match('/name="import_session" value="([^"]+)"/', test()->actingAs($admin)->get($upload->headers->get('Location'))->getContent(), $m);

    // Nationenfilter: "NPC Austria" ist unbekannt und hat eine Staffel → kommt zur Klärung.
    $run = test()->actingAs($admin)->post(route('lenex.import.run'), [
        'import_session' => $m[1], 'meet_id' => '', 'only_nation' => 'AUT', 'meet_nation' => 'GER',
    ]);
    $run->assertRedirectContains('/lenex/import/review');
    $reviewKey = collect(explode('session=', $run->headers->get('Location')))->last();
    expect(array_column(session($reviewKey)['unresolved_clubs'], 'name'))->toBe(['NPC Austria']);

    test()->actingAs($admin)->post(route('lenex.import.resolve-clubs'), [
        'import_session' => $reviewKey,
        'clubs' => [['selection' => (string) $federation->id]],
    ])->assertSessionHas('success', fn (string $message) => str_starts_with($message, 'LENEX 2.0 (sem2004), results importiert:')
        && str_contains($message, '7 Ergebnisse')
        && str_contains($message, '7 Ergebnis(se) unter einem Verband dem Heimverein des Athleten zugeordnet')
        && str_contains($message, '10 weitere Platzierung(en) in anderen Wertungsgruppen zusammengefasst'));

    expect(Result::where('club_id', $home->id)->count())->toBe(7)
        ->and(Result::where('athlete_id', $muster->id)->where('lenex_result_id', 'a365-e1')->value('place'))->toBe(7)
        ->and(RelayResult::sole()->club_id)->toBe($federation->id)
        ->and(RelayResult::sole()->place)->toBe(3);
});
