<?php

use App\Http\Controllers\Public\AccessibilityStatementController;
use App\Http\Controllers\Public\AnnualBestController;
use App\Http\Controllers\Public\BaseTimeTableController;
use App\Http\Controllers\Public\CupRankingController;
use App\Http\Controllers\Public\DocumentDownloadController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\ImprintController;
use App\Http\Controllers\Public\MeetController;
use App\Http\Controllers\Public\MeetResultController;
use App\Http\Controllers\Public\PointCalculatorController;
use App\Http\Controllers\Public\PrivacyPolicyController;
use App\Http\Controllers\Public\QualifyingTimeController;
use App\Http\Controllers\Public\RecordController;
use App\Http\Controllers\Public\RecordExportController;
use App\Http\Controllers\Public\RegulationController;
use App\Http\Controllers\Public\RobotsController;
use App\Http\Controllers\Public\SitemapController;
use App\Http\Controllers\Public\WpsPointCalculatorController;
use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Öffentlicher Bereich
|--------------------------------------------------------------------------
|
| Eigene Datei statt Erweiterung von web.php (Spec public-frontend §3.2) — dort sind in der
| Vergangenheit bereits Routen verlorengegangen. Registrierung über bootstrap/app.php.
|
*/

// "/" trägt kein Sprachpräfix: Sprache aus Cookie, sonst Browsersprache, sonst Default
// ermitteln, die Wahl im Cookie für den nächsten Besuch merken, weiterleiten. Ersetzt die
// bisherige Route::redirect('/', '/meets') aus web.php.
Route::get('/', function (Request $request) {
    $locale = $request->cookie('locale')
        ?? $request->getPreferredLanguage(SetLocale::SUPPORTED)
        ?? SetLocale::DEFAULT;

    return Redirect::to("/$locale")->withCookie(cookie('locale', $locale, 60 * 24 * 365));
})->name('public.root');

// Kein Sprachpräfix, keine SetLocale-Middleware: eine Sitemap ist eine einzige, sprachübergreifende
// Datei (jede URL erscheint einmal je Sprache), keine pro Sprache aufgeteilte Seite (Phase 9).
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('public.sitemap');

// Ersetzt die bisherige statische public/robots.txt (siehe RobotsController-Kommentar) — die
// Datei musste dafür entfernt werden, sonst liefert der Webserver sie weiter direkt aus.
Route::get('/robots.txt', [RobotsController::class, 'index'])->name('public.robots');

Route::prefix('{locale}')
    ->where(['locale' => implode('|', SetLocale::SUPPORTED)])
    ->middleware(SetLocale::class)
    ->group(function (): void {
        Route::get('/', [HomeController::class, 'index'])->name('public.home');

        // Dokumente: generisch, nicht meet-verschachtelt — Phase 8 (Regelmente) braucht
        // denselben Controller für Dokumente ganz ohne Veranstaltungsbezug.
        Route::get('dokumente/{document}/download', [DocumentDownloadController::class, 'show'])
            ->name('public.documents.download');

        Route::prefix('veranstaltungen')->name('public.meets.')->group(function (): void {
            Route::get('/', [MeetController::class, 'index'])->name('index');

            // Muss vor {meet} stehen, sonst bindet Laravel "archiv" als Meet-Schlüssel und
            // liefert 404, statt die Archiv-Aktion zu erreichen.
            Route::get('archiv', [MeetController::class, 'archive'])->name('archive');

            Route::get('{meet}', [MeetController::class, 'show'])->name('show');
            Route::get('{meet}/ergebnisse', [MeetResultController::class, 'show'])->name('results');
        });

        Route::prefix('rekorde')->name('public.records.')->group(function (): void {
            Route::get('/', [RecordController::class, 'index'])->name('index');
            Route::get('export', [RecordExportController::class, 'download'])->name('export');
        });

        Route::get('punktetabelle', [BaseTimeTableController::class, 'index'])->name('public.base-times.index');
        Route::get('punkterechner', [PointCalculatorController::class, 'index'])->name('public.point-calculator.index');
        Route::get('wps-punkterechner', [WpsPointCalculatorController::class, 'index'])
            ->name('public.wps-point-calculator.index');

        // Jahr optional: ohne Angabe (Nav-Link) löst der Controller das aktuellste verfügbare
        // Jahr auf, die Jahresauswahl auf der Seite selbst führt danach auf die volle URL.
        Route::get('cup/{jahr?}', [CupRankingController::class, 'index'])
            ->where('jahr', '[0-9]+')
            ->name('public.cup-ranking.index');
        Route::get('startberechtigung', [QualifyingTimeController::class, 'index'])
            ->name('public.qualifying-times.index');
        Route::get('bestleistungen/{jahr?}', [AnnualBestController::class, 'index'])
            ->where('jahr', '[0-9]+')
            ->name('public.annual-best.index');
        Route::get('reglemente', [RegulationController::class, 'index'])->name('public.regulations.index');
        Route::get('barrierefreiheit', [AccessibilityStatementController::class, 'index'])
            ->name('public.accessibility-statement.index');

        // Entwürfe mit Platzhaltern statt echtem Inhalt (Rückmeldung, Phase 9 Nachtrag) — siehe
        // ImprintController/PrivacyPolicyController. Bewusst nicht in SitemapController gelistet
        // und zusätzlich zum noindex-Meta-Tag in robots.txt gesperrt (RobotsController), bis der
        // echte Inhalt feststeht.
        Route::get('impressum', [ImprintController::class, 'index'])->name('public.imprint.index');
        Route::get('datenschutz', [PrivacyPolicyController::class, 'index'])
            ->name('public.privacy-policy.index');
    });
