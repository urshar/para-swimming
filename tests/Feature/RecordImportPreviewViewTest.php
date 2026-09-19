<?php

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View as FacadesView;
use Illuminate\Support\ViewErrorBag;

uses(RefreshDatabase::class)->group('record-import-preview');

/**
 * Baut ein minimales $preview-Array mit einem unbekannten Verein und einem darauf
 * verweisenden unbekannten Athleten.
 */
function makePreview_rip(): array
{
    return [
        'records' => [],
        'regional_records' => [],
        'pending_records' => [],
        'skipped' => 0,
        'unknown_clubs' => [
            ['key' => 'FFST', 'code' => 'FFST', 'name' => 'Flying Flippers Schwimmteam', 'nation' => 'AUT',
                'suggestions' => [], 'preselect' => null],
        ],
        'unknown_athletes' => [
            [
                'key' => 'Zimmermann|Elfriede|2000-01-01',
                'last_name' => 'Zimmermann', 'first_name' => 'Elfriede',
                'birth_date' => '2000-01-01', 'gender' => 'F', 'license' => '',
                'club_key' => 'FFST', 'club_name' => 'Flying Flippers Schwimmteam',
                'club_db_id' => null, 'sport_class' => 'S10', 'db_id' => null,
                'suggestions' => [], 'preselect' => null,
            ],
        ],
    ];
}

function previewView_rip(): View
{
    // $errors wird sonst von der Web-Middleware (ShareErrorsFromSession) geteilt; beim direkten
    // Rendern ist es nicht gesetzt und Flux' select-Variante bricht darauf ab.
    FacadesView::share('errors', new ViewErrorBag);

    return view('records.import-preview', [
        'preview' => makePreview_rip(),
        'clubs' => collect([(object) ['id' => 5, 'display_name' => 'Flying Flippers']]),
        'athletes' => collect(),
        'fileName' => 'oebsv.lxf',
    ]);
}

it('verdrahtet die Import-Vorschau für die Live-Vereinsnamen-Aktualisierung', function () {
    $admin = User::forceCreate([
        'name' => 'Admin', 'email' => 'admin_rip@example.test', 'password' => bcrypt('secret'), 'is_admin' => true,
    ]);

    $this->actingAs($admin);
    // Über $this->view rendern (render() @throws Throwable → Inspection greift über die
    // untypisierte Property nicht).
    $this->view = previewView_rip();
    $html = $this->view->render();

    expect($html)
        // Seitenweite Alpine-Komponente + Konfiguration (bestehende Vereine + Vorbelegung)
        ->toContain('x-data="recordImportPreview()"')
        ->toContain('"clubsById":{"5":"Flying Flippers"}')
        ->toContain('"initialSelections":{"FFST":"new"}')
        // Vereins-Select schreibt seine Auswahl in die gemeinsame Map
        ->toContain('data-club-key="FFST"')
        ->toContain('x-model="clubSelections[$el.dataset.clubKey]"')
        // Athlet leitet seinen Vereinsnamen reaktiv ab (mit LENEX-Text als No-JS-Fallback)
        ->toContain('data-lenex-name="Flying Flippers Schwimmteam"')
        ->toContain("x-text=\"'· ' + athleteClubName(\$el.dataset.clubKey, \$el.dataset.lenexName)\"");
});
