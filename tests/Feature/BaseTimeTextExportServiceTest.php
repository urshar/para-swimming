<?php

use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeDiscipline;
use App\Models\BaseTimeSportClass;
use App\Models\BaseTimeVersion;
use App\Models\StrokeType;
use App\Models\User;
use App\Services\BaseTimeTextExportService;
use App\Services\BaseTimeTextImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('base-time-text-export');

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

/** Legt die vom Import/Export benötigten StrokeTypes an. */
function seedStrokeTypes_bttxe(): void
{
    foreach (['FREE', 'BACK', 'BREAST', 'FLY', 'MEDLEY'] as $code) {
        StrokeType::create(['name_de' => $code, 'name_en' => $code, 'lenex_code' => $code, 'code' => $code]);
    }
}

// ── buildContent() ──────────────────────────────────────────────────────────────

describe('BaseTimeTextExportService::buildContent', function () {
    beforeEach(function () {
        seedStrokeTypes_bttxe();
        $free = StrokeType::where('lenex_code', 'FREE')->first();
        $medley = StrokeType::where('lenex_code', 'MEDLEY')->first();

        $this->version = BaseTimeVersion::create(['label' => 'OeBSV 2021', 'valid_from' => '2021-01-01', 'valid_until' => null]);
        $category = BaseTimeCategory::create(['code' => 'SC_WOMEN', 'course' => 'SCM', 'gender' => 'F', 'label' => 'SC Women']);

        $einzel = BaseTimeDiscipline::create(['code' => '25FR', 'distance' => 25, 'relay_count' => 1, 'stroke_type_id' => $free->id]);
        $einzelNa = BaseTimeDiscipline::create(['code' => '50FR', 'distance' => 50, 'relay_count' => 1, 'stroke_type_id' => $free->id]);
        $staffel = BaseTimeDiscipline::create(['code' => '4x25ME', 'distance' => 25, 'relay_count' => 4, 'stroke_type_id' => $medley->id]);

        $s1 = BaseTimeSportClass::create(['code' => 'S1', 'sort_order' => 1]);
        $s14 = BaseTimeSportClass::create(['code' => 'S14', 'sort_order' => 2]);

        BaseTime::create(['base_time_version_id' => $this->version->id, 'base_time_category_id' => $category->id,
            'base_time_discipline_id' => $einzel->id, 'base_time_sport_class_id' => $s1->id,
            'value_centiseconds' => 2578, 'value_type' => BaseTime::TYPE_MANUAL]);
        BaseTime::create(['base_time_version_id' => $this->version->id, 'base_time_category_id' => $category->id,
            'base_time_discipline_id' => $einzelNa->id, 'base_time_sport_class_id' => $s1->id,
            'value_centiseconds' => 0, 'value_type' => BaseTime::TYPE_NOT_APPLICABLE]);
        BaseTime::create(['base_time_version_id' => $this->version->id, 'base_time_category_id' => $category->id,
            'base_time_discipline_id' => $staffel->id, 'base_time_sport_class_id' => $s14->id,
            'value_centiseconds' => 7104, 'value_type' => BaseTime::TYPE_MANUAL]);

        $this->content = (new BaseTimeTextExportService)->buildContent($this->version);
    });

    it('schreibt den vollständigen Kopf mit Metadaten aus der Version', function () {
        expect($this->content)->toContain('Formula=CUBED')
            ->and($this->content)->toContain('Id=502')
            ->and($this->content)->toContain('Name=OeBSV 2021')
            ->and($this->content)->toContain('Options=HANDICAP')
            ->and($this->content)->toContain('Version=2021')
            ->and($this->content)->toContain('<BASETIMES>')
            ->and($this->content)->toContain('COURSE;GENDER;RELAYCOUNT;DISTANCE;STROKE;HANDICAP;MINTIME;MAXTIME');
    });

    it('schreibt Einzelbewerbe mit Handicap ohne S-Präfix', function () {
        expect($this->content)->toContain('SCM;F;1;25;FREE;1;00:25.78');
    });

    it('schreibt Staffeln mit S-Präfix und MEDLEY als STROKE', function () {
        expect($this->content)->toContain('SCM;F;4;25;MEDLEY;S14;01:11.04');
    });

    it('schreibt NOT_APPLICABLE als 99:99.99-Sentinel', function () {
        expect($this->content)->toContain('SCM;F;1;50;FREE;1;99:99.99');
    });

    it('nutzt Windows-Zeilenumbrüche', function () {
        expect($this->content)->toContain("\r\n");
    });
});

// ── downloadFilename() ──────────────────────────────────────────────────────────

it('baut einen .txt-Download-Dateinamen aus dem Versions-Label', function () {
    $version = BaseTimeVersion::create(['label' => '2021-2026', 'valid_from' => '2021-01-01', 'valid_until' => null]);

    expect((new BaseTimeTextExportService)->downloadFilename($version))->toBe('OeBSV-Base-Times_2021-2026.txt');
});

// ── Round-Trip gegen die echte OeBSV-2021-Datei ─────────────────────────────────

it('exportiert die echte Datei so, dass ein Re-Import identische Basiswerte ergibt', function () {
    seedStrokeTypes_bttxe();
    // Import-Service über $this: die import*()-Methoden deklarieren @throws Throwable (aus
    // DB::transaction); über die untypisierte Test-Property greift PhpStorms Unhandled-Throwable-
    // Inspection nicht (gleiches Muster wie die übrigen Import-Tests). buildContent() wirft nicht.
    $this->import = new BaseTimeTextImportService;
    $export = new BaseTimeTextExportService;

    // Import der Fixture → Version A
    $resultA = $this->import->import(base_path('tests/Fixtures/base-times/502-para-2021.txt'), [
        'label' => 'A 2021', 'valid_from' => '2021-01-01', 'valid_until' => null,
    ]);
    $versionA = BaseTimeVersion::find($resultA['version_id']);

    // Export → temporäre Datei → Re-Import in Version B (importIntoExistingVersion umgeht die
    // Überlappungsprüfung, die Zeiträume sind hier irrelevant)
    $tmp = tempnam(sys_get_temp_dir(), 'bttxe_').'.txt';
    file_put_contents($tmp, $export->buildContent($versionA));
    $versionB = BaseTimeVersion::create(['label' => 'B', 'valid_from' => '2019-01-01', 'valid_until' => '2019-12-31']);
    $resultB = $this->import->importIntoExistingVersion($tmp, $versionB);

    $tuples = fn (int $versionId) => BaseTime::where('base_time_version_id', $versionId)
        ->get(['base_time_category_id', 'base_time_discipline_id', 'base_time_sport_class_id', 'value_centiseconds', 'value_type'])
        ->map(fn (BaseTime $r) => implode(',', [
            $r->base_time_category_id, $r->base_time_discipline_id, $r->base_time_sport_class_id,
            $r->value_centiseconds, $r->value_type,
        ]))
        ->sort()->values()->all();

    expect($resultA['base_times'])->toBe(806)
        ->and($resultB['base_times'])->toBe(806)
        ->and($tuples($versionB->id))->toBe($tuples($versionA->id));
});

// ── Kategorie-Scoping ───────────────────────────────────────────────────────────

describe('BaseTimeTextExportService – Kategorie-Scoping', function () {
    beforeEach(function () {
        $free = StrokeType::create(['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'lenex_code' => 'FREE', 'code' => 'FREE']);
        $this->version = BaseTimeVersion::create(['label' => '2021-2026', 'valid_from' => '2021-01-01', 'valid_until' => null]);
        $this->women = BaseTimeCategory::create(['code' => 'SC_WOMEN', 'course' => 'SCM', 'gender' => 'F', 'label' => 'SC Women']);
        $men = BaseTimeCategory::create(['code' => 'SC_MEN', 'course' => 'SCM', 'gender' => 'M', 'label' => 'SC Men']);
        $disc = BaseTimeDiscipline::create(['code' => '25FR', 'distance' => 25, 'relay_count' => 1, 'stroke_type_id' => $free->id]);
        $s1 = BaseTimeSportClass::create(['code' => 'S1', 'sort_order' => 1]);

        BaseTime::create(['base_time_version_id' => $this->version->id, 'base_time_category_id' => $this->women->id,
            'base_time_discipline_id' => $disc->id, 'base_time_sport_class_id' => $s1->id,
            'value_centiseconds' => 2578, 'value_type' => BaseTime::TYPE_MANUAL]);
        BaseTime::create(['base_time_version_id' => $this->version->id, 'base_time_category_id' => $men->id,
            'base_time_discipline_id' => $disc->id, 'base_time_sport_class_id' => $s1->id,
            'value_centiseconds' => 4551, 'value_type' => BaseTime::TYPE_MANUAL]);
    });

    it('exportiert mit Kategorie nur deren Zeilen, ohne Kategorie beide', function () {
        $service = new BaseTimeTextExportService;

        expect($service->buildContent($this->version, $this->women))->toContain('SCM;F;1;25;FREE;1;00:25.78')
            ->and($service->buildContent($this->version, $this->women))->not->toContain('SCM;M;')
            ->and($service->buildContent($this->version))->toContain('SCM;F;1;25;FREE;1;00:25.78')
            ->and($service->buildContent($this->version))->toContain('SCM;M;1;25;FREE;1;00:45.51');
    });

    it('hängt die Kategorie an den Dateinamen', function () {
        expect((new BaseTimeTextExportService)->downloadFilename($this->version, $this->women))
            ->toBe('OeBSV-Base-Times_2021-2026_SC-Women.txt');
    });
});

// ── Controller ──────────────────────────────────────────────────────────────────

describe('BaseTimeExportController::exportText', function () {
    beforeEach(function () {
        $free = StrokeType::create(['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'lenex_code' => 'FREE', 'code' => 'FREE']);
        $this->version = BaseTimeVersion::create(['label' => 'OeBSV 2021', 'valid_from' => '2021-01-01', 'valid_until' => null]);
        $this->category = BaseTimeCategory::create(['code' => 'SC_WOMEN', 'course' => 'SCM', 'gender' => 'F', 'label' => 'SC Women']);
        $disc = BaseTimeDiscipline::create(['code' => '25FR', 'distance' => 25, 'relay_count' => 1, 'stroke_type_id' => $free->id]);
        $sc = BaseTimeSportClass::create(['code' => 'S1', 'sort_order' => 1]);
        BaseTime::create(['base_time_version_id' => $this->version->id, 'base_time_category_id' => $this->category->id,
            'base_time_discipline_id' => $disc->id, 'base_time_sport_class_id' => $sc->id,
            'value_centiseconds' => 2578, 'value_type' => BaseTime::TYPE_MANUAL]);
    });

    it('liefert die .txt-Datei der gesamten Version für Admins zum Download', function () {
        $admin = User::forceCreate([
            'name' => 'Admin', 'email' => 'admin_bttxe@example.test', 'password' => bcrypt('secret'), 'is_admin' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('base-times.export.text', $this->version))
            ->assertOk()
            ->assertDownload('OeBSV-Base-Times_OeBSV-2021.txt');
    });

    it('liefert über die Kategorie-Route nur die angezeigte Kategorie', function () {
        $admin = User::forceCreate([
            'name' => 'Admin', 'email' => 'admin2_bttxe@example.test', 'password' => bcrypt('secret'), 'is_admin' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('base-times.categories.export.text', [$this->version, $this->category]))
            ->assertOk()
            ->assertDownload('OeBSV-Base-Times_OeBSV-2021_SC-Women.txt');
    });

    it('verweigert Nicht-Admins den Zugriff (403)', function () {
        $user = User::forceCreate([
            'name' => 'Klub', 'email' => 'klub_bttxe@example.test', 'password' => bcrypt('secret'), 'is_admin' => false,
        ]);

        $this->actingAs($user)
            ->get(route('base-times.export.text', $this->version))
            ->assertForbidden();
    });
});
