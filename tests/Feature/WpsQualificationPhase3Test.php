<?php

use App\Models\BaseTimeSportClass;
use App\Models\Championship;
use App\Models\ChampionshipStandard;
use App\Models\StrokeType;
use App\Models\User;
use App\Services\ChampionshipStandardImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

uses(RefreshDatabase::class)->group('wps-qual-p3');

// ── Helper (Suffix _wq3 gegen Namenskollisionen) ─────────────────────────────

function admin_wq3(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function clubUser_wq3(): User
{
    return User::factory()->create(['is_admin' => false]);
}

function stroke_wq3(string $lenexCode): StrokeType
{
    return StrokeType::firstOrCreate(
        ['lenex_code' => $lenexCode],
        ['code' => $lenexCode, 'name_de' => $lenexCode, 'name_en' => $lenexCode]
    );
}

function championship_wq3(): Championship
{
    return Championship::query()->create([
        'name' => 'Para Swimming European Open Championships 2024',
        'short_name' => 'EM 2024',
        'type' => Championship::TYPE_EC,
        'year' => 2024,
        'qualification_start' => '2023-01-01',
        'qualification_end' => '2024-02-26',
    ]);
}

/**
 * Baut eine Datei im Aufbau der echten WPS-Normdatei.
 *
 * @param  list<array<int, string|null>>  $dataRows  ab Zeile 4: [Events, Class, MQS m, MET m, MQS w, MET w]
 */
function xlsx_wq3(array $dataRows, string $title, array $header, array $subHeader): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();

    $sheet->setCellValue('A1', $title);

    foreach (['A', 'B', 'C', 'E'] as $index => $column) {
        $sheet->setCellValue($column.'2', $header[$index]);
    }

    foreach (['C', 'D', 'E', 'F'] as $index => $column) {
        $sheet->setCellValue($column.'3', $subHeader[$index]);
    }

    $nummer = 4;

    foreach ($dataRows as $zeile) {
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $index => $column) {
            $wert = $zeile[$index] ?? null;

            if ($wert !== null && $wert !== '') {
                // Bewusst als Text gesetzt: Die echten Dateien führen die Zeiten als
                // Zeichenkette, nicht als Excel-Zeitwert.
                $sheet->setCellValueExplicit(
                    $column.$nummer,
                    $wert,
                    DataType::TYPE_STRING
                );
            }
        }

        $nummer++;
    }

    $pfad = tempnam(sys_get_temp_dir(), 'wq3_').'.xlsx';

    (new XlsxWriter($spreadsheet))->save($pfad);

    return $pfad;
}

/** Standardkopf der echten Datei. */
function standardFile_wq3(array $dataRows): string
{
    return xlsx_wq3(
        $dataRows,
        "List of MQS and MET Times for the Madeira 2024 Para Swimming European Open Championships\n"
        .'Qualification Period - 1 January 2023 to 26 February 2024',
        ['Events', 'Class', 'Men', 'Women'],
        ['MQS', 'MET', 'MQS', 'MET'],
    );
}

beforeEach(function () {
    $this->service = app(ChampionshipStandardImportService::class);

    foreach (['FREE', 'BACK', 'BREAST', 'FLY', 'MEDLEY'] as $code) {
        stroke_wq3($code);
    }

    foreach ([3, 5, 7, 8, 9, 14] as $nummer) {
        BaseTimeSportClass::query()->firstOrCreate(['code' => "S$nummer"], ['sort_order' => $nummer]);
    }
});

// ── Parsen ───────────────────────────────────────────────────────────────────

it('liest Männer- und Frauenspalte als je eine eigene Norm', function () {
    $pfad = standardFile_wq3([
        ['50m Freestyle', 'S7', '00:31.62', '00:32.38', '00:34.76', '00:35.43'],
    ]);

    $preview = $this->service->parse($pfad, null);

    expect($preview->errors)->toBe([])
        ->and($preview->rowCount())->toBe(2)
        ->and($preview->rows[0]['gender'])->toBe('M')
        ->and($preview->rows[0]['mqs_centiseconds'])->toBe(3162)
        ->and($preview->rows[0]['met_centiseconds'])->toBe(3238)
        ->and($preview->rows[0]['distance'])->toBe(50)
        ->and($preview->rows[1]['gender'])->toBe('F')
        ->and($preview->rows[1]['mqs_centiseconds'])->toBe(3476);
});

it('führt den Bewerbsnamen über die folgenden Zeilen mit', function () {
    $pfad = standardFile_wq3([
        ['100m Freestyle', 'S7', '01:09.84', '01:10.84', null, null],
        [null, 'S8', '01:02.28', '01:03.62', null, null],
        [null, 'S9', '00:58.61', '00:59.03', null, null],
    ]);

    $preview = $this->service->parse($pfad, null);

    expect($preview->errors)->toBe([])
        ->and($preview->rowCount())->toBe(3)
        ->and(collect($preview->rows)->pluck('distance')->unique()->all())->toBe([100])
        ->and(collect($preview->rows)->pluck('sport_class')->all())->toBe(['S7', 'S8', 'S9']);
});

it('lässt sich von Leerzeilen zwischen den Bewerbsgruppen nicht abbringen', function () {
    $pfad = standardFile_wq3([
        ['50m Freestyle', 'S7', '00:31.62', null, null, null],
        [null, null, null, null, null, null],
        ['100m Backstroke', 'S9', '01:08.90', null, null, null],
        [null, null, null, null, null, null],
        ['200m Individual Medley', 'S9', '02:28.96', null, null, null],
    ]);

    $preview = $this->service->parse($pfad, null);

    // Bräche das Parsen bei der ersten Leerzeile ab, bliebe nur die erste Norm übrig.
    expect($preview->errors)->toBe([])
        ->and($preview->rowCount())->toBe(3);
});

it('erzeugt für leere Zellen keine Zeile und keinen Fehler', function () {
    $pfad = standardFile_wq3([
        ['50m Freestyle', 'S7', '00:31.62', '00:32.38', null, null],
        [null, 'S8', null, null, '00:34.76', '00:35.43'],
    ]);

    $preview = $this->service->parse($pfad, null);

    expect($preview->errors)->toBe([])
        ->and($preview->rowCount())->toBe(2)
        ->and($preview->rows[0]['gender'])->toBe('M')
        ->and($preview->rows[1]['gender'])->toBe('F');
});

it('übernimmt eine Norm auch ohne MET', function () {
    $pfad = standardFile_wq3([
        ['50m Freestyle', 'S7', '00:31.62', null, null, null],
    ]);

    $preview = $this->service->parse($pfad, null);

    expect($preview->rowCount())->toBe(1)
        ->and($preview->rows[0]['mqs_centiseconds'])->toBe(3162)
        ->and($preview->rows[0]['met_centiseconds'])->toBeNull()
        ->and($preview->counts['with_met'])->toBe(0);
});

it('erkennt die Sportklassen SB und SM', function () {
    BaseTimeSportClass::query()->firstOrCreate(['code' => 'S4'], ['sort_order' => 4]);

    $pfad = standardFile_wq3([
        ['100m Breaststroke', 'SB4', '02:10.93', '02:18.51', null, null],
        ['150m Individual Medley', 'SM3', '05:10.44', '05:10.44', null, null],
    ]);

    $preview = $this->service->parse($pfad, null);

    expect($preview->errors)->toBe([])
        ->and($preview->rows[0]['sport_class'])->toBe('SB4')
        ->and($preview->rows[1]['sport_class'])->toBe('SM3')
        ->and($preview->rows[1]['distance'])->toBe(150);
});

it('überspringt den Staffelabschnitt und weist ihn als Hinweis aus', function () {
    $pfad = standardFile_wq3([
        ['50m Freestyle', 'S7', '00:31.62', null, null, null],
        ['Relays*', null, null, null, null, null],
        ['Mixed 4x100m Freestyle', "34\nPoints", '\\', null, null, null],
        ['Mixed 4x100m Medley', "34\nPoints", '\\', null, null, null],
    ]);

    $preview = $this->service->parse($pfad, null);

    expect($preview->errors)->toBe([])
        ->and($preview->rowCount())->toBe(1)
        ->and($preview->warningCount())->toBe(1)
        ->and($preview->warnings[0])->toContain('Staffelabschnitt');
});

it('liest den Qualifikationszeitraum aus der Titelzeile als Vorschlag', function () {
    $pfad = standardFile_wq3([
        ['50m Freestyle', 'S7', '00:31.62', null, null, null],
    ]);

    $preview = $this->service->parse($pfad, null);

    expect($preview->suggestedPeriod)->toBe(['start' => '2023-01-01', 'end' => '2024-02-26']);
});

it('kommt ohne Zeitraum in der Titelzeile aus', function () {
    $pfad = xlsx_wq3(
        [['50m Freestyle', 'S7', '00:31.62', null, null, null]],
        'Irgendein Titel ohne Zeitraum',
        ['Events', 'Class', 'Men', 'Women'],
        ['MQS', 'MET', 'MQS', 'MET'],
    );

    $preview = $this->service->parse($pfad, null);

    expect($preview->suggestedPeriod)->toBeNull()
        ->and($preview->rowCount())->toBe(1);
});

// ── Fehlerfälle ──────────────────────────────────────────────────────────────

it('bricht bei fremdem Dateiaufbau mit einer verständlichen Meldung ab', function () {
    $pfad = xlsx_wq3(
        [['irgendwas', 'S7', '1', '2', '3', '4']],
        'Fremde Datei',
        ['Gender', 'Event', 'Class', 'a'],
        ['b', 'c', 'd', 'e'],
    );

    expect(fn () => $this->service->parse($pfad, null))
        ->toThrow(RuntimeException::class, 'Unerwartetes Dateiformat');
});

it('meldet eine unlesbare Zeit mit Zeilennummer', function () {
    $pfad = standardFile_wq3([
        ['50m Freestyle', 'S7', 'kaputt', null, null, null],
    ]);

    $preview = $this->service->parse($pfad, null);

    expect($preview->errorCount())->toBe(1)
        ->and($preview->errors[0])->toContain('Zeile 4')
        ->and($preview->isValid())->toBeFalse();
});

it('meldet einen unbekannten Schwimmstil und eine ungültige Sportklasse', function () {
    $pfad = standardFile_wq3([
        ['100m Unterwasser', 'S7', '01:00.00', null, null, null],
        ['50m Freestyle', 'S99', '00:31.62', null, null, null],
    ]);

    $preview = $this->service->parse($pfad, null);

    expect($preview->errorCount())->toBe(2)
        ->and($preview->errors[0])->toContain('Schwimmstil')
        ->and($preview->errors[1])->toContain('Sportklasse');
});

it('meldet eine doppelte Kombination innerhalb der Datei', function () {
    $pfad = standardFile_wq3([
        ['50m Freestyle', 'S7', '00:31.62', null, null, null],
        [null, 'S7', '00:30.00', null, null, null],
    ]);

    $preview = $this->service->parse($pfad, null);

    expect($preview->errorCount())->toBe(1)
        ->and($preview->errors[0])->toContain('Zeile 4');
});

it('importiert nichts, wenn die Vorschau Fehler enthält', function () {
    $championship = championship_wq3();
    $pfad = standardFile_wq3([
        ['50m Freestyle', 'S7', 'kaputt', null, null, null],
    ]);

    $preview = $this->service->parse($pfad, null);

    expect(fn () => $this->service->import($championship, $preview))
        ->toThrow(RuntimeException::class, 'enthält Fehler')
        ->and(ChampionshipStandard::query()->count())->toBe(0);
});

// ── Import ───────────────────────────────────────────────────────────────────

it('schreibt beim Parsen nichts in die Datenbank', function () {
    $championship = championship_wq3();
    $pfad = standardFile_wq3([
        ['50m Freestyle', 'S7', '00:31.62', '00:32.38', '00:34.76', '00:35.43'],
    ]);

    $this->service->parse($pfad, $championship);

    expect(ChampionshipStandard::query()->count())->toBe(0);
});

it('legt die Normen an', function () {
    $championship = championship_wq3();
    $pfad = standardFile_wq3([
        ['50m Freestyle', 'S7', '00:31.62', '00:32.38', '00:34.76', '00:35.43'],
        ['100m Backstroke', 'S9', '01:08.90', '01:09.31', null, null],
    ]);

    $ergebnis = $this->service->import($championship, $this->service->parse($pfad, $championship));

    expect($ergebnis)->toBe(['created' => 3, 'updated' => 0])
        ->and($championship->standards()->count())->toBe(3);

    $norm = $championship->standards()
        ->where('sport_class', 'S7')->where('gender', 'M')->sole();

    expect($norm->mqs_centiseconds)->toBe(3162)
        ->and($norm->met_centiseconds)->toBe(3238)
        ->and($norm->isObsvOpen())->toBeTrue();
});

it('lässt beim erneuten Import die ÖBSV-Werte unberührt', function () {
    $championship = championship_wq3();

    $ersterLauf = standardFile_wq3([
        ['50m Freestyle', 'S7', '00:31.62', '00:32.38', null, null],
    ]);
    $this->service->import($championship, $this->service->parse($ersterLauf, $championship));

    $norm = $championship->standards()->sole();
    $norm->update([
        'obsv_percent' => 2.0,
        'obsv_centiseconds' => 3098,
        'obsv_is_manual' => true,
    ]);

    // Zweite Datei: geänderte MQS und MET
    $zweiterLauf = standardFile_wq3([
        ['50m Freestyle', 'S7', '00:31.00', '00:32.00', null, null],
    ]);
    $ergebnis = $this->service->import($championship, $this->service->parse($zweiterLauf, $championship));

    $frisch = $norm->fresh();

    expect($ergebnis)->toBe(['created' => 0, 'updated' => 1])
        ->and($championship->standards()->count())->toBe(1)
        ->and($frisch->mqs_centiseconds)->toBe(3100)
        ->and($frisch->met_centiseconds)->toBe(3200)
        ->and($frisch->obsv_percent)->toBe(2.0)
        ->and($frisch->obsv_centiseconds)->toBe(3098)
        ->and($frisch->obsv_is_manual)->toBeTrue();
});

it('lässt vorhandene Normen stehen, die in der Datei fehlen, und weist sie aus', function () {
    $championship = championship_wq3();

    $ersterLauf = standardFile_wq3([
        ['50m Freestyle', 'S7', '00:31.62', null, null, null],
        ['100m Backstroke', 'S9', '01:08.90', null, null, null],
    ]);
    $this->service->import($championship, $this->service->parse($ersterLauf, $championship));

    $zweiterLauf = standardFile_wq3([
        ['50m Freestyle', 'S7', '00:31.00', null, null, null],
    ]);
    $preview = $this->service->parse($zweiterLauf, $championship);
    $this->service->import($championship, $preview);

    expect($preview->warningCount())->toBe(1)
        ->and($preview->warnings[0])->toContain('nicht vor')
        ->and($championship->standards()->count())->toBe(2);
});

// ── Routen und Berechtigungen ────────────────────────────────────────────────

it('verwehrt Club-Nutzern den Import', function () {
    $nutzer = clubUser_wq3();
    $championship = championship_wq3();

    $this->actingAs($nutzer)->get(route('championships.import', $championship))->assertForbidden();
    $this->actingAs($nutzer)
        ->post(route('championships.import.preview', $championship))->assertForbidden();
    $this->actingAs($nutzer)
        ->post(route('championships.import.run', $championship))->assertForbidden();
});

it('führt Admins durch Formular, Vorschau und Import', function () {
    $championship = championship_wq3();
    $admin = admin_wq3();

    $this->actingAs($admin)->get(route('championships.import', $championship))->assertOk();

    $pfad = standardFile_wq3([
        ['50m Freestyle', 'S7', '00:31.62', '00:32.38', '00:34.76', '00:35.43'],
    ]);

    $datei = new UploadedFile($pfad, 'normen.xlsx', null, null, true);

    $this->actingAs($admin)
        ->post(route('championships.import.preview', $championship), ['standards_file' => $datei])
        ->assertOk()
        ->assertViewHas('preview', fn ($preview) => $preview->rowCount() === 2);

    expect(ChampionshipStandard::query()->count())->toBe(0);

    $this->actingAs($admin)
        ->post(route('championships.import.run', $championship))
        ->assertRedirect(route('championships.show', $championship));

    expect($championship->standards()->count())->toBe(2);
});

it('verweist auf einen neuen Upload, wenn keine Vorschau in der Sitzung liegt', function () {
    $championship = championship_wq3();

    $this->actingAs(admin_wq3())
        ->post(route('championships.import.run', $championship))
        ->assertRedirect(route('championships.import', $championship))
        ->assertSessionHasErrors('standards_file');
});
