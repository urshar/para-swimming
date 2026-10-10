<?php

use App\Models\Athlete;
use App\Models\AthleteClubHistory;
use App\Models\Club;
use App\Models\ImportReviewItem;
use App\Models\Nation;
use App\Models\RelayTeamMember;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimRecord;
use App\Models\User;
use App\Services\RecordImportReviewService;
use App\Services\RecordImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class)->group('record-import-review');

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function nation_rir(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'],
        ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
}

function stroke_rir(): StrokeType
{
    return StrokeType::firstOrCreate(['lenex_code' => 'FREE'],
        ['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'code' => 'FREE']);
}

function club_rir(string $code): Club
{
    return Club::create(['name' => 'Verein '.$code, 'code' => $code, 'nation_id' => nation_rir()->id]);
}

/** Athlet mit Stammverein und aktivem History-Eintrag ab $joinedAt. */
function athlete_rir(string $lastName, Club $club, ?string $joinedAt): Athlete
{
    $athlete = Athlete::create([
        'nation_id' => nation_rir()->id,
        'first_name' => 'Max',
        'last_name' => $lastName,
        'birth_date' => '2000-05-05',
        'gender' => 'M',
        'club_id' => $club->id,
    ]);

    if ($joinedAt !== null) {
        AthleteClubHistory::create([
            'athlete_id' => $athlete->id, 'club_id' => $club->id, 'joined_at' => $joinedAt, 'is_active' => true,
        ]);
    }

    return $athlete;
}

/** Athlet ohne Stammverein. */
function clubless_rir(string $lastName): Athlete
{
    return Athlete::create([
        'nation_id' => nation_rir()->id,
        'first_name' => 'Max',
        'last_name' => $lastName,
        'birth_date' => '2000-05-05',
        'gender' => 'M',
    ]);
}

function athleteXml_rir(Athlete $athlete, ?string $birthDate): string
{
    return '<ATHLETE lastname="'.$athlete->last_name.'" firstname="'.$athlete->first_name.'" birthdate="'
        .($birthDate ?? $athlete->birth_date->toDateString()).'" gender="M"';
}

/** Einzelrekord als RECORDLIST-Fragment. */
function individualXml_rir(Athlete $athlete, string $clubCode, string $date, string $type, int $distance): string
{
    return '<RECORDLIST type="'.$type.'" course="SCM" gender="M" handicap="10"><RECORDS>'
        .'<RECORD swimtime="00:30.00"><SWIMSTYLE stroke="FREE" distance="'.$distance.'" relaycount="1"/>'
        .athleteXml_rir($athlete,
            null).'><CLUB code="'.$clubCode.'" name="Verein '.$clubCode.'" nation="AUT"/></ATHLETE>'
        .'<MEETINFO name="Testmeet" city="Wien" nation="AUT" date="'.$date.'"/>'
        .'</RECORD></RECORDS></RECORDLIST>';
}

/** Staffelrekord (4×50 m) mit den Athleten als Mitglieder. */
function relayXml_rir(array $athletes, string $clubCode, string $date, string $type): string
{
    $positions = '';
    foreach ($athletes as $i => $athlete) {
        $positions .= '<RELAYPOSITION number="'.($i + 1).'">'.athleteXml_rir($athlete, null).'/></RELAYPOSITION>';
    }

    return '<RECORDLIST type="'.$type.'" course="SCM" gender="M" handicap="14"><RECORDS>'
        .'<RECORD swimtime="02:00.00"><SWIMSTYLE stroke="FREE" distance="50" relaycount="4"/>'
        .'<RELAY><CLUB code="'.$clubCode.'" name="Verein '.$clubCode.'" nation="AUT"/>'
        .'<RELAYPOSITIONS>'.$positions.'</RELAYPOSITIONS></RELAY>'
        .'<MEETINFO name="Testmeet" city="Wien" nation="AUT" date="'.$date.'"/>'
        .'</RECORD></RECORDS></RECORDLIST>';
}

function lenex_rir(string ...$recordLists): string
{
    return '<?xml version="1.0" encoding="UTF-8"?><LENEX version="3.0"><RECORDLISTS>'
        .implode('', $recordLists).'</RECORDLISTS></LENEX>';
}

function lenexFile_rir(string $xml): string
{
    $path = tempnam(sys_get_temp_dir(), 'rir_').'.xml';
    file_put_contents($path, $xml);

    return $path;
}

function admin_rir(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

/**
 * preview()/import() deklarieren geprüfte Exceptions; im Test führt jede davon ohnehin zum Fehlschlag. Die Helper
 * reichen sie deshalb als (ungeprüfte) LogicException weiter, statt jede Aufrufstelle mit "@throws" zu versehen.
 */
function preview_rir(string $xml): array
{
    try {
        return (new RecordImportService)->preview(lenexFile_rir($xml));
    } catch (Throwable $e) {
        throw new LogicException($e->getMessage(), 0, $e);
    }
}

/** Import ohne Vereins-/Regional-/Pending-Entscheidungen; nur Athleten-Zuordnungen. */
function import_rir(string $xml, array $approvedAthletes, string $source): array
{
    try {
        return (new RecordImportService)->import(
            filePath: lenexFile_rir($xml),
            approvedClubs: [],
            approvedAthletes: $approvedAthletes,
            newClubData: [],
            newAthleteData: [],
            source: $source,
        );
    } catch (Throwable $e) {
        throw new LogicException($e->getMessage(), 0, $e);
    }
}

beforeEach(function () {
    stroke_rir();
});

// ── Vorschau ──────────────────────────────────────────────────────────────────

describe('Vorschau: Verein laut Rekord für Athleten ohne Verein', function () {

    it('nennt einen Athleten ohne Verein mit dem Verein laut Rekord', function () {
        $new = club_rir('NEU');
        $athlete = clubless_rir('Zimmermann');

        $assignments = preview_rir(lenex_rir(
            individualXml_rir($athlete, 'NEU', '2024-03-01', 'AUT', 50),
        ))['club_assignments'];

        expect($assignments)->toHaveCount(1)
            ->and($assignments[0]['athlete_id'])->toBe($athlete->id)
            ->and($assignments[0]['current_club_id'])->toBeNull()
            ->and($assignments[0]['lenex_club_id'])->toBe($new->id)
            ->and($assignments[0]['date'])->toBe('2024-03-01');
    });

    it('lässt Athleten mit einem anderen Stammverein aus', function () {
        club_rir('NEU');
        $athlete = athlete_rir('Saram', club_rir('ALT'), '2022-01-01');

        $assignments = preview_rir(lenex_rir(
            individualXml_rir($athlete, 'NEU', '2024-06-01', 'AUT', 50),
        ))['club_assignments'];

        expect($assignments)->toBe([]);
    });

    it('nimmt den Verein des jüngsten Rekords', function () {
        club_rir('ALT');
        $new = club_rir('NEU');
        $athlete = clubless_rir('Saram');

        $assignments = preview_rir(lenex_rir(
            individualXml_rir($athlete, 'ALT', '2018-06-01', 'AUT', 50),
            individualXml_rir($athlete, 'NEU', '2023-06-01', 'AUT', 100),
        ))['club_assignments'];

        expect($assignments[0]['lenex_club_id'])->toBe($new->id);
    });

    it('wertet Mitglieder nationaler Staffeln aus, internationale Staffeln nicht', function () {
        club_rir('NEU');
        $a = clubless_rir('Staffel');

        $national = preview_rir(lenex_rir(
            relayXml_rir([$a], 'NEU', '2024-01-01', 'AUT'),
        ))['club_assignments'];
        $international = preview_rir(lenex_rir(
            relayXml_rir([$a], 'NEU', '2024-01-01', 'WR'),
        ))['club_assignments'];

        expect($national)->toHaveCount(1)
            ->and($national[0]['relay'])->toBeTrue()
            ->and($international)->toBe([]);
    });

    it('wertet Rekorde für einen Verband (z. B. ÖBSV als Nationalteam) nicht', function () {
        Club::create([
            'name' => 'Verband', 'code' => 'VBD', 'nation_id' => nation_rir()->id,
            'type' => Club::TYPE_VERBAND,
        ]);
        $athlete = clubless_rir('National');

        $assignments = preview_rir(lenex_rir(
            individualXml_rir($athlete, 'VBD', '2025-12-06', 'AUT', 50),
        ))['club_assignments'];

        expect($assignments)->toBe([]);
    });

    it('lässt den Einzelrekord vor einer jüngeren Staffel gelten', function () {
        $old = club_rir('ALT');
        club_rir('NEU');
        $athlete = clubless_rir('Einzel');

        $assignments = preview_rir(lenex_rir(
            individualXml_rir($athlete, 'ALT', '2020-01-01', 'AUT', 50),
            relayXml_rir([$athlete], 'NEU', '2024-01-01', 'AUT'),
        ))['club_assignments'];

        expect($assignments[0]['lenex_club_id'])->toBe($old->id);
    });
});

// ── Import ────────────────────────────────────────────────────────────────────

describe('Import: Verein laut Rekord', function () {

    it('gibt einem Athleten ohne Verein den Verein laut Rekord, ab dem Rekorddatum, ohne Prüfliste', function () {
        $new = club_rir('NEU');
        $athlete = clubless_rir('Zimmermann');

        $result = import_rir(lenex_rir(individualXml_rir($athlete, 'NEU', '2024-03-01', 'AUT', 50)), [], 'oebsv.lxf');

        $history = AthleteClubHistory::where('athlete_id', $athlete->id)->sole();

        expect($result['club_updated'])->toBe(1)
            ->and($result['review_open'])->toBe(0)
            ->and($athlete->fresh()->club_id)->toBe($new->id)
            ->and($history->club_id)->toBe($new->id)
            ->and($history->joined_at->toDateString())->toBe('2024-03-01')
            ->and(ImportReviewItem::count())->toBe(0);
    });

    it('lässt einen anderen Stammverein unverändert und schreibt nichts in die Prüfliste', function () {
        $old = club_rir('ALT');
        $new = club_rir('NEU');
        $athlete = athlete_rir('Zimmermann', $old, '2020-01-01');

        $result = import_rir(lenex_rir(individualXml_rir($athlete, 'NEU', '2024-03-01', 'AUT', 50)), [], 'a.lxf');

        expect($result['club_updated'])->toBe(0)
            ->and($result['review_open'])->toBe(0)
            ->and($athlete->fresh()->club_id)->toBe($old->id)
            ->and(SwimRecord::sole()->club_id)->toBe($new->id)
            ->and(AthleteClubHistory::where('athlete_id', $athlete->id)->count())->toBe(1)
            ->and(ImportReviewItem::count())->toBe(0);
    });

    it('nimmt die Zuordnung auf eine Person mit abweichendem Geburtsdatum zur Kontrolle auf', function () {
        $athlete = athlete_rir('Hochenberger', club_rir('TST'), null);
        $xml = lenex_rir('<RECORDLIST type="AUT" course="SCM" gender="M" handicap="10"><RECORDS>'
            .'<RECORD swimtime="00:30.00"><SWIMSTYLE stroke="FREE" distance="50" relaycount="1"/>'
            .athleteXml_rir($athlete, '2000-01-01').'><CLUB code="TST" name="Verein TST" nation="AUT"/></ATHLETE>'
            .'</RECORD></RECORDS></RECORDLIST>');
        $key = preview_rir($xml)['unknown_athletes'][0]['key'];

        import_rir($xml, [$key => (string) $athlete->id], 'a.lxf');

        $item = ImportReviewItem::sole();

        expect($item->type)->toBe(ImportReviewItem::TYPE_YEAR_MATCH)
            ->and($item->athlete_id)->toBe($athlete->id)
            ->and($item->details['file_birth_date'])->toBe('2000-01-01')
            ->and($item->details['db_birth_date'])->toBe('2000-05-05')
            ->and($item->status)->toBe(ImportReviewItem::STATUS_OPEN);
    });

    it('zeigt die Zuordnung in der Vorschau und setzt sie im echten Formular-Ablauf', function () {
        $new = club_rir('NEU');
        $athlete = clubless_rir('Zimmermann');
        $file = UploadedFile::fake()->createWithContent('oebsv.lxf',
            lenex_rir(individualXml_rir($athlete, 'NEU', '2024-03-01', 'AUT', 50)));

        $this->actingAs(admin_rir())
            ->post(route('records.import.preview'), ['lenex_file' => $file])
            ->assertOk()
            ->assertSee('Bekommen den Verein laut Rekord (1)')
            ->assertDontSee('club_updates');

        $this->post(route('records.import.run'))
            ->assertRedirect(route('records.index'))
            ->assertSessionHas('success', fn (string $msg) => str_contains($msg, '1 Athlet(en) ohne Verein bekamen den Verein laut Rekord'));

        expect($athlete->fresh()->club_id)->toBe($new->id);
    });
});

// ── Prüfliste ─────────────────────────────────────────────────────────────────

describe('Prüfliste', function () {

    it('findet bei der Bestandsprüfung Athleten ohne Verein aus Einzel- und Staffelrekorden, ändert aber nichts', function () {
        $old = club_rir('ALT');
        $new = club_rir('NEU');
        $single = clubless_rir('Einzel');
        $member = clubless_rir('Staffel');
        $withClub = athlete_rir('Vereinsmitglied', $old, null);
        $base = [
            'stroke_type_id' => stroke_rir()->id, 'nation_id' => nation_rir()->id, 'gender' => 'M',
            'course' => 'SCM', 'distance' => 50, 'swim_time' => 3000, 'record_status' => 'APPROVED',
            'is_current' => true, 'set_date' => '2024-01-01', 'club_id' => $new->id,
        ];
        SwimRecord::create([
            ...$base, 'record_type' => 'AUT', 'sport_class' => 'S10', 'relay_count' => 1,
            'athlete_id' => $single->id,
        ]);
        SwimRecord::create([
            ...$base, 'record_type' => 'AUT', 'sport_class' => 'S9', 'relay_count' => 1,
            'athlete_id' => $withClub->id,
        ]);
        $relay = SwimRecord::create([...$base, 'record_type' => 'AUT', 'sport_class' => 'S14', 'relay_count' => 4]);
        RelayTeamMember::create([
            'swim_record_id' => $relay->id, 'position' => 1, 'first_name' => 'Max',
            'last_name' => 'Staffel', 'athlete_id' => $member->id,
        ]);

        $service = new RecordImportReviewService;
        $created = $service->scanExisting();
        $again = $service->scanExisting();

        expect($created)->toBe(2)
            ->and($again)->toBe(0)
            ->and(ImportReviewItem::open()->pluck('athlete_id')->sort()->values()->all())
            ->toBe(collect([$single->id, $member->id])->sort()->values()->all())
            ->and(ImportReviewItem::first()->source)->toBe(RecordImportReviewService::SOURCE_SCAN)
            ->and($single->fresh()->club_id)->toBeNull()
            ->and($withClub->fresh()->club_id)->toBe($old->id);
    });

    it('übernimmt den Verein aus der Prüfliste und markiert den Eintrag', function () {
        $old = club_rir('ALT');
        $new = club_rir('NEU');
        $athlete = athlete_rir('Zimmermann', $old, '2020-01-01');
        $item = ImportReviewItem::create([
            'type' => ImportReviewItem::TYPE_CLUB_CONFLICT, 'athlete_id' => $athlete->id,
            'current_club_id' => $old->id, 'lenex_club_id' => $new->id, 'source' => 'a.lxf',
            'details' => ['date' => '2024-03-01', 'relay' => false, 'label' => '50m Freistil S10 SCM (AUT)'],
            'status' => ImportReviewItem::STATUS_OPEN,
        ]);

        $this->actingAs(admin_rir())
            ->from(route('records.import-review.index'))
            ->post(route('records.import-review.apply', $item))
            ->assertRedirect(route('records.import-review.index'))
            ->assertSessionHas('success');

        expect($athlete->fresh()->club_id)->toBe($new->id)
            ->and($item->fresh()->status)->toBe(ImportReviewItem::STATUS_APPLIED)
            ->and(AthleteClubHistory::where('athlete_id', $athlete->id)->where('is_active', true)->sole()->joined_at
                ->toDateString())->toBe('2024-03-01');
    });

    it('ignoriert einen Eintrag, und die Bestandsprüfung nimmt ihn nicht wieder auf', function () {
        $new = club_rir('NEU');
        $athlete = clubless_rir('Einzel');
        SwimRecord::create([
            'stroke_type_id' => stroke_rir()->id, 'nation_id' => nation_rir()->id, 'gender' => 'M',
            'course' => 'SCM', 'distance' => 50, 'swim_time' => 3000, 'record_status' => 'APPROVED',
            'is_current' => true, 'set_date' => '2024-01-01', 'club_id' => $new->id, 'record_type' => 'AUT',
            'sport_class' => 'S10', 'relay_count' => 1, 'athlete_id' => $athlete->id,
        ]);

        $this->actingAs(admin_rir())->post(route('records.import-review.scan'))->assertRedirect();
        $item = ImportReviewItem::sole();

        $this->post(route('records.import-review.ignore', $item))->assertRedirect();
        $this->post(route('records.import-review.scan'))
            ->assertSessionHas('success', 'Bestandsprüfung: keine neuen Einträge gefunden.');

        expect($item->fresh()->status)->toBe(ImportReviewItem::STATUS_IGNORED)
            ->and(ImportReviewItem::count())->toBe(1)
            ->and($athlete->fresh()->club_id)->toBeNull();
    });

    it('zeigt offene Einträge an und filtert nach Status', function () {
        $club = club_rir('ALT');
        $athlete = athlete_rir('Zimmermann', $club, null);
        ImportReviewItem::create([
            'type' => ImportReviewItem::TYPE_YEAR_MATCH, 'athlete_id' => $athlete->id, 'source' => 'a.lxf',
            'details' => [
                'file_name' => 'ZIMMERMANN, Max', 'file_birth_date' => '2000-01-01', 'db_birth_date' => '2000-05-05',
            ],
            'status' => ImportReviewItem::STATUS_OPEN,
        ]);

        $this->actingAs(admin_rir())
            ->get(route('records.import-review.index'))
            ->assertOk()
            ->assertSee('Geburtsdatum abweichend')
            ->assertSee('ZIMMERMANN, Max')
            ->assertSee('01.01.2000');

        $this->get(route('records.import-review.index', ['status' => 'ignored']))
            ->assertOk()
            ->assertSee('Keine Einträge.');
    });

    it('verweigert Vereinsnutzern die Prüfliste', function () {
        $club = club_rir('ALT');

        $this->actingAs(User::factory()->create(['is_admin' => false, 'club_id' => $club->id]))
            ->get(route('records.import-review.index'))
            ->assertForbidden();
    });
});

// ── Nationalität nicht AUT ────────────────────────────────────────────────────

/** Ändert die Nationalität eines Athleten nachträglich (wie im Komarov-Fall). */
function foreign_rir(Athlete $athlete, string $code): Athlete
{
    $nation = Nation::firstOrCreate(['code' => $code], ['name_de' => $code, 'name_en' => $code, 'is_active' => true]);
    $athlete->update(['nation_id' => $nation->id]);

    return $athlete;
}

/** Einzelrekord 50 m Freistil S5 AUT; $supersedes = Vorgänger in der Historie. */
function record_rir(?Athlete $athlete, Club $club, string $date, int $time, ?SwimRecord $supersedes, ?Result $result): SwimRecord
{
    $record = SwimRecord::create([
        'stroke_type_id' => stroke_rir()->id, 'nation_id' => nation_rir()->id, 'record_type' => 'AUT',
        'sport_class' => 'S5', 'gender' => 'M', 'course' => 'SCM', 'distance' => 50, 'relay_count' => 1,
        'swim_time' => $time, 'record_status' => 'APPROVED', 'is_current' => true, 'set_date' => $date,
        'athlete_id' => $athlete?->id, 'club_id' => $club->id, 'supersedes_id' => $supersedes?->id,
        'result_id' => $result?->id,
    ]);
    $supersedes?->markAsSupersededBy($record);

    return $record;
}

describe('Nationalität nicht AUT', function () {

    it('gibt Athleten anderer Nationalität ohne Verein keinen Verein laut Rekord', function () {
        club_rir('NEU');
        $athlete = foreign_rir(clubless_rir('Komarov'), 'UKR');

        $assignments = preview_rir(lenex_rir(
            individualXml_rir($athlete, 'NEU', '2024-03-01', 'AUT', 50),
        ))['club_assignments'];

        expect($assignments)->toBe([]);
    });

    it('findet bei der Bestandsprüfung aktuelle und historische Rekorde, aber keine von AUT-Athleten', function () {
        $club = club_rir('KBSV');
        $foreign = foreign_rir(athlete_rir('Komarov', $club, null), 'UKR');
        $older = record_rir($foreign, $club, '2023-04-22', 4000, null, null);
        $newer = record_rir($foreign, $club, '2023-09-16', 3900, $older, null);
        record_rir(athlete_rir('Österreicher', $club, null), $club, '2024-01-01', 3800, $newer, null);

        $created = (new RecordImportReviewService)->scanExisting();
        $items = ImportReviewItem::where('type', ImportReviewItem::TYPE_NATIONALITY)->orderBy('swim_record_id')->get();

        expect($created)->toBe(2)
            ->and($items->pluck('swim_record_id')->all())->toBe([$older->id, $newer->id])
            ->and($items->first()->details['nation'])->toBe('UKR')
            ->and((new RecordImportReviewService)->scanExisting())->toBe(0);
    });

    it('entfernt einen aktuellen Rekord: Vorgänger wird wieder aktuell, Rekord-Flag am Ergebnis zurückgesetzt', function () {
        $club = club_rir('KBSV');
        $foreign = foreign_rir(athlete_rir('Komarov', $club, null), 'UKR');
        $meet = makeMeet_p5();
        $result = Result::create(['meet_id' => $meet->id, 'swim_event_id' => makeEvent_p5($meet)->id,
            'athlete_id' => $foreign->id, 'club_id' => $club->id, 'swim_time' => 3900, 'is_national_record' => true]);
        $predecessor = record_rir(athlete_rir('Österreicher', $club, null), $club, '2020-01-01', 4000, null, null);
        $invalid = record_rir($foreign, $club, '2023-09-16', 3900, $predecessor, $result);

        (new RecordImportReviewService)->scanExisting();
        $item = ImportReviewItem::where('type', ImportReviewItem::TYPE_NATIONALITY)->sole();

        $this->actingAs(admin_rir())
            ->from(route('records.import-review.index'))
            ->post(route('records.import-review.apply', $item))
            ->assertRedirect(route('records.import-review.index'))
            ->assertSessionHas('success');

        $predecessor->refresh();

        expect(SwimRecord::find($invalid->id))->toBeNull()
            ->and($predecessor->is_current)->toBeTrue()
            ->and($predecessor->record_status)->toBe('APPROVED')
            ->and($predecessor->superseded_by_id)->toBeNull()
            ->and($result->fresh()->is_national_record)->toBeFalse()
            ->and($item->fresh()->status)->toBe(ImportReviewItem::STATUS_APPLIED)
            ->and($item->fresh()->status_label)->toBe('Entfernt');
    });

    it('entfernt einen historischen Rekord und verknüpft Vorgänger und Nachfolger', function () {
        $club = club_rir('KBSV');
        $foreign = foreign_rir(athlete_rir('Komarov', $club, null), 'UKR');
        $first = record_rir(athlete_rir('Erster', $club, null), $club, '2019-01-01', 4100, null, null);
        $invalid = record_rir($foreign, $club, '2023-04-22', 4000, $first, null);
        $current = record_rir(athlete_rir('Aktuell', $club, null), $club, '2024-01-01', 3800, $invalid, null);

        (new RecordImportReviewService)->scanExisting();

        $this->actingAs(admin_rir())
            ->post(route('records.import-review.apply', ImportReviewItem::where('type', ImportReviewItem::TYPE_NATIONALITY)->sole()));

        expect(SwimRecord::find($invalid->id))->toBeNull()
            ->and($current->fresh()->supersedes_id)->toBe($first->id)
            ->and($current->fresh()->is_current)->toBeTrue()
            ->and($first->fresh()->superseded_by_id)->toBe($current->id)
            ->and($first->fresh()->is_current)->toBeFalse();
    });

    it('nimmt beim Import neue Rekorde von Athleten anderer Nationalität auf', function () {
        $club = club_rir('KBSV');
        $foreign = foreign_rir(athlete_rir('Komarov', $club, null), 'UKR');

        $result = import_rir(lenex_rir(individualXml_rir($foreign, 'KBSV', '2024-03-01', 'AUT', 50)), [], 'a.lxf');

        expect($result['review_open'])->toBe(1)
            ->and(ImportReviewItem::sole()->type)->toBe(ImportReviewItem::TYPE_NATIONALITY)
            ->and(ImportReviewItem::sole()->swim_record_id)->toBe(SwimRecord::sole()->id);
    });
});
