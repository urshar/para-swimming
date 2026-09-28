<?php

use App\Models\Club;
use App\Models\Entry;
use App\Models\Meet;
use App\Models\RelayEntry;
use App\Models\RelayEntryMember;
use App\Models\User;
use App\Services\MeetEntryListExportService;
use App\Services\MeetEntryListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;

uses(RefreshDatabase::class)->group('entry-lists');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php):
// makeMeet_p5(), makeClub_p5(), makeAthlete_p5(), makeEvent_p5().

function mel_admin(): User
{
    return User::firstWhere('email', 'mel_admin@example.test')
        ?? User::forceCreate([
            'name' => 'Admin MEL',
            'email' => 'mel_admin@example.test',
            'password' => bcrypt('secret'),
            'is_admin' => true,
        ]);
}

function mel_clubUser(Club $club): User
{
    return User::firstWhere('email', 'mel_club@example.test')
        ?? User::forceCreate([
            'name' => 'Club MEL',
            'email' => 'mel_club@example.test',
            'password' => bcrypt('secret'),
            'is_admin' => false,
            'club_id' => $club->id,
        ]);
}

function mel_entry(Meet $meet, object $event, Club $club, object $athlete): Entry
{
    return Entry::create([
        'meet_id' => $meet->id,
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
    ]);
}

/**
 * Baut ein Szenario: 2-Tage-Meet in Graz, zwei Vereine A/B mit je einem
 * gemeldeten Athleten und in Verein A eine Staffel mit einem weiteren Athleten,
 * der sonst nirgends gemeldet ist (prüft die Staffel-Berücksichtigung).
 *
 * @return array{meet: Meet, clubA: Club, clubB: Club, athleteA: object, athleteB: object, relayAthlete: object}
 */
function mel_scenario(): array
{
    $meet = makeMeet_p5();
    $meet->update(['city' => 'Graz', 'end_date' => '2025-06-16', 'is_open' => true]);

    $event = makeEvent_p5($meet);
    $relayEvent = makeEvent_p5($meet, ['relay_count' => 4, 'event_number' => 2]);

    $clubA = makeClub_p5();
    $clubA->update(['name' => 'Alpha Verein', 'short_name' => 'Alpha SV']);
    $clubB = makeClub_p5();
    $clubB->update(['name' => 'Zeta Verein', 'short_name' => 'Zeta SV']);

    $athleteA = makeAthlete_p5($clubA);
    $athleteA->update(['last_name' => 'Berger', 'first_name' => 'Anna', 'license' => 'ST - 100']);
    $athleteB = makeAthlete_p5($clubB);
    $athleteB->update(['last_name' => 'Auer', 'first_name' => 'Bernd', 'license' => 'ST - 200']);
    $relayAthlete = makeAthlete_p5($clubA);
    $relayAthlete->update(['last_name' => 'Zöhrer', 'first_name' => 'Clara', 'license' => 'ST - 300']);

    mel_entry($meet, $event, $clubA, $athleteA);
    mel_entry($meet, $event, $clubB, $athleteB);

    $relay = RelayEntry::create([
        'meet_id' => $meet->id,
        'swim_event_id' => $relayEvent->id,
        'club_id' => $clubA->id,
    ]);
    RelayEntryMember::create([
        'relay_entry_id' => $relay->id,
        'athlete_id' => $relayAthlete->id,
        'position' => 1,
    ]);

    return compact('meet', 'clubA', 'clubB', 'athleteA', 'athleteB', 'relayAthlete');
}

// ── Data-Service ────────────────────────────────────────────────────────────

it('gruppiert Teilnehmer je Verein und bezieht Staffelmitglieder ein', function () {
    $s = mel_scenario();
    $byClub = app(MeetEntryListService::class)->participantsByClub($s['meet']);

    expect($byClub)->toHaveCount(2);

    $groupA = $byClub->firstWhere('club.id', $s['clubA']->id);
    $groupB = $byClub->firstWhere('club.id', $s['clubB']->id);

    // Verein A: der gemeldete Athlet UND das Staffelmitglied, nach Nachname sortiert.
    expect($groupA['participants']->pluck('last_name')->all())->toBe(['Berger', 'Zöhrer'])
        ->and($groupB['participants']->pluck('last_name')->all())->toBe(['Auer']);
});

it('scopet die Teilnehmerliste auf einen Verein', function () {
    $s = mel_scenario();
    $byClub = app(MeetEntryListService::class)->participantsByClub($s['meet'], $s['clubA']->id);

    expect($byClub)->toHaveCount(1)
        ->and($byClub->first()['club']->id)->toBe($s['clubA']->id)
        ->and($byClub->first()['participants'])->toHaveCount(2);
});

it('sortiert die Gesamtliste nach Verein, dann nach Namen', function () {
    $s = mel_scenario();
    $all = app(MeetEntryListService::class)->allParticipants($s['meet']);

    // Alpha SV (Berger, Zöhrer) vor Zeta SV (Auer).
    expect($all->pluck('athlete.last_name')->all())->toBe(['Berger', 'Zöhrer', 'Auer'])
        ->and($all->pluck('club.display_name')->all())->toBe(['Alpha SV', 'Alpha SV', 'Zeta SV']);
});

it('berechnet die Anzahl der Veranstaltungstage inklusive', function () {
    $service = app(MeetEntryListService::class);

    $single = makeMeet_p5();
    $multi = makeMeet_p5();
    $multi->update(['end_date' => '2025-06-17']); // 15.-17. = 3 Tage

    expect($service->days($single))->toBe(1)
        ->and($service->days($multi))->toBe(3);
});

// ── Excel-Export ────────────────────────────────────────────────────────────

it('erzeugt je Verein ein Teilnehmer-Arbeitsblatt', function () {
    $s = mel_scenario();
    $lists = app(MeetEntryListService::class);
    $export = app(MeetEntryListExportService::class);

    $path = $export->teilnehmerXlsx($s['meet'], $lists->participantsByClub($s['meet']));
    $sheet = IOFactory::createReader('Xlsx')->load($path);

    $titles = collect($sheet->getAllSheets())->map(fn ($s) => $s->getTitle());
    expect($titles)->toContain($s['clubA']->display_name)
        ->and($titles)->toContain($s['clubB']->display_name);

    $sheetA = $sheet->getSheetByName($s['clubA']->display_name);
    // Titel (A1), Vereinsfeld (G7) und erste Datenzeile (B10) mit "Nachname Vorname".
    expect($sheetA->getCell('A1')->getValue())->toBe('T E I L N E H M E R I N N E N L I S T E')
        ->and($sheetA->getCell('G7')->getValue())->toBe($s['clubA']->display_name)
        ->and($sheetA->getCell('B10')->getValue())->toBe('Berger Anna')
        ->and($sheetA->getDrawingCollection())->toHaveCount(1); // Sport-Austria-Logo

    @unlink($path);
});

it('erzeugt eine Sportpass-Liste mit Lizenznummer und Verein', function () {
    $s = mel_scenario();
    $lists = app(MeetEntryListService::class);
    $export = app(MeetEntryListExportService::class);

    $path = $export->sportpassXlsx($s['meet'], $lists->allParticipants($s['meet']));
    $sheet = IOFactory::createReader('Xlsx')->load($path)->getSheetByName('Sportpasskontrolle');

    // Kopf (A6), erste Datenzeile (14) nach Verein-Sortierung: Alpha SV / Berger,
    // Verein als zweite Zeile, Lizenz in F.
    expect($sheet->getCell('A6')->getValue())->toBe('LISTE FÜR SPORTPASSKONTROLLE')
        ->and($sheet->getCell('B14')->getValue()->getPlainText())->toBe("Berger Anna\n".$s['clubA']->display_name)
        ->and($sheet->getCell('F14')->getValue())->toBe('ST - 100')
        ->and($sheet->getDrawingCollection())->toHaveCount(1); // ÖBSV-Logo

    @unlink($path);
});

// ── Meldeliste nach Namen ───────────────────────────────────────────────────

it('gruppiert die Meldeliste nach Namen: Geschlecht → Verein → Athlet', function () {
    $s = mel_scenario();
    $sections = app(MeetEntryListService::class)->byName($s['meet']);

    // Alle Testathleten männlich → nur eine Herren-Sektion.
    expect($sections)->toHaveCount(1)
        ->and($sections->first()['label'])->toContain('Herren');

    // Vereine nach Vereinsname sortiert (Alpha vor Zeta).
    $clubs = $sections->first()['clubs'];
    expect($clubs->pluck('club.id')->all())->toBe([$s['clubA']->id, $s['clubB']->id]);

    // Alpha Verein: der Einzelmelder mit Bewerb + die Staffel (getrennt gelistet).
    $alpha = $clubs->firstWhere('club.id', $s['clubA']->id);
    expect($alpha['athletes']->pluck('athlete.last_name')->all())->toBe(['Berger'])
        ->and($alpha['athletes']->first()['entries'])->not->toBeEmpty()
        ->and($alpha['relays'])->toHaveCount(1);
});

it('scopet die Meldeliste nach Namen auf den eigenen Verein', function () {
    $s = mel_scenario();
    $sections = app(MeetEntryListService::class)->byName($s['meet'], $s['clubB']->id);

    $clubs = $sections->first()['clubs'];
    expect($clubs)->toHaveCount(1)
        ->and($clubs->first()['club']->id)->toBe($s['clubB']->id);
});

// ── Meldeliste nach Bewerben ────────────────────────────────────────────────

it('gruppiert die Meldeliste nach Bewerben: Abschnitt → Bewerb → Teilnehmer', function () {
    $s = mel_scenario();
    $sections = app(MeetEntryListService::class)->byEvent($s['meet']);

    // Ein Abschnitt (Session), zwei Bewerbe (Einzel + Staffel).
    expect($sections)->toHaveCount(1)
        ->and($sections->first()['label'])->toContain('Abschnitt 1');

    $events = $sections->first()['events'];
    $indiv = $events->firstWhere('isRelay', false);
    $relayEv = $events->firstWhere('isRelay', true);

    // Einzelbewerb: beide Athleten, alphabetisch (Auer vor Berger).
    expect($indiv['entrants']->pluck('name')->all())->toBe(['Auer, Bernd', 'Berger, Anna'])
        // Staffelbewerb: eine Staffel mit ihrem Schwimmer in Positionsreihenfolge.
        ->and($relayEv['relays'])->toHaveCount(1)
        ->and($relayEv['relays']->first()['members']->pluck('name')->all())->toBe(['Zöhrer Clara']);
});

it('scopet die Meldeliste nach Bewerben auf den eigenen Verein', function () {
    $s = mel_scenario();
    $sections = app(MeetEntryListService::class)->byEvent($s['meet'], $s['clubB']->id);

    $events = $sections->first()['events'];
    // Nur Zeta-Athlet im Einzelbewerb, kein Staffelbewerb (Staffel gehört Alpha).
    expect($events->firstWhere('isRelay', false)['entrants']->pluck('name')->all())->toBe(['Auer, Bernd'])
        ->and($events->firstWhere('isRelay', true))->toBeNull();
});

// ── HTTP / Zugriff ──────────────────────────────────────────────────────────

it('lässt Admins alle vier Listen herunterladen', function () {
    $s = mel_scenario();
    $admin = mel_admin();

    $this->actingAs($admin)->get(route('meets.entry-lists.teilnehmer.pdf', $s['meet']))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->actingAs($admin)->get(route('meets.entry-lists.teilnehmer.xlsx', $s['meet']))
        ->assertOk();
    $this->actingAs($admin)->get(route('meets.entry-lists.sportpass.pdf', $s['meet']))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->actingAs($admin)->get(route('meets.entry-lists.sportpass.xlsx', $s['meet']))
        ->assertOk();
    $this->actingAs($admin)->get(route('meets.entry-lists.nach-namen.pdf', $s['meet']))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->actingAs($admin)->get(route('meets.entry-lists.nach-bewerben.pdf', $s['meet']))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
    // Einspaltige Variante der Meldeliste nach Bewerben.
    $this->actingAs($admin)->get(route('meets.entry-lists.nach-bewerben.pdf', ['meet' => $s['meet'], 'columns' => 1]))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('macht die Sportpasskontrolle admin-only', function () {
    $s = mel_scenario();
    $clubUser = mel_clubUser($s['clubA']);

    $this->actingAs($clubUser)->get(route('meets.entry-lists.sportpass.pdf', $s['meet']))->assertForbidden();
    $this->actingAs($clubUser)->get(route('meets.entry-lists.sportpass.xlsx', $s['meet']))->assertForbidden();
});

it('erlaubt Vereinen ihre eigene Teilnehmerliste', function () {
    $s = mel_scenario();
    $clubUser = mel_clubUser($s['clubA']);

    $this->actingAs($clubUser)->get(route('meets.entry-lists.teilnehmer.pdf', $s['meet']))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->actingAs($clubUser)->get(route('meets.entry-lists.teilnehmer.xlsx', $s['meet']))
        ->assertOk();
    $this->actingAs($clubUser)->get(route('meets.entry-lists.nach-namen.pdf', $s['meet']))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->actingAs($clubUser)->get(route('meets.entry-lists.nach-bewerben.pdf', $s['meet']))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
});
