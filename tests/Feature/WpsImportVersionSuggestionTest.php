<?php

use App\Models\StrokeType;
use App\Models\User;
use App\Models\WpsPointVersion;
use App\Support\WpsImportPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class)->group('wps-version-edit-p2');

// ── Helpers ──────────────────────────────────────────────────────────────────

function admin_wve2(): User
{
    return User::factory()->create(['is_admin' => true]);
}

function seedStrokeTypes_wve2(): void
{
    foreach (['FREE', 'BACK', 'BREAST', 'FLY', 'MEDLEY'] as $code) {
        StrokeType::firstOrCreate(['lenex_code' => $code], [
            'code' => $code,
            'name_de' => $code,
            'name_en' => $code,
        ]);
    }
}

/** Kleinste gültige WPS-Datei: zwei Parameterzeilen, "version control" mit Version 3 vom 15.02.2026. */
function makeXlsx_wve2(): UploadedFile
{
    $spreadsheet = new Spreadsheet;

    $parameters = $spreadsheet->getActiveSheet();
    $parameters->setTitle('Parameters');
    $parameters->fromArray(['Gender', 'Event', 'Class', 'a', 'b', 'c', 'p_ref'], null, 'A1', true);
    $parameters->fromArray(['Men', '50 m Freestyle', 'S1', 1200, 6.190278, 515.385, 0], null, 'A2', true);
    $parameters->fromArray(['Women', '100 m Backstroke', 'S13', 1200, 4.487057, 500.789, 0], null, 'A3', true);

    $version = $spreadsheet->createSheet();
    $version->setTitle('version control');
    $version->fromArray(['Version', 'Date', 'Comments'], null, 'A1', true);
    $version->fromArray(['3', null, 'Korrektur'], null, 'A2', true);
    $version->getCell('B2')->setValue(ExcelDate::stringToExcel('2026-02-15'));
    $version->getStyle('B2')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);

    $path = tempnam(sys_get_temp_dir(), 'wps').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'wps-point-scores.xlsx', null, null, true);
}

/** @param array<string, string> $overrides */
function runInput_wve2(array $overrides = []): array
{
    return array_merge([
        'label' => 'WPS 2026',
        'year' => '2026',
        'version' => '3',
        'source' => 'World Para Swimming',
        'valid_from' => '2026-02-15',
    ], $overrides);
}

/** @param array<string, string> $metadata */
function preview_wve2(array $metadata): WpsImportPreview
{
    return new WpsImportPreview(rows: [], errors: [], metadata: $metadata, counts: []);
}

// ── Vorschlag aus den Datei-Angaben ──────────────────────────────────────────

describe('Vorschlag für die Versionsdaten', function () {
    it('übernimmt Version und Datum aus dem Blatt "version control"', function () {
        $suggestion = preview_wve2(['version' => '1', 'date' => '30.01.2026', 'comment' => 'x'])->versionSuggestion();

        expect($suggestion['label'])->toBe('WPS 2026')
            ->and($suggestion['year'])->toBe(2026)
            ->and($suggestion['version'])->toBe('1')
            ->and($suggestion['valid_from'])->toBe('2026-01-30')
            ->and($suggestion['source'])->toBe('World Para Swimming Point Scores for Senior Long Course Events 2026');
    });

    it('nimmt bei Textdateien das Jahr aus dem Kopfblock und den 1. Jänner als Gültig ab', function () {
        $suggestion = preview_wve2([
            'name' => 'World Para Points Table',
            'short_name' => 'WPP 2017',
            'version' => '2017',
            'id' => '1023',
        ])->versionSuggestion();

        expect($suggestion['label'])->toBe('WPS 2017')
            ->and($suggestion['year'])->toBe(2017)
            ->and($suggestion['version'])->toBeNull()
            ->and($suggestion['source'])->toBe('WPP 2017')
            ->and($suggestion['valid_from'])->toBe('2017-01-01');
    });

    it('findet das Jahr einer Textdatei auch nur im Kurznamen', function () {
        $suggestion = preview_wve2(['short_name' => 'WPP 2019'])->versionSuggestion();

        expect($suggestion['year'])->toBe(2019)
            ->and($suggestion['valid_from'])->toBe('2019-01-01');
    });

    it('lässt alles leer, was die Datei nicht hergibt', function () {
        expect(preview_wve2([])->versionSuggestion())->toBe([
            'label' => null,
            'year' => null,
            'version' => null,
            'source' => null,
            'valid_from' => null,
        ]);
    });

    it('übergeht ein Datum, das sich nicht lesen lässt', function () {
        $suggestion = preview_wve2(['version' => '2', 'date' => 'Jänner 2026'])->versionSuggestion();

        expect($suggestion['year'])->toBeNull()
            ->and($suggestion['valid_from'])->toBeNull()
            ->and($suggestion['version'])->toBe('2');
    });
});

// ── Import-Flow ──────────────────────────────────────────────────────────────

describe('Import mit Versionsdaten aus der Vorschau', function () {
    beforeEach(function () {
        seedStrokeTypes_wve2();

        // Der Controller legt die hochgeladene Datei auf der Disk "local" ab.
        Storage::fake('local');
    });

    it('fragt im ersten Schritt nur die Datei ab', function () {
        $this->actingAs(admin_wve2())
            ->get(route('wps.import'))
            ->assertOk()
            ->assertSee('name="wps_file"', false)
            ->assertDontSee('name="label"', false)
            ->assertDontSee('name="valid_from"', false);
    });

    it('belegt das Formular in der Vorschau aus der Datei vor', function () {
        $this->actingAs(admin_wve2())
            ->post(route('wps.import.preview'), ['wps_file' => makeXlsx_wve2()])
            ->assertOk()
            ->assertViewIs('wps.import.preview')
            ->assertSee('value="WPS 2026"', false)
            ->assertSee('value="3"', false)
            ->assertSee('2026-02-15')
            ->assertSee('formaction="'.route('wps.import.cancel').'"', false);

        expect(WpsPointVersion::count())->toBe(0);
    });

    it('legt die Version mit den abgeschickten Daten an', function () {
        $admin = admin_wve2();

        $this->actingAs($admin)->post(route('wps.import.preview'), ['wps_file' => makeXlsx_wve2()]);

        $this->actingAs($admin)
            ->post(route('wps.import.run'), runInput_wve2(['label' => 'WPS 2026 Korrektur']))
            ->assertRedirect()
            ->assertSessionHas('success', '2 Parametersätze importiert.');

        $version = WpsPointVersion::sole();

        expect($version->label)->toBe('WPS 2026 Korrektur')
            ->and($version->version)->toBe('3')
            ->and($version->valid_from->format('Y-m-d'))->toBe('2026-02-15')
            ->and(Storage::disk('local')->allFiles('wps-point-imports'))->toBeEmpty();
    });

    it('führt bei vorhandener Kombination aus Jahr und Version zurück in die Vorschau', function () {
        WpsPointVersion::create(['label' => 'WPS 2026', 'year' => 2026, 'version' => '3']);
        $admin = admin_wve2();

        $this->actingAs($admin)->post(route('wps.import.preview'), ['wps_file' => makeXlsx_wve2()]);

        $this->actingAs($admin)
            ->post(route('wps.import.run'), runInput_wve2(['label' => 'Meine Eingabe']))
            ->assertRedirect(route('wps.import.preview.show'))
            ->assertSessionHasErrors('version');

        // Die Datei bleibt liegen, die Vorschau zeigt die letzte Eingabe statt des Vorschlags. followingRedirects(),
        // weil ein separates get() die Flash-Daten (Fehler, alte Eingabe) nicht mehr sieht.
        $this->actingAs($admin)
            ->followingRedirects()
            ->post(route('wps.import.run'), runInput_wve2(['label' => 'Meine Eingabe']))
            ->assertOk()
            ->assertSee('existiert bereits die Version')
            ->assertSee('value="Meine Eingabe"', false);

        expect(WpsPointVersion::count())->toBe(1)
            ->and(Storage::disk('local')->allFiles('wps-point-imports'))->toHaveCount(1);
    });

    it('verwirft beim erneuten Hochladen die Datei der alten Vorschau', function () {
        $admin = admin_wve2();

        $this->actingAs($admin)->post(route('wps.import.preview'), ['wps_file' => makeXlsx_wve2()]);
        $this->actingAs($admin)->post(route('wps.import.preview'), ['wps_file' => makeXlsx_wve2()]);

        expect(Storage::disk('local')->allFiles('wps-point-imports'))->toHaveCount(1);
    });

    it('schickt die Vorschau ohne laufenden Import zurück zum Upload', function () {
        $this->actingAs(admin_wve2())
            ->get(route('wps.import.preview.show'))
            ->assertRedirect(route('wps.import'))
            ->assertSessionHasErrors('wps_file');
    });
});
