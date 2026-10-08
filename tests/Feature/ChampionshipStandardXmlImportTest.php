<?php

use App\Models\BaseTimeSportClass;
use App\Models\Championship;
use App\Models\ChampionshipStandard;
use App\Models\StrokeType;
use App\Models\User;
use App\Services\ChampionshipStandardImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class)->group('standards-xml-import');

// Normen-Import aus der SDMS-Ranglistendatei von World Para Swimming (XML).

function championship_sxi(): Championship
{
    return Championship::query()->create([
        'name' => 'LA28 Paralympic Games',
        'short_name' => 'PG 2028',
        'type' => Championship::TYPE_PARALYMPICS,
        'year' => 2028,
        'qualification_start' => '2026-09-01',
        'qualification_end' => '2028-07-01',
    ]);
}

/**
 * Ein Bewerbsblock im Aufbau der echten Datei, mit einer Ranglistenzeile, die ignoriert werden muss.
 */
function block_sxi(string $code, string $description, string $label, ?string $eligible, ?string $mqs, ?string $met): string
{
    $infos = '<ExtendedInfo Type="CLASSES" Code="LABEL" Value="'.$label.'"/>';
    $infos .= $eligible === null ? '' : '<ExtendedInfo Type="CLASSES" Code="ELIGIBLE" Value="'.$eligible.'"/>';
    $infos .= '<ExtendedInfo Type="STANDARDS" Code="CLASS" Pos="1" Value="'.($eligible ?? $label).'"/>';
    $infos .= $mqs === null ? '' : '<ExtendedInfo Type="STANDARDS" Code="MQS" Pos="1" Value="'.$mqs.'"/>';
    $infos .= $met === null ? '' : '<ExtendedInfo Type="STANDARDS" Code="MET" Pos="1" Value="'.$met.'"/>';

    return '<Rankings Code="'.$code.'" Description="'.$description.'"><ExtendedInfos>'.$infos.'</ExtendedInfos>'
        .'<Ranking Rank="1" Value="0:42.62" ValueType="TIME" Competition="Test" Date="2026-09-11"/></Rankings>';
}

function xml_sxi(string $blocks, string $documentType = 'DT_FED_RANKING'): string
{
    $xml = '<?xml version="1.0" encoding="utf-8"?>'
        .'<SdmsBody SportCode="SWM" DocumentType="'.$documentType.'" Source="SDMS"><Sport><ExtendedInfos>'
        .'<ExtendedInfo Type="PERIOD" Code="START" Value="2026-09-01"/>'
        .'<ExtendedInfo Type="PERIOD" Code="END" Value="2028-07-01"/>'
        .'<ExtendedInfo Type="LABEL" Code="NAME" Value="LA28 Paralympic Games - MQS ONLY APPLIED"/>'
        .'</ExtendedInfos>'.$blocks.'</Sport></SdmsBody>';

    // Bewusst mit Endung .tmp: Erkannt wird am Inhalt, nicht an der Endung.
    $pfad = tempnam(sys_get_temp_dir(), 'sxi_');
    file_put_contents($pfad, $xml);

    return $pfad;
}

beforeEach(function () {
    $this->service = app(ChampionshipStandardImportService::class);

    foreach (['FREE', 'BACK', 'BREAST', 'FLY', 'MEDLEY'] as $code) {
        StrokeType::firstOrCreate(['lenex_code' => $code], ['code' => $code, 'name_de' => $code, 'name_en' => $code]);
    }

    foreach (range(1, 14) as $nummer) {
        BaseTimeSportClass::query()->firstOrCreate(['code' => "S$nummer"], ['sort_order' => $nummer]);
    }
});

it('liest Bewerb, Geschlecht, Klasse, MQS und MET aus einem Bewerbsblock', function () {
    $pfad = xml_sxi(block_sxi('SWMW100MBA--06010-----', "Women's 100 m Backstroke S6", 'S6', 'S6', '1:26.15', '1:28.90'));

    $preview = $this->service->parse($pfad, null);
    $zeile = $preview->rows[0];

    expect($preview->errors)->toBe([])
        ->and($preview->rowCount())->toBe(1)
        ->and($zeile['stroke_type_id'])->toBe(StrokeType::where('lenex_code', 'BACK')->value('id'))
        ->and($zeile['distance'])->toBe(100)
        ->and($zeile['gender'])->toBe('F')
        ->and($zeile['sport_class'])->toBe('S6')
        ->and($zeile['mqs_centiseconds'])->toBe(8615)
        ->and($zeile['met_centiseconds'])->toBe(8890);
});

it('legt die Norm eines kombinierten Bewerbs für jede startberechtigte Klasse an', function () {
    $pfad = xml_sxi(block_sxi('SWMM50MFR---03030-----', "Men's 50 m Freestyle S3", 'S3', 'S1-3', '0:50.27', '0:53.60'));

    $preview = $this->service->parse($pfad, null);

    expect($preview->errors)->toBe([])
        ->and(array_column($preview->rows, 'sport_class'))->toBe(['S1', 'S2', 'S3'])
        ->and(array_unique(array_column($preview->rows, 'mqs_centiseconds')))->toBe([5027])
        ->and($preview->rows[0]['event_label'])->toBe("Men's 50 m Freestyle S3 (S1-3)");
});

it('erkennt die Klassen SB und SM samt Bereich', function () {
    $pfad = xml_sxi(
        block_sxi('SWMM50MBR---02021-----', "Men's 50 m Breaststroke SB2", 'SB2', 'SB1-2', '1:12.71', '1:23.28')
        .block_sxi('SWMW200MIM--13022-----', "Women's 200 m Individual Medley SM13", 'SM13', 'SM12-13', '2:38.74', null)
    );

    $preview = $this->service->parse($pfad, null);

    expect($preview->errors)->toBe([])
        ->and(array_column($preview->rows, 'sport_class'))->toBe(['SB1', 'SB2', 'SM12', 'SM13'])
        ->and($preview->rows[2]['met_centiseconds'])->toBeNull();
});

it('überspringt Staffeln und weist sie als Hinweis aus', function () {
    $pfad = xml_sxi(
        block_sxi('SWMM50MFR---05010-----', "Men's 50 m Freestyle S5", 'S5', 'S5', '0:34.08', '0:35.51')
        .block_sxi('SWMX4X100MFR10102-----', 'Mixed 4x100 m Freestyle 34pts', '34pts', 'S1-10', '4:34.68', null)
    );

    $preview = $this->service->parse($pfad, null);

    expect($preview->rowCount())->toBe(1)
        ->and($preview->errors)->toBe([])
        ->and($preview->warnings)->toContain('1 Staffelbewerb(e) wurden übersprungen — Staffelnormen sind nicht Teil dieses Moduls.');
});

it('übernimmt Zeitraum und Titel aus dem Dateikopf als Vorschlag', function () {
    $pfad = xml_sxi(block_sxi('SWMM50MFR---05010-----', "Men's 50 m Freestyle S5", 'S5', 'S5', '0:34.08', null));

    $preview = $this->service->parse($pfad, null);

    expect($preview->suggestedPeriod)->toBe(['start' => '2026-09-01', 'end' => '2028-07-01'])
        ->and($preview->title)->toBe('LA28 Paralympic Games - MQS ONLY APPLIED');
});

it('meldet eine unlesbare Zeit und eine nicht lesbare Klassenangabe', function () {
    $pfad = xml_sxi(
        block_sxi('SWMM50MFR---05010-----', "Men's 50 m Freestyle S5", 'S5', 'S5', 'schnell', null)
        .block_sxi('SWMM100MFR--06010-----', "Men's 100 m Freestyle S6", 'S6', 'S6/SB5', '1:08.08', null)
    );

    $preview = $this->service->parse($pfad, null);

    expect($preview->isValid())->toBeFalse()
        ->and($preview->errors)->toContain("Men's 50 m Freestyle S5: \"schnell\" ist keine lesbare Zeit.")
        ->and($preview->errors)->toContain("Men's 100 m Freestyle S6: Die startberechtigten Klassen \"S6/SB5\" sind nicht lesbar.");
});

it('meldet eine Klasse, die über zwei Bewerbe startberechtigt wäre', function () {
    $pfad = xml_sxi(
        block_sxi('SWMM50MFR---03030-----', "Men's 50 m Freestyle S3", 'S3', 'S1-3', '0:50.27', null)
        .block_sxi('SWMM50MFR---04010-----', "Men's 50 m Freestyle S4", 'S4', 'S3-4', '0:41.45', null)
    );

    $preview = $this->service->parse($pfad, null);

    expect($preview->errors)->toBe([
        "Men's 50 m Freestyle S4: Die Klasse S3 ist bereits über \"Men's 50 m Freestyle S3\" startberechtigt — die Norm ist nicht eindeutig.",
    ]);
});

it('bricht bei einer Weltrangliste ohne Normen mit einer verständlichen Meldung ab', function () {
    $pfad = xml_sxi(block_sxi('SWMM50MFR---05010-----', "Men's 50 m Freestyle S5", 'S5', 'S5', null, null));

    expect(fn () => $this->service->parse($pfad, null))
        ->toThrow(RuntimeException::class, 'Die XML-Datei enthält keine Normen (MQS/MET)');
});

it('bricht bei einem fremden XML-Format und bei kaputtem XML ab', function () {
    $fremd = xml_sxi('', 'DT_RESULT');
    $kaputt = tempnam(sys_get_temp_dir(), 'sxi_');
    file_put_contents($kaputt, '<SdmsBody><Sport>');

    expect(fn () => $this->service->parse($fremd, null))->toThrow(RuntimeException::class, 'Unbekanntes XML-Format')
        ->and(fn () => $this->service->parse($kaputt, null))->toThrow(RuntimeException::class, 'keine gültige XML-Datei');
});

it('importiert die XML-Normen über Vorschau und Import und lässt die ÖBSV-Werte unberührt', function () {
    $championship = championship_sxi();
    $admin = User::factory()->create(['is_admin' => true, 'club_id' => null]);
    $vorhanden = ChampionshipStandard::query()->create([
        'championship_id' => $championship->id,
        'stroke_type_id' => StrokeType::where('lenex_code', 'FREE')->value('id'),
        'distance' => 50,
        'gender' => 'M',
        'sport_class' => 'S3',
        'mqs_centiseconds' => 6000,
        'obsv_percent' => 105,
    ]);

    $pfad = xml_sxi(block_sxi('SWMM50MFR---03030-----', "Men's 50 m Freestyle S3", 'S3', 'S1-3', '0:50.27', '0:53.60'));
    $datei = new UploadedFile($pfad, 'LA28.xml', 'application/xml', null, true);

    $this->actingAs($admin)
        ->post(route('championships.import.preview', $championship), ['standards_file' => $datei])
        ->assertOk()
        ->assertViewHas('preview', fn ($preview) => $preview->rowCount() === 3);

    $this->actingAs($admin)
        ->post(route('championships.import.run', $championship))
        ->assertRedirect(route('championships.show', $championship));

    $vorhanden->refresh();

    expect($championship->standards()->count())->toBe(3)
        ->and($vorhanden->mqs_centiseconds)->toBe(5027)
        ->and($vorhanden->met_centiseconds)->toBe(5360)
        ->and((float) $vorhanden->obsv_percent)->toBe(105.0);
});
