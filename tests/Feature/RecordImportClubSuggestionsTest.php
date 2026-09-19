<?php

use App\Models\Club;
use App\Models\Nation;
use App\Models\StrokeType;
use App\Models\User;
use App\Services\RecordImportService;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;

uses(RefreshDatabase::class)->group('record-import-club-suggestions');

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function nation_rics(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
}

function club_rics(string $name, string $code): Club
{
    return Club::create(['name' => $name, 'code' => $code, 'nation_id' => nation_rics()->id, 'type' => 'CLUB']);
}

/** Minimales LENEX mit einem (unbekannten) Athleten und dem übergebenen Verein an dessen CLUB. */
function lenexWithClub_rics(array $clubAttrs): string
{
    StrokeType::firstOrCreate(['lenex_code' => 'FREE'], ['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'code' => 'FREE']);

    $c = '';
    foreach ($clubAttrs as $k => $v) {
        $c .= ' '.$k.'="'.htmlspecialchars($v, ENT_QUOTES).'"';
    }

    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<LENEX version="3.0"><RECORDLISTS>'
        .'<RECORDLIST type="WR" course="SCM" gender="M" handicap="10">'
        .'<RECORDS><RECORD swimtime="00:30.00">'
        .'<SWIMSTYLE stroke="FREE" distance="50" relaycount="1"/>'
        .'<ATHLETE lastname="Unbekannt" firstname="Athlet" birthdate="2000-05-05" gender="M">'
        .'<CLUB'.$c.'/></ATHLETE>'
        .'</RECORD></RECORDS></RECORDLIST></RECORDLISTS></LENEX>';

    $path = tempnam(sys_get_temp_dir(), 'rics_').'.xml';
    file_put_contents($path, $xml);

    return $path;
}

function firstUnknownClub_rics(array $preview): array
{
    return $preview['unknown_clubs'][0];
}

// ── suggestClubs über preview() ─────────────────────────────────────────────────

describe('RecordImportService::preview – Vereins-Vorschläge', function () {
    // Service über die (untypisierte) $this-Property einer describe-beforeEach: preview() deklariert
    // @throws, was PhpStorm hier dann nicht mehr als "Unhandled Exception" auflöst.
    beforeEach(function () {
        $this->service = new RecordImportService;
    });

    it('belegt bei exaktem Namenstreffer trotz abweichendem Code/Whitespace vor', function () {
        $club = club_rics('SC Wien', 'SCWDB');
        // Anderer Code + doppeltes Leerzeichen → findClub findet nicht, suggestClubs normalisiert.
        $path = lenexWithClub_rics(['code' => 'SCWX', 'name' => 'SC  Wien', 'nation' => 'AUT']);

        $uc = firstUnknownClub_rics($this->service->preview($path));

        expect($uc['preselect'])->toBe($club->id)
            ->and($uc['suggestions'])->toHaveCount(1)
            ->and($uc['suggestions'][0]['id'])->toBe($club->id);
    });

    it('belegt bei Wortgrenzen-Präfix (Namenszusatz) vor', function () {
        $club = club_rics('Flying Flippers', 'FF');
        $path = lenexWithClub_rics(['code' => 'FFST', 'name' => 'Flying Flippers Schwimmteam', 'nation' => 'AUT']);

        $uc = firstUnknownClub_rics($this->service->preview($path));

        expect($uc['preselect'])->toBe($club->id)
            ->and($uc['suggestions'])->toHaveCount(1);
    });

    it('macht keinen Vorschlag bei einem echt unbekannten Verein', function () {
        club_rics('Flying Flippers', 'FF');
        $path = lenexWithClub_rics(['code' => 'DIA', 'name' => 'SC Diana', 'nation' => 'AUT']);

        $uc = firstUnknownClub_rics($this->service->preview($path));

        expect($uc['suggestions'])->toBeEmpty()
            ->and($uc['preselect'])->toBeNull();
    });

    it('belegt bei mehreren Treffern nicht vor', function () {
        club_rics('SC Wien', 'SCW1');
        club_rics('SC Wien', 'SCW2');
        $path = lenexWithClub_rics(['code' => 'SCWX', 'name' => 'SC  Wien', 'nation' => 'AUT']);

        $uc = firstUnknownClub_rics($this->service->preview($path));

        expect($uc['suggestions'])->toHaveCount(2)
            ->and($uc['preselect'])->toBeNull();
    });

    it('rendert den vorbelegten Vereins-Vorschlag und koppelt ihn an die Live-Namensanzeige', function () {
        $club = club_rics('Flying Flippers', 'FF');
        $path = lenexWithClub_rics(['code' => 'FFST', 'name' => 'Flying Flippers Schwimmteam', 'nation' => 'AUT']);
        $preview = $this->service->preview($path);

        $admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin_rics@example.test', 'password' => bcrypt('secret'), 'is_admin' => true]);
        View::share('errors', new ViewErrorBag);
        $this->actingAs($admin);

        // View als Contract typisieren: dessen render() deklariert kein @throws (anders als die
        // konkrete Illuminate\View\View), daher keine Unhandled-Throwable-Meldung.
        /** @var ViewContract $view */
        $view = view('records.import-preview', [
            'preview' => $preview,
            'clubs' => Club::all(),
            'athletes' => collect(),
            'fileName' => 'test.xml',
        ]);
        $html = $view->render();

        // Badge + Vorbelegung im data-config (koppelt an die Alpine-Live-Namensanzeige der Athleten).
        expect($html)->toContain('Vorschlag vorbelegt')
            ->toContain('"FFST":"'.$club->id.'"');
    });
});
