<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('public-p9');

function makeNation_p9(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria']);
}

function makeMeet_p9(array $overrides = []): Meet
{
    return Meet::create(array_merge([
        'name' => 'Testwettkampf',
        'city' => 'Wien',
        'nation_id' => makeNation_p9()->id,
        'start_date' => now()->addDays(10)->toDateString(),
        'is_published' => true,
    ], $overrides));
}

function makeClub_p9(array $overrides = []): Club
{
    return Club::create(array_merge([
        'name' => 'Testverein',
        'nation_id' => makeNation_p9()->id,
    ], $overrides));
}

function makeAthlete_p9(array $overrides = []): Athlete
{
    return Athlete::create(array_merge([
        'nation_id' => makeNation_p9()->id,
        'club_id' => makeClub_p9()->id,
        'first_name' => 'Max',
        'last_name' => 'Mustermann',
        'birth_date' => '2000-05-01',
    ], $overrides));
}

function makeStrokeType_p9(): StrokeType
{
    return StrokeType::firstOrCreate(['lenex_code' => 'FREE'], [
        'code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle',
    ]);
}

function makeRecord_p9(array $overrides = []): SwimRecord
{
    return SwimRecord::create(array_merge([
        'stroke_type_id' => makeStrokeType_p9()->id,
        'athlete_id' => makeAthlete_p9()->id,
        'club_id' => makeClub_p9()->id,
        'record_type' => 'AUT',
        'sport_class' => 'S6',
        'gender' => 'M',
        'course' => 'LCM',
        'distance' => 50,
        'relay_count' => 1,
        'swim_time' => 6000,
        'record_status' => 'APPROVED',
        'is_current' => true,
        'set_date' => '2025-01-01',
    ], $overrides));
}

function makeSwimEventWithResult_p9(Meet $meet, array $eventOverrides = [], array $resultOverrides = []): SwimEvent
{
    $swimEvent = SwimEvent::create(array_merge([
        'meet_id' => $meet->id,
        'stroke_type_id' => makeStrokeType_p9()->id,
        'distance' => 50,
        'gender' => 'M',
        'session_number' => 1,
        'event_number' => 1,
        'relay_count' => 1,
    ], $eventOverrides));

    Result::create(array_merge([
        'meet_id' => $meet->id,
        'swim_event_id' => $swimEvent->id,
        'athlete_id' => makeAthlete_p9()->id,
        'club_id' => makeClub_p9()->id,
        'sport_class' => 'S6',
        'swim_time' => 6000,
        'status' => 'OK',
    ], $resultOverrides));

    return $swimEvent;
}

// ── Startseite ───────────────────────────────────────────────────────────────

it('zeigt Leerzustände auf der Startseite ohne Veranstaltungen und Rekorde', function () {
    $this->get(route('public.home', ['locale' => 'de']))
        ->assertOk()
        ->assertSee('Derzeit ist keine kommende Veranstaltung veröffentlicht.')
        ->assertSee('Derzeit liegen keine österreichischen Rekorde vor.')
        ->assertSee('Für die letzten Veranstaltungen sind noch keine Ergebnisse veröffentlicht.');
});

it('zeigt die nächste veröffentlichte Veranstaltung', function () {
    makeMeet_p9(['name' => 'Kommender Wettkampf', 'start_date' => now()->addDays(5)->toDateString()]);
    makeMeet_p9(['name' => 'Unveröffentlicht', 'start_date' => now()->addDay()->toDateString(), 'is_published' => false]);

    $this->get(route('public.home', ['locale' => 'de']))
        ->assertOk()
        ->assertSee('Kommender Wettkampf')
        ->assertDontSee('Unveröffentlicht');
});

it('zeigt die zuletzt aufgestellten nationalen Rekorde, keine Landesverbandsrekorde', function () {
    makeRecord_p9(['sport_class' => 'S6', 'set_date' => '2025-01-01']);
    makeRecord_p9(['sport_class' => 'S7', 'set_date' => '2026-01-01', 'record_type' => 'AUT.WBSV']);

    $this->get(route('public.home', ['locale' => 'de']))
        ->assertOk()
        ->assertSee('S6')
        ->assertDontSee('S7');
});

it('zeigt die letzte vergangene Veranstaltung mit Ergebnissen, nicht zwingend die chronologisch letzte', function () {
    $withoutResults = makeMeet_p9(['name' => 'Ohne Ergebnisse', 'start_date' => now()->subDay()->toDateString()]);
    $withResults = makeMeet_p9(['name' => 'Mit Ergebnissen', 'start_date' => now()->subDays(5)->toDateString()]);
    makeSwimEventWithResult_p9($withResults);

    $this->get(route('public.home', ['locale' => 'de']))
        ->assertOk()
        ->assertSee('Mit Ergebnissen')
        ->assertDontSee('Ohne Ergebnisse');

    expect($withoutResults->name)->toBe('Ohne Ergebnisse');
});

// ── Erklärung zur Barrierefreiheit ─────────────────────────────────────────────

it('zeigt die Erklärung zur Barrierefreiheit mit Kontaktadresse', function () {
    $this->get(route('public.accessibility-statement.index', ['locale' => 'de']))
        ->assertOk()
        ->assertSee('Erklärung zur Barrierefreiheit')
        ->assertSee('schwimmen@obsv.at');
});

// ── Impressum & Datenschutzerklärung (Entwürfe mit Platzhaltern) ──────────────

it('zeigt das Impressum als Entwurf mit Platzhaltern, noindex', function () {
    $this->get(route('public.imprint.index', ['locale' => 'de']))
        ->assertOk()
        ->assertSee('Entwurf — noch nicht rechtsgültig')
        ->assertSee('[vollständiger Vereinsname]')
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
});

it('zeigt die Datenschutzerklärung als Entwurf mit Platzhaltern, noindex', function () {
    $this->get(route('public.privacy-policy.index', ['locale' => 'de']))
        ->assertOk()
        ->assertSee('Entwurf — noch nicht rechtsgültig')
        ->assertSee('Beschwerderecht')
        ->assertSee('dsb@dsb.gv.at')
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
});

it('führt Impressum und Datenschutz in der Fußzeile, links neben Barrierefreiheit', function () {
    $this->get(route('public.home', ['locale' => 'de']))
        ->assertOk()
        ->assertSee(route('public.imprint.index', ['locale' => 'de']), false)
        ->assertSee(route('public.privacy-policy.index', ['locale' => 'de']), false);
});

// ── Meta-Description ───────────────────────────────────────────────────────────

it('setzt eine seiteneigene Meta-Description', function () {
    $this->get(route('public.regulations.index', ['locale' => 'de']))
        ->assertOk()
        ->assertSee('<meta name="description" content="Regelwerke und Formulare des ÖBSV zum Download.">', false);
});

it('fällt ohne eigene Meta-Description auf die Standardbeschreibung zurück', function () {
    $this->get(route('public.base-times.index', ['locale' => 'de']))
        ->assertOk()
        ->assertDontSee('content=""', false);
});

// ── Sitemap ─────────────────────────────────────────────────────────────────────

it('listet die öffentlichen Seiten je Sprache und veröffentlichte Veranstaltungen', function () {
    $meet = makeMeet_p9();

    $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($xml)->toContain(route('public.home', ['locale' => 'de']))
        ->and($xml)->toContain(route('public.home', ['locale' => 'en']))
        ->and($xml)->toContain(route('public.records.index', ['locale' => 'de']))
        ->and($xml)->toContain(route('public.meets.show', ['locale' => 'de', 'meet' => $meet]));
});

it('nimmt Impressum und Datenschutz nicht in die Sitemap auf, solange sie Entwürfe sind', function () {
    $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($xml)->not->toContain(route('public.imprint.index', ['locale' => 'de']))
        ->and($xml)->not->toContain(route('public.privacy-policy.index', ['locale' => 'de']));
});

it('nimmt per robots.txt gesperrte Seiten nicht in die Sitemap auf', function () {
    $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($xml)->not->toContain('/cup')
        ->and($xml)->not->toContain('/startberechtigung')
        ->and($xml)->not->toContain('/bestleistungen')
        ->and($xml)->not->toContain('/ergebnisse');
});

it('nennt die Sitemap in robots.txt', function () {
    $this->get('/robots.txt')
        ->assertOk()
        ->assertSee('Sitemap: '.url('/sitemap.xml'));
});

it('sperrt Impressum und Datenschutz zusätzlich in robots.txt', function () {
    $this->get('/robots.txt')
        ->assertOk()
        ->assertSee('Disallow: /*/impressum')
        ->assertSee('Disallow: /*/datenschutz');
});
