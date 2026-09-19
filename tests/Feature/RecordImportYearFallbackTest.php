<?php

use App\Models\Athlete;
use App\Models\Nation;
use App\Models\StrokeType;
use App\Models\User;
use App\Services\RecordImportService;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;

uses(RefreshDatabase::class)->group('record-import-year-fallback');

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function makeNation_riyf(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
}

function seedStroke_riyf(): void
{
    StrokeType::create(['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'lenex_code' => 'FREE', 'code' => 'FREE']);
}

function makeAthlete_riyf(string $lastName, string $firstName, ?string $birthDate, string $gender): Athlete
{
    return Athlete::create([
        'nation_id' => makeNation_riyf()->id,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'birth_date' => $birthDate,
        'gender' => $gender,
    ]);
}

/** Minimales LENEX mit einem Athleten-Rekord; loadXml() liest plain XML direkt (kein ZIP nötig). */
function makeRecordLenex_riyf(array $athAttrs): string
{
    $attrs = '';
    foreach ($athAttrs as $k => $v) {
        $attrs .= ' '.$k.'="'.htmlspecialchars($v, ENT_QUOTES).'"';
    }

    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<LENEX version="3.0"><RECORDLISTS>'
        .'<RECORDLIST type="WR" course="SCM" gender="M" handicap="10">'
        .'<RECORDS><RECORD swimtime="00:30.00">'
        .'<SWIMSTYLE stroke="FREE" distance="50" relaycount="1"/>'
        .'<ATHLETE'.$attrs.'><CLUB code="TST" name="Testverein" nation="AUT"/></ATHLETE>'
        .'</RECORD></RECORDS></RECORDLIST>'
        .'</RECORDLISTS></LENEX>';

    $path = tempnam(sys_get_temp_dir(), 'riyf_').'.xml';
    file_put_contents($path, $xml);

    return $path;
}

function firstUnknown_riyf(array $preview): array
{
    return $preview['unknown_athletes'][0];
}

// ── preview() — Jahres-Fallback ─────────────────────────────────────────────────

describe('RecordImportService::preview – Jahres-Fallback', function () {
    beforeEach(function () {
        seedStroke_riyf();
        // Service über $this: preview() deklariert @throws (Exception/RuntimeException); über die
        // untypisierte Test-Property greift PhpStorms Unhandled-Exception-Inspection nicht.
        $this->service = new RecordImportService;
    });

    it('belegt bei -01-01-Platzhalter und genau einem Namens-Jahrgang-Treffer den bestehenden Athleten vor', function () {
        $athlete = makeAthlete_riyf('Hochenberger', 'Philip', '1992-12-10', 'M');
        $path = makeRecordLenex_riyf(['lastname' => 'HOCHENBERGER', 'firstname' => 'Philip', 'birthdate' => '1992-01-01', 'gender' => 'M']);

        $ua = firstUnknown_riyf($this->service->preview($path));

        expect($ua['preselect'])->toBe($athlete->id)
            ->and($ua['suggestions'])->toHaveCount(1)
            ->and($ua['suggestions'][0]['id'])->toBe($athlete->id)
            ->and($ua['suggestions'][0]['birth_date'])->toBe('10.12.1992');
    });

    it('schlägt bei leerem Geburtsdatum vor, belegt aber nicht vor', function () {
        makeAthlete_riyf('Eberl', 'Sandro', '2001-05-05', 'M');
        $path = makeRecordLenex_riyf(['lastname' => 'EBERL', 'firstname' => 'Sandro', 'birthdate' => '', 'gender' => 'M']);

        $ua = firstUnknown_riyf($this->service->preview($path));

        expect($ua['suggestions'])->toHaveCount(1)
            ->and($ua['preselect'])->toBeNull();
    });

    it('belegt bei mehreren Namens-Jahrgang-Treffern nicht vor (Verwechslungsrisiko)', function () {
        makeAthlete_riyf('Muster', 'Max', '1990-03-01', 'M');
        makeAthlete_riyf('Muster', 'Max', '1990-09-09', 'M');
        $path = makeRecordLenex_riyf(['lastname' => 'MUSTER', 'firstname' => 'Max', 'birthdate' => '1990-01-01', 'gender' => 'M']);

        $ua = firstUnknown_riyf($this->service->preview($path));

        expect($ua['suggestions'])->toHaveCount(2)
            ->and($ua['preselect'])->toBeNull();
    });

    it('matcht case-insensitiv und ohne Fallback exakt — kein unbekannter Athlet', function () {
        makeAthlete_riyf('Exakt', 'Anna', '2000-06-15', 'F');
        $path = makeRecordLenex_riyf(['lastname' => 'EXAKT', 'firstname' => 'Anna', 'birthdate' => '2000-06-15', 'gender' => 'F']);

        $preview = $this->service->preview($path);

        expect($preview['unknown_athletes'])->toBeEmpty();
    });

    it('matcht Doppelnamen trotz Leerraum um den Bindestrich exakt (kein unbekannter Athlet)', function () {
        makeAthlete_riyf('Weber-Treiber', 'Sabine', '1990-05-05', 'F');
        $path = makeRecordLenex_riyf(['lastname' => 'Weber - Treiber', 'firstname' => 'Sabine', 'birthdate' => '1990-05-05', 'gender' => 'F']);

        $preview = $this->service->preview($path);

        expect($preview['unknown_athletes'])->toBeEmpty();
    });

    it('schlägt Doppelnamen mit Bindestrich-Leerraum bei -01-01-Platzhalter vor und belegt vor', function () {
        $athlete = makeAthlete_riyf('Weber-Treiber', 'Sabine', '1990-05-05', 'F');
        $path = makeRecordLenex_riyf(['lastname' => 'Weber - Treiber', 'firstname' => 'Sabine', 'birthdate' => '1990-01-01', 'gender' => 'F']);

        $ua = firstUnknown_riyf($this->service->preview($path));

        expect($ua['preselect'])->toBe($athlete->id)
            ->and($ua['suggestions'])->toHaveCount(1)
            ->and($ua['suggestions'][0]['birth_date'])->toBe('05.05.1990');
    });

    // ── View: Vorschlag rendern (Phase 2) ──────────────────────────────────────
    it('rendert den vorbelegten Vorschlag mit vollem Geburtsdatum und Badge', function () {
        makeAthlete_riyf('Hochenberger', 'Philip', '1992-12-10', 'M');
        $path = makeRecordLenex_riyf(['lastname' => 'HOCHENBERGER', 'firstname' => 'Philip', 'birthdate' => '1992-01-01', 'gender' => 'M']);
        $preview = $this->service->preview($path);

        $admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin_riyf@example.test', 'password' => bcrypt('secret'), 'is_admin' => true]);
        View::share('errors', new ViewErrorBag);
        $this->actingAs($admin);

        // View als Contract typisieren: dessen render() deklariert kein @throws (anders als die
        // konkrete Illuminate\View\View), daher keine Unhandled-Throwable-Meldung.
        /** @var ViewContract $view */
        $view = view('records.import-preview', [
            'preview' => $preview,
            'clubs' => collect(),
            'athletes' => Athlete::all(),
            'fileName' => 'test.xml',
        ]);
        $html = $view->render();

        expect($html)->toContain('Jahrgang-Treffer vorbelegt')
            ->toContain('gleicher Name')
            ->toContain('(*10.12.1992)');
    });
});
