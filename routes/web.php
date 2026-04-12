<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AthleteController;
use App\Http\Controllers\ClassifierController;
use App\Http\Controllers\ClubController;
use App\Http\Controllers\EntryController;
use App\Http\Controllers\LenexExportController;
use App\Http\Controllers\LenexImportController;
use App\Http\Controllers\MeetController;
use App\Http\Controllers\NationController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\RecordExportController;
use App\Http\Controllers\RecordImportController;
use App\Http\Controllers\ResultController;
use App\Http\Controllers\SwimEventController;
use App\Http\Middleware\RequireAdmin;
use Illuminate\Support\Facades\Route;

// Startseite → Wettkampf-Übersicht
Route::redirect('/', '/meets')->name('home');
Route::redirect('/dashboard', '/meets')->name('dashboard');

Route::middleware(['auth', RequireAdmin::class])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
});

// ── Stammdaten ────────────────────────────────────────────────────────────────
Route::resource('nations', NationController::class)
    ->only(['index', 'edit', 'update']);

Route::resource('clubs', ClubController::class);

Route::resource('athletes', AthleteController::class);

// Vereinswechsel
Route::post('athletes/{athlete}/transfer-club',
    [AthleteController::class, 'transferClub'])->name('athletes.transfer-club');

// Klassifikation
Route::post('athletes/{athlete}/classifications',
    [AthleteController::class, 'storeClassification'])->name('athletes.classifications.store');
Route::put('athletes/{athlete}/classifications/{classification}',
    [AthleteController::class, 'updateClassification'])->name('athletes.classifications.update');
Route::put('athletes/{athlete}/classifications/{classification}',
    [AthleteController::class, 'updateClassification'])->name('athletes.classifications.update');
Route::delete('athletes/{athlete}/classifications/{classification}',
    [AthleteController::class, 'destroyClassification'])->name('athletes.classifications.destroy');

// Level
Route::post('athletes/{athlete}/levels', [AthleteController::class, 'storeLevel'])->name('athletes.levels.store');

// ── Klassifizierer-Stammdaten (einfaches Resource) ────────────────────────────
Route::resource('classifiers', ClassifierController::class);

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
    Route::get('/import', [LenexImportController::class, 'showForm'])->name('import');
    Route::post('/import', [LenexImportController::class, 'import'])->name('import.store');
    Route::get('/import/confirm-meet', [LenexImportController::class, 'confirmMeet'])->name('import.confirm-meet');
    Route::post('/import/run', [LenexImportController::class, 'runImport'])->name('import.run');
    Route::get('/import/review', [LenexImportController::class, 'review'])->name('import.review');
    Route::post('/import/resolve-clubs', [LenexImportController::class, 'resolveClubs'])->name('import.resolve-clubs');
    Route::post('/import/resolve-athletes',
        [LenexImportController::class, 'resolveAthletes'])->name('import.resolve-athletes');

    // Export
    Route::get('export', [LenexExportController::class, 'showForm'])->name('export');
    Route::post('export/download', [LenexExportController::class, 'download'])->name('export.download');
});

// ── Rekorde ───────────────────────────────────────────────────────────────────
Route::prefix('records')->name('records.')->group(function () {

    Route::get('/', [RecordController::class, 'index'])->name('index');
    Route::get('create', [RecordController::class, 'createManual'])->name('create');
    Route::post('/', [RecordController::class, 'storeManual'])->name('store');

    // Import + Export + Check — VOR {record} damit 'import' nicht als ID gematcht wird
    Route::get('import', [RecordImportController::class, 'showForm'])->name('import');
    Route::post('import/preview', [RecordImportController::class, 'preview'])->name('import.preview');
    Route::post('import/run', [RecordImportController::class, 'run'])->name('import.run');

    // Export — zweistufig: Formular → Download
    Route::get('export', [RecordExportController::class, 'showForm'])->name('export');
    Route::post('export/download', [RecordExportController::class, 'download'])->name('export.download');

    Route::post('check/{meet}', [RecordController::class, 'checkMeet'])->name('check');

    // {record} Routen zuletzt
    Route::get('{record}/edit', [RecordController::class, 'edit'])->name('edit');
    Route::put('{record}', [RecordController::class, 'update'])->name('update');
    Route::get('{record}', [RecordController::class, 'show'])->name('show');
    Route::delete('{record}', [RecordController::class, 'destroy'])->name('destroy');
    Route::post('{record}/restore', [RecordController::class, 'restore'])->name('restore');
});
