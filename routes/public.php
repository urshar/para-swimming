<?php

use App\Http\Controllers\Public\DocumentDownloadController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\MeetController;
use App\Http\Controllers\Public\MeetResultController;
use App\Http\Controllers\Public\RecordController;
use App\Http\Controllers\Public\RecordExportController;
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
    });
