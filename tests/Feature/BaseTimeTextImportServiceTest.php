<?php

use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeDerivationRule;
use App\Models\BaseTimeDiscipline;
use App\Models\BaseTimeSportClass;
use App\Models\StrokeType;
use App\Models\User;
use App\Services\BaseTimeTextImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class)->group('base-time-text-import');

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

/**
 * Schreibt eine minimale MeetManager-"Points"-Textdatei: Kopf-Metadaten + <BASETIMES> +
 * Spaltenkopf + die übergebenen Datenzeilen. Gibt den temporären Dateipfad zurück.
 *
 * @param  string[]  $dataLines
 */
function makeBaseTimeTextFile_bttxt(array $dataLines): string
{
    $head = "Formula=CUBED\n".
        "Id=502\n".
        "Name=Test Table \n".
        "Options=HANDICAP\n".
        "ShortNameVersion=Test 2021\n".
        "Version=2021\n".
        "<BASETIMES>\n".
        "\n".
        "COURSE;GENDER;RELAYCOUNT;DISTANCE;STROKE;HANDICAP;MINTIME;MAXTIME\n";

    $path = tempnam(sys_get_temp_dir(), 'bt_txt_').'.txt';
    file_put_contents($path, $head.implode("\n", $dataLines)."\n");

    return $path;
}

/** Legt die StrokeTypes an, die der Text-Import zum Auflösen der Bewerbe braucht. */
function seedStrokeTypes_bttxt(): void
{
    foreach (['FREE', 'BACK', 'BREAST', 'FLY', 'MEDLEY'] as $code) {
        StrokeType::create([
            'name_de' => $code,
            'name_en' => $code,
            'lenex_code' => $code,
            'code' => $code,
        ]);
    }
}

// ── parse() ───────────────────────────────────────────────────────────────────

describe('BaseTimeTextImportService::parse', function () {
    beforeEach(function () {
        $this->service = new BaseTimeTextImportService;
    });

    it('überspringt Kopfzeilen und liest die Datenzeilen', function () {
        $path = makeBaseTimeTextFile_bttxt([
            'SCM;F;1;25;FREE;1;00:25.78',
            'SCM;M;1;50;FREE;1;01:35.58',
        ]);

        $parsed = $this->service->parse($path);

        expect($parsed['cells'])->toHaveCount(2)
            ->and($parsed['warnings'])->toBe([])
            ->and($parsed['categories'])->toHaveKeys(['SC_WOMEN', 'SC_MEN']);
    });

    it('bildet COURSE/GENDER auf die Excel-kompatiblen Kategorie-Codes ab', function () {
        $path = makeBaseTimeTextFile_bttxt([
            'SCM;F;1;25;FREE;1;00:25.78',
            'SCM;M;1;25;FREE;1;00:45.51',
            'SCM;X;4;25;FREE;S14;01:11.95',
            'LCM;F;1;50;FREE;1;00:54.15',
        ]);

        $parsed = $this->service->parse($path);

        expect($parsed['categories']['SC_WOMEN'])->toMatchArray(['course' => 'SCM', 'gender' => 'F', 'label' => 'SC Women'])
            ->and($parsed['categories']['SC_MEN'])->toMatchArray(['course' => 'SCM', 'gender' => 'M', 'label' => 'SC Men'])
            ->and($parsed['categories']['SC_MIXED'])->toMatchArray(['course' => 'SCM', 'gender' => 'X', 'label' => 'SC Mixed'])
            ->and($parsed['categories']['LC_WOMEN'])->toMatchArray(['course' => 'LCM', 'gender' => 'F', 'label' => 'LC Women']);
    });

    it('ergänzt bei Einzelbewerben das S-Präfix und übernimmt es bei Staffeln unverändert', function () {
        $path = makeBaseTimeTextFile_bttxt([
            'SCM;F;1;25;FREE;1;00:25.78',   // Einzel: "1" → S1
            'SCM;F;1;25;FREE;21;00:15.29',  // Einzel: "21" → S21
            'SCM;F;4;25;FREE;S14;01:01.42', // Staffel: "S14" bleibt S14
            'SCM;F;4;25;FREE;S49;01:01.93', // Staffel: "S49" bleibt S49
        ]);

        $parsed = $this->service->parse($path);

        expect(array_keys($parsed['sportClasses']))->toEqualCanonicalizing(['S1', 'S21', 'S14', 'S49']);
    });

    it('überspringt WA-1000-Zeilen (HANDICAP X/SX), nicht aber GENDER X', function () {
        $path = makeBaseTimeTextFile_bttxt([
            'SCM;X;4;25;FREE;S14;01:11.95',  // GENDER X + echte Klasse → behalten
            'SCM;F;1;25;FREE;X;00:10.91',    // HANDICAP X → überspringen
            'SCM;F;4;25;FREE;SX;99:99.99',   // HANDICAP SX → überspringen
        ]);

        $parsed = $this->service->parse($path);

        expect($parsed['cells'])->toHaveCount(1)
            ->and($parsed['warnings'])->toBe([])
            ->and($parsed['sportClasses'])->toHaveKey('S14')
            ->and($parsed['sportClasses'])->not->toHaveKey('SX')
            ->and($parsed['categories'])->toHaveKey('SC_MIXED');
    });

    it('erkennt den 99:99.99-Sentinel als NOT_APPLICABLE und sonst als MANUAL', function () {
        $path = makeBaseTimeTextFile_bttxt([
            'SCM;F;1;25;FREE;1;00:25.78',
            'SCM;M;1;200;FREE;1;99:99.99',
        ]);

        $parsed = $this->service->parse($path);

        $manual = collect($parsed['cells'])->firstWhere('discipline_code', '25FR');
        $notApplicable = collect($parsed['cells'])->firstWhere('discipline_code', '200FR');

        expect($manual['value_type'])->toBe(BaseTime::TYPE_MANUAL)
            ->and($manual['value_centiseconds'])->toBe(2578)
            ->and($notApplicable['value_type'])->toBe(BaseTime::TYPE_NOT_APPLICABLE)
            ->and($notApplicable['value_centiseconds'])->toBe(0);
    });

    it('baut Einzel-Lagen als IM und Staffel-Lagen als ME', function () {
        $path = makeBaseTimeTextFile_bttxt([
            'SCM;F;1;100;MEDLEY;1;99:99.99',
            'SCM;F;1;150;MEDLEY;3;03:43.85',
            'SCM;F;4;25;MEDLEY;S14;01:11.04',
            'SCM;F;4;100;MEDLEY;S15;04:28.06',
        ]);

        $parsed = $this->service->parse($path);

        expect(array_keys($parsed['disciplines']))->toEqualCanonicalizing(['100IM', '150IM', '4x25ME', '4x100ME'])
            ->and($parsed['disciplines']['100IM'])->toMatchArray(['stroke_lenex_code' => 'MEDLEY', 'distance' => 100, 'relay_count' => 1])
            ->and($parsed['disciplines']['4x25ME'])->toMatchArray(['stroke_lenex_code' => 'MEDLEY', 'distance' => 25, 'relay_count' => 4]);
    });

    it('warnt bei unbekannter Schwimmart und unbekannter Kategorie, statt abzubrechen', function () {
        $path = makeBaseTimeTextFile_bttxt([
            'SCM;F;1;25;FROG;1;00:25.78',   // unbekannte Schwimmart
            'XXX;F;1;25;FREE;1;00:25.78',   // unbekannter Kurs
            'SCM;F;1;25;FREE;1;00:25.78',   // gültig
        ]);

        $parsed = $this->service->parse($path);

        expect($parsed['cells'])->toHaveCount(1)
            ->and($parsed['warnings'])->toHaveCount(2)
            ->and(collect($parsed['warnings'])->contains(fn ($w) => str_contains($w, 'STROKE="FROG"')))->toBeTrue()
            ->and(collect($parsed['warnings'])->contains(fn ($w) => str_contains($w, 'COURSE="XXX"')))->toBeTrue();
    });

    it('warnt bei doppeltem Basiswert und übernimmt nur den ersten', function () {
        $path = makeBaseTimeTextFile_bttxt([
            'SCM;F;1;25;FREE;1;00:25.78',
            'SCM;F;1;25;FREE;1;00:26.99',
        ]);

        $parsed = $this->service->parse($path);

        expect($parsed['cells'])->toHaveCount(1)
            ->and($parsed['cells'][0]['value_centiseconds'])->toBe(2578)
            ->and($parsed['warnings'])->toHaveCount(1)
            ->and($parsed['warnings'][0])->toContain('doppelter Basiswert');
    });

    it('wirft bei fehlender Datenkopfzeile', function () {
        $path = tempnam(sys_get_temp_dir(), 'bt_txt_').'.txt';
        file_put_contents($path, "Formula=CUBED\nId=502\nkein Datenblock\n");

        expect(fn () => $this->service->parse($path))->toThrow(RuntimeException::class);
    });
});

// ── import() ──────────────────────────────────────────────────────────────────

describe('BaseTimeTextImportService::import', function () {
    beforeEach(function () {
        seedStrokeTypes_bttxt();
        $this->service = new BaseTimeTextImportService;
    });

    it('importiert Kategorien, Bewerbe, Sportklassen und Basiswerte, ohne Herleitungs-Regeln', function () {
        $path = makeBaseTimeTextFile_bttxt([
            'SCM;F;1;25;FREE;1;00:25.78',
            'SCM;M;1;25;FREE;1;00:45.51',
            'SCM;X;4;25;FREE;S14;01:11.95',
            'SCM;M;1;200;FREE;1;99:99.99',
            'SCM;F;1;25;FREE;X;00:10.91', // WA-1000 → übersprungen
        ]);

        $result = $this->service->import($path, [
            'label' => 'OeBSV 2021',
            'valid_from' => '2021-01-01',
            'valid_until' => null,
        ]);

        expect($result['categories'])->toBe(3)         // SC_WOMEN, SC_MEN, SC_MIXED
            ->and($result['disciplines'])->toBe(3)      // 25FR, 4x25FR, 200FR
            ->and($result['sport_classes'])->toBe(2)    // S1, S14
            ->and($result['derivation_rules'])->toBe(0) // Textformat kennt keine Formeln
            ->and($result['base_times'])->toBe(4)       // X-Zeile übersprungen
            ->and(BaseTime::count())->toBe(4)
            ->and(BaseTimeDerivationRule::count())->toBe(0)
            ->and(BaseTimeDiscipline::where('code', '4x25FR')->value('relay_count'))->toBe(4)
            ->and(BaseTimeSportClass::pluck('code')->all())->toEqualCanonicalizing(['S1', 'S14']);
    });

    it('teilt sich Kategorie-Zeilen mit einem vorherigen Import (kein Duplikat)', function () {
        // Kategorie existiert bereits (z.B. aus einem früheren Excel-Import).
        BaseTimeCategory::create(['code' => 'SC_WOMEN', 'course' => 'SCM', 'gender' => 'F', 'label' => 'SC Women']);

        $path = makeBaseTimeTextFile_bttxt(['SCM;F;1;25;FREE;1;00:25.78']);

        $this->service->import($path, ['label' => 'V1', 'valid_from' => '2021-01-01', 'valid_until' => null]);

        expect(BaseTimeCategory::where('code', 'SC_WOMEN')->count())->toBe(1);
    });

    it('verhindert überlappende Gültigkeitszeiträume', function () {
        $path = makeBaseTimeTextFile_bttxt(['SCM;F;1;25;FREE;1;00:25.78']);

        $this->service->import($path, ['label' => 'V1', 'valid_from' => '2021-01-01', 'valid_until' => '2026-12-31']);

        expect(fn () => $this->service->import($path, [
            'label' => 'V2', 'valid_from' => '2025-01-01', 'valid_until' => null,
        ]))->toThrow(RuntimeException::class);
    });
});

// ── Echte OeBSV-2021-Datei (Fixture) ────────────────────────────────────────────

describe('BaseTimeTextImportService gegen die echte OeBSV-2021-Datei', function () {
    it('parst die Datei mit den erwarteten Kennzahlen', function () {
        $parsed = (new BaseTimeTextImportService)->parse(
            base_path('tests/Fixtures/base-times/502-para-2021.txt')
        );

        $byType = array_count_values(array_column($parsed['cells'], 'value_type'));

        expect($parsed['categories'])->toHaveCount(3)
            ->and(array_keys($parsed['categories']))->toEqualCanonicalizing(['SC_WOMEN', 'SC_MEN', 'SC_MIXED'])
            ->and($parsed['disciplines'])->toHaveCount(29)
            ->and($parsed['sportClasses'])->toHaveCount(19)
            ->and($parsed['cells'])->toHaveCount(806)
            ->and($parsed['warnings'])->toBe([])
            ->and($byType[BaseTime::TYPE_MANUAL])->toBe(698)
            ->and($byType[BaseTime::TYPE_NOT_APPLICABLE])->toBe(108)
            ->and($parsed['sportClasses'])->not->toHaveKey('SX');
    });
});

// ── Controller: Format-Switch über die Datei-Endung ─────────────────────────────

describe('BaseTimeImportController mit einer .txt-Datei', function () {
    beforeEach(function () {
        Storage::fake('local');
        seedStrokeTypes_bttxt();
        $this->user = User::forceCreate([
            'name' => 'Import Admin',
            'email' => 'import_admin_bttxt@example.test',
            'password' => bcrypt('secret'),
            'is_admin' => true,
        ]);
    });

    it('wählt für .txt den Text-Parser und importiert die echte Datei durch bis in die DB', function () {
        $file = new UploadedFile(
            base_path('tests/Fixtures/base-times/502-para-2021.txt'),
            '502-para-2021.txt',
            'text/plain',
            null,
            true, // Test-Modus: kein move_uploaded_file, Fixture bleibt unangetastet
        );

        // Schritt 1: Vorschau — der Controller erkennt das Format an der Endung.
        $this->actingAs($this->user)
            ->post(route('base-times.import.preview'), [
                'base_time_file' => $file,
                'label' => 'OeBSV 2021',
                'valid_from' => '2021-01-01',
            ])
            ->assertOk()
            ->assertViewIs('base-times.import-preview');

        expect(session('base_time_import.format'))->toBe('txt');

        // Schritt 2: Import ausführen — Session trägt Pfad + Format.
        $this->actingAs($this->user)
            ->post(route('base-times.import.run'))
            ->assertRedirect();

        expect(BaseTime::count())->toBe(806)
            ->and(BaseTimeCategory::count())->toBe(3)
            ->and(BaseTime::where('value_type', BaseTime::TYPE_NOT_APPLICABLE)->count())->toBe(108);
    });
});
