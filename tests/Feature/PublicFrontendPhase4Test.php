<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Services\Public\PublicResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('public-p4');

function makeNation_p4(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria']);
}

function makeMeet_p4(array $overrides = []): Meet
{
    return Meet::create(array_merge([
        'name' => 'Testwettkampf',
        'city' => 'Wien',
        'nation_id' => makeNation_p4()->id,
        'course' => 'LCM',
        'start_date' => now()->subDays(10)->toDateString(),
        'is_published' => true,
    ], $overrides));
}

function makeClub_p4(array $overrides = []): Club
{
    return Club::create(array_merge([
        'name' => 'Testverein',
        'nation_id' => makeNation_p4()->id,
    ], $overrides));
}

function makeAthlete_p4(array $overrides = []): Athlete
{
    return Athlete::create(array_merge([
        'nation_id' => makeNation_p4()->id,
        'club_id' => makeClub_p4()->id,
        'first_name' => 'Max',
        'last_name' => 'Mustermann',
        'birth_date' => '2000-05-01',
    ], $overrides));
}

function makeStrokeType_p4(): StrokeType
{
    return StrokeType::firstOrCreate(['lenex_code' => 'FREE'], [
        'code' => 'FREE',
        'name_de' => 'Freistil',
        'name_en' => 'Freestyle',
    ]);
}

function makeSwimEvent_p4(Meet $meet, array $overrides = []): SwimEvent
{
    return SwimEvent::create(array_merge([
        'meet_id' => $meet->id,
        'stroke_type_id' => makeStrokeType_p4()->id,
        'session_number' => 1,
        'event_number' => 1,
        'distance' => 100,
    ], $overrides));
}

function makeResult_p4(Meet $meet, SwimEvent $swimEvent, array $overrides = []): Result
{
    return Result::create(array_merge([
        'meet_id' => $meet->id,
        'swim_event_id' => $swimEvent->id,
        'athlete_id' => makeAthlete_p4()->id,
        'club_id' => makeClub_p4()->id,
        'swim_time' => 6000,
        'place' => 1,
    ], $overrides));
}

// ── Sichtbarkeit ──────────────────────────────────────────────────────────────

it('liefert 404 für die Ergebnisseite eines unveröffentlichten Meets', function () {
    $meet = makeMeet_p4(['is_published' => false]);

    $this->get(route('public.meets.results', ['locale' => 'de', 'meet' => $meet]))
        ->assertNotFound();
});

it('zeigt die Ergebnisseite eines veröffentlichten Meets ohne Ergebnisse leer an', function () {
    $meet = makeMeet_p4();

    $this->get(route('public.meets.results', ['locale' => 'de', 'meet' => $meet]))
        ->assertOk()
        ->assertSee('Für diese Veranstaltung sind noch keine Ergebnisse veröffentlicht.');
});

it('markiert die Ergebnisseite als noindex,nofollow', function () {
    $meet = makeMeet_p4();
    $swimEvent = makeSwimEvent_p4($meet);
    makeResult_p4($meet, $swimEvent);

    $this->get(route('public.meets.results', ['locale' => 'de', 'meet' => $meet]))
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
});

it('sperrt die Ergebnisseite in robots.txt', function () {
    expect(file_get_contents(public_path('robots.txt')))
        ->toContain('Disallow: /*/veranstaltungen/*/ergebnisse');
});

it('zeigt den Link zu den Ergebnissen auf der Detailseite nur, wenn Ergebnisse vorliegen', function () {
    $meetOhne = makeMeet_p4(['name' => 'Ohne Ergebnisse']);
    $meetMit = makeMeet_p4(['name' => 'Mit Ergebnissen']);
    $swimEvent = makeSwimEvent_p4($meetMit);
    makeResult_p4($meetMit, $swimEvent);

    $this->get(route('public.meets.show', ['locale' => 'de', 'meet' => $meetOhne]))
        ->assertOk()
        ->assertDontSee('Ergebnisse ansehen');

    $this->get(route('public.meets.show', ['locale' => 'de', 'meet' => $meetMit]))
        ->assertOk()
        ->assertSee('Ergebnisse ansehen')
        ->assertSee(route('public.meets.results', ['locale' => 'de', 'meet' => $meetMit]), false);
});

// ── Name/Verein bewusst unverlinkt (Spec §2.3) ──────────────────────────────────

it('verlinkt Athlet- und Vereinsname nirgends', function () {
    $meet = makeMeet_p4();
    $swimEvent = makeSwimEvent_p4($meet);
    $athlete = makeAthlete_p4(['first_name' => 'Erika', 'last_name' => 'Musterfrau']);
    $club = makeClub_p4(['name' => 'Verlinkungsfreier Verein']);
    makeResult_p4($meet, $swimEvent, ['athlete_id' => $athlete->id, 'club_id' => $club->id]);

    $content = $this->get(route('public.meets.results', ['locale' => 'de', 'meet' => $meet]))
        ->assertOk()
        ->assertSee($athlete->full_name)
        ->assertSee($club->name)
        ->getContent();

    preg_match_all('/<a\b[^>]*>(.*?)<\/a>/is', $content, $matches);
    $linkedTexts = implode(' ', $matches[1] ?? []);

    expect($linkedTexts)->not->toContain($athlete->full_name)
        ->and($linkedTexts)->not->toContain($club->name);
});

// ── PublicResultService: Gruppierung und Sortierung ─────────────────────────────

it('gruppiert Ergebnisse nach Bewerb und Sportklasse', function () {
    $meet = makeMeet_p4();
    $swimEvent = makeSwimEvent_p4($meet);
    makeResult_p4($meet, $swimEvent, ['sport_class' => 'S4', 'place' => 1]);
    makeResult_p4($meet, $swimEvent, ['sport_class' => 'S5', 'place' => 1]);

    $groups = (new PublicResultService)->forMeet($meet);

    expect($groups)->toHaveCount(1)
        ->and($groups->first()->classes->keys()->all())->toEqualCanonicalizing(['S4', 'S5']);
});

it('sortiert gültige Ergebnisse nach Platz und schiebt DNS/DNF/DSQ ans Ende, EXH bleibt sichtbar', function () {
    $meet = makeMeet_p4();
    $swimEvent = makeSwimEvent_p4($meet);

    $dsq = makeResult_p4($meet, $swimEvent, ['place' => null, 'swim_time' => null, 'status' => 'DSQ']);
    $second = makeResult_p4($meet, $swimEvent, ['place' => 2, 'swim_time' => 6200]);
    $exh = makeResult_p4($meet, $swimEvent, ['place' => null, 'swim_time' => 5900, 'status' => 'EXH']);
    $first = makeResult_p4($meet, $swimEvent, ['place' => 1, 'swim_time' => 6000]);

    $groups = (new PublicResultService)->forMeet($meet);
    $ordered = $groups->first()->classes->first();

    expect($ordered->pluck('id')->all())->toBe([$first->id, $second->id, $exh->id, $dsq->id]);
});

it('zeigt Punkte nur an, wenn für die Ergebnisse dieser Klasse tatsächlich welche berechnet wurden', function () {
    $meet = makeMeet_p4();
    $swimEvent = makeSwimEvent_p4($meet);
    makeResult_p4($meet, $swimEvent, ['points' => 850]);

    $this->get(route('public.meets.results', ['locale' => 'de', 'meet' => $meet]))
        ->assertOk()
        ->assertSee('850')
        ->assertDontSee('WPS-Punkte');
});

it('zeigt die reelle Zeit bei EXH-Ergebnissen statt des Status', function () {
    $meet = makeMeet_p4();
    $swimEvent = makeSwimEvent_p4($meet);
    makeResult_p4($meet, $swimEvent, ['status' => 'EXH', 'swim_time' => 6543, 'place' => null]);

    $this->get(route('public.meets.results', ['locale' => 'de', 'meet' => $meet]))
        ->assertOk()
        ->assertDontSee('Ausstellungsstart');
});

it('zeigt den lokalisierten Status, wenn keine Zeit erfasst wurde', function () {
    $meet = makeMeet_p4();
    $swimEvent = makeSwimEvent_p4($meet);
    makeResult_p4($meet, $swimEvent, ['status' => 'DNS', 'swim_time' => null, 'place' => null]);

    $this->get(route('public.meets.results', ['locale' => 'de', 'meet' => $meet]))
        ->assertOk()
        ->assertSee('Nicht angetreten');
});
