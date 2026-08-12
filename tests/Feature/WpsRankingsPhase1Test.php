<?php

use App\Livewire\WpsRankings;
use App\Models\AgeGroup;
use App\Models\Athlete;
use App\Models\AthleteKaderMembership;
use App\Models\Club;
use App\Models\KaderType;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\User;
use App\Models\WpsPointVersion;
use App\Services\WpsRankingService;
use App\Support\WpsRankingEntry;
use App\Support\WpsRankingFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class)->group('wps-rankings-p1');

// ── Helper (Suffix _wr1 gegen Namenskollisionen) ─────────────────────────────

function nation_wr1(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria']);
}

function club_wr1(string $name): Club
{
    return Club::query()->create(['name' => $name, 'nation_id' => nation_wr1()->id]);
}

function stroke_wr1(string $lenexCode): StrokeType
{
    return StrokeType::firstOrCreate(
        ['lenex_code' => $lenexCode],
        ['code' => $lenexCode, 'name_de' => $lenexCode, 'name_en' => $lenexCode]
    );
}

function athlete_wr1(string $nachname, ?string $geburtsdatum, string $gender = 'F'): Athlete
{
    // Verein über den zuletzt angelegten holen statt über test()->club: Ein dynamisch
    // gesetztes Attribut der Testinstanz ist für die statische Analyse nicht auflösbar.
    return Athlete::query()->create([
        'club_id' => Club::query()->orderBy('id')->value('id'),
        'nation_id' => nation_wr1()->id,
        'first_name' => 'Test',
        'last_name' => $nachname,
        'birth_date' => $geburtsdatum,
        'gender' => $gender,
    ]);
}

function meet_wr1(string $name, string $datum, string $course = 'SCM'): Meet
{
    return Meet::query()->create([
        'name' => $name,
        'city' => 'Wien',
        'nation_id' => nation_wr1()->id,
        'course' => $course,
        'start_date' => $datum,
    ]);
}

function event_wr1(Meet $meet, string $lenexCode, int $distance, int $relayCount = 1): SwimEvent
{
    return SwimEvent::query()->create([
        'meet_id' => $meet->id,
        'stroke_type_id' => stroke_wr1($lenexCode)->id,
        'event_number' => 1,
        'gender' => 'F',
        'distance' => $distance,
        'relay_count' => $relayCount,
    ]);
}

function result_wr1(SwimEvent $event, Athlete $athlete, int $zeit, ?int $punkte, array $extra = []): Result
{
    return Result::query()->create(array_merge([
        'meet_id' => $event->meet_id,
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $athlete->club_id,
        'swim_time' => $zeit,
        'sport_class' => 'S9',
        'wps_points' => $punkte,
        'wps_calculation_type' => $punkte === null ? null : Result::WPS_TYPE_OFFICIAL,
    ], $extra));
}

beforeEach(function () {
    $this->service = app(WpsRankingService::class);
    $this->club = club_wr1('WAT');
    $this->meet = meet_wr1('Kurzbahn-Meeting', '2026-03-13');
    $this->event = event_wr1($this->meet, 'FREE', 100);
    $this->filter = new WpsRankingFilter(year: 2026);
});

// ── Ergebnisauswahl (§4) ─────────────────────────────────────────────────────

it('wertet nur Ergebnisse mit WPS-Punkten', function () {
    result_wr1($this->event, athlete_wr1('MitPunkten', '2000-05-01'), 7000, 800);
    result_wr1($this->event, athlete_wr1('OhnePunkte', '2000-05-01'), 6900, null);

    $rangliste = $this->service->ranking($this->filter);

    expect($rangliste)->toHaveCount(1)
        ->and($rangliste->first()->athlete->last_name)->toBe('MitPunkten');
});

it('schließt DNS, DNF, DSQ, SICK und WDR aus', function () {
    result_wr1($this->event, athlete_wr1('Gewertet', '2000-05-01'), 7000, 800);

    foreach (['DNS', 'DNF', 'DSQ', 'SICK', 'WDR'] as $index => $status) {
        result_wr1($this->event, athlete_wr1("Aus$index", '2000-05-01'), 6800, 850, ['status' => $status]);
    }

    expect($this->service->ranking($this->filter))->toHaveCount(1);
});

it('schließt EXH standardmäßig aus, bezieht es auf Wunsch aber ein', function () {
    result_wr1($this->event, athlete_wr1('Regulaer', '2000-05-01'), 7000, 800);
    result_wr1($this->event, athlete_wr1('AusserKonkurrenz', '2000-05-01'), 6800, 850, ['status' => 'EXH']);

    // Eine Rangliste ist eine Wertung; außer Konkurrenz Geschwommenes gehört dort nicht
    // platziert — anders als in der Statistik, wo EXH als Start zählt.
    expect($this->service->ranking($this->filter))->toHaveCount(1);

    $mitExh = new WpsRankingFilter(year: 2026, includeExhibition: true);

    expect($this->service->ranking($mitExh))->toHaveCount(2)
        ->and($this->service->ranking($mitExh)->first()->athlete->last_name)->toBe('AusserKonkurrenz');
});

it('schließt Staffeln aus', function () {
    $staffel = event_wr1($this->meet, 'FREE', 400, 4);

    result_wr1($this->event, athlete_wr1('Einzel', '2000-05-01'), 7000, 800);
    result_wr1($staffel, athlete_wr1('Staffel', '2000-05-01'), 25000, 900);

    // Es gibt keine WPS-Staffelparameter.
    expect($this->service->ranking($this->filter))->toHaveCount(1)
        ->and($this->service->ranking($this->filter)->first()->athlete->last_name)->toBe('Einzel');
});

it('vermischt Lang- und Kurzbahn standardmäßig nicht', function () {
    $langbahn = meet_wr1('Langbahn-Meeting', '2026-04-01', 'LCM');

    result_wr1($this->event, athlete_wr1('Kurzbahn', '2000-05-01'), 7000, 800);
    result_wr1(event_wr1($langbahn, 'FREE', 100), athlete_wr1('Langbahn', '2000-05-01'), 7200, 850);

    $scm = $this->service->ranking(new WpsRankingFilter(year: 2026, course: WpsRankingFilter::COURSE_SCM));
    $lcm = $this->service->ranking(new WpsRankingFilter(year: 2026, course: WpsRankingFilter::COURSE_LCM));
    $beide = $this->service->ranking(new WpsRankingFilter(year: 2026, course: WpsRankingFilter::COURSE_MIXED));

    expect($scm)->toHaveCount(1)
        ->and($scm->first()->athlete->last_name)->toBe('Kurzbahn')
        ->and($lcm)->toHaveCount(1)
        ->and($lcm->first()->athlete->last_name)->toBe('Langbahn')
        ->and($beide)->toHaveCount(2);
});

// ── Jahresabgrenzung (§6.2) ──────────────────────────────────────────────────

it('erfasst Veranstaltungen am 1. Januar und am 31. Dezember', function () {
    $neujahr = meet_wr1('Neujahrsschwimmen', '2026-01-01');
    $silvester = meet_wr1('Silvesterschwimmen', '2026-12-31');
    $vorjahr = meet_wr1('Vorjahr', '2025-12-31');

    result_wr1(event_wr1($neujahr, 'FREE', 100), athlete_wr1('Neujahr', '2000-05-01'), 7000, 800);
    result_wr1(event_wr1($silvester, 'FREE', 100), athlete_wr1('Silvester', '2000-05-01'), 7100, 790);
    result_wr1(event_wr1($vorjahr, 'FREE', 100), athlete_wr1('Vorjahr', '2000-05-01'), 6900, 810);

    $namen = $this->service->ranking($this->filter)
        ->map(static fn (WpsRankingEntry $e): string => $e->athlete->last_name)
        ->all();

    // Ohne die Uhrzeit an der oberen Grenze fiele der 31. Dezember je nach Treiber still aus.
    expect($namen)->toContain('Neujahr')
        ->and($namen)->toContain('Silvester')
        ->and($namen)->not->toContain('Vorjahr');
});

// ── Sortierung und Bestenauswahl ─────────────────────────────────────────────

it('sortiert nach Punkten absteigend', function () {
    result_wr1($this->event, athlete_wr1('Mittel', '2000-05-01'), 7000, 800);
    result_wr1($this->event, athlete_wr1('Beste', '2000-05-01'), 7500, 900);
    result_wr1($this->event, athlete_wr1('Schwaechste', '2000-05-01'), 6500, 700);

    $namen = $this->service->ranking($this->filter)
        ->map(static fn (WpsRankingEntry $e): string => $e->athlete->last_name)
        ->all();

    // Bewusst so konstruiert, dass die schnellste Zeit nicht die meisten Punkte hat.
    expect($namen)->toBe(['Beste', 'Mittel', 'Schwaechste']);
});

it('entscheidet bei Punktgleichheit über die schnellere Zeit', function () {
    result_wr1($this->event, athlete_wr1('Langsamer', '2000-05-01'), 7100, 800);
    result_wr1($this->event, athlete_wr1('Schneller', '2000-05-01'), 7000, 800);

    $rangliste = $this->service->ranking($this->filter);

    expect($rangliste->first()->athlete->last_name)->toBe('Schneller')
        // Punktgleiche teilen sich den Rang.
        ->and($rangliste->pluck('rank')->all())->toBe([1, 1]);
});

it('lässt den Rang nach einer Punktgleichheit springen', function () {
    result_wr1($this->event, athlete_wr1('A', '2000-05-01'), 7000, 900);
    result_wr1($this->event, athlete_wr1('B', '2000-05-01'), 7100, 900);
    result_wr1($this->event, athlete_wr1('C', '2000-05-01'), 7200, 800);

    expect($this->service->ranking($this->filter)->pluck('rank')->all())->toBe([1, 1, 3]);
});

it('nimmt je Athlet und Bewerb nur die beste Leistung', function () {
    $athlet = athlete_wr1('Mehrfachstarter', '2000-05-01');
    $zweitesMeeting = meet_wr1('Zweites Meeting', '2026-05-01');

    result_wr1($this->event, $athlet, 7200, 780);
    result_wr1(event_wr1($zweitesMeeting, 'FREE', 100), $athlet, 7000, 830);

    $rangliste = $this->service->ranking($this->filter);

    expect($rangliste)->toHaveCount(1)
        ->and($rangliste->first()->points)->toBe(830);
});

it('zeigt in der Veranstaltungsrangliste jeden Start einzeln', function () {
    $athlet = athlete_wr1('Vorlauf-und-Finale', '2000-05-01');

    result_wr1($this->event, $athlet, 7200, 780);
    result_wr1($this->event, $athlet, 7000, 830, ['heat' => 2, 'lane' => 4]);

    $veranstaltung = new WpsRankingFilter(
        type: WpsRankingFilter::TYPE_MEET,
        meetId: $this->meet->id,
    );

    // Innerhalb einer Veranstaltung ist jedes Ergebnis ein eigener Start; Vorlauf und Finale
    // sollen beide sichtbar bleiben.
    expect($this->service->ranking($veranstaltung))->toHaveCount(2);
});

// ── Alterslogik (§5) ─────────────────────────────────────────────────────────

it('rechnet das Alter zum 31. Dezember des Wettkampfjahres', function () {
    // Geburtstag am 31.12. — zählt das ganze Jahr über bereits als ein Jahr älter.
    result_wr1($this->event, athlete_wr1('Silvesterkind', '2008-12-31'), 7000, 800);

    expect($this->service->ranking($this->filter)->first()->age)->toBe(18);
});

it('filtert die Jugendrangliste über die Altersobergrenze', function () {
    result_wr1($this->event, athlete_wr1('Jugend', '2009-06-01'), 7000, 800);
    result_wr1($this->event, athlete_wr1('Allgemein', '1998-06-01'), 7100, 850);

    $jugend = new WpsRankingFilter(year: 2026, maxAge: 18);

    expect($this->service->ranking($jugend))->toHaveCount(1)
        ->and($this->service->ranking($jugend)->first()->athlete->last_name)->toBe('Jugend');
});

it('weist Athleten ohne Geburtsdatum als Sammelposten aus', function () {
    result_wr1($this->event, athlete_wr1('MitDatum', '2009-06-01'), 7000, 800);
    result_wr1($this->event, athlete_wr1('OhneDatum', null), 6900, 850);

    $jugend = new WpsRankingFilter(year: 2026, maxAge: 18);

    // Fehlende Zuordnungen bleiben sichtbar, statt still zu verschwinden.
    expect($this->service->ranking($jugend))->toHaveCount(1)
        ->and($this->service->withoutBirthDate($jugend))->toHaveCount(1)
        ->and($this->service->withoutBirthDate($jugend)->first()->athlete->last_name)->toBe('OhneDatum');
});

it('erzeugt ohne Altersgrenze keinen Sammelposten', function () {
    result_wr1($this->event, athlete_wr1('OhneDatum', null), 6900, 850);

    // Ohne Altersgrenze ist niemand ausgeschlossen worden.
    expect($this->service->ranking($this->filter))->toHaveCount(1)
        ->and($this->service->withoutBirthDate($this->filter))->toBeEmpty();
});

// ── Punkteversionen (§11.2) ──────────────────────────────────────────────────

it('weist alle verwendeten Punkteversionen aus', function () {
    $alt = WpsPointVersion::query()->create([
        'label' => 'WPS 2025', 'year' => 2025, 'version' => '1',
        'status' => WpsPointVersion::STATUS_ACTIVE, 'valid_from' => '2025-01-01',
    ]);
    $neu = WpsPointVersion::query()->create([
        'label' => 'WPS 2026', 'year' => 2026, 'version' => '1',
        'status' => WpsPointVersion::STATUS_ACTIVE, 'valid_from' => '2026-01-01',
    ]);

    result_wr1($this->event, athlete_wr1('Alt', '2000-05-01'), 7000, 800, ['wps_point_version_id' => $alt->id]);
    result_wr1($this->event, athlete_wr1('Neu', '2000-05-01'), 7100, 790, ['wps_point_version_id' => $neu->id]);

    $rangliste = $this->service->ranking($this->filter);

    // Eine Liste aus verschiedenen Jahrgängen sähe sonst aus wie eine einheitlich gerechnete.
    expect($this->service->usedVersions($rangliste))->toBe(['WPS 2025', 'WPS 2026']);
});

// ── Filterobjekt ─────────────────────────────────────────────────────────────

it('ist auf Kurzbahn vorbelegt', function () {
    // Eine LCM-Standardansicht wäre für österreichische Auswertungen nahezu leer.
    expect(WpsRankingFilter::default(2026)->course)->toBe(WpsRankingFilter::COURSE_SCM);
});

it('verwirft unbekannte Werte aus der Adresse', function () {
    $filter = WpsRankingFilter::fromQuery([
        'type' => 'unsinn',
        'course' => 'xyz',
        'gender' => 'Q',
        'calc' => 'egal',
        'year' => '2026',
    ]);

    expect($filter->type)->toBe(WpsRankingFilter::TYPE_SEASON)
        ->and($filter->course)->toBe(WpsRankingFilter::COURSE_SCM)
        ->and($filter->gender)->toBe('')
        ->and($filter->calculationType)->toBe('')
        ->and($filter->year)->toBe(2026);
});

it('beschreibt nur, was vom Standard abweicht', function () {
    $standard = new WpsRankingFilter(year: 2026);
    $eingeschraenkt = new WpsRankingFilter(year: 2026, gender: 'F', sportClass: 'S9', maxAge: 18);

    expect($standard->describe())->toBe(['Jahr 2026', 'Bahnlänge SCM'])
        ->and($eingeschraenkt->describe())->toContain('weiblich')
        ->and($eingeschraenkt->describe())->toContain('Sportklasse S9')
        ->and($eingeschraenkt->describe())->toContain('bis 18 Jahre');
});

// ── Oberfläche ───────────────────────────────────────────────────────────────

it('zeigt die Rangliste allen angemeldeten Nutzern', function () {
    result_wr1($this->event, athlete_wr1('Sichtbar', '2000-05-01'), 7000, 800);

    $vereinsnutzer = User::factory()->create(['is_admin' => false, 'club_id' => $this->club->id]);

    // Ranglisten sind verbandsweit; anders als Vereinsauswertungen (Phase 7).
    $this->actingAs($vereinsnutzer)
        ->get(route('wps.rankings'))
        ->assertOk();
});

it('weist nicht angemeldete Anfragen ab', function () {
    $this->get(route('wps.rankings'))->assertRedirect();
});

it('setzt Filter über die Komponente und springt auf Seite 1 zurück', function () {
    result_wr1($this->event, athlete_wr1('Weiblich', '2000-05-01'), 7000, 800);
    result_wr1($this->event, athlete_wr1('Maennlich', '2000-05-01', 'M'), 7100, 850);

    $komponente = Livewire::actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->test(WpsRankings::class);

    expect($komponente->instance()->entries())->toHaveCount(2);

    $komponente->call('setFilter', 'gender', 'F');

    expect($komponente->instance()->entries())->toHaveCount(1)
        ->and($komponente->instance()->entries()->first()->athlete->last_name)->toBe('Weiblich');

    $komponente->call('toggleExhibition');

    expect($komponente->instance()->filter()->includeExhibition)->toBeTrue();

    $komponente->call('resetFilters');

    expect($komponente->instance()->entries())->toHaveCount(2)
        ->and($komponente->instance()->filter()->includeExhibition)->toBeFalse();
});

// ── Kaderfilter (§10) ────────────────────────────────────────────────────────

function kaderAthlet_wr1(string $nachname, ?KaderType $kaderType): Athlete
{
    $athlet = athlete_wr1($nachname, '2000-05-01');

    if ($kaderType !== null) {
        AthleteKaderMembership::query()->create([
            'athlete_id' => $athlet->id,
            'kader_type_id' => $kaderType->id,
            'valid_from' => '2020-01-01',
        ]);
    }

    return $athlet;
}

it('blendet ausgewählte Kaderarten aus', function () {
    $weltklasse = KaderType::query()->create([
        'code' => 'WK', 'name_de' => 'Weltklasse', 'sort_order' => 1, 'is_active' => true,
    ]);

    result_wr1($this->event, kaderAthlet_wr1('Weltklasse', $weltklasse), 6800, 950);
    result_wr1($this->event, kaderAthlet_wr1('OhneKader', null), 7000, 800);

    $filter = new WpsRankingFilter(
        year: 2026,
        kaderMode: WpsRankingFilter::KADER_EXCEPT,
        kaderIds: [$weltklasse->id],
    );

    $rangliste = $this->service->ranking($filter);

    expect($rangliste)->toHaveCount(1)
        ->and($rangliste->first()->athlete->last_name)->toBe('OhneKader');
});

it('zeigt nur ausgewählte Kaderarten', function () {
    $weltklasse = KaderType::query()->create([
        'code' => 'WK', 'name_de' => 'Weltklasse', 'sort_order' => 1, 'is_active' => true,
    ]);
    $international = KaderType::query()->create([
        'code' => 'IK', 'name_de' => 'International', 'sort_order' => 2, 'is_active' => true,
    ]);

    result_wr1($this->event, kaderAthlet_wr1('Weltklasse', $weltklasse), 6800, 950);
    result_wr1($this->event, kaderAthlet_wr1('International', $international), 6900, 900);
    result_wr1($this->event, kaderAthlet_wr1('OhneKader', null), 7000, 800);

    $filter = new WpsRankingFilter(
        year: 2026,
        kaderMode: WpsRankingFilter::KADER_ONLY,
        kaderIds: [$weltklasse->id, $international->id],
    );

    expect($this->service->ranking($filter))->toHaveCount(2);
});

it('behandelt Athleten ohne Kaderzuordnung als eigene, wählbare Gruppe', function () {
    $weltklasse = KaderType::query()->create([
        'code' => 'WK', 'name_de' => 'Weltklasse', 'sort_order' => 1, 'is_active' => true,
    ]);

    result_wr1($this->event, kaderAthlet_wr1('Weltklasse', $weltklasse), 6800, 950);
    result_wr1($this->event, kaderAthlet_wr1('OhneKader', null), 7000, 800);

    // Ohne diese Wahlmöglichkeit verschwänden Athleten ohne Zuordnung entweder immer oder
    // nie — beides wäre eine stille Festlegung.
    $nurOhne = new WpsRankingFilter(
        year: 2026,
        kaderMode: WpsRankingFilter::KADER_ONLY,
        kaderIds: [WpsRankingFilter::KADER_NONE],
    );

    $ohneAusgeblendet = new WpsRankingFilter(
        year: 2026,
        kaderMode: WpsRankingFilter::KADER_EXCEPT,
        kaderIds: [WpsRankingFilter::KADER_NONE],
    );

    expect($this->service->ranking($nurOhne))->toHaveCount(1)
        ->and($this->service->ranking($nurOhne)->first()->athlete->last_name)->toBe('OhneKader')
        ->and($this->service->ranking($ohneAusgeblendet))->toHaveCount(1)
        ->and($this->service->ranking($ohneAusgeblendet)->first()->athlete->last_name)->toBe('Weltklasse');
});

it('lässt einen Kaderfilter ohne Auswahl wirkungslos', function () {
    result_wr1($this->event, athlete_wr1('Irgendwer', '2000-05-01'), 7000, 800);

    $ohneAuswahl = new WpsRankingFilter(year: 2026, kaderMode: WpsRankingFilter::KADER_ONLY);

    expect($ohneAuswahl->hasKaderFilter())->toBeFalse()
        ->and($this->service->ranking($ohneAuswahl))->toHaveCount(1);
});

it('setzt beim Abwählen der letzten Kaderart den Modus zurück', function () {
    $weltklasse = KaderType::query()->create([
        'code' => 'WK', 'name_de' => 'Weltklasse', 'sort_order' => 1, 'is_active' => true,
    ]);

    $komponente = Livewire::actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->test(WpsRankings::class);

    $komponente->call('toggleKader', $weltklasse->id);

    // Wer eine Kaderart anhakt, will einschränken.
    expect($komponente->instance()->filter()->kaderMode)->toBe(WpsRankingFilter::KADER_EXCEPT)
        ->and($komponente->instance()->isKaderSelected($weltklasse->id))->toBeTrue();

    $komponente->call('toggleKader', $weltklasse->id);

    // Ein gesetzter Modus ohne Auswahl wäre wirkungslos und sähe trotzdem nach einer
    // Einschränkung aus.
    expect($komponente->instance()->filter()->kaderMode)->toBe(WpsRankingFilter::KADER_ALL)
        ->and($komponente->instance()->filter()->hasKaderFilter())->toBeFalse();
});

// ── Jahresvorbelegung ────────────────────────────────────────────────────────

it('belegt das Jahr mit dem jüngsten Jahr vor, für das Wettkämpfe vorliegen', function () {
    // Der Wettkampf aus beforeEach liegt in 2026; ein älterer daneben.
    meet_wr1('Alt', '2024-05-01');

    $komponente = Livewire::actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->test(WpsRankings::class);

    // Stünde hier das laufende Kalenderjahr, zeigte die Auswahlliste ihren ersten Eintrag,
    // während intern ein anderes Jahr gefiltert wird — die Liste bliebe unerklärt leer.
    expect($komponente->instance()->filter()->year)->toBe(2026)
        ->and($komponente->instance()->availableYears()[0])->toBe(2026);
});

it('schränkt die Veranstaltungsliste auf das gewählte Jahr ein', function () {
    meet_wr1('Alt', '2024-05-01');

    $komponente = Livewire::actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->test(WpsRankings::class);

    expect($komponente->instance()->meets())->toHaveCount(1);

    $komponente->call('setFilter', 'year', '2024');

    expect($komponente->instance()->meets())->toHaveCount(1)
        ->and($komponente->instance()->meets()->first()->name)->toBe('Alt');
});

// ── Altersgruppen (§5) ───────────────────────────────────────────────────────

function ageGroup_wr1(string $code, ?int $min, ?int $max, int $sortOrder): AgeGroup
{
    return AgeGroup::query()->create([
        'code' => $code,
        'name_de' => $code,
        'min_age' => $min,
        'max_age' => $max,
        'sort_order' => $sortOrder,
        'is_active' => true,
    ]);
}

it('schränkt auf eine Altersgruppe ein', function () {
    $jugend = ageGroup_wr1('Jugend', null, 18, 1);
    ageGroup_wr1('Allgemein', 19, null, 2);

    result_wr1($this->event, athlete_wr1('Jung', '2009-06-01'), 7000, 800);
    result_wr1($this->event, athlete_wr1('Erwachsen', '1998-06-01'), 7100, 850);

    $filter = new WpsRankingFilter(year: 2026, ageGroupId: $jugend->id);

    expect($this->service->ranking($filter))->toHaveCount(1)
        ->and($this->service->ranking($filter)->first()->athlete->last_name)->toBe('Jung');
});

it('verwendet die statischen Grenzen der Altersgruppe, nicht die Cup-Übersteuerung', function () {
    // Offenes Intervall nach oben — min_age gesetzt, max_age null.
    $offen = ageGroup_wr1('Offen', 19, null, 1);

    result_wr1($this->event, athlete_wr1('Jung', '2010-06-01'), 7000, 800);
    result_wr1($this->event, athlete_wr1('Alt', '1980-06-01'), 7100, 850);

    $filter = new WpsRankingFilter(year: 2026, ageGroupId: $offen->id);

    expect($this->service->ranking($filter))->toHaveCount(1)
        ->and($this->service->ranking($filter)->first()->athlete->last_name)->toBe('Alt');
});

it('weist auch bei gesetzter Altersgruppe Athleten ohne Geburtsdatum aus', function () {
    $jugend = ageGroup_wr1('Jugend', null, 18, 1);

    result_wr1($this->event, athlete_wr1('MitDatum', '2009-06-01'), 7000, 800);
    result_wr1($this->event, athlete_wr1('OhneDatum', null), 6900, 850);

    $filter = new WpsRankingFilter(year: 2026, ageGroupId: $jugend->id);

    expect($this->service->ranking($filter))->toHaveCount(1)
        ->and($this->service->withoutBirthDate($filter))->toHaveCount(1);
});

it('ignoriert eine unbekannte Altersgruppe, statt eine leere Liste zu liefern', function () {
    result_wr1($this->event, athlete_wr1('Irgendwer', '2000-05-01'), 7000, 800);

    $filter = new WpsRankingFilter(year: 2026, ageGroupId: 999999);

    expect($this->service->ranking($filter))->toHaveCount(1);
});

it('nennt die gewählte Altersgruppe in der Beschreibung des Filterstands', function () {
    $jugend = ageGroup_wr1('Jugend', null, 18, 1);

    $komponente = Livewire::actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->test(WpsRankings::class);

    $komponente->call('setFilter', 'ageGroupId', (string) $jugend->id);

    expect($komponente->instance()->filter()->describe())->toContain('Altersgruppe Jugend');
});
