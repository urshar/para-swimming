<?php

use App\Models\Athlete;
use App\Models\AthleteClassification;
use App\Models\Classifier;
use App\Models\Club;
use App\Models\ExceptionCode;
use App\Models\Nation;
use App\Models\User;
use App\Services\TeamManagerImportService;
use App\Services\TeamManagerSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class)->group('team-manager-import-p2');

// Übernahme aus dem Splash Team Manager. Die Access-Datei selbst wird nicht gelesen (braucht einen Windows-Treiber),
// die Tests geben die Rohzeilen so vor, wie sie die Quelle liefert.

function club_tmi2(array $overrides = []): array
{
    return array_merge([
        'CLUBSID' => '1', 'NAME' => 'VCA Salzburg', 'SHORTNAME' => 'VCA Salzburg', 'CODE' => 'VCAS', 'LSC' => 'SLSV',
        'NATION' => 'AUT',
    ], $overrides);
}

function member_tmi2(array $overrides = []): array
{
    return array_merge([
        'MEMBERSID' => '716', 'LASTNAME' => 'Muster', 'FIRSTNAME' => 'Carina', 'NAMEPREFIX' => null,
        'BIRTHDATE' => '2001-01-20 00:00:00', 'GENDER' => '2', 'NATION' => 'AUT', 'CLUBSID1' => '1',
        'REGISTRATIONID' => 'S - 0726', 'SDMSID' => '69570', 'ACTIVE' => 'T', 'NOTES' => null,
        'STREET' => null, 'ZIP' => null, 'PLACE' => null, 'ADDRESSNATION' => null,
        'SWIMLEVEL' => 'A', 'GROUPS' => 'PIR', 'GRADE' => 'PI-R',
        'HANDICAPS' => '3', 'HANDICAPSB' => '2', 'HANDICAPSM' => '3', 'HANDICAPEX' => 'A,3,5,12',
        'LASTMEDICAL' => null, 'NEXTMEDICAL' => null, 'ENTRYDATE' => null, 'EXITDATE' => null,
        'FREE1' => null, 'FREE2' => null, 'FREE3' => null, 'FREE4' => null, 'FREE5' => null, 'FREE6' => null,
    ], $overrides);
}

function data_tmi2(array $members, ?array $clubs = null): array
{
    return ['clubs' => $clubs ?? [club_tmi2()], 'members' => $members];
}

function service_tmi2(): TeamManagerImportService
{
    return app(TeamManagerImportService::class);
}

/**
 * Vorschau und Import in einem Schritt, unbekannte Klassifizierer werden übergangen.
 *
 * @throws Throwable
 */
function import_tmi2(array $data): array
{
    $preview = service_tmi2()->preview($data);

    return service_tmi2()->import($preview, array_map(fn () => null, $preview->unknownClassifiers));
}

beforeEach(function () {
    Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
    Nation::firstOrCreate(['code' => 'UKR'], ['name_de' => 'Ukraine', 'name_en' => 'Ukraine', 'is_active' => true]);

    foreach (['A', 'Y', '3', '5', '12', '+'] as $code) {
        ExceptionCode::query()->create(['code' => $code, 'name_de' => $code, 'name_en' => $code, 'is_active' => true]);
    }
});

it('legt Vereine mit Landesverband an und erkennt vorhandene am Code', /** @throws Throwable */ function () {
    $austria = Nation::where('code', 'AUT')->value('id');
    $obsv = Club::query()->create(['name' => 'Österreichischer BSV', 'code' => 'ÖBSV', 'nation_id' => $austria]);
    $wat = Club::query()->create(['name' => 'WAT', 'code' => 'WAT', 'nation_id' => $austria]);

    $counts = import_tmi2(data_tmi2([], [
        club_tmi2(),
        club_tmi2(['CLUBSID' => '2', 'NAME' => 'Kärntner Behindertensportverband', 'CODE' => 'KBSV', 'LSC' => 'KLSV']),
        club_tmi2(['CLUBSID' => '3', 'NAME' => 'Österreichischer Behindertensportverband', 'CODE' => 'AUT', 'LSC' => null]),
        club_tmi2(['CLUBSID' => '4', 'NAME' => 'WAT Wien', 'CODE' => 'WAT', 'LSC' => 'WLSV']),
    ]));

    $vcas = Club::where('code', 'VCAS')->firstOrFail();
    $kbsv = Club::where('code', 'KBSV')->firstOrFail();

    expect($counts['clubs_created'])->toBe(2)
        ->and($counts['clubs_updated'])->toBe(2)
        ->and($vcas->type)->toBe('CLUB')
        ->and($vcas->regional_association)->toBe('SBSV')
        ->and($kbsv->type)->toBe('VERBAND')
        ->and($kbsv->regional_association)->toBe('KBSV')
        ->and($obsv->fresh()->type)->toBe('VERBAND')
        ->and($obsv->fresh()->regional_association)->toBeNull()
        ->and($wat->fresh()->regional_association)->toBe('WBSV')
        ->and($wat->fresh()->name)->toBe('WAT');
});

it('übernimmt die Stammdaten eines Athleten', /** @throws Throwable */ function () {
    import_tmi2(data_tmi2([member_tmi2([
        'STREET' => 'Hauptstraße 1', 'ZIP' => '5020', 'PLACE' => 'Salzburg', 'ADDRESSNATION' => 'AT',
        'LASTMEDICAL' => '2025-03-14 00:00:00', 'NEXTMEDICAL' => '1800-01-01 00:00:00',
    ])]));

    $athlete = Athlete::firstOrFail();

    expect($athlete->last_name)->toBe('Muster')
        ->and($athlete->gender)->toBe('F')
        ->and($athlete->birth_date->toDateString())->toBe('2001-01-20')
        ->and($athlete->license)->toBe('S-0726')
        ->and($athlete->license_ipc)->toBe('69570')
        ->and($athlete->club->code)->toBe('VCAS')
        ->and($athlete->is_active)->toBeTrue()
        ->and($athlete->level)->toBe('A')
        ->and($athlete->levelHistory()->count())->toBe(1)
        ->and($athlete->disability_group)->toBe('PI')
        ->and($athlete->disability_subgroup)->toBe('R')
        ->and($athlete->address_country)->toBe('AUT')
        ->and($athlete->last_medical_check_at->toDateString())->toBe('2025-03-14')
        ->and($athlete->next_medical_check_at)->toBeNull();
});

it('liest Lizenz, Level, Gruppe und Nationalität in den uneinheitlichen Schreibweisen der Datei', function () {
    $preview = service_tmi2()->preview(data_tmi2([
        member_tmi2(['MEMBERSID' => '1', 'REGISTRATIONID' => 'V–1234', 'SDMSID' => '0', 'ACTIVE' => 'F',
            'SWIMLEVEL' => 'II', 'GROUPS' => null, 'GRADE' => 'II-DS', 'NATION' => null, 'CLUBSID1' => '0']),
        member_tmi2(['MEMBERSID' => '2', 'LASTNAME' => 'Beispiel', 'REGISTRATIONID' => 'W - 99', 'GROUPS' => 'MI',
            'GRADE' => 'T21', 'NATION' => 'UKR', 'ADDRESSNATION' => 'AR']),
    ]));

    [$erster, $zweiter] = array_column($preview->athletes, 'attributes');

    expect($erster['license'])->toBe('V-1234')
        ->and($erster['license_ipc'])->toBeNull()
        ->and($erster['is_active'])->toBeFalse()
        ->and($erster['level'])->toBeNull()
        ->and($erster['disability_group'])->toBe('T21')
        ->and($erster['nation_id'])->toBe(Nation::where('code', 'AUT')->value('id'))
        ->and($preview->athletes[0]['access_club_id'])->toBeNull()
        ->and($zweiter['license'])->toBe('W-99')
        ->and($zweiter['disability_group'])->toBe('MI')
        ->and($zweiter['nation_id'])->toBe(Nation::where('code', 'UKR')->value('id'))
        ->and($zweiter['address_country'])->toBeNull()
        ->and($preview->warnings)->toContain('1 Athlet(en) ohne Verein.')
        ->and($preview->warnings)->toContain('1 Athlet(en) ohne Nationalität — AUT angenommen.')
        ->and($preview->warnings)->toContain('Beispiel Carina (Nr. 2): Gruppe "MI" und Ausbildung "T21" widersprechen sich — "MI" übernommen.')
        ->and($preview->warnings)->toContain('Beispiel Carina (Nr. 2): Land der Adresse "AR" ist unbekannt — leer gelassen.');
});

it('übernimmt Sportklassen und Ausnahme-Codes', /** @throws Throwable */ function () {
    $preview = service_tmi2()->preview(data_tmi2([
        member_tmi2(['MEMBERSID' => '1', 'HANDICAPEX' => 'A,3,5,12+']),
        member_tmi2(['MEMBERSID' => '2', 'LASTNAME' => 'Zwei', 'HANDICAPS' => '21', 'HANDICAPSB' => '21',
            'HANDICAPSM' => '0', 'HANDICAPEX' => 'A12, Y(50cm) DS']),
    ]));

    expect($preview->athletes[0]['sport_classes'])->toBe(['S' => 'S3', 'SB' => 'SB2', 'SM' => 'SM3'])
        ->and(array_column($preview->athletes[0]['exceptions'], 'code'))->toBe(['A', '3', '5', '12', '+'])
        ->and($preview->athletes[1]['sport_classes'])->toBe(['S' => 'S21', 'SB' => 'SB21'])
        ->and($preview->athletes[1]['exceptions'])->toBe([
            ['id' => ExceptionCode::where('code', 'Y')->value('id'), 'code' => 'Y', 'note' => '50CM'],
            ['id' => ExceptionCode::where('code', 'A')->value('id'), 'code' => 'A', 'note' => null],
            ['id' => ExceptionCode::where('code', '12')->value('id'), 'code' => '12', 'note' => null],
        ])
        ->and($preview->warnings)->toContain('Zwei Carina (Nr. 2): Ausnahme-Code(s) "DS" unbekannt — nicht übernommen.');

    service_tmi2()->import($preview, []);

    $athlete = Athlete::where('last_name', 'Muster')->firstOrFail();

    expect($athlete->sportClasses()->pluck('sport_class')->sort()->values()->all())->toBe(['S3', 'SB2', 'SM3'])
        ->and($athlete->exceptions()->pluck('code')->sort()->values()->all())->toBe(['+', '3', '5', '12', 'A']);
});

it('trägt die Klassifizierung samt Status, Datum, Ort und Klassifizierern in die Historie ein', /** @throws Throwable */ function () {
    $maurer = Classifier::query()->create(['first_name' => 'Hartwig', 'last_name' => 'Maurer', 'type' => 'MED']);
    $schmid = Classifier::query()->create(['first_name' => 'Michael', 'last_name' => 'Schmid', 'type' => 'TECH']);

    $preview = service_tmi2()->preview(data_tmi2([
        member_tmi2(['FREE1' => 'P-2029', 'FREE2' => '06.05.2026', 'FREE3' => 'Berlin',
            'FREE4' => 'Maurer  Hartwig', 'FREE5' => 'Michael Schmid', 'FREE6' => 'Danzl Gisela']),
    ]));

    expect($preview->unknownClassifiers)->toBe(['danzl gisela' => ['name' => 'Danzl Gisela', 'count' => 1]]);

    service_tmi2()->import($preview, ['danzl gisela' => null]);

    $classification = AthleteClassification::firstOrFail();

    expect($classification->classification_status)->toBe('FRD')
        ->and($classification->frd_year)->toBe(2029)
        ->and($classification->classified_at->toDateString())->toBe('2026-05-06')
        ->and($classification->location)->toBe('Berlin')
        ->and($classification->med_classifier_id)->toBe($maurer->id)
        ->and($classification->tech1_classifier_id)->toBe($schmid->id)
        ->and($classification->tech2_classifier_id)->toBeNull()
        ->and($classification->classification_scope)->toBe('INTL')
        ->and($classification->result_s)->toBe('S3')
        ->and($classification->exceptions()->count())->toBe(4)
        ->and(Athlete::firstOrFail()->getSportClass('S')->classification_status)->toBe('FRD');
});

it('liest die Schreibweisen des Klassifizierungsstatus', function (string $value, ?string $status, ?int $year) {
    $preview = service_tmi2()->preview(data_tmi2([member_tmi2(['FREE1' => $value])]));

    expect($preview->athletes[0]['classification']['classification_status'])->toBe($status)
        ->and($preview->athletes[0]['classification']['frd_year'])->toBe($year);
})->with([
    ['C', 'CONFIRMED', null],
    ['C-2025', 'CONFIRMED', null],
    ['N', 'NEW', null],
    ['Review', 'REVIEW', null],
    ['R-2025', 'FRD', 2025],
    ['R2025', 'FRD', 2025],
    ['Review 2020', 'FRD', 2020],
    ['P-2029', 'FRD', 2029],
    ['unklar', null, null],
]);

it('legt bei einem zweiten Lauf nichts doppelt an', /** @throws Throwable */ function () {
    $data = data_tmi2([member_tmi2(['FREE1' => 'C', 'FREE2' => '06.05.2026', 'FREE3' => 'Rif'])]);

    import_tmi2($data);
    $counts = import_tmi2($data);

    expect($counts['athletes_created'])->toBe(0)
        ->and($counts['athletes_updated'])->toBe(1)
        ->and($counts['classifications'])->toBe(0)
        ->and(Athlete::count())->toBe(1)
        ->and(Club::count())->toBe(1)
        ->and(AthleteClassification::count())->toBe(1);
});

it('verweigert den Import, solange ein unbekannter Klassifizierer nicht zugeordnet ist', function () {
    $preview = service_tmi2()->preview(data_tmi2([member_tmi2(['FREE5' => 'Hava Thomas'])]));

    expect(/** @throws Throwable */ fn () => service_tmi2()->import($preview, []))
        ->toThrow(RuntimeException::class, 'Nicht alle unbekannten Klassifizierer sind zugeordnet.')
        ->and(Athlete::count())->toBe(0);
});

it('führt über Upload, Vorschau und Zuordnung zum Import', function () {
    $admin = User::factory()->create(['is_admin' => true, 'club_id' => null]);
    $hava = Classifier::query()->create(['first_name' => 'Thomas', 'last_name' => 'Hava', 'type' => 'TECH']);
    app()->instance(TeamManagerSource::class, new class implements TeamManagerSource
    {
        public function read(string $path): array
        {
            return data_tmi2([member_tmi2(['FREE5' => 'Hava Tomas'])]);
        }
    });

    $this->actingAs($admin)
        ->post(route('team-manager-import.upload'), ['team_file' => UploadedFile::fake()->create('Team.mdb', 100)])
        ->assertRedirect(route('team-manager-import.preview'));

    $this->actingAs($admin)
        ->get(route('team-manager-import.preview'))
        ->assertOk()
        ->assertSee('Unbekannte Klassifizierer (1)')
        ->assertSee('Hava Tomas');

    $feld = 'classifier_map.'.md5('hava tomas');

    $this->actingAs($admin)
        ->from(route('team-manager-import.preview'))
        ->post(route('team-manager-import.run'))
        ->assertRedirect(route('team-manager-import.preview'))
        ->assertSessionHasErrors($feld);

    $this->actingAs($admin)
        ->post(route('team-manager-import.run'), ['classifier_map' => [md5('hava tomas') => (string) $hava->id]])
        ->assertRedirect(route('athletes.index'))
        ->assertSessionHas('success');

    expect(AthleteClassification::firstOrFail()->tech1_classifier_id)->toBe($hava->id);
});

it('schickt ohne hochgeladene Datei zurück zum Formular', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->get(route('team-manager-import.preview'))
        ->assertRedirect(route('team-manager-import'))
        ->assertSessionHasErrors('team_file');
});

it('nennt bei einer doppelten Lizenznummer die betroffenen Athleten', function () {
    $preview = service_tmi2()->preview(data_tmi2([
        member_tmi2(['MEMBERSID' => '1', 'LASTNAME' => 'Erste', 'REGISTRATIONID' => 'K-0538']),
        member_tmi2(['MEMBERSID' => '2', 'LASTNAME' => 'Zweite', 'REGISTRATIONID' => 'K - 0538']),
        member_tmi2(['MEMBERSID' => '3', 'LASTNAME' => 'Dritte', 'REGISTRATIONID' => 'K-0539']),
    ]));

    expect($preview->warnings)->toContain(
        'Lizenznummer K-0538 kommt 2× vor (Erste Carina (Nr. 1), Zweite Carina (Nr. 2)) — diese Athleten werden über Name und Geburtsdatum zugeordnet.'
    );
});

it('trägt S14 und S21 ohne Klassifizierungsangaben als nationale, bestätigte Klassifizierung ein', /** @throws Throwable */ function () {
    import_tmi2(data_tmi2([
        member_tmi2(['MEMBERSID' => '1', 'LASTNAME' => 'Vierzehn', 'REGISTRATIONID' => 'W-1', 'SDMSID' => '0',
            'HANDICAPS' => '14', 'HANDICAPSB' => '14', 'HANDICAPSM' => '14', 'HANDICAPEX' => null]),
        member_tmi2(['MEMBERSID' => '2', 'LASTNAME' => 'Einundzwanzig', 'REGISTRATIONID' => 'W-2', 'SDMSID' => '0',
            'HANDICAPS' => '21', 'HANDICAPSB' => '21', 'HANDICAPSM' => '0', 'HANDICAPEX' => null]),
        member_tmi2(['MEMBERSID' => '3', 'LASTNAME' => 'Sechs', 'REGISTRATIONID' => 'W-3',
            'HANDICAPS' => '6', 'HANDICAPSB' => '5', 'HANDICAPSM' => '6']),
    ]));

    $vierzehn = Athlete::where('last_name', 'Vierzehn')->firstOrFail()->classifications()->sole();
    $einundzwanzig = Athlete::where('last_name', 'Einundzwanzig')->firstOrFail()->classifications()->sole();

    expect($vierzehn->classification_scope)->toBe('NAT')
        ->and($vierzehn->classification_status)->toBe('CONFIRMED')
        ->and($vierzehn->classified_at)->toBeNull()
        ->and([$vierzehn->result_s, $vierzehn->result_sb, $vierzehn->result_sm])->toBe(['S14', 'SB14', 'SM14'])
        ->and([$einundzwanzig->result_s, $einundzwanzig->result_sb, $einundzwanzig->result_sm])->toBe(['S21', 'SB21', null])
        ->and(Athlete::where('last_name', 'Vierzehn')->firstOrFail()->getSportClass('S')->classification_status)->toBe('CONFIRMED')
        ->and(Athlete::where('last_name', 'Sechs')->firstOrFail()->classifications()->count())->toBe(0);
});
