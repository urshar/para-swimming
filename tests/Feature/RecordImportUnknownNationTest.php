<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\ImportReviewItem;
use App\Models\Nation;
use App\Models\StrokeType;
use App\Models\SwimRecord;
use App\Models\User;
use App\Services\RecordImportReviewService;
use App\Services\RecordImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class)->group('record-import-unknown-nation');

// Rekordlisten-Einträge ohne Nationalität (Verein in der Datei ohne nation, Athlet nicht bekannt) werden kein Rekord,
// sondern kommen in die Prüfliste; ein bekannter österreichischer Athlet macht den Eintrag zu einem normalen Rekord.

/** Einzelrekord 50 m Freistil SCM Damen S14 der Athletin $lastName, Verein ohne nation-Attribut. */
function record_run(string $lastName, string $time, string $date): string
{
    return '<RECORD swimtime="'.$time.'"><SWIMSTYLE distance="50" stroke="FREE" relaycount="1"/>'
        .'<ATHLETE lastname="'.$lastName.'" firstname="Nina" gender="F" birthdate="1990-01-01">'
        .'<CLUB name="VCA Wien" code="VCAW"/></ATHLETE>'
        .'<MEETINFO city="Wien" name="Meet '.$date.'" nation="AUT" date="'.$date.'"/></RECORD>';
}

function file_run(string $records): string
{
    $path = tempnam(sys_get_temp_dir(), 'run_').'.xml';
    file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?><LENEX version="3.0"><RECORDLISTS>'
        .'<RECORDLIST type="AUT" course="SCM" gender="F" handicap="14" nation="AUT"><RECORDS>'
        .$records.'</RECORDS></RECORDLIST></RECORDLISTS></LENEX>');

    return $path;
}

/** @throws Throwable */
function import_run(string $path, array $approvedAthletes = []): array
{
    return (new RecordImportService)->import(
        filePath: $path,
        approvedClubs: [],
        approvedAthletes: $approvedAthletes,
        newClubData: [],
        newAthleteData: [],
        source: 'Liste.lxf',
    );
}

function athleteKey_run(string $lastName): string
{
    return $lastName.'|Nina|1990-01-01';
}

beforeEach(function () {
    $this->austria = Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
    $this->stroke = StrokeType::firstOrCreate(['lenex_code' => 'FREE'], ['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'code' => 'FREE']);
    $this->club = Club::query()->create(['name' => 'VCA Wien', 'code' => 'VCAW', 'nation_id' => $this->austria->id,
        'type' => 'CLUB', 'regional_association' => 'WBSV']);
});

it('legt einen Eintrag ohne Nationalität nicht als Rekord an, sondern in die Prüfliste', /** @throws Throwable */ function () {
    $path = file_run(record_run('Neu', '00:00:40.00', '2010-05-01'));

    $preview = (new RecordImportService)->preview($path);
    $result = import_run($path, [athleteKey_run('Neu') => 'new']);
    $item = ImportReviewItem::query()->sole();

    expect($preview['pending_records'])->toHaveCount(1)
        ->and($preview['records'])->toBe([])
        ->and(SwimRecord::query()->count())->toBe(0)
        ->and($result['review_open'])->toBe(1)
        ->and($item->type)->toBe(ImportReviewItem::TYPE_UNKNOWN_NATION)
        ->and($item->athlete_id)->toBe(Athlete::query()->sole()->id)
        ->and($item->current_club_id)->toBe($this->club->id)
        ->and($item->details['list']['swim_time'])->toBe(4000);
});

it('legt bei einem erneuten Import weder einen zweiten Prüflisten-Eintrag noch einen Rekord an', /** @throws Throwable */ function () {
    $path = file_run(record_run('Neu', '00:00:40.00', '2010-05-01'));

    import_run($path, [athleteKey_run('Neu') => 'new']);
    // Der beim ersten Lauf angelegte Athlet trägt die Vorgabe AUT — entschieden wird trotzdem in der Prüfliste.
    import_run($path);

    expect(ImportReviewItem::query()->count())->toBe(1)
        ->and(Athlete::query()->count())->toBe(1)
        ->and(SwimRecord::query()->count())->toBe(0);
});

it('importiert den Eintrag normal, wenn der Athlet in der DB Österreicher ist', /** @throws Throwable */ function () {
    Athlete::query()->create(['first_name' => 'Nina', 'last_name' => 'Bekannt', 'gender' => 'F', 'birth_date' => '1990-01-01',
        'nation_id' => $this->austria->id, 'club_id' => $this->club->id]);
    $path = file_run(record_run('Bekannt', '00:00:40.00', '2010-05-01'));

    $preview = (new RecordImportService)->preview($path);
    import_run($path);

    expect($preview['pending_records'])->toBe([])
        ->and(SwimRecord::query()->where('record_type', 'AUT')->sole()->record_status)->toBe('APPROVED')
        ->and(ImportReviewItem::query()->count())->toBe(0);
});

it('überspringt den Eintrag, wenn der Athlet in der DB eine andere Nationalität hat', /** @throws Throwable */ function () {
    $germany = Nation::query()->create(['code' => 'GER', 'name_de' => 'Deutschland', 'name_en' => 'Germany', 'is_active' => true]);
    Athlete::query()->create(['first_name' => 'Nina', 'last_name' => 'Fremd', 'gender' => 'F', 'birth_date' => '1990-01-01',
        'nation_id' => $germany->id, 'club_id' => $this->club->id]);

    $preview = (new RecordImportService)->preview(file_run(record_run('Fremd', '00:00:40.00', '2010-05-01')));

    expect($preview['pending_records'])->toBe([])
        ->and($preview['records'])->toBe([])
        ->and($preview['skipped'])->toBe(1);
});

it('hängt den Eintrag beim Übernehmen nach Datum in die Kette und leitet als aktueller Rekord den Landesrekord ab', /** @throws Throwable */ function () {
    $path = file_run(record_run('Neu', '00:00:40.00', '2010-05-01'));
    import_run($path, [athleteKey_run('Neu') => 'new']);
    $admin = User::factory()->create(['is_admin' => true, 'club_id' => null]);

    app(RecordImportReviewService::class)->apply(ImportReviewItem::query()->sole(), $admin->id);

    $national = SwimRecord::query()->where('record_type', 'AUT')->sole();

    expect($national->is_current)->toBeTrue()
        ->and($national->record_status)->toBe('APPROVED')
        ->and($national->swim_time)->toBe(4000)
        ->and($national->club_id)->toBe($this->club->id)
        ->and(SwimRecord::query()->where('record_type', 'AUT.WBSV')->sole()->swim_time)->toBe(4000)
        ->and(ImportReviewItem::query()->sole()->status)->toBe(ImportReviewItem::STATUS_APPLIED);
});

it('hängt einen älteren Eintrag als Historie vor den aktuellen Rekord, ohne Landesrekord', /** @throws Throwable */ function () {
    $current = SwimRecord::query()->create(['stroke_type_id' => $this->stroke->id, 'nation_id' => $this->austria->id,
        'record_type' => 'AUT', 'sport_class' => 'S14', 'gender' => 'F', 'course' => 'SCM', 'distance' => 50,
        'relay_count' => 1, 'swim_time' => 3800, 'record_status' => 'APPROVED', 'is_current' => true, 'set_date' => '2015-01-01']);
    import_run(file_run(record_run('Neu', '00:00:40.00', '2010-05-01')), [athleteKey_run('Neu') => 'new']);
    $admin = User::factory()->create(['is_admin' => true, 'club_id' => null]);

    app(RecordImportReviewService::class)->apply(ImportReviewItem::query()->sole(), $admin->id);

    $older = SwimRecord::query()->where('swim_time', 4000)->sole();

    expect($older->is_current)->toBeFalse()
        ->and($older->superseded_by_id)->toBe($current->id)
        ->and($current->fresh()->supersedes_id)->toBe($older->id)
        ->and($current->fresh()->is_current)->toBeTrue()
        ->and(SwimRecord::query()->where('record_type', 'AUT.WBSV')->count())->toBe(0);
});

it('legt beim Ignorieren keinen Rekord an', /** @throws Throwable */ function () {
    import_run(file_run(record_run('Neu', '00:00:40.00', '2010-05-01')), [athleteKey_run('Neu') => 'new']);
    $admin = User::factory()->create(['is_admin' => true, 'club_id' => null]);

    app(RecordImportReviewService::class)->ignore(ImportReviewItem::query()->sole(), $admin->id);

    expect(SwimRecord::query()->count())->toBe(0)
        ->and(ImportReviewItem::query()->sole()->status)->toBe(ImportReviewItem::STATUS_IGNORED);
});

it('zeigt in der Vorschau den Hinweis auf die Prüfliste', function () {
    $admin = User::factory()->create(['is_admin' => true, 'club_id' => null]);
    $file = new UploadedFile(file_run(record_run('Neu', '00:00:40.00', '2010-05-01')), 'Rekorde.lxf',
        'application/xml', null, true);

    $this->actingAs($admin)
        ->post(route('records.import.preview'), ['lenex_file' => $file])
        ->assertOk()
        ->assertSee('ohne Nationalität')
        ->assertDontSee('name="pending[', false);
});
