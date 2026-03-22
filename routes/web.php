<?php

use App\Http\Controllers\AthleteController;
use App\Http\Controllers\ClubController;
use App\Http\Controllers\EntryController;
use App\Http\Controllers\LenexExportController;
use App\Http\Controllers\LenexImportController;
use App\Http\Controllers\MeetController;
use App\Http\Controllers\NationController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\ResultController;
use App\Http\Controllers\SwimEventController;
use Illuminate\Support\Facades\Route;

// Startseite → Wettkampf-Übersicht
Route::redirect('/', '/meets');

// ── Stammdaten ────────────────────────────────────────────────────────────────
Route::resource('nations', NationController::class)
    ->only(['index', 'edit', 'update']);

Route::resource('clubs', ClubController::class);

Route::resource('athletes', AthleteController::class);

// ── Wettkämpfe ────────────────────────────────────────────────────────────────
Route::resource('meets', MeetController::class);

// Disziplinen nested unter Meet (shallow: edit/update/destroy per eigener ID)
Route::resource('meets.events', SwimEventController::class)
    ->shallow()
    ->except(['index', 'show'])
    ->parameters(['events' => 'event']);

// Meldungen
Route::resource('entries', EntryController::class)
    ->only(['index']);

Route::resource('meets.entries', EntryController::class)
    ->shallow()
    ->except(['index'])
    ->parameters(['entries' => 'entry']);

// Ergebnisse
Route::resource('results', ResultController::class)
    ->only(['index']);

Route::resource('meets.results', ResultController::class)
    ->shallow()
    ->except(['index'])
    ->parameters(['results' => 'result']);

// ── LENEX ─────────────────────────────────────────────────────────────────────
Route::prefix('lenex')->name('lenex.')->group(function () {

    // Import — dreistufig: Upload → Review → Resolve
    Route::get('import', [LenexImportController::class, 'showForm'])->name('import');
    Route::post('import', [LenexImportController::class, 'import'])->name('import.store');
    Route::get('import/review', [LenexImportController::class, 'review'])->name('import.review');
    Route::post('import/resolve', [LenexImportController::class, 'resolve'])->name('import.resolve');

    // Export
    Route::get('export', [LenexExportController::class, 'showForm'])->name('export');
    Route::post('export/download', [LenexExportController::class, 'download'])->name('export.download');
});

// ── Rekorde ───────────────────────────────────────────────────────────────────
Route::prefix('records')->name('records.')->group(function () {

    Route::get('/', [RecordController::class, 'index'])->name('index');
    Route::get('create', [RecordController::class, 'createManual'])->name('create');
    Route::post('/', [RecordController::class, 'storeManual'])->name('store');
    Route::get('{record}', [RecordController::class, 'show'])->name('show');
    Route::delete('{record}', [RecordController::class, 'destroy'])->name('destroy');

    Route::get('import', [RecordController::class, 'importForm'])->name('import');
    Route::post('import', [RecordController::class, 'import'])->name('import.store');
    Route::post('export', [RecordController::class, 'export'])->name('export');

    Route::post('check/{meet}', [RecordController::class, 'checkMeet'])->name('check');
});
