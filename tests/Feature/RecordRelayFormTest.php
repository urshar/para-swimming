<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\ImportReviewItem;
use App\Models\Nation;
use App\Models\RecordSplit;
use App\Models\RelayTeamMember;
use App\Models\StrokeType;
use App\Models\SwimRecord;
use App\Models\User;
use App\Services\RecordImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;

uses(RefreshDatabase::class)->group('record-relay-form');

// Staffelrekord bearbeiten: Athleten im Staffelteam, Zwischenzeiten, Prüfliste; Staffelzeilen der Import-Vorschau.

function nation_rrf(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'],
        ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
}

function stroke_rrf(): StrokeType
{
    return StrokeType::firstOrCreate(['lenex_code' => 'MEDLEY'],
        ['name_de' => 'Lagen', 'name_en' => 'Medley', 'code' => 'MEDLEY', 'is_active' => true]);
}

function club_rrf(string $name): Club
{
    return Club::create(['name' => $name, 'nation_id' => nation_rrf()->id, 'type' => 'CLUB']);
}

function athlete_rrf(string $lastName, Club $club): Athlete
{
    return Athlete::create(['first_name' => 'Max', 'last_name' => $lastName, 'gender' => 'M',
        'birth_date' => '2001-02-03', 'nation_id' => nation_rrf()->id, 'club_id' => $club->id]);
}

/** Staffelrekord 4×50 m Lagen ohne Verein mit vier Zwischenzeiten (wie aus der Liste importiert). */
function relayRecord_rrf(): SwimRecord
{
    $record = SwimRecord::create([
        'stroke_type_id' => stroke_rrf()->id, 'nation_id' => nation_rrf()->id, 'record_type' => 'AUT',
        'sport_class' => 'SM49', 'gender' => 'X', 'course' => 'SCM', 'distance' => 50, 'relay_count' => 4,
        'swim_time' => 19632, 'record_status' => 'APPROVED', 'is_current' => true, 'set_date' => '2023-12-16',
        'meet_name' => 'STRAHOVSKÉ SPRINTY',
    ]);
    foreach ([50 => 5234, 100 => 11036, 150 => 15166, 200 => 19632] as $distance => $time) {
        RecordSplit::create(['swim_record_id' => $record->id, 'distance' => $distance, 'split_time' => $time]);
    }

    return $record;
}

function admin_rrf(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

/** Formulardaten des Rekords; $members je Position [athlete_id, last_name, first_name, birth_date]. */
function payload_rrf(SwimRecord $record, ?Club $club, array $members): array
{
    return [
        'record_type' => $record->record_type, 'stroke_type_id' => $record->stroke_type_id,
        'sport_class' => $record->sport_class, 'gender' => $record->gender, 'course' => $record->course,
        'distance' => $record->distance, 'relay_count' => $record->relay_count, 'club_id' => $club?->id,
        'swim_time' => '03:16.32', 'record_status' => 'APPROVED', 'set_date' => '2023-12-16',
        'splits' => [['distance' => 200, 'split_time' => '03:16.32']],
        'relay_members' => array_map(fn (array $m) => [
            'athlete_id' => $m[0], 'last_name' => $m[1], 'first_name' => $m[2], 'birth_date' => $m[3],
        ], $members),
    ];
}

beforeEach(function () {
    nation_rrf();
});

it('zeigt im Bearbeiten-Formular alle Zwischenzeiten einer Staffel', function () {
    $record = relayRecord_rrf();

    $html = $this->actingAs(admin_rrf())->get(route('records.edit', $record))->assertOk()->getContent();

    // Gespeicherte Zeilen sind immer sichtbar (auch die Endzeit bei 200 m), die übrigen nach der Gesamtstrecke.
    expect($html)->toContain('01:50.36')
        ->toContain('03:16.32')
        ->toContain('parseInt(distanceValue) * Math.max(1, parseInt(relayCountValue) || 1)')
        ->toContain('Auch andere Vereine');
});

it('speichert gewählte Athleten und frei eingetragene Namen im Staffelteam', function () {
    $club = club_rrf('Staffelverein');
    $chosen = athlete_rrf('Gewählt', $club);
    $record = relayRecord_rrf();

    $this->actingAs(admin_rrf())
        ->put(route('records.update', $record), payload_rrf($record, $club, [
            [$chosen->id, 'Ignoriert', 'Ignoriert', null],
            [null, 'Frei', 'Anna', '1999-04-05'],
        ]))
        ->assertRedirect(route('records.show', $record));

    $team = RelayTeamMember::where('swim_record_id', $record->id)->orderBy('position')->get();

    expect($team)->toHaveCount(2)
        ->and($team[0]->athlete_id)->toBe($chosen->id)
        ->and($team[0]->last_name)->toBe('Gewählt')
        ->and($team[0]->birth_date->toDateString())->toBe('2001-02-03')
        ->and($team[0]->gender)->toBe('M')
        ->and($team[1]->athlete_id)->toBeNull()
        ->and($team[1]->last_name)->toBe('Frei')
        ->and($record->fresh()->club_id)->toBe($club->id);
});

it('belegt gespeicherte Athleten vor und zeigt bei Vereinswechsel alle Vereine', function () {
    $club = club_rrf('Staffelverein');
    $moved = athlete_rrf('Gewechselt', club_rrf('Neuer Verein'));
    $record = relayRecord_rrf();
    $record->update(['club_id' => $club->id]);
    RelayTeamMember::create(['swim_record_id' => $record->id, 'position' => 1, 'athlete_id' => $moved->id,
        'last_name' => 'Gewechselt', 'first_name' => 'Max']);

    $html = $this->actingAs(admin_rrf())->get(route('records.edit', $record))->assertOk()->getContent();

    // @json kodiert den Gedankenstrich im Label als —.
    expect($html)->toContain('"athleteIds":["'.$moved->id.'","","",""]')
        ->toContain('Gewechselt Max (2001) \\u2014 Neuer Verein')
        ->toContain('memberAllClubs: true');
});

it('erledigt den Prüflisten-Eintrag, wenn die Staffel einen Verein bekommt', function () {
    $club = club_rrf('Staffelverein');
    $record = relayRecord_rrf();
    $item = ImportReviewItem::create(['type' => ImportReviewItem::TYPE_RELAY_NO_CLUB, 'swim_record_id' => $record->id,
        'source' => 'Liste.lxf', 'status' => ImportReviewItem::STATUS_OPEN]);

    $this->actingAs(admin_rrf())->put(route('records.update', $record), payload_rrf($record, $club, []));

    expect($item->fresh()->status)->toBe(ImportReviewItem::STATUS_APPLIED)
        ->and($item->fresh()->status_label)->toBe('Geprüft');
});

it('zeigt Staffeln in der Import-Vorschau mit Bewerb, Zeit, Datum, Wettkampf und Zwischenzeiten', function () {
    stroke_rrf();
    $xml = '<?xml version="1.0" encoding="UTF-8"?><LENEX version="3.0"><RECORDLISTS>'
        .'<RECORDLIST type="AUT" course="SCM" gender="X" handicap="49" nation="AUT"><RECORDS>'
        .'<RECORD swimtime="00:03:16.32"><SWIMSTYLE distance="50" stroke="MEDLEY" relaycount="4"/>'
        .'<MEETINFO city="Praha 6" name="STRAHOVSKÉ SPRINTY" nation="CZE" date="2023-12-16"/>'
        .'<SPLITS><SPLIT distance="50" swimtime="00:00:52.34"/><SPLIT distance="100" swimtime="00:01:50.36"/>'
        .'<SPLIT distance="150" swimtime="00:02:31.66"/><SPLIT distance="200" swimtime="00:03:16.32"/></SPLITS>'
        .'</RECORD></RECORDS></RECORDLIST></RECORDLISTS></LENEX>';
    $path = tempnam(sys_get_temp_dir(), 'rrf_').'.xml';
    file_put_contents($path, $xml);

    try {
        $preview = (new RecordImportService)->preview($path);
    } catch (Throwable $e) {
        throw new LogicException($e->getMessage(), 0, $e);
    }

    $this->actingAs(admin_rrf());

    try {
        $html = view('records.import-preview', ['preview' => $preview, 'clubs' => collect(),
            'athletes' => collect(), 'fileName' => 'Liste.lxf', 'errors' => new ViewErrorBag])->render();
    } catch (Throwable $e) {
        throw new LogicException($e->getMessage(), 0, $e);
    }

    expect($html)->toContain('Mixed')
        ->toContain('4×50m Lagen')
        ->toContain('03:16.32')
        ->toContain('16.12.2023')
        ->toContain('STRAHOVSKÉ SPRINTY, Praha 6')
        ->toContain('4 Zwischenzeiten')
        ->toContain('Staffel ohne Verein');
});
