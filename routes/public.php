<?php

use App\Http\Controllers\Public\HomeController;
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
    });
