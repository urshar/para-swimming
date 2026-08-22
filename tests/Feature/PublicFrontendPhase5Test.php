<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Nation;
use App\Models\RelayTeamMember;
use App\Models\StrokeType;
use App\Models\SwimRecord;
use App\Services\Public\PublicRecordService;
use App\Support\PublicRecordFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('public-p5');

function makeNation_pfr5(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria']);
}

function makeClub_pfr5(array $overrides = []): Club
{
    return Club::create(array_merge([
        'name' => 'Testverein',
        'nation_id' => makeNation_pfr5()->id,
    ], $overrides));
}

function makeAthlete_pfr5(array $overrides = []): Athlete
{
    return Athlete::create(array_merge([
        'nation_id' => makeNation_pfr5()->id,
        'club_id' => makeClub_pfr5()->id,
        'first_name' => 'Max',
        'last_name' => 'Mustermann',
        'birth_date' => '2000-05-01',
    ], $overrides));
}

function makeStrokeType_pfr5(string $lenexCode = 'FREE'): StrokeType
{
    $names = [
        'FREE' => ['Freistil', 'Freestyle'],
        'BACK' => ['Rücken', 'Backstroke'],
        'BREAST' => ['Brust', 'Breaststroke'],
        'FLY' => ['Schmetterling', 'Butterfly'],
        'MEDLEY' => ['Lagen', 'Medley'],
    ];

    return StrokeType::firstOrCreate(['lenex_code' => $lenexCode], [
        'code' => $lenexCode,
        'name_de' => $names[$lenexCode][0],
        'name_en' => $names[$lenexCode][1],
    ]);
}

function makeRecord_pfr5(array $overrides = []): SwimRecord
{
    return SwimRecord::create(array_merge([
        'stroke_type_id' => makeStrokeType_pfr5()->id,
        'athlete_id' => makeAthlete_pfr5()->id,
        'club_id' => makeClub_pfr5()->id,
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

function makeRelayMember_pfr5(SwimRecord $record, array $overrides = []): RelayTeamMember
{
    return RelayTeamMember::create(array_merge([
        'swim_record_id' => $record->id,
        'position' => 1,
        'first_name' => 'Erika',
        'last_name' => 'Musterfrau',
    ], $overrides));
}

// ── Rekordebene: national vs. regional ──────────────────────────────────────────

it('zeigt bei einem Landesverband nur dessen Rekorde, nicht national oder andere Verbände', function () {
    makeRecord_pfr5(['record_type' => 'AUT', 'swim_time' => 6000]); // 01:00.00
    makeRecord_pfr5(['record_type' => 'AUT.WBSV', 'swim_time' => 6500]); // 01:05.00
    makeRecord_pfr5(['record_type' => 'AUT.TBSV', 'swim_time' => 7000]); // 01:10.00

    $this->get(route('public.records.index', ['locale' => 'de', 'association' => 'WBSV']))
        ->assertOk()
        ->assertSee('01:05.00')
        ->assertDontSee('01:00.00')
        ->assertDontSee('01:10.00');
});

it('zeigt ohne Verbandsauswahl die nationalen Rekorde', function () {
    makeRecord_pfr5(['record_type' => 'AUT', 'swim_time' => 6000]);
    makeRecord_pfr5(['record_type' => 'AUT.WBSV', 'swim_time' => 6500]);

    $this->get(route('public.records.index', ['locale' => 'de']))
        ->assertOk()
        ->assertSee('01:00.00')
        ->assertDontSee('01:05.00');
});

// ── Altersklasse: Jugend-Umschalter ──────────────────────────────────────────────

it('wechselt mit dem Jugend-Umschalter von AUT auf AUT.JR', function () {
    makeRecord_pfr5(['record_type' => 'AUT', 'swim_time' => 6000]);
    makeRecord_pfr5(['record_type' => 'AUT.JR', 'swim_time' => 6100]);

    $this->get(route('public.records.index', ['locale' => 'de']))
        ->assertOk()->assertSee('01:00.00')->assertDontSee('01:01.00');

    $this->get(route('public.records.index', ['locale' => 'de', 'youth' => '1']))
        ->assertOk()->assertSee('01:01.00')->assertDontSee('01:00.00');
});

// ── nur genehmigte, aktuelle Rekorde ─────────────────────────────────────────────

it('zeigt nur aktuelle, genehmigte Rekorde — PENDING, INVALID und History bleiben unsichtbar', function () {
    makeRecord_pfr5(['record_status' => 'APPROVED', 'is_current' => true, 'swim_time' => 6000]);
    makeRecord_pfr5(['record_status' => 'PENDING', 'is_current' => true, 'swim_time' => 6100]);
    makeRecord_pfr5(['record_status' => 'INVALID', 'is_current' => true, 'swim_time' => 6200]);
    makeRecord_pfr5(['record_status' => 'APPROVED.HISTORY', 'is_current' => false, 'swim_time' => 6300]);
    makeRecord_pfr5(['record_status' => 'TARGETTIME', 'is_current' => true, 'swim_time' => 6400]);

    $this->get(route('public.records.index', ['locale' => 'de']))
        ->assertOk()
        ->assertSee('01:00.00')
        ->assertDontSee('01:01.00')
        ->assertDontSee('01:02.00')
        ->assertDontSee('01:03.00')
        ->assertDontSee('01:04.00');
});

// ── Staffelrekorde ────────────────────────────────────────────────────────────

it('zeigt Staffelrekorde mit den Namen der Mitglieder', function () {
    $relay = makeRecord_pfr5(['relay_count' => 4, 'sport_class' => 'S34', 'swim_time' => 12000]);
    makeRelayMember_pfr5($relay, ['position' => 1, 'first_name' => 'Anna', 'last_name' => 'Erste']);
    makeRelayMember_pfr5($relay, ['position' => 2, 'first_name' => 'Bernd', 'last_name' => 'Zweite']);

    $this->get(route('public.records.index', ['locale' => 'de']))
        ->assertOk()
        ->assertSee('Anna Erste')
        ->assertSee('Bernd Zweite');
});

// ── Verein-Anzeigename und Ort/Flagge ────────────────────────────────────────────

it('zeigt den Vereins-Kurznamen, wenn vorhanden, statt des vollen Namens', function () {
    $club = makeClub_pfr5(['name' => 'Sportunion Musterstadt', 'short_name' => 'SU Musterstadt']);
    makeRecord_pfr5(['club_id' => $club->id, 'swim_time' => 6000]);

    $this->get(route('public.records.index', ['locale' => 'de']))
        ->assertOk()
        ->assertSee('SU Musterstadt')
        ->assertDontSee('Sportunion Musterstadt');
});

it('zeigt Ort und Flagge des Rekords, ohne Flagge wenn kein Austragungsland hinterlegt ist', function () {
    $nation = makeNation_pfr5();
    makeRecord_pfr5(['meet_nation_id' => $nation->id, 'meet_city' => 'Wien', 'swim_time' => 6000]);
    makeRecord_pfr5(['meet_nation_id' => null, 'meet_city' => 'Ohne Flagge', 'swim_time' => 6100]);

    $content = $this->get(route('public.records.index', ['locale' => 'de']))
        ->assertOk()
        ->assertSee('Wien')
        ->assertSee('Ohne Flagge')
        ->getContent();

    expect($content)->toContain('fi fi-at')
        ->and(substr_count($content, 'fi fi-at'))->toBe(1);
});

// ── Namen nirgends verlinkt (Spec §2.3) ──────────────────────────────────────────

it('verlinkt Athlet- und Staffelmitgliedsnamen nirgends', function () {
    $individual = makeAthlete_pfr5(['first_name' => 'Erika', 'last_name' => 'Solorekord']);
    $record = makeRecord_pfr5(['athlete_id' => $individual->id, 'swim_time' => 6000]);

    $relay = makeRecord_pfr5(['relay_count' => 4, 'sport_class' => 'S34', 'swim_time' => 12000]);
    makeRelayMember_pfr5($relay, ['position' => 1, 'first_name' => 'Team', 'last_name' => 'Mitglied']);

    $content = $this->get(route('public.records.index', ['locale' => 'de']))
        ->assertOk()
        ->assertSee($record->athlete->full_name)
        ->assertSee('Team Mitglied')
        ->getContent();

    preg_match_all('/<a\b[^>]*>(.*?)<\/a>/is', $content, $matches);
    $linkedTexts = implode(' ', $matches[1] ?? []);

    expect($linkedTexts)->not->toContain($record->athlete->full_name)
        ->and($linkedTexts)->not->toContain('Team Mitglied');
});

// ── PublicRecordService ───────────────────────────────────────────────────────

it('liefert nur die im gewählten record_type tatsächlich vorkommenden Sportklassen', function () {
    makeRecord_pfr5(['record_type' => 'AUT', 'sport_class' => 'S6']);
    makeRecord_pfr5(['record_type' => 'AUT', 'sport_class' => 'S4']);
    makeRecord_pfr5(['record_type' => 'AUT.WBSV', 'sport_class' => 'S9']);

    $classes = (new PublicRecordService)->availableSportClasses('AUT');

    expect($classes)->toBe(['4', '6']); // nur die Klassifizierungsnummer, numerisch sortiert, "9" aus WBSV bleibt außen vor
});

it('grenzt die Sportklasse serverseitig ein und über alle Lagen derselben Nummer (S/SB/SM)', function () {
    makeRecord_pfr5(['sport_class' => 'S6', 'swim_time' => 6000]);
    makeRecord_pfr5(['sport_class' => 'SB4', 'swim_time' => 6100]);
    makeRecord_pfr5(['sport_class' => 'SM4', 'swim_time' => 6200]);

    $this->get(route('public.records.index', ['locale' => 'de', 'sport_class' => '4']))
        ->assertOk()
        ->assertSee('01:01.00')
        ->assertSee('01:02.00')
        ->assertDontSee('01:00.00');
});

// ── Gruppierung nach Schwimmart (Rückmeldung: alphabetisch war unübersichtlich) ─────────────────

it('gruppiert Rekorde nach Schwimmart in Verbandsreihenfolge Frei/Rücken/Brust/Fly/Lagen', function () {
    makeRecord_pfr5(['stroke_type_id' => makeStrokeType_pfr5('MEDLEY')->id, 'sport_class' => 'SM6', 'swim_time' => 6000]);
    makeRecord_pfr5(['stroke_type_id' => makeStrokeType_pfr5('BACK')->id, 'sport_class' => 'S6', 'swim_time' => 6100]);
    makeRecord_pfr5(['stroke_type_id' => makeStrokeType_pfr5()->id, 'sport_class' => 'S6', 'swim_time' => 6200]);
    makeRecord_pfr5(['stroke_type_id' => makeStrokeType_pfr5('FLY')->id, 'sport_class' => 'S6', 'swim_time' => 6300]);
    makeRecord_pfr5(['stroke_type_id' => makeStrokeType_pfr5('BREAST')->id, 'sport_class' => 'SB6', 'swim_time' => 6400]);

    $service = new PublicRecordService;
    $groups = $service->groupByStroke($service->forFilter(PublicRecordFilter::fromQuery([])));

    expect($groups->pluck('stroke.lenex_code')->all())->toBe(['FREE', 'BACK', 'BREAST', 'FLY', 'MEDLEY']);
});

it('zeigt die Schwimmart-Überschriften in Verbandsreihenfolge auf der Rekordseite', function () {
    makeRecord_pfr5(['stroke_type_id' => makeStrokeType_pfr5('MEDLEY')->id, 'sport_class' => 'SM6', 'swim_time' => 6000]);
    makeRecord_pfr5(['stroke_type_id' => makeStrokeType_pfr5('BACK')->id, 'sport_class' => 'S6', 'swim_time' => 6100]);
    makeRecord_pfr5(['stroke_type_id' => makeStrokeType_pfr5()->id, 'sport_class' => 'S6', 'swim_time' => 6200]);

    $content = $this->get(route('public.records.index', ['locale' => 'de']))->assertOk()->getContent();

    $freiPos = strpos($content, 'Freistil');
    $rueckenPos = strpos($content, 'Rücken');
    $lagenPos = strpos($content, 'Lagen');

    expect($freiPos)->not->toBeFalse()
        ->and($rueckenPos)->not->toBeFalse()
        ->and($lagenPos)->not->toBeFalse()
        ->and($freiPos)->toBeLessThan($rueckenPos)
        ->and($rueckenPos)->toBeLessThan($lagenPos);
});

// ── Export: LENEX ─────────────────────────────────────────────────────────────

it('liefert einen strukturell gültigen LENEX-Export der gewählten Rekordebene', function () {
    makeRecord_pfr5(['record_type' => 'AUT', 'course' => 'LCM', 'gender' => 'M']);
    makeRecord_pfr5(['record_type' => 'AUT.WBSV']);

    $response = $this->get(route('public.records.export', [
        'locale' => 'de', 'format' => 'lxf', 'course' => 'LCM', 'gender' => 'M',
    ]))->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/zip');

    $tmp = tempnam(sys_get_temp_dir(), 'lxf_test_');
    file_put_contents($tmp, $response->getContent());
    $zip = new ZipArchive;
    $zip->open($tmp);
    $xml = $zip->getFromIndex(0);
    $zip->close();
    unlink($tmp);

    $dom = new DOMDocument;
    expect($dom->loadXML($xml))->toBeTrue();

    $recordLists = $dom->getElementsByTagName('RECORDLIST');
    expect($recordLists->length)->toBeGreaterThan(0)
        ->and($recordLists->item(0)->getAttribute('type'))->toBe('AUT');
});

it('grenzt den LENEX-Export nicht nach Sportklasse ein (vollständige Bestenliste der Ebene)', function () {
    makeRecord_pfr5(['record_type' => 'AUT', 'sport_class' => 'S6', 'course' => 'LCM']);
    makeRecord_pfr5(['record_type' => 'AUT', 'sport_class' => 'S4', 'course' => 'LCM']);

    $response = $this->get(route('public.records.export', [
        'locale' => 'de', 'format' => 'lxf', 'sport_class' => '6',
    ]))->assertOk();

    $tmp = tempnam(sys_get_temp_dir(), 'lxf_test_');
    file_put_contents($tmp, $response->getContent());
    $zip = new ZipArchive;
    $zip->open($tmp);
    $xml = $zip->getFromIndex(0);
    $zip->close();
    unlink($tmp);

    expect($xml)->toContain('handicap="6"')->toContain('handicap="4"');
});

// ── Export: PDF ───────────────────────────────────────────────────────────────

it('liefert die Rekordliste als PDF entsprechend der Filterauswahl', function () {
    makeRecord_pfr5(['sport_class' => 'S6']);
    makeRecord_pfr5(['sport_class' => 'S4']);

    $response = $this->get(route('public.records.export', [
        'locale' => 'de', 'format' => 'pdf', 'sport_class' => '6',
    ]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});

// ── PublicRecordFilter ────────────────────────────────────────────────────────

it('fällt bei unbekannten Filterwerten auf den Standard zurück', function () {
    $filter = PublicRecordFilter::fromQuery([
        'association' => 'NICHT_ECHT',
        'course' => 'SCY',
        'gender' => 'X',
    ]);

    expect($filter->association)->toBe('')
        ->and($filter->course)->toBe('')
        ->and($filter->gender)->toBe('')
        ->and($filter->recordType())->toBe('AUT');
});
