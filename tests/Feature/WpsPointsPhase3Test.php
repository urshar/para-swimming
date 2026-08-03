<?php

use App\Models\Result;
use App\Models\StrokeType;
use App\Models\User;
use App\Models\WpsPointParameter;
use App\Models\WpsPointVersion;
use App\Services\WpsParameterImportService;
use App\Services\WpsPointCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class)->group('wps-points-p3');

// ── Testdatei erzeugen ───────────────────────────────────────────────────────

/**
 * Baut eine Datei im Aufbau der offiziellen WPS-Point-Score-Datei.
 *
 * Die echte Datei wird bewusst nicht als Fixture mitgeliefert: Sie ist urheberrechtlich
 * geschützt und würde die Tests an eine konkrete Veröffentlichung binden. Der Aufbau ist
 * identisch — Blatt "Parameters" mit den Spalten A–F, Blatt "version control".
 *
 * @param  list<array<int, string|float>>  $rows
 * @param  list<string>|null  $headers
 */
function makeWpsFile_wps3(array $rows, ?array $headers = null): UploadedFile
{
    $spreadsheet = new Spreadsheet;

    $parameters = $spreadsheet->getActiveSheet();
    $parameters->setTitle('Parameters');
    $parameters->fromArray(
        $headers ?? ['Gender', 'Event', 'Class', 'a', 'b', 'c', 'p_ref'],
        null,
        'A1',
        true
    );

    $line = 2;

    foreach ($rows as $row) {
        // strictNullComparison, damit numerische Nullwerte nicht stillschweigend
        // als leere Zelle geschrieben werden.
        $parameters->fromArray($row, null, 'A'.$line, true);
        $line++;
    }

    $version = $spreadsheet->createSheet();
    $version->setTitle('version control');
    $version->fromArray(['Version', 'Date', 'Comments'], null, 'A1', true);
    $version->fromArray(['1', null, 'Erstveröffentlichung'], null, 'A2', true);

    // Wie in der Originaldatei: Excel legt das Datum als Seriennummer ab und hält das
    // Datum nur über das Zahlenformat fest.
    $version->getCell('B2')->setValue(ExcelDate::stringToExcel('2026-01-30'));
    $version->getStyle('B2')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);

    $path = tempnam(sys_get_temp_dir(), 'wps').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'wps-point-scores.xlsx', null, null, true);
}

/** @return list<array<int, string|float>> */
function validRows_wps3(): array
{
    return [
        ['Men', '50 m Freestyle', 'S1', 1200, 6.190278, 515.385, 0],
        ['Men', '50 m Freestyle', 'S2', 1200, 6.190278, 433.181, 0],
        ['Men', '100 m Breaststroke', 'SB8', 1200, 5.052771, 470.123, 0],
        ['Women', '200 m Individual Medley', 'SM10', 1200, 4.932103, 880.456, 0],
        ['Women', '100 m Backstroke', 'S13', 1200, 4.487057, 500.789, 0],
    ];
}

function seedStrokeTypes_wps3(): void
{
    foreach (['FREE', 'BACK', 'BREAST', 'FLY', 'MEDLEY'] as $code) {
        StrokeType::firstOrCreate(['lenex_code' => $code], [
            'code' => $code,
            'name_de' => $code,
            'name_en' => $code,
        ]);
    }
}

function admin_wps3(): User
{
    return User::factory()->create(['is_admin' => true]);
}

/** @return array<string, string> Formularfelder für den Preview-Schritt */
function versionInput_wps3(array $overrides = []): array
{
    return array_merge([
        'label' => 'WPS 2026',
        'year' => '2026',
        'version' => '1',
        'source' => 'World Para Swimming Point Scores',
        'valid_from' => '2026-01-01',
    ], $overrides);
}

function importService_wps3(): WpsParameterImportService
{
    return app(WpsParameterImportService::class);
}

// ── Parsen ───────────────────────────────────────────────────────────────────

describe('WpsParameterImportService::parse', function () {
    beforeEach(function () {
        seedStrokeTypes_wps3();
    });

    it('liest gültige Zeilen und löst Bewerbe auf', function () {
        $file = makeWpsFile_wps3(validRows_wps3());

        $preview = importService_wps3()->parse($file->getPathname());

        expect($preview->isValid())->toBeTrue()
            ->and($preview->rowCount())->toBe(5)
            ->and($preview->errors)->toBeEmpty()
            ->and($preview->rows[0]['gender'])->toBe('M')
            ->and($preview->rows[0]['distance'])->toBe(50)
            ->and($preview->rows[0]['sport_class'])->toBe('S1')
            ->and($preview->rows[0]['parameter_c'])->toBe(515.385)
            ->and($preview->rows[3]['gender'])->toBe('F');
    });

    it('ordnet die Schwimmstile korrekt zu', function () {
        $preview = importService_wps3()->parse(makeWpsFile_wps3(validRows_wps3())->getPathname());

        $strokeIds = StrokeType::pluck('id', 'lenex_code');

        expect($preview->rows[0]['stroke_type_id'])->toBe($strokeIds['FREE'])
            ->and($preview->rows[2]['stroke_type_id'])->toBe($strokeIds['BREAST'])
            ->and($preview->rows[3]['stroke_type_id'])->toBe($strokeIds['MEDLEY'])
            ->and($preview->rows[4]['stroke_type_id'])->toBe($strokeIds['BACK']);
    });

    it('ignoriert die abgeleitete Spalte p_ref', function () {
        $preview = importService_wps3()->parse(makeWpsFile_wps3(validRows_wps3())->getPathname());

        expect($preview->rows[0])->not->toHaveKey('p_ref');
    });

    it('liest die Versionsangaben aus dem Blatt "version control"', function () {
        $preview = importService_wps3()->parse(makeWpsFile_wps3(validRows_wps3())->getPathname());

        expect($preview->metadata)->toHaveKey('version')
            ->and($preview->metadata['version'])->toBe('1')
            // Excel legt Datumswerte als Seriennummer ab (30.01.2026 = 46052).
            // Ohne Umrechnung stünde diese Zahl in der Vorschau.
            ->and($preview->metadata['date'])->toBe('30.01.2026');
    });

    it('zählt Geschlechter, Bewerbe und Sportklassen', function () {
        $preview = importService_wps3()->parse(makeWpsFile_wps3(validRows_wps3())->getPathname());

        expect($preview->counts['rows'])->toBe(5)
            ->and($preview->counts['genders'])->toBe(2)
            ->and($preview->counts['events'])->toBe(4)
            ->and($preview->counts['sport_classes'])->toBe(5);
    });

    it('bricht bei einer abweichenden Kopfzeile ab', function () {
        $file = makeWpsFile_wps3(validRows_wps3(), ['Foo', 'Bar', 'Baz', 'x', 'y', 'z']);

        expect(fn () => importService_wps3()->parse($file->getPathname()))
            ->toThrow(RuntimeException::class, 'Unerwartetes Dateiformat');
    });

    it('sammelt alle Fehler mit Zeilennummer statt beim ersten abzubrechen', function () {
        $file = makeWpsFile_wps3([
            ['Diverse', '50 m Freestyle', 'S1', 1200, 6.19, 515.385, 0],
            ['Men', '50 m Schmetterling', 'S1', 1200, 6.19, 515.385, 0],
            ['Men', '50 m Freestyle', 'X9', 1200, 6.19, 515.385, 0],
            ['Men', '50 m Freestyle', 'S2', 1200, '', 433.181, 0],
        ]);

        $preview = importService_wps3()->parse($file->getPathname());

        expect($preview->isValid())->toBeFalse()
            ->and($preview->errorCount())->toBe(4)
            ->and($preview->errors[0])->toContain('Zeile 2')
            ->and($preview->errors[0])->toContain('Geschlecht')
            ->and($preview->errors[1])->toContain('Schwimmstil')
            ->and($preview->errors[2])->toContain('Sportklasse')
            ->and($preview->errors[3])->toContain('Parameter b');
    });

    it('erkennt doppelte Merkmalskombinationen innerhalb der Datei', function () {
        $file = makeWpsFile_wps3([
            ['Men', '50 m Freestyle', 'S1', 1200, 6.19, 515.385, 0],
            ['Men', '50 m Freestyle', 'S1', 1200, 6.19, 999.999, 0],
        ]);

        $preview = importService_wps3()->parse($file->getPathname());

        expect($preview->isValid())->toBeFalse()
            ->and($preview->errors[0])->toContain('bereits in Zeile 2');
    });

    it('lehnt einen Parameter a von 0 ab', function () {
        $file = makeWpsFile_wps3([['Men', '50 m Freestyle', 'S1', 0, 6.19, 515.385, 0]]);

        expect(importService_wps3()->parse($file->getPathname())->errors[0])
            ->toContain('Parameter a');
    });

    it('meldet eine Datei ohne Datenzeilen', function () {
        expect(importService_wps3()->parse(makeWpsFile_wps3([])->getPathname())->errors[0])
            ->toContain('keine Datenzeilen');
    });
});

// ── Import ───────────────────────────────────────────────────────────────────

describe('WpsParameterImportService::import', function () {
    beforeEach(function () {
        seedStrokeTypes_wps3();
    });

    it('legt Version und Parameter an und setzt LCM sowie official', function () {
        $preview = importService_wps3()->parse(makeWpsFile_wps3(validRows_wps3())->getPathname());

        $result = importService_wps3()->import($preview, [
            'label' => 'WPS 2026',
            'year' => 2026,
            'version' => '1',
            'source' => 'WPS',
            'valid_from' => '2026-01-01',
        ]);

        $version = WpsPointVersion::find($result['version_id']);

        expect($result['parameters'])->toBe(5)
            ->and($version->label)->toBe('WPS 2026')
            ->and($version->status)->toBe(WpsPointVersion::STATUS_ACTIVE)
            ->and($version->parameters()->count())->toBe(5)
            ->and(WpsPointParameter::where('course', '!=', WpsPointParameter::COURSE_LCM)->count())->toBe(0)
            ->and(WpsPointParameter::where('official', false)->count())->toBe(0)
            ->and(WpsPointParameter::where('relay_count', '!=', 1)->count())->toBe(0);
    });

    it('speichert die Parameter verlustfrei', function () {
        $preview = importService_wps3()->parse(makeWpsFile_wps3(validRows_wps3())->getPathname());
        importService_wps3()->import($preview, [
            'label' => 'WPS 2026', 'year' => 2026, 'version' => '1', 'valid_from' => '2026-01-01',
        ]);

        $parameter = WpsPointParameter::where('sport_class', 'S2')->first();

        expect($parameter->parameter_a)->toBe(1200.0)
            ->and($parameter->parameter_b)->toBe(6.190278)
            ->and($parameter->parameter_c)->toBe(433.181);
    });

    it('lehnt eine bereits vorhandene Jahr/Versions-Kombination ab', function () {
        WpsPointVersion::create(['label' => 'Alt', 'year' => 2026, 'version' => '1']);

        $preview = importService_wps3()->parse(makeWpsFile_wps3(validRows_wps3())->getPathname());

        expect(fn () => importService_wps3()->import($preview, [
            'label' => 'WPS 2026', 'year' => 2026, 'version' => '1', 'valid_from' => '2026-01-01',
        ]))->toThrow(RuntimeException::class, 'existiert bereits')
            ->and(WpsPointParameter::count())->toBe(0);
    });

    it('importiert nichts, wenn die Vorschau Fehler enthält', function () {
        $preview = importService_wps3()->parse(
            makeWpsFile_wps3([['Diverse', '50 m Freestyle', 'S1', 1200, 6.19, 515.385, 0]])->getPathname()
        );

        expect(fn () => importService_wps3()->import($preview, [
            'label' => 'WPS 2026', 'year' => 2026, 'version' => '1', 'valid_from' => '2026-01-01',
        ]))->toThrow(RuntimeException::class)
            ->and(WpsPointVersion::count())->toBe(0)
            ->and(WpsPointParameter::count())->toBe(0);
    });
});

// ── Import-Flow über HTTP ────────────────────────────────────────────────────

describe('Import-Flow', function () {
    beforeEach(function () {
        seedStrokeTypes_wps3();
    });

    it('zeigt die Vorschau, ohne etwas zu speichern', function () {
        $response = $this->actingAs(admin_wps3())->post(
            route('wps.import.preview'),
            versionInput_wps3() + ['wps_file' => makeWpsFile_wps3(validRows_wps3())]
        );

        $response->assertOk()->assertViewIs('wps.import.preview');

        expect(WpsPointVersion::count())->toBe(0)
            ->and(WpsPointParameter::count())->toBe(0);
    });

    it('importiert erst im zweiten Schritt', function () {
        $admin = admin_wps3();

        $this->actingAs($admin)->post(
            route('wps.import.preview'),
            versionInput_wps3() + ['wps_file' => makeWpsFile_wps3(validRows_wps3())]
        );

        $this->actingAs($admin)->post(route('wps.import.run'))
            ->assertRedirect()
            ->assertSessionHas('success');

        expect(WpsPointVersion::count())->toBe(1)
            ->and(WpsPointParameter::count())->toBe(5);
    });

    it('weist ohne vorherige Vorschau ab', function () {
        $this->actingAs(admin_wps3())->post(route('wps.import.run'))
            ->assertRedirect(route('wps.import'))
            ->assertSessionHasErrors('wps_file');

        expect(WpsPointVersion::count())->toBe(0);
    });

    it('meldet eine unlesbare Datei zurück ans Formular', function () {
        $file = makeWpsFile_wps3(validRows_wps3(), ['Foo', 'Bar', 'Baz', 'x', 'y', 'z']);

        $this->actingAs(admin_wps3())->post(
            route('wps.import.preview'),
            versionInput_wps3() + ['wps_file' => $file]
        )->assertRedirect(route('wps.import'))->assertSessionHasErrors('wps_file');
    });

    it('verlangt die Pflichtangaben zur Version', function () {
        $this->actingAs(admin_wps3())->post(
            route('wps.import.preview'),
            ['wps_file' => makeWpsFile_wps3(validRows_wps3())]
        )->assertSessionHasErrors(['label', 'year', 'valid_from']);
    });

    it('bricht ab und verwirft die Vorschau', function () {
        $admin = admin_wps3();

        $this->actingAs($admin)->post(
            route('wps.import.preview'),
            versionInput_wps3() + ['wps_file' => makeWpsFile_wps3(validRows_wps3())]
        );

        $this->actingAs($admin)->post(route('wps.import.cancel'))->assertRedirect();
        $this->actingAs($admin)->post(route('wps.import.run'))->assertSessionHasErrors('wps_file');

        expect(WpsPointVersion::count())->toBe(0);
    });
});

// ── Versionsverwaltung ───────────────────────────────────────────────────────

describe('Versionsverwaltung', function () {
    it('listet die Versionen mit Parameteranzahl', function () {
        $version = WpsPointVersion::create(['label' => 'WPS 2026', 'year' => 2026, 'version' => '1']);

        $this->actingAs(admin_wps3())->get(route('wps.versions.index'))
            ->assertOk()
            ->assertSee('WPS 2026')
            ->assertViewHas('versions', fn ($versions) => $versions->first()->parameters_count === 0);

        expect($version->fresh()->isArchived())->toBeFalse();
    });

    it('archiviert und reaktiviert eine Version', function () {
        $admin = admin_wps3();
        $version = WpsPointVersion::create(['label' => 'WPS 2026', 'year' => 2026, 'version' => '1']);

        $this->actingAs($admin)->post(route('wps.versions.archive', $version))->assertRedirect();
        expect($version->fresh()->isArchived())->toBeTrue();

        $this->actingAs($admin)->post(route('wps.versions.activate', $version))->assertRedirect();
        expect($version->fresh()->isArchived())->toBeFalse();
    });

    it('löscht eine ungenutzte Version', function () {
        $version = WpsPointVersion::create(['label' => 'WPS 2026', 'year' => 2026, 'version' => '1']);

        $this->actingAs(admin_wps3())->delete(route('wps.versions.destroy', $version))
            ->assertRedirect()
            ->assertSessionHas('success');

        expect(WpsPointVersion::count())->toBe(0);
    });

    it('verweigert das Löschen, sobald Ergebnisse darauf verweisen', function () {
        seedStrokeTypes_wps3();
        $version = WpsPointVersion::create(['label' => 'WPS 2026', 'year' => 2026, 'version' => '1']);
        $result = result_wps2();
        $result->update(['wps_points' => 900, 'wps_point_version_id' => $version->id]);

        $this->actingAs(admin_wps3())->delete(route('wps.versions.destroy', $version))
            ->assertSessionHasErrors('version');

        expect(WpsPointVersion::count())->toBe(1)
            ->and($result->fresh()->wps_points)->toBe(900);
    });

    it('filtert die Parametertabelle', function () {
        seedStrokeTypes_wps3();
        $preview = importService_wps3()->parse(makeWpsFile_wps3(validRows_wps3())->getPathname());
        $result = importService_wps3()->import($preview, [
            'label' => 'WPS 2026', 'year' => 2026, 'version' => '1', 'valid_from' => '2026-01-01',
        ]);

        $this->actingAs(admin_wps3())
            ->get(route('wps.versions.show', $result['version_id']).'?gender=F')
            ->assertOk()
            ->assertViewHas('parameters', fn ($parameters) => $parameters->total() === 2);
    });
});

// ── Berechtigungen ───────────────────────────────────────────────────────────

describe('Berechtigungen', function () {
    it('verwehrt Nicht-Admins den Zugriff', function (string $route) {
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get(route($route))
            ->assertForbidden();
    })->with(['wps.import', 'wps.versions.index']);

    it('leitet nicht angemeldete Besucher zur Anmeldung', function () {
        $this->get(route('wps.versions.index'))->assertRedirect(route('login'));
    });

    it('lässt Admins durch', function () {
        $this->actingAs(admin_wps3())->get(route('wps.import'))->assertOk();
    });
});

// ── Zusammenspiel mit der Berechnung ─────────────────────────────────────────

it('berechnet nach dem Import Punkte mit den importierten Parametern', function () {
    seedStrokeTypes_wps3();

    $preview = importService_wps3()->parse(makeWpsFile_wps3(validRows_wps3())->getPathname());
    importService_wps3()->import($preview, [
        'label' => 'WPS 2026', 'year' => 2026, 'version' => '1', 'valid_from' => '2026-01-01',
    ]);

    // Men, 50 m Freestyle, S2, 57,00 s → 939,9101 → abgerundet 939
    $result = result_wps2(['swim_time' => 5700, 'sport_class' => 'S2']);

    app(WpsPointCalculationService::class)->recalculateForMeet($result->meet);

    expect($result->fresh()->wps_points)->toBe(939)
        ->and($result->fresh()->wps_calculation_type)->toBe(Result::WPS_TYPE_OFFICIAL);
})->group('wps-points-p3');
