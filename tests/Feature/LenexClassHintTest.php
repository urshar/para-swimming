<?php

use App\Models\Athlete;
use App\Models\AthleteClassification;
use App\Models\Club;
use App\Models\Meet;
use App\Models\User;
use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use App\Services\MeetDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('lenex-class-hints');

// Weicht beim Import einer Ergebnisliste die Klasse eines bestehenden Athleten von seinen Stammdaten ab, bleiben die
// Stammdaten unverändert und die Klasse der Datei landet als Hinweis in der Klassifizierungshistorie.
// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

function xml_lch(Club $club, string $type = 'results'): string
{
    $results = $type === 'results'
        ? '<RESULTS><RESULT eventid="1058" resultid="501" swimtime="00:01:10.00" /></RESULTS>'
        : '<ENTRIES><ENTRY eventid="1058" entrytime="00:01:10.00" /></ENTRIES>';

    return '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="61. Staatsmeisterschaften" city="Dornbirn" nation="AUT" course="SCM">
<SESSIONS><SESSION number="1" date="2012-05-05"><EVENTS>
  <EVENT eventid="1058" number="1"><SWIMSTYLE distance="100" relaycount="1" stroke="FREE" />
    <AGEGROUPS><AGEGROUP agegroupid="1" agemax="-1" agemin="-1" gender="F" handicap="9" /></AGEGROUPS></EVENT>
</EVENTS></SESSION></SESSIONS>
<CLUBS><CLUB name="'.$club->name.'" code="'.$club->code.'" nation="AUT"><ATHLETES>
  <ATHLETE athleteid="1" lastname="Hummel" firstname="Anna" gender="F" birthdate="2000-01-01" license="LIC1">
    <HANDICAP free="9" breast="8" freestatus="CONFIRMED" />'.$results.'</ATHLETE>
</ATHLETES></CLUB></CLUBS></MEET></MEETS></LENEX>';
}

/**
 * @param  int|null  $meetId  erneuter Import in einen bestehenden Wettkampf (wie nach "Wettkampf bestätigen")
 *
 * @throws Exception
 */
function import_lch(Club $club, string $type = 'results', ?int $meetId = null): void
{
    $path = tempnam(sys_get_temp_dir(), 'lenex_lch').'.lef';
    file_put_contents($path, xml_lch($club, $type));

    (new LenexParserService)->import($path, new LenexResolverService, $meetId);
}

function athlete_lch(Club $club, array $sportClasses): Athlete
{
    makeStrokeType_p5();
    $athlete = makeAthlete_p5($club, 'F', $sportClasses);
    $athlete->update(['last_name' => 'Hummel', 'first_name' => 'Anna', 'license' => 'LIC1']);
    $athlete->sportClasses()->update(['classification_status' => 'CONFIRMED', 'classification_scope' => 'INTL']);

    return $athlete;
}

it('hält eine abweichende Klasse als Hinweis in der Historie fest und lässt die Stammdaten unverändert', /** @throws Exception */ function () {
    $club = makeClub_p5();
    $athlete = athlete_lch($club, ['S10', 'SB8']);

    import_lch($club);

    $hint = AthleteClassification::query()->sole();
    $meet = Meet::query()->sole();

    expect($athlete->getSportClass('S')->sport_class)->toBe('S10')
        ->and($athlete->getSportClass('S')->classification_status)->toBe('CONFIRMED')
        ->and($hint->athlete_id)->toBe($athlete->id)
        ->and($hint->meet_id)->toBe($meet->id)
        ->and($hint->classified_at->toDateString())->toBe($meet->start_date->toDateString())
        ->and($hint->location)->toBe('Dornbirn')
        ->and([$hint->result_s, $hint->result_sb, $hint->result_sm])->toBe(['S9', 'SB8', null])
        ->and($hint->classification_status)->toBe('CONFIRMED')
        ->and($hint->classification_scope)->toBe('INTL')
        ->and($hint->med_classifier_id)->toBeNull()
        ->and($hint->notes)->toBe('Klasse laut Ergebnisliste: 61. Staatsmeisterschaften');
});

it('legt keinen Hinweis an, wenn die Klassen übereinstimmen oder nur eine Kategorie fehlt', /** @throws Exception */ function () {
    $club = makeClub_p5();
    $athlete = athlete_lch($club, ['S9']);

    import_lch($club);

    expect(AthleteClassification::query()->count())->toBe(0)
        ->and($athlete->sportClasses()->orderBy('category')->pluck('sport_class')->all())->toBe(['S9', 'SB8']);
});

it('legt bei einem erneuten Import keinen zweiten Hinweis an', /** @throws Exception */ function () {
    $club = makeClub_p5();
    athlete_lch($club, ['S10']);

    import_lch($club);
    import_lch($club, 'results', Meet::query()->sole()->id);

    expect(Meet::query()->count())->toBe(1)
        ->and(AthleteClassification::query()->count())->toBe(1);
});

it('legt aus einer Meldedatei keinen Hinweis an', /** @throws Exception */ function () {
    $club = makeClub_p5();
    athlete_lch($club, ['S10']);

    import_lch($club, 'entries');

    expect(AthleteClassification::query()->count())->toBe(0);
});

it('entfernt den Hinweis mit dem Wettkampf, echte Klassifizierungen bleiben', /** @throws Throwable */ function () {
    $club = makeClub_p5();
    $athlete = athlete_lch($club, ['S10']);
    $athlete->classifications()->create(['classified_at' => '2010-01-01', 'result_s' => 'S10', 'classification_scope' => 'INTL']);

    import_lch($club);
    app(MeetDeletionService::class)->delete(Meet::query()->sole());

    expect(AthleteClassification::query()->count())->toBe(1)
        ->and(AthleteClassification::query()->sole()->meet_id)->toBeNull();
});

it('kennzeichnet den Hinweis in der Historie des Athleten mit Link zum Wettkampf', /** @throws Exception */ function () {
    $club = makeClub_p5();
    $athlete = athlete_lch($club, ['S10']);
    import_lch($club);
    $admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin@lch.at', 'password' => bcrypt('x'), 'is_admin' => true]);

    $this->actingAs($admin)->get(route('athletes.show', $athlete))
        ->assertOk()
        ->assertSee('aus Ergebnisliste')
        ->assertSee(route('meets.show', Meet::query()->sole()), false);
});
