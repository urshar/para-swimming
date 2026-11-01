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
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class)->group('wps-import-txt');

// ── Testdatei erzeugen ───────────────────────────────────────────────────────

/** Standard-Kopfblock wie in der Referenzdatei "WPP 2017". */
function txtHeader_wpstxt(): array
{
    return [
        'Formula=GOMPERTZ',
        'Id=1023',
        'Name=World Para Points Table',
        'Options=HANDICAP',
        'ShortNameVersion=WPP 2017',
        'Version=2017',
        '',
        '<BASETIMES>',
        'GENDER;RELAYCOUNT;DISTANCE;STROKE;HANDICAP;GOMPERTZA;GOMPERTZB;GOMPERTZC',
    ];
}

/** @return list<string> */
function validRows_wpstxt(): array
{
    return [
        'M;1;50;FREE;S1;1200;6.190278;515.385',
        'M;1;50;FREE;S2;1200;6.190278;433.181',
        'M;1;100;BREAST;SB8;1200;5.052771;470.123',
        'W;1;200;MEDLEY;SM10;1200;4.932103;880.456',
        'W;1;100;BACK;S13;1200;4.487057;500.789',
    ];
}

/**
 * Schreibt eine Textdatei im Aufbau der Referenzdatei — mit CRLF wie das Original.
 *
 * @param  list<string>  $rows
 * @param  list<string>|null  $header  ersetzt den kompletten Kopfblock inkl. Spaltenüberschrift
 */
function makeTxtFile_wpstxt(array $rows, ?array $header = null, string $prefix = ''): string
{
    $path = tempnam(sys_get_temp_dir(), 'wpstxt').'.txt';

    file_put_contents($path, $prefix.implode("\r\n", array_merge($header ?? txtHeader_wpstxt(), $rows))."\r\n");

    return $path;
}

function seedStrokeTypes_wpstxt(): void
{
    foreach (['FREE', 'BACK', 'BREAST', 'FLY', 'MEDLEY'] as $code) {
        StrokeType::firstOrCreate(['lenex_code' => $code], [
            'code' => $code,
            'name_de' => $code,
            'name_en' => $code,
        ]);
    }
}

function importService_wpstxt(): WpsParameterImportService
{
    return app(WpsParameterImportService::class);
}

/** @return array<string, mixed> */
function versionData_wpstxt(): array
{
    return ['label' => 'WPS 2017', 'year' => 2017, 'version' => '2017', 'valid_from' => '2017-01-01'];
}

beforeEach(function () {
    seedStrokeTypes_wpstxt();
});

// ── Parsen ───────────────────────────────────────────────────────────────────

describe('Textformat parsen', function () {
    it('liest gültige Zeilen und löst Geschlecht, Stil und Klasse auf', function () {
        $preview = importService_wpstxt()->parse(makeTxtFile_wpstxt(validRows_wpstxt()));

        $strokeIds = StrokeType::pluck('id', 'lenex_code');

        expect($preview->isValid())->toBeTrue()
            ->and($preview->rowCount())->toBe(5)
            ->and($preview->errors)->toBeEmpty()
            ->and($preview->rows[0]['gender'])->toBe(WpsPointParameter::GENDER_MALE)
            ->and($preview->rows[0]['distance'])->toBe(50)
            ->and($preview->rows[0]['sport_class'])->toBe('S1')
            ->and($preview->rows[0]['parameter_a'])->toBe(1200.0)
            ->and($preview->rows[0]['parameter_b'])->toBe(6.190278)
            ->and($preview->rows[0]['parameter_c'])->toBe(515.385)
            ->and($preview->rows[0]['stroke_type_id'])->toBe($strokeIds['FREE'])
            ->and($preview->rows[2]['stroke_type_id'])->toBe($strokeIds['BREAST'])
            ->and($preview->rows[3]['gender'])->toBe(WpsPointParameter::GENDER_FEMALE)
            ->and($preview->rows[3]['stroke_type_id'])->toBe($strokeIds['MEDLEY'])
            ->and($preview->rows[4]['stroke_type_id'])->toBe($strokeIds['BACK']);
    });

    it('übernimmt die Angaben aus dem Kopfblock als Metadaten', function () {
        $preview = importService_wpstxt()->parse(makeTxtFile_wpstxt(validRows_wpstxt()));

        expect($preview->metadata)->toBe([
            'name' => 'World Para Points Table',
            'short_name' => 'WPP 2017',
            'version' => '2017',
            'id' => '1023',
        ]);
    });

    it('zählt Geschlechter, Bewerbe und Sportklassen', function () {
        $preview = importService_wpstxt()->parse(makeTxtFile_wpstxt(validRows_wpstxt()));

        expect($preview->counts['rows'])->toBe(5)
            ->and($preview->counts['genders'])->toBe(2)
            ->and($preview->counts['events'])->toBe(4)
            ->and($preview->counts['sport_classes'])->toBe(5);
    });

    it('verträgt BOM, LF-Zeilenenden und Leerzeilen', function () {
        $path = tempnam(sys_get_temp_dir(), 'wpstxt').'.txt';
        $lines = array_merge(txtHeader_wpstxt(), ['', validRows_wpstxt()[0], '', validRows_wpstxt()[1], '', '']);
        file_put_contents($path, "\xEF\xBB\xBF".implode("\n", $lines));

        $preview = importService_wpstxt()->parse($path);

        expect($preview->isValid())->toBeTrue()
            ->and($preview->rowCount())->toBe(2)
            ->and($preview->metadata['id'])->toBe('1023');
    });

    it('erkennt das Format an der Endung unabhängig von der Groß-/Kleinschreibung', function () {
        $path = makeTxtFile_wpstxt(validRows_wpstxt());
        $upper = substr($path, 0, -4).'.TXT';
        rename($path, $upper);

        expect(importService_wpstxt()->parse($upper)->rowCount())->toBe(5);
    });

    it('meldet eine nicht lesbare Datei', function () {
        expect(fn () => importService_wpstxt()->parse(sys_get_temp_dir().'/gibt-es-nicht_wpstxt.txt'))
            ->toThrow(RuntimeException::class, 'nicht gelesen');
    });

    it('bricht ab, wenn der Abschnitt <BASETIMES> fehlt', function () {
        $header = array_values(array_filter(
            txtHeader_wpstxt(),
            static fn (string $line): bool => $line !== '<BASETIMES>'
        ));

        expect(fn () => importService_wpstxt()->parse(makeTxtFile_wpstxt(validRows_wpstxt(), $header)))
            ->toThrow(RuntimeException::class, '<BASETIMES>');
    });

    it('bricht bei einer anderen Formel ab', function () {
        $header = txtHeader_wpstxt();
        $header[0] = 'Formula=CUBIC';

        expect(fn () => importService_wpstxt()->parse(makeTxtFile_wpstxt(validRows_wpstxt(), $header)))
            ->toThrow(RuntimeException::class, 'Formula=GOMPERTZ');
    });

    it('bricht ab, wenn die Option HANDICAP fehlt', function () {
        $header = txtHeader_wpstxt();
        $header[3] = 'Options=';

        expect(fn () => importService_wpstxt()->parse(makeTxtFile_wpstxt(validRows_wpstxt(), $header)))
            ->toThrow(RuntimeException::class, 'Options=HANDICAP');
    });

    it('bricht bei einer abweichenden Spaltenüberschrift ab', function () {
        $header = txtHeader_wpstxt();
        $header[8] = 'GENDER;DISTANCE;STROKE;HANDICAP;A;B;C';

        expect(fn () => importService_wpstxt()->parse(makeTxtFile_wpstxt(validRows_wpstxt(), $header)))
            ->toThrow(RuntimeException::class, 'Unerwartetes Dateiformat');
    });

    it('sammelt alle Fehler mit Zeilennummer der Datei', function () {
        $preview = importService_wpstxt()->parse(makeTxtFile_wpstxt([
            'X;1;50;FREE;S1;1200;6.19;515.385',
            'M;4;400;FREE;S1;1200;6.19;515.385',
            'M;1;50;SURF;S1;1200;6.19;515.385',
            'M;1;50;FREE;X9;1200;6.19;515.385',
            'M;1;50;FREE;S2;1200;6,19;433.181',
            'M;1;50;FREE;S3;1200;6.19',
            'M;1;abc;FREE;S4;1200;6.19;515.385',
        ]));

        // Kopfblock belegt die Zeilen 1–9, die erste Datenzeile ist Zeile 10.
        expect($preview->isValid())->toBeFalse()
            ->and($preview->errorCount())->toBe(7)
            ->and($preview->errors[0])->toStartWith('Zeile 10:')
            ->and($preview->errors[0])->toContain('Geschlecht')
            ->and($preview->errors[1])->toContain('Staffel')
            ->and($preview->errors[2])->toContain('Schwimmstil')
            ->and($preview->errors[3])->toContain('Sportklasse')
            ->and($preview->errors[4])->toContain('Parameter b')
            ->and($preview->errors[5])->toContain('Felder')
            ->and($preview->errors[6])->toContain('Strecke');
    });

    it('erkennt doppelte Merkmalskombinationen innerhalb der Datei', function () {
        $preview = importService_wpstxt()->parse(makeTxtFile_wpstxt([
            'M;1;50;FREE;S1;1200;6.19;515.385',
            'M;1;50;FREE;S1;1200;6.19;999.999',
        ]));

        expect($preview->isValid())->toBeFalse()
            ->and($preview->errors[0])->toBe('Zeile 11: Kombination bereits in Zeile 10 enthalten.');
    });

    it('lehnt einen Parameter a von 0 ab', function () {
        $preview = importService_wpstxt()->parse(makeTxtFile_wpstxt(['M;1;50;FREE;S1;0;6.19;515.385']));

        expect($preview->errors[0])->toContain('Parameter a');
    });

    it('meldet eine Datei ohne Datenzeilen', function () {
        expect(importService_wpstxt()->parse(makeTxtFile_wpstxt([]))->errors[0])
            ->toContain('keine Datenzeilen');
    });

    it('beendet die Tabelle an einem weiteren Abschnitt', function () {
        $preview = importService_wpstxt()->parse(makeTxtFile_wpstxt([
            validRows_wpstxt()[0],
            '<OTHER>',
            'irgendwas;anderes',
        ]));

        expect($preview->isValid())->toBeTrue()
            ->and($preview->rowCount())->toBe(1);
    });
});

// ── Import und Berechnung ────────────────────────────────────────────────────

describe('Textformat importieren', function () {
    it('legt Version und Parameter wie beim Excel-Import an', function () {
        $preview = importService_wpstxt()->parse(makeTxtFile_wpstxt(validRows_wpstxt()));

        $result = importService_wpstxt()->import($preview, versionData_wpstxt());

        expect($result['parameters'])->toBe(5)
            ->and(WpsPointVersion::find($result['version_id'])->label)->toBe('WPS 2017')
            ->and(WpsPointParameter::where('course', '!=', WpsPointParameter::COURSE_LCM)->count())->toBe(0)
            ->and(WpsPointParameter::where('official', false)->count())->toBe(0)
            ->and(WpsPointParameter::where('relay_count', '!=', 1)->count())->toBe(0)
            ->and(WpsPointParameter::where('sport_class', 'S2')->first()->parameter_c)->toBe(433.181);
    });

    it('berechnet mit denselben Parametern dieselben Punkte wie der Excel-Import', function () {
        $preview = importService_wpstxt()->parse(makeTxtFile_wpstxt(validRows_wpstxt()));
        importService_wpstxt()->import($preview, array_merge(versionData_wpstxt(), [
            'year' => 2026,
            'valid_from' => '2026-01-01',
        ]));

        // Gleiche Werte und gleiche Erwartung wie im Excel-Test (WpsPointsPhase3Test):
        // Men, 50 m Freestyle, S2, 57,00 s → 939,9101 → abgerundet 939
        $result = result_wps2(['swim_time' => 5700, 'sport_class' => 'S2']);

        app(WpsPointCalculationService::class)->recalculateForMeet($result->meet);

        expect($result->fresh()->wps_points)->toBe(939)
            ->and($result->fresh()->wps_calculation_type)->toBe(Result::WPS_TYPE_OFFICIAL);
    });
});

// ── Import-Flow über HTTP ────────────────────────────────────────────────────

function admin_wpstxt(): User
{
    return User::factory()->create(['is_admin' => true]);
}

function upload_wpstxt(string $path, string $name = 'WPS_2017.txt'): UploadedFile
{
    return new UploadedFile($path, $name, 'text/plain', null, true);
}

/** @return array<string, string> Versionsdaten aus dem Vorschau-Formular, abgeschickt mit dem Import */
function versionInput_wpstxt(): array
{
    return [
        'label' => 'WPS 2017',
        'year' => '2017',
        'version' => '2017',
        'source' => 'WPP 2017',
        'valid_from' => '2017-01-01',
    ];
}

describe('Import-Flow mit Textdatei', function () {
    // Der Controller legt die hochgeladene Datei auf der Disk "local" ab — ohne Fake blieben
    // nach jedem Testlauf Dateien im echten storage/app liegen.
    beforeEach(function () {
        Storage::fake('local');
    });

    it('zeigt die Vorschau mit den Angaben aus dem Kopfblock, ohne etwas zu speichern', function () {
        $this->actingAs(admin_wpstxt())->post(
            route('wps.import.preview'),
            ['wps_file' => upload_wpstxt(makeTxtFile_wpstxt(validRows_wpstxt()))]
        )->assertOk()
            ->assertViewIs('wps.import.preview')
            ->assertSee('Kurzname: WPP 2017')
            ->assertSee('WPS_2017.txt');

        expect(WpsPointVersion::count())->toBe(0)
            ->and(WpsPointParameter::count())->toBe(0);
    });

    it('importiert erst im zweiten Schritt', function () {
        $admin = admin_wpstxt();

        $this->actingAs($admin)->post(
            route('wps.import.preview'),
            ['wps_file' => upload_wpstxt(makeTxtFile_wpstxt(validRows_wpstxt()))]
        );

        $this->actingAs($admin)->post(route('wps.import.run'), versionInput_wpstxt())
            ->assertRedirect()
            ->assertSessionHas('success', '5 Parametersätze importiert.');

        expect(WpsPointVersion::count())->toBe(1)
            ->and(WpsPointVersion::first()->label)->toBe('WPS 2017')
            ->and(WpsPointParameter::count())->toBe(5);
    });

    it('erkennt eine großgeschriebene Endung auch beim Import-Schritt als Textdatei', function () {
        $admin = admin_wpstxt();

        $this->actingAs($admin)->post(
            route('wps.import.preview'),
            [
                'wps_file' => upload_wpstxt(makeTxtFile_wpstxt(validRows_wpstxt()), 'WPS_2017.TXT'),
            ]
        )->assertOk();

        $this->actingAs($admin)->post(route('wps.import.run'), versionInput_wpstxt())
            ->assertSessionHas('success');

        expect(WpsPointParameter::count())->toBe(5);
    });

    it('meldet eine Textdatei im falschen Format zurück ans Formular', function () {
        $header = txtHeader_wpstxt();
        $header[0] = 'Formula=CUBIC';

        $this->actingAs(admin_wpstxt())->post(
            route('wps.import.preview'),
            ['wps_file' => upload_wpstxt(makeTxtFile_wpstxt(validRows_wpstxt(), $header))]
        )->assertRedirect(route('wps.import'))
            ->assertSessionHasErrors('wps_file');
    });

    it('lehnt andere Dateiendungen ab', function () {
        $this->actingAs(admin_wpstxt())->post(
            route('wps.import.preview'),
            [
                'wps_file' => upload_wpstxt(makeTxtFile_wpstxt(validRows_wpstxt()), 'WPS_2017.csv'),
            ]
        )->assertSessionHasErrors('wps_file');

        expect(WpsPointVersion::count())->toBe(0);
    });
});

// ── Referenzdatei ────────────────────────────────────────────────────────────

// Die Referenzdatei liegt nur lokal unter docs/snippets und ist nicht eingecheckt — ohne
// sie wird der Test übersprungen.
it('liest die Referenzdatei WPP 2017 vollständig und fehlerfrei', function () {
    $preview = importService_wpstxt()->parse(base_path('docs/snippets/WPS_2017.txt'));

    expect($preview->errors)->toBe([])
        ->and($preview->rowCount())->toBe(342)
        ->and($preview->counts['genders'])->toBe(2)
        ->and($preview->metadata['short_name'])->toBe('WPP 2017')
        ->and($preview->rows[0])->toMatchArray([
            'gender' => WpsPointParameter::GENDER_MALE,
            'distance' => 50,
            'sport_class' => 'S1',
            'parameter_a' => 1200.0,
            'parameter_b' => 6.13722,
            'parameter_c' => 505.689,
        ]);
})->skip(! file_exists(dirname(__DIR__, 2).'/docs/snippets/WPS_2017.txt'), 'Referenzdatei nicht vorhanden');
