<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\ImportReviewItem;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\RelayResult;
use App\Models\RelayResultMember;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use App\Models\User;
use App\Services\RecordImportReviewService;
use App\Services\RecordImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('record-list-review');

// Prüfliste: "Abweichung zur Rekordliste" und "Staffelrekord ohne Verein" (docs/specs/records.md).

function nation_rlr(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'],
        ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
}

function stroke_rlr(): StrokeType
{
    return StrokeType::firstOrCreate(['lenex_code' => 'FREE'],
        ['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'code' => 'FREE']);
}

/** Staffelrekord 4×50 m Freistil SCM Herren S14 wie in der Liste aus dem Sport Management Tool (ohne Verein). */
function listXml_rlr(string $swimtime, string $date): string
{
    return '<?xml version="1.0" encoding="UTF-8"?><LENEX version="3.0"><RECORDLISTS>'
        .'<RECORDLIST type="AUT" course="SCM" gender="M" handicap="14" nation="AUT"><RECORDS>'
        .'<RECORD swimtime="'.$swimtime.'"><SWIMSTYLE distance="50" stroke="FREE" relaycount="4"/>'
        .'<MEETINFO city="Wien" name="Listenmeet" nation="AUT" date="'.$date.'"/>'
        .'<SPLITS><SPLIT distance="50" swimtime="00:00:30.00"/></SPLITS>'
        .'</RECORD></RECORDS></RECORDLIST></RECORDLISTS></LENEX>';
}

/** Import ohne Entscheidungen (die Liste hat keine Vereine und Athleten). */
function import_rlr(string $xml): array
{
    $path = tempnam(sys_get_temp_dir(), 'rlr_').'.xml';
    file_put_contents($path, $xml);

    try {
        return (new RecordImportService)->import(
            filePath: $path,
            approvedClubs: [],
            approvedAthletes: [],
            newClubData: [],
            newAthleteData: [],
            source: 'Liste.lxf',
        );
    } catch (Throwable $e) {
        throw new LogicException($e->getMessage(), 0, $e);
    }
}

/** Rekord derselben Kategorie in der DB; $previous wird zur Historie. */
function record_rlr(int $time, string $date, ?SwimRecord $previous, ?Club $club): SwimRecord
{
    $record = SwimRecord::create([
        'stroke_type_id' => stroke_rlr()->id, 'nation_id' => nation_rlr()->id, 'record_type' => 'AUT',
        'sport_class' => 'S14', 'gender' => 'M', 'course' => 'SCM', 'distance' => 50, 'relay_count' => 4,
        'swim_time' => $time, 'record_status' => 'APPROVED', 'is_current' => true, 'set_date' => $date,
        'club_id' => $club?->id, 'supersedes_id' => $previous?->id,
    ]);
    $previous?->markAsSupersededBy($record);

    return $record;
}

/** Kette der Kategorie als [Zeit, aktuell] in zeitlicher Reihenfolge, vom aktuellen Rekord aus. */
function chain_rlr(): array
{
    $chain = [];
    $record = SwimRecord::where('record_type', 'AUT')->where('is_current', true)->sole();
    while ($record !== null) {
        array_unshift($chain, [$record->swim_time, $record->is_current]);
        $record = $record->supersedes_id !== null ? SwimRecord::find($record->supersedes_id) : null;
    }

    return $chain;
}

/** Staffelergebnis 4×50 m Freistil (Herren) mit vier Mitgliedern des Vereins am $date. */
function relayResult_rlr(Club $club, int $time, string $date): RelayResult
{
    $meet = Meet::create(['name' => 'ÖSTM', 'start_date' => $date, 'end_date' => $date, 'course' => 'SCM',
        'city' => 'Spittal', 'nation_id' => nation_rlr()->id]);
    $event = SwimEvent::create(['meet_id' => $meet->id, 'stroke_type_id' => stroke_rlr()->id, 'distance' => 50,
        'relay_count' => 4, 'gender' => 'M', 'session_number' => 1, 'event_number' => 1, 'round' => 'TIM']);
    $relay = RelayResult::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'club_id' => $club->id,
        'gender' => 'M', 'relay_class' => 'S14', 'swim_time' => $time]);
    for ($i = 1; $i <= 4; $i++) {
        $athlete = Athlete::create(['first_name' => 'Max', 'last_name' => 'Staffel'.$i, 'gender' => 'M',
            'birth_date' => '2000-01-01', 'nation_id' => nation_rlr()->id, 'club_id' => $club->id]);
        RelayResultMember::create(['relay_result_id' => $relay->id, 'position' => $i, 'athlete_id' => $athlete->id,
            'gender' => 'M', 'sport_class' => 'S14']);
    }

    return $relay;
}

function club_rlr(): Club
{
    return Club::create(['name' => 'Staffelverein', 'nation_id' => nation_rlr()->id, 'type' => 'CLUB']);
}

function admin_rlr(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function mismatch_rlr(): ImportReviewItem
{
    return ImportReviewItem::where('type', ImportReviewItem::TYPE_LIST_MISMATCH)->sole();
}

beforeEach(function () {
    stroke_rlr();
    nation_rlr();
});

// ── Import ────────────────────────────────────────────────────────────────────

it('legt einen Listeneintrag in einer leeren Kategorie an und meldet die Staffel ohne Verein', function () {
    $result = import_rlr(listXml_rlr('00:02:00.00', '2024-05-05'));

    $record = SwimRecord::where('record_type', 'AUT')->sole();
    $item = ImportReviewItem::where('type', ImportReviewItem::TYPE_RELAY_NO_CLUB)->sole();

    expect($result['imported'])->toBe(1)
        ->and($result['review_open'])->toBe(1)
        ->and($record->club_id)->toBeNull()
        ->and($item->swim_record_id)->toBe($record->id)
        ->and($item->athlete_id)->toBeNull();
});

it('überspringt einen Listeneintrag, dessen Zeit schon in der Kette steht', function () {
    record_rlr(12000, '2024-05-04', null, null);

    $result = import_rlr(listXml_rlr('00:02:00.00', '2024-05-05'));

    expect($result['imported'])->toBe(0)
        ->and(ImportReviewItem::count())->toBe(0)
        ->and(SwimRecord::count())->toBe(1);
});

it('überspringt einen langsameren Listeneintrag, wenn der schnellere DB-Rekord neuer ist', function () {
    record_rlr(11000, '2025-05-10', null, null);

    $result = import_rlr(listXml_rlr('00:02:00.00', '2024-05-05'));

    expect($result['imported'])->toBe(0)
        ->and(ImportReviewItem::count())->toBe(0);
});

it('meldet einen schnelleren, aber älteren Listeneintrag und ändert dabei nichts', function () {
    $older = record_rlr(13000, '2020-01-01', null, null);
    $current = record_rlr(12500, '2024-05-04', $older, null); // nach der Liste kein Rekord

    $result = import_rlr(listXml_rlr('00:02:00.00', '2023-09-16'));
    $item = mismatch_rlr();

    expect($result['imported'])->toBe(0)
        ->and($result['review_open'])->toBe(1)
        ->and(array_column($item->details['contradictions'], 'id'))->toBe([$current->id])
        ->and($item->details['list']['swim_time'])->toBe(12000)
        ->and(chain_rlr())->toBe([[13000, false], [12500, true]]);
});

it('meldet einen langsameren Listeneintrag, wenn die DB davor einen schnelleren Rekord hat', function () {
    $faster = record_rlr(11000, '2003-05-24', null, null);

    import_rlr(listXml_rlr('00:02:00.00', '2024-05-05'));

    expect(array_column(mismatch_rlr()->details['contradictions'], 'id'))->toBe([$faster->id]);
});

it('legt dieselbe Abweichung beim erneuten Import nicht doppelt an', function () {
    record_rlr(11000, '2003-05-24', null, null);

    import_rlr(listXml_rlr('00:02:00.00', '2024-05-05'));
    $second = import_rlr(listXml_rlr('00:02:00.00', '2024-05-05'));

    expect($second['review_open'])->toBe(0)
        ->and(ImportReviewItem::where('type', ImportReviewItem::TYPE_LIST_MISMATCH)->count())->toBe(1);
});

// ── Aktionen ──────────────────────────────────────────────────────────────────

it('übernimmt die Liste: entfernt den Widerspruch und hängt den Eintrag nach Datum ein', function () {
    $older = record_rlr(13000, '2020-01-01', null, null);
    record_rlr(12500, '2024-05-04', $older, null);
    import_rlr(listXml_rlr('00:02:00.00', '2023-09-16'));

    $this->actingAs(admin_rlr())
        ->post(route('records.import-review.apply', mismatch_rlr()))
        ->assertRedirect()
        ->assertSessionHas('success');

    $listRecord = SwimRecord::where('swim_time', 12000)->sole();

    expect(chain_rlr())->toBe([[13000, false], [12000, true]])
        ->and(SwimRecord::where('swim_time', 12500)->exists())->toBeFalse()
        ->and($listRecord->set_date->toDateString())->toBe('2023-09-16')
        ->and($listRecord->meet_name)->toBe('Listenmeet')
        ->and($listRecord->splits()->count())->toBe(1)
        ->and(mismatch_rlr()->status)->toBe(ImportReviewItem::STATUS_APPLIED)
        ->and(ImportReviewItem::where('type', ImportReviewItem::TYPE_RELAY_NO_CLUB)
            ->where('swim_record_id', $listRecord->id)->exists())->toBeTrue();
});

it('übernimmt die Liste auch, wenn die DB davor einen schnelleren Rekord hatte', function () {
    $first = record_rlr(13000, '2001-01-01', null, null);
    record_rlr(11000, '2003-05-24', $first, null);
    import_rlr(listXml_rlr('00:02:00.00', '2024-05-05'));

    $this->actingAs(admin_rlr())->post(route('records.import-review.apply', mismatch_rlr()));

    expect(chain_rlr())->toBe([[13000, false], [12000, true]]);
});

it('lässt beim Ignorieren die Rekorde unverändert', function () {
    record_rlr(11000, '2003-05-24', null, null);
    import_rlr(listXml_rlr('00:02:00.00', '2024-05-05'));

    $this->actingAs(admin_rlr())->post(route('records.import-review.ignore', mismatch_rlr()));

    expect(chain_rlr())->toBe([[11000, true]])
        ->and(mismatch_rlr()->status)->toBe(ImportReviewItem::STATUS_IGNORED);
});

it('verknüpft eine Staffel ohne Verein mit dem passenden Staffelergebnis', function () {
    $club = club_rlr();
    $relay = relayResult_rlr($club, 12000, '2024-05-05');
    import_rlr(listXml_rlr('00:02:00.00', '2024-05-05'));
    $item = ImportReviewItem::where('type', ImportReviewItem::TYPE_RELAY_NO_CLUB)->sole();

    $this->actingAs(admin_rlr())
        ->get(route('records.import-review.index'))
        ->assertOk()
        ->assertSee('Mit Staffelergebnis verknüpfen')
        ->assertSee('Staffelverein');

    $this->actingAs(admin_rlr())->post(route('records.import-review.apply', $item));

    $record = SwimRecord::where('record_type', 'AUT')->sole();

    expect($record->club_id)->toBe($club->id)
        ->and($record->relay_result_id)->toBe($relay->id)
        ->and($record->relayTeam()->whereNotNull('athlete_id')->count())->toBe(4)
        ->and($relay->fresh()->is_national_record)->toBeTrue()
        ->and($item->fresh()->status_label)->toBe('Verknüpft');
});

it('bietet ohne passendes Staffelergebnis das Bearbeiten an und markiert nur als geprüft', function () {
    import_rlr(listXml_rlr('00:02:00.00', '2024-05-05'));
    $item = ImportReviewItem::where('type', ImportReviewItem::TYPE_RELAY_NO_CLUB)->sole();

    $this->actingAs(admin_rlr())
        ->get(route('records.import-review.index'))
        ->assertOk()
        ->assertSee('Verein und Mitglieder beim Rekord ergänzen');

    $this->actingAs(admin_rlr())->post(route('records.import-review.apply', $item));

    expect($item->fresh()->status_label)->toBe('Geprüft')
        ->and(SwimRecord::where('record_type', 'AUT')->sole()->club_id)->toBeNull();
});

it('findet bei der Bestandsprüfung Staffelrekorde ohne Verein', function () {
    $record = record_rlr(12000, '2024-05-05', null, null);
    record_rlr(9000, '2010-01-01', null, club_rlr())->update(['distance' => 100]); // mit Verein: kein Befund

    $created = (new RecordImportReviewService)->scanExisting();

    expect($created)->toBe(1)
        ->and(ImportReviewItem::where('type', ImportReviewItem::TYPE_RELAY_NO_CLUB)->sole()->swim_record_id)
        ->toBe($record->id);
});

it('setzt beim Entfernen eines Staffelrekords das Flag am Staffelergebnis zurück', function () {
    $relay = relayResult_rlr(club_rlr(), 12000, '2024-05-05');
    $relay->update(['is_national_record' => true]);
    $record = record_rlr(12000, '2024-05-05', null, null);
    $record->update(['relay_result_id' => $relay->id]);

    try {
        $record->removeFromHistory();
    } catch (Throwable $e) {
        throw new LogicException($e->getMessage(), 0, $e);
    }

    expect($relay->fresh()->is_national_record)->toBeFalse();
});

// ── Bereinigen ────────────────────────────────────────────────────────────────

it('löscht nur erledigte Einträge und behält offene und ignorierte', function () {
    foreach ([ImportReviewItem::STATUS_APPLIED, ImportReviewItem::STATUS_APPLIED, ImportReviewItem::STATUS_OPEN,
        ImportReviewItem::STATUS_IGNORED] as $status) {
        ImportReviewItem::create(['type' => ImportReviewItem::TYPE_RELAY_NO_CLUB, 'status' => $status]);
    }

    $this->actingAs(admin_rlr())
        ->post(route('records.import-review.purge'))
        ->assertRedirect(route('records.import-review.index'))
        ->assertSessionHas('success', '2 erledigte Einträge gelöscht.');

    expect(ImportReviewItem::pluck('status')->sort()->values()->all())
        ->toBe([ImportReviewItem::STATUS_IGNORED, ImportReviewItem::STATUS_OPEN]);
});

it('erlaubt das Bereinigen nur Admins', function () {
    ImportReviewItem::create(['type' => ImportReviewItem::TYPE_RELAY_NO_CLUB,
        'status' => ImportReviewItem::STATUS_APPLIED]);
    $user = User::factory()->create(['is_admin' => false, 'club_id' => null]);

    $this->actingAs($user)->post(route('records.import-review.purge'))->assertForbidden();

    expect(ImportReviewItem::count())->toBe(1);
});

it('meldet beim Bereinigen ohne erledigte Einträge, dass nichts zu löschen war', function () {
    ImportReviewItem::create(['type' => ImportReviewItem::TYPE_RELAY_NO_CLUB, 'status' => ImportReviewItem::STATUS_OPEN]);

    $this->actingAs(admin_rlr())
        ->post(route('records.import-review.purge'))
        ->assertSessionHas('success', 'Keine erledigten Einträge vorhanden.');

    expect(ImportReviewItem::count())->toBe(1);
});
