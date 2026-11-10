<?php

use App\Models\Athlete;
use App\Models\Nation;
use App\Models\StrokeType;
use App\Models\SwimRecord;
use App\Models\User;
use App\Services\RecordImportService;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;

uses(RefreshDatabase::class)->group('record-import-birthdate-suggestion');

// Rekord-Import: unbekannte Athleten mit geändertem Nachnamen (z. B. "Hava Veronika" heißt heute "Jank Veronika")
// werden über gleiches Geburtsdatum + gemeinsamen Namensteil vorgeschlagen, nie vorbelegt.

function nation_ribd(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
}

function athlete_ribd(string $lastName, string $firstName, string $birthDate): Athlete
{
    return Athlete::create(['nation_id' => nation_ribd()->id, 'first_name' => $firstName, 'last_name' => $lastName,
        'birth_date' => $birthDate, 'gender' => 'F']);
}

/** Minimales LENEX (plain XML) mit einem Einzelrekord von "Hava Veronika". */
function lenex_ribd(): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<LENEX version="3.0"><RECORDLISTS>'
        .'<RECORDLIST type="AUT" course="LCM" gender="F" handicap="15" nation="AUT">'
        .'<RECORDS><RECORD swimtime="00:01:29.77">'
        .'<SWIMSTYLE stroke="FREE" distance="100" relaycount="1"/>'
        .'<MEETINFO name="IDM" city="Berlin" nation="GER" date="2006-06-02"/>'
        .'<ATHLETE lastname="Hava" firstname="Veronika" birthdate="1984-03-14" gender="F">'
        .'<CLUB code="TST" name="Testverein" nation="AUT"/></ATHLETE>'
        .'</RECORD></RECORDS></RECORDLIST>'
        .'</RECORDLISTS></LENEX>';

    $path = tempnam(sys_get_temp_dir(), 'ribd_').'.xml';
    file_put_contents($path, $xml);

    return $path;
}

beforeEach(function () {
    StrokeType::create(['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'lenex_code' => 'FREE', 'code' => 'FREE']);
    $this->service = new RecordImportService;
});

it('schlägt einen Athleten mit gleichem Geburtsdatum und geändertem Nachnamen vor, ohne vorzubelegen', /** @throws Exception */ function () {
    $jank = athlete_ribd('Jank', 'Veronika', '1984-03-14');
    athlete_ribd('Lidl', 'Veronika', '2009-10-13');

    $ua = $this->service->preview(lenex_ribd())['unknown_athletes'][0];

    expect($ua['suggestions'])->toBeEmpty()
        ->and($ua['birth_date_suggestions'])->toHaveCount(1)
        ->and($ua['birth_date_suggestions'][0]['id'])->toBe($jank->id)
        ->and($ua['birth_date_suggestions'][0]['birth_date'])->toBe('14.03.1984')
        ->and($ua['preselect'])->toBeNull();
});

it('zeigt den Vorschlag im Select und alle Athleten in der gemeinsamen Suchliste', /** @throws Exception */ function () {
    athlete_ribd('Jank', 'Veronika', '1984-03-14');
    athlete_ribd('Zimmer', 'Anna', '1990-01-01');
    athlete_ribd('Zimmer', 'Anna', '1990-01-01');
    $preview = $this->service->preview(lenex_ribd());

    $admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin_ribd@example.test', 'password' => bcrypt('secret'), 'is_admin' => true]);
    View::share('errors', new ViewErrorBag);
    $this->actingAs($admin);

    /** @var ViewContract $view */
    $view = view('records.import-preview', [
        'preview' => $preview,
        'clubs' => collect(),
        'athletes' => Athlete::orderBy('last_name')->get(),
        'fileName' => 'test.xml',
    ]);
    $html = $view->render();

    $zimmerIds = Athlete::where('last_name', 'Zimmer')->orderBy('id')->pluck('id');

    expect($html)->toContain('Vorschlag — gleiches Geburtsdatum (Name geändert?)')
        ->toContain('(*14.03.1984)')
        ->toContain('<datalist id="record-import-athletes">')
        ->toContain('list="record-import-athletes"')
        // Gleicher Name und Geburtstag: mit ID eindeutig gemacht.
        ->toContain('Zimmer, Anna (*01.01.1990) · #'.$zimmerIds[0])
        ->toContain('Zimmer, Anna (*01.01.1990) · #'.$zimmerIds[1]);
});

it('übernimmt das Suchfeld "Anderer Name" statt der Auswahl im Select', function () {
    $jank = athlete_ribd('Jank', 'Veronika', '1984-03-14');
    $admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin2_ribd@example.test', 'password' => bcrypt('secret'), 'is_admin' => true]);
    Storage::fake('local');
    $file = new UploadedFile(lenex_ribd(), 'Rekorde.lxf', 'application/xml', null, true);

    $this->actingAs($admin)->post(route('records.import.preview'), ['lenex_file' => $file])->assertOk();
    $this->actingAs($admin)->post(route('records.import.run'), [
        'clubs' => ['TST|AUT' => 'skip'],
        'athletes' => ['Hava|Veronika|1984-03-14' => 'new'],
        'athlete_override' => ['Hava|Veronika|1984-03-14' => (string) $jank->id],
    ])->assertRedirect(route('records.index'));

    expect(SwimRecord::query()->sole()->athlete_id)->toBe($jank->id)
        ->and(Athlete::query()->count())->toBe(1);
});

it('übernimmt beim Import die Zuordnung auf den vorgeschlagenen Athleten', /** @throws Throwable */ function () {
    $jank = athlete_ribd('Jank', 'Veronika', '1984-03-14');
    $path = lenex_ribd();
    $key = $this->service->preview($path)['unknown_athletes'][0]['key'];

    $this->service->import($path, ['TST|AUT' => 'skip'], [$key => (string) $jank->id], [], []);

    expect(SwimRecord::query()->sole()->athlete_id)->toBe($jank->id)
        ->and(Athlete::query()->count())->toBe(1);
});
