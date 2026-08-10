<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AgeGroupController;
use App\Http\Controllers\AthleteController;
use App\Http\Controllers\BaseTimeCategoryController;
use App\Http\Controllers\BaseTimeExportController;
use App\Http\Controllers\BaseTimeImportController;
use App\Http\Controllers\BaseTimeVersionController;
use App\Http\Controllers\ChampionshipController;
use App\Http\Controllers\ChampionshipQualificationController;
use App\Http\Controllers\ChampionshipSelectionController;
use App\Http\Controllers\ChampionshipStandardImportController;
use App\Http\Controllers\ClassifierController;
use App\Http\Controllers\ClubController;
use App\Http\Controllers\ClubEntryController;
use App\Http\Controllers\CupClubRankingController;
use App\Http\Controllers\CupController;
use App\Http\Controllers\CupDailyRankingController;
use App\Http\Controllers\CupOverallRankingController;
use App\Http\Controllers\EntryController;
use App\Http\Controllers\KaderTypeController;
use App\Http\Controllers\LenexExportController;
use App\Http\Controllers\LenexImportController;
use App\Http\Controllers\MeetController;
use App\Http\Controllers\NationController;
use App\Http\Controllers\QualifyingExcludedDisciplineController;
use App\Http\Controllers\QualifyingTimeListController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\RecordExportController;
use App\Http\Controllers\RecordImportController;
use App\Http\Controllers\ResultController;
use App\Http\Controllers\SportClassGroupController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\SwimEventController;
use App\Http\Controllers\WorldAquaticsPointsController;
use App\Http\Controllers\WpsPointCalculationController;
use App\Http\Controllers\WpsPointImportController;
use App\Http\Controllers\WpsPointVersionController;
use App\Http\Controllers\WpsScmFactorController;
use App\Http\Middleware\RequireAdmin;
use Illuminate\Support\Facades\Route;

// Startseite → Wettkampf-Übersicht
Route::redirect('/', '/meets')->name('home');
Route::redirect('/dashboard', '/meets')->name('dashboard');

// ── Admin-Bereich (RequireAdmin Middleware) ────────────────────────────────────
Route::middleware(['auth', RequireAdmin::class])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
});

// ── Authentifizierte Routen ───────────────────────────────────────────────────
Route::middleware(['auth'])->group(function () {

    // ── BaseTimes tools ──────────────────────────────────────────────────
    Route::prefix('base-times')->name('base-times.')->group(function () {
        Route::get('import', [BaseTimeImportController::class, 'showForm'])->name('import');
        Route::post('import/preview', [BaseTimeImportController::class, 'preview'])->name('import.preview');
        Route::post('import/run', [BaseTimeImportController::class, 'run'])->name('import.run');

        Route::resource('versions', BaseTimeVersionController::class)
            ->except(['show'])->names('versions');

        Route::get('{version}/categories', [BaseTimeCategoryController::class, 'index'])->name('categories.index');
        Route::get('{version}/categories/{category}',
            [BaseTimeCategoryController::class, 'show'])->name('categories.show');

        Route::get('{version}/export', [BaseTimeExportController::class, 'export'])->name('export');
    });

    // ── Club-Einzelmeldungen ──────────────────────────────────────────────────
    Route::prefix('meets/{meet}/club-entries')->name('club-entries.')->group(function () {
        Route::get('/', [ClubEntryController::class, 'index'])->name('index');
        Route::get('/create', [ClubEntryController::class, 'create'])->name('create');
        Route::post('/', [ClubEntryController::class, 'store'])->name('store');
        Route::get('/{entry}/edit', [ClubEntryController::class, 'edit'])->name('edit');
        Route::put('/{entry}', [ClubEntryController::class, 'update'])->name('update');
        Route::delete('/{entry}', [ClubEntryController::class, 'destroy'])->name('destroy');
        Route::get('/eligible-athletes', [ClubEntryController::class, 'eligibleAthletes'])->name('eligible-athletes');
        Route::get('/best-times', [ClubEntryController::class, 'bestTimes'])->name('best-times');
    });
    Route::get('/club-entries/pick-meet',
        [ClubEntryController::class, 'pickMeet'])->name('club-entries.pick-meet');

    // ── Staffelmeldungen ──────────────────────────────────────────────────────
    Route::prefix('meets/{meet}/relay-entries')->name('club-entries.relay.')->group(function () {
        // AJAX zuerst (vor {relayEntry}-Platzhalter, sonst wird 'relay-athletes' als ID interpretiert)
        Route::get('/relay-athletes', [ClubEntryController::class, 'eligibleRelayAthletes'])->name('relay-athletes');

        Route::get('/', [ClubEntryController::class, 'indexRelay'])->name('index');
        Route::get('/create', [ClubEntryController::class, 'createRelay'])->name('create');
        Route::post('/', [ClubEntryController::class, 'storeRelay'])->name('store');
        Route::get('/{relayEntry}/edit', [ClubEntryController::class, 'editRelay'])->name('edit');
        Route::put('/{relayEntry}', [ClubEntryController::class, 'updateRelay'])->name('update');
        Route::delete('/{relayEntry}', [ClubEntryController::class, 'destroyRelay'])->name('destroy');
    });
    Route::get('/relay-entries/pick-meet',
        [ClubEntryController::class, 'pickMeetRelay'])->name('club-entries.relay.pick-meet');

    // ── Stammdaten ────────────────────────────────────────────────────────────
    Route::resource('nations', NationController::class)
        ->only(['index', 'edit', 'update']);

    Route::resource('clubs', ClubController::class);

    Route::resource('athletes', AthleteController::class);

    Route::post('athletes/{athlete}/transfer-club',
        [AthleteController::class, 'transferClub'])->name('athletes.transfer-club');

    Route::post('athletes/{athlete}/classifications',
        [AthleteController::class, 'storeClassification'])->name('athletes.classifications.store');
    Route::put('athletes/{athlete}/classifications/{classification}',
        [AthleteController::class, 'updateClassification'])->name('athletes.classifications.update');
    Route::delete('athletes/{athlete}/classifications/{classification}',
        [AthleteController::class, 'destroyClassification'])->name('athletes.classifications.destroy');

    Route::post('athletes/{athlete}/levels',
        [AthleteController::class, 'storeLevel'])->name('athletes.levels.store');

    Route::post('athletes/{athlete}/kader-memberships',
        [AthleteController::class, 'storeKaderMembership'])->name('athletes.kader-memberships.store');
    Route::delete('athletes/{athlete}/kader-memberships/{kaderMembership}',
        [AthleteController::class, 'destroyKaderMembership'])->name('athletes.kader-memberships.destroy');

    // ── ÖBSV Cup Wertung — Stammdaten (Phase 1, admin-only) ────────────────────
    Route::resource('cups', CupController::class)->except(['show']);
    Route::post('cups/{cup}/classify-top-group',
        [CupController::class, 'classifyTopGroup'])->name('cups.classify-top-group');

    Route::get('cup-wertung',
        [CupOverallRankingController::class, 'index'])->name('cups.overall-ranking.index');

    Route::get('cups/{cup}/overall-ranking',
        [CupOverallRankingController::class, 'show'])->name('cups.overall-ranking.show');
    Route::get('cups/{cup}/overall-ranking/pdf',
        [CupOverallRankingController::class, 'pdf'])->name('cups.overall-ranking.pdf');
    Route::post('cups/{cup}/overall-ranking/calculate',
        [CupOverallRankingController::class, 'calculate'])->name('cups.overall-ranking.calculate');

    // ── ÖBSV Cup Wertung — Vereinswertung (Phase 3) ────────────────────────────
    Route::get('vereinswertung',
        [CupClubRankingController::class, 'index'])->name('cups.club-ranking.index');
    Route::get('cups/{cup}/club-ranking',
        [CupClubRankingController::class, 'show'])->name('cups.club-ranking.show');
    Route::get('cups/{cup}/club-ranking/pdf',
        [CupClubRankingController::class, 'pdf'])->name('cups.club-ranking.pdf');

    Route::resource('kader-types', KaderTypeController::class)->except(['show']);

    Route::resource('age-groups', AgeGroupController::class)->except(['show']);

    Route::resource('sport-class-groups', SportClassGroupController::class)->except(['show']);
    Route::post('sport-class-groups/{sportClassGroup}/members',
        [SportClassGroupController::class, 'storeMember'])->name('sport-class-groups.members.store');
    Route::delete('sport-class-groups/{sportClassGroup}/members/{member}',
        [SportClassGroupController::class, 'destroyMember'])->name('sport-class-groups.members.destroy');

    // ── Richtzeiten ÖSTM & ÖM (Phase 1: Verwaltung) ─────────────────────────────
    Route::resource('qualifying-time-lists', QualifyingTimeListController::class);
    Route::get('qualifying-time-lists/{qualifyingTimeList}/qualifications',
        [QualifyingTimeListController::class, 'qualifications'])->name('qualifying-time-lists.qualifications');
    Route::get('qualifying-time-lists/{qualifyingTimeList}/pdf',
        [QualifyingTimeListController::class, 'pdfTimes'])->name('qualifying-time-lists.pdf');
    Route::get('qualifying-time-lists/{qualifyingTimeList}/qualifications/pdf',
        [QualifyingTimeListController::class, 'pdfQualifications'])->name('qualifying-time-lists.qualifications.pdf');
    Route::post('qualifying-time-lists/{qualifyingTimeList}/target-points',
        [QualifyingTimeListController::class, 'storeTargetPoint'])->name('qualifying-time-lists.target-points.store');
    Route::delete('qualifying-time-lists/{qualifyingTimeList}/target-points/{targetPoint}',
        [QualifyingTimeListController::class, 'destroyTargetPoint'])->name('qualifying-time-lists.target-points.destroy');
    Route::post('qualifying-time-lists/{qualifyingTimeList}/times',
        [QualifyingTimeListController::class, 'storeTime'])->name('qualifying-time-lists.times.store');
    Route::delete('qualifying-time-lists/{qualifyingTimeList}/times/{time}',
        [QualifyingTimeListController::class, 'destroyTime'])->name('qualifying-time-lists.times.destroy');
    Route::post('qualifying-time-lists/{qualifyingTimeList}/calculate',
        [QualifyingTimeListController::class, 'calculate'])->name('qualifying-time-lists.calculate');
    Route::post('qualifying-time-lists/{qualifyingTimeList}/qualifications/calculate',
        [QualifyingTimeListController::class, 'calculateQualifications'])->name('qualifying-time-lists.qualifications.calculate');

    // ── Richtzeiten ÖSTM & ÖM: Ausgeschlossene Bewerbe (z.B. 25m, 800m/1500m Frei) ──
    Route::get('qualifying-excluded-disciplines',
        [QualifyingExcludedDisciplineController::class, 'index'])->name('qualifying-excluded-disciplines.index');
    Route::post('qualifying-excluded-disciplines/{discipline}',
        [QualifyingExcludedDisciplineController::class, 'store'])->name('qualifying-excluded-disciplines.store');
    Route::delete('qualifying-excluded-disciplines/{discipline}',
        [QualifyingExcludedDisciplineController::class, 'destroy'])->name('qualifying-excluded-disciplines.destroy');

    // ── Klassifizierer ────────────────────────────────────────────────────────
    Route::resource('classifiers', ClassifierController::class);

    // ── Meisterschaften und Qualifikationsnormen ──────────────────────────────
    // Ansichten für alle Angemeldeten, Verwaltung nur für Admins (Spec §4).
    Route::get('championships', [ChampionshipController::class, 'index'])->name('championships.index');

    // create und import MÜSSEN vor der {championship}-Route stehen, sonst bindet Laravel
    // das Wort als Modell-Schlüssel und liefert 404.
    Route::middleware(RequireAdmin::class)->group(function () {
        Route::get('championships/create', [ChampionshipController::class, 'create'])
            ->name('championships.create');
        Route::post('championships', [ChampionshipController::class, 'store'])->name('championships.store');
        Route::get('championships/{championship}/edit', [ChampionshipController::class, 'edit'])
            ->name('championships.edit');
        Route::put('championships/{championship}', [ChampionshipController::class, 'update'])
            ->name('championships.update');
        Route::delete('championships/{championship}', [ChampionshipController::class, 'destroy'])
            ->name('championships.destroy');
        Route::post('championships/{championship}/copy-from', [ChampionshipController::class, 'copyFrom'])
            ->name('championships.copy-from');

        // Normimport, dreistufig: Formular → Vorschau → Import (Spec §9.2).
        Route::get('championships/{championship}/import',
            [ChampionshipStandardImportController::class, 'showForm'])->name('championships.import');
        Route::post('championships/{championship}/import/preview',
            [ChampionshipStandardImportController::class, 'preview'])->name('championships.import.preview');
        Route::post('championships/{championship}/import/run',
            [ChampionshipStandardImportController::class, 'run'])->name('championships.import.run');
    });

    // Auswertungen — lesend, deshalb ohne RequireAdmin. Vereinsnutzer sehen nur die
    // Athleten ihres Vereins; das regelt der jeweilige Controller.
    Route::get('championships/{championship}/qualified',
        [ChampionshipQualificationController::class, 'qualified'])->name('championships.qualified');
    Route::get('championships/{championship}/qualified/pdf',
        [ChampionshipQualificationController::class, 'qualifiedPdf'])->name('championships.qualified.pdf');
    Route::get('championships/{championship}/development',
        [ChampionshipQualificationController::class, 'development'])->name('championships.development');
    Route::get('championships/{championship}/development/pdf',
        [ChampionshipQualificationController::class, 'developmentPdf'])->name('championships.development.pdf');
    Route::get('championships/{championship}/selection',
        [ChampionshipSelectionController::class, 'show'])->name('championships.selection');
    Route::get('championships/{championship}/selection/pdf',
        [ChampionshipSelectionController::class, 'pdf'])->name('championships.selection.pdf');

    Route::get('championships/{championship}', [ChampionshipController::class, 'show'])
        ->name('championships.show');

    // ── WPS Point Scores (nur Admin) ──────────────────────────────────────────
    Route::middleware(RequireAdmin::class)->prefix('wps')->name('wps.')->group(function () {
        Route::get('import', [WpsPointImportController::class, 'showForm'])->name('import');
        Route::post('import/preview', [WpsPointImportController::class, 'preview'])->name('import.preview');
        Route::post('import/run', [WpsPointImportController::class, 'run'])->name('import.run');
        Route::post('import/cancel', [WpsPointImportController::class, 'cancel'])->name('import.cancel');

        Route::get('versions', [WpsPointVersionController::class, 'index'])->name('versions.index');
        Route::get('versions/{version}', [WpsPointVersionController::class, 'show'])->name('versions.show');
        Route::post('versions/{version}/activate',
            [WpsPointVersionController::class, 'activate'])->name('versions.activate');
        Route::post('versions/{version}/archive',
            [WpsPointVersionController::class, 'archive'])->name('versions.archive');
        Route::delete('versions/{version}',
            [WpsPointVersionController::class, 'destroy'])->name('versions.destroy');

        Route::get('factors', [WpsScmFactorController::class, 'index'])->name('factors.index');
        Route::get('factors/report', [WpsScmFactorController::class, 'report'])->name('factors.report');
        Route::post('factors/calibrate',
            [WpsScmFactorController::class, 'calibrate'])->name('factors.calibrate');
        Route::put('factors/{factor}', [WpsScmFactorController::class, 'update'])->name('factors.update');
        Route::delete('factors/{factor}',
            [WpsScmFactorController::class, 'destroy'])->name('factors.destroy');
    });

    // ── Statistik (nur Admin) ─────────────────────────────────────────────────
    Route::middleware(RequireAdmin::class)->group(function () {
        Route::view('/statistics', 'statistics.page')->name('statistics.index');
        Route::get('/statistics/report', [StatisticsController::class, 'report'])->name('statistics.report');
        Route::get('/statistics/report/pdf', [StatisticsController::class, 'reportPdf'])->name('statistics.report.pdf');
        Route::get('/statistics/report/xlsx', [StatisticsController::class, 'reportXlsx'])->name('statistics.report.xlsx');
        Route::get('/statistics/report/csv', [StatisticsController::class, 'reportCsv'])->name('statistics.report.csv');
    });

    // ── Wettkämpfe ────────────────────────────────────────────────────────────
    Route::resource('meets', MeetController::class);

    Route::get('meets/{meet}/cup-daily-ranking',
        [CupDailyRankingController::class, 'show'])->name('meets.cup-daily-ranking.show');
    Route::get('meets/{meet}/cup-daily-ranking/pdf',
        [CupDailyRankingController::class, 'pdf'])->name('meets.cup-daily-ranking.pdf');
    Route::post('meets/{meet}/cup-daily-ranking/calculate',
        [CupDailyRankingController::class, 'calculate'])->name('meets.cup-daily-ranking.calculate');

    Route::resource('meets.events', SwimEventController::class)
        ->shallow()
        ->except(['index', 'show'])
        ->parameters(['events' => 'event']);

    // Meldungen
    Route::resource('entries', EntryController::class)->only(['index']);
    Route::resource('meets.entries', EntryController::class)
        ->shallow()
        ->except(['index'])
        ->parameters(['entries' => 'entry']);

    // Ergebnisse
    Route::resource('results', ResultController::class)->only(['index']);
    Route::resource('meets.results', ResultController::class)
        ->shallow()
        ->except(['index'])
        ->parameters(['results' => 'result']);

    Route::post('meets/{meet}/recalculate-points', [WorldAquaticsPointsController::class, 'recalculate'])
        ->name('meets.recalculate-points');
    Route::post('meets/{meet}/recalculate-wps-points',
        [WpsPointCalculationController::class, 'recalculate'])->name('meets.wps-points.recalculate');

    // ── LENEX ─────────────────────────────────────────────────────────────────
    Route::prefix('lenex')->name('lenex.')->group(function () {
        Route::get('/import', [LenexImportController::class, 'showForm'])->name('import');
        Route::post('/import', [LenexImportController::class, 'import'])->name('import.store');
        Route::get('/import/confirm-meet', [LenexImportController::class, 'confirmMeet'])->name('import.confirm-meet');
        Route::post('/import/run', [LenexImportController::class, 'runImport'])->name('import.run');
        Route::get('/import/review', [LenexImportController::class, 'review'])->name('import.review');
        Route::post('/import/resolve-clubs',
            [LenexImportController::class, 'resolveClubs'])->name('import.resolve-clubs');
        Route::post('/import/resolve-athletes',
            [LenexImportController::class, 'resolveAthletes'])->name('import.resolve-athletes');

        Route::get('export', [LenexExportController::class, 'showForm'])->name('export');
        Route::post('export/download', [LenexExportController::class, 'download'])->name('export.download');
    });

    // ── Rekorde ───────────────────────────────────────────────────────────────
    Route::prefix('records')->name('records.')->group(function () {
        Route::get('/', [RecordController::class, 'index'])->name('index');
        Route::get('create', [RecordController::class, 'createManual'])->name('create');
        Route::post('/', [RecordController::class, 'storeManual'])->name('store');

        Route::get('import', [RecordImportController::class, 'showForm'])->name('import');
        Route::post('import/preview', [RecordImportController::class, 'preview'])->name('import.preview');
        Route::post('import/run', [RecordImportController::class, 'run'])->name('import.run');

        Route::get('export', [RecordExportController::class, 'showForm'])->name('export');
        Route::post('export/download', [RecordExportController::class, 'download'])->name('export.download');

        Route::post('check/{meet}', [RecordController::class, 'checkMeet'])->name('check');

        Route::get('{record}/edit', [RecordController::class, 'edit'])->name('edit');
        Route::put('{record}', [RecordController::class, 'update'])->name('update');
        Route::get('{record}', [RecordController::class, 'show'])->name('show');
        Route::delete('{record}', [RecordController::class, 'destroy'])->name('destroy');
        Route::post('{record}/restore', [RecordController::class, 'restore'])->name('restore');
    });

});

// ── Benutzereinstellungen (Profil, Sicherheit, Darstellung) ───────────────────
require __DIR__.'/settings.php';
