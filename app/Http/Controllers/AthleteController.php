<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\AthleteClassification;
use App\Models\AthleteClubHistory;
use App\Models\AthleteLevelHistory;
use App\Models\Classifier;
use App\Models\Club;
use App\Models\ExceptionCode;
use App\Models\Nation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class AthleteController extends Controller
{
    public function index(Request $request): View
    {
        $query = Athlete::with(['club', 'nation', 'sportClasses'])
            ->orderBy('last_name')
            ->orderBy('first_name');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('last_name', 'like', '%'.$search.'%')
                    ->orWhere('first_name', 'like', '%'.$search.'%')
                    ->orWhere('license', 'like', '%'.$search.'%')
                    ->orWhere('license_ipc', 'like', '%'.$search.'%');
            });
        }

        if ($sportClass = $request->query('sport_class')) {
            $query->whereHas('sportClasses', function ($q) use ($sportClass) {
                $q->where('sport_class', $sportClass);
            });
        }

        if ($gender = $request->query('gender')) {
            $query->where('gender', $gender);
        }

        if ($nationId = $request->query('nation_id')) {
            $query->where('nation_id', $nationId);
        }

        if ($clubId = $request->query('club_id')) {
            $query->where('club_id', $clubId);
        }

        // Filter: nur aktive / alle
        if ($request->query('active_only', '1') === '1') {
            $query->where('is_active', true);
        }

        $athletes = $query->paginate(25)->withQueryString();
        $nations = Nation::active()->orderBy('name_de')->get();
        $clubs = Club::orderBy('name')->get();

        return view('athletes.index', compact('athletes', 'nations', 'clubs'));
    }

    /**
     * @throws Throwable
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateAthlete($request);

        DB::transaction(function () use ($data, $request) {
            $athlete = Athlete::create($data['athlete']);
            $this->syncSportClasses($athlete, $data['sport_classes']);
            $this->syncExceptions($athlete, $data['exceptions']);

            // Initialen Vereinseintrag in der History anlegen, wenn ein Verein gesetzt ist
            if ($athlete->club_id) {
                AthleteClubHistory::create([
                    'athlete_id' => $athlete->id,
                    'club_id' => $athlete->club_id,
                    'joined_at' => $request->input('club_joined_at', today()->toDateString()),
                    'is_active' => true,
                    'notes' => 'Ersterfassung',
                ]);
            }

            // Initialen Level-Eintrag anlegen, wenn ein Level gesetzt ist
            if ($athlete->level) {
                AthleteLevelHistory::create([
                    'athlete_id' => $athlete->id,
                    'user_id' => auth()->id(),
                    'level' => $athlete->level,
                    'previous_level' => null,
                    'changed_at' => today(),
                    'notes' => 'Ersterfassung',
                ]);
            }
        });

        return redirect()
            ->route('athletes.index')
            ->with('success', 'Athlet erfolgreich angelegt.');
    }

    public function create(): View
    {
        $nations = Nation::active()->orderBy('name_de')->get();
        $clubs = Club::with('nation')->orderBy('name')->get();
        $exceptionCodes = ExceptionCode::active()->orderBy('code')->get();

        return view('athletes.form', compact('nations', 'clubs', 'exceptionCodes'));
    }

    public function show(Athlete $athlete): View
    {
        $athlete->load([
            'club.nation',
            'nation',
            'sportClasses',
            'exceptions',
            'clubHistory.club',
            'classifications.medClassifier',
            'classifications.tech1Classifier',
            'classifications.tech2Classifier',
            'classifications.exceptions',
            'levelHistory.user',
        ]);

        $results = $athlete->results()
            ->with(['meet', 'swimEvent.strokeType'])
            ->orderByDesc('created_at')
            ->paginate(20);

        $clubs = Club::orderBy('name')->get();
        $medClassifiers = Classifier::active()->medical()->orderBy('last_name')->get();
        $techClassifiers = Classifier::active()->technical()->orderBy('last_name')->get();
        $users = User::orderBy('name')->get();
        $exceptionCodes = ExceptionCode::active()->orderBy('code')->get();

        return view('athletes.show', compact(
            'athlete', 'results',
            'clubs', 'medClassifiers', 'techClassifiers', 'users', 'exceptionCodes'
        ));
    }

    // ── Vereinswechsel (Ummeldung) ────────────────────────────────────────────

    public function edit(Athlete $athlete): View
    {
        $athlete->load(['sportClasses', 'exceptions']);

        $nations = Nation::active()->orderBy('name_de')->get();
        $clubs = Club::with('nation')->orderBy('name')->get();
        $exceptionCodes = ExceptionCode::active()->orderBy('code')->get();

        return view('athletes.form', compact('athlete', 'nations', 'clubs', 'exceptionCodes'));
    }

    // ── Klassifikation ────────────────────────────────────────────────────────

    public function destroy(Athlete $athlete): RedirectResponse
    {
        $athlete->delete();

        return redirect()
            ->route('athletes.index')
            ->with('success', 'Athlet gelöscht.');
    }

    /**
     * POST /athletes/{athlete}/transfer-club
     *
     * Schließt den aktiven Vereinseintrag und legt einen neuen an.
     * Aktualisiert auch das Convenience-Feld athletes.club_id.
     *
     * @throws Throwable
     */
    public function transferClub(Request $request, Athlete $athlete): RedirectResponse
    {
        $validated = $request->validate([
            'club_id' => 'required|exists:clubs,id|different:athlete.club_id',
            'joined_at' => 'required|date',
            'notes' => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($athlete, $validated) {
            // Aktiven Eintrag schließen
            AthleteClubHistory::where('athlete_id', $athlete->id)
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'left_at' => Carbon::parse($validated['joined_at'])->subDay()->toDateString(),
                ]);

            // Neuen Eintrag anlegen
            AthleteClubHistory::create([
                'athlete_id' => $athlete->id,
                'club_id' => $validated['club_id'],
                'joined_at' => $validated['joined_at'],
                'is_active' => true,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Convenience-Feld am Athleten aktualisieren
            $athlete->update(['club_id' => $validated['club_id']]);
        });

        return redirect()
            ->route('athletes.show', $athlete)
            ->with('success', 'Vereinswechsel erfolgreich eingetragen.');
    }

    /**
     * @used-by web.php (Route::put athletes/{athlete})
     *
     * @throws Throwable
     */
    public function update(Request $request, Athlete $athlete): RedirectResponse
    {
        $data = $this->validateAthlete($request);

        DB::transaction(function () use ($athlete, $data) {
            $oldLevel = $athlete->level;
            $athlete->update($data['athlete']);
            $this->syncSportClasses($athlete, $data['sport_classes']);
            $this->syncExceptions($athlete, $data['exceptions']);

            // Wenn Level geändert → History-Eintrag automatisch anlegen
            if ($oldLevel !== $athlete->level && $athlete->level) {
                AthleteLevelHistory::create([
                    'athlete_id' => $athlete->id,
                    'user_id' => auth()->id(),
                    'level' => $athlete->level,
                    'previous_level' => $oldLevel,
                    'changed_at' => today(),
                    'notes' => null,
                ]);
            }
        });

        return redirect()
            ->route('athletes.show', $athlete)
            ->with('success', 'Athlet aktualisiert.');
    }

    // ── Level-History ─────────────────────────────────────────────────────────

    /**
     * POST /athletes/{athlete}/classifications
     *
     * @throws Throwable
     */
    public function storeClassification(Request $request, Athlete $athlete): RedirectResponse
    {
        $validated = $this->validateClassification($request);

        DB::transaction(function () use ($athlete, $validated) {
            // Klassennummern normalisieren bevor gespeichert wird
            $validated['result_s'] = $this->normalizeClassNumber('S', $validated['result_s'] ?? null);
            $validated['result_sb'] = $this->normalizeClassNumber('SB', $validated['result_sb'] ?? null);
            $validated['result_sm'] = $this->normalizeClassNumber('SM', $validated['result_sm'] ?? null);

            $classification = $athlete->classifications()->create(
                collect($validated)->except('exceptions')->toArray()
            );
            $this->syncClassificationExceptions($classification, $athlete, $validated['exceptions'] ?? []);
            $this->syncSportClassFromClassification($athlete, $validated);
        });

        return redirect()
            ->route('athletes.show', $athlete)
            ->with('success', 'Klassifikation eingetragen, Sportklasse und Exceptions aktualisiert.');
    }

    /**
     * PUT /athletes/{athlete}/classifications/{classification}
     *
     * @used-by web.php (Route::put athletes.classifications.update)
     *
     * @throws Throwable
     */
    public function updateClassification(
        Request $request,
        Athlete $athlete,
        AthleteClassification $classification
    ): RedirectResponse {
        abort_if($classification->athlete_id !== $athlete->id, 403);

        $validated = $this->validateClassification($request);

        DB::transaction(function () use ($athlete, $classification, $validated) {
            // Klassennummern normalisieren bevor gespeichert wird
            $validated['result_s'] = $this->normalizeClassNumber('S', $validated['result_s'] ?? null);
            $validated['result_sb'] = $this->normalizeClassNumber('SB', $validated['result_sb'] ?? null);
            $validated['result_sm'] = $this->normalizeClassNumber('SM', $validated['result_sm'] ?? null);

            $classification->update(
                collect($validated)->except('exceptions')->toArray()
            );
            $this->syncClassificationExceptions($classification, $athlete, $validated['exceptions'] ?? []);
            $this->syncSportClassFromClassification($athlete, $validated);
        });

        return redirect()
            ->route('athletes.show', $athlete)
            ->with('success', 'Klassifikation aktualisiert, Sportklasse und Exceptions geprüft.');
    }

    /**
     * DELETE /athletes/{athlete}/classifications/{classification}
     *
     * @used-by web.php (Route::delete athletes.classifications.destroy)
     */
    public function destroyClassification(Athlete $athlete, AthleteClassification $classification): RedirectResponse
    {
        abort_if($classification->athlete_id !== $athlete->id, 403);

        $classification->delete();

        return redirect()
            ->route('athletes.show', $athlete)
            ->with('success', 'Klassifikation gelöscht.');
    }

    /**
     * POST /athletes/{athlete}/levels
     *
     * Expliziter Level-Eintrag (z.B. wenn der Level rückwirkend eingetragen wird).
     * Aktualisiert auch das Convenience-Feld athletes.level.
     *
     * @used-by web.php (Route::post athletes.levels.store)
     *
     * @throws Throwable
     */
    public function storeLevel(Request $request, Athlete $athlete): RedirectResponse
    {
        $validated = $request->validate([
            'level' => 'required|string|max:50',
            'changed_at' => 'required|date',
            'notes' => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($athlete, $validated) {
            AthleteLevelHistory::create([
                'athlete_id' => $athlete->id,
                'user_id' => auth()->id(),
                'level' => $validated['level'],
                'previous_level' => $athlete->level,
                'changed_at' => $validated['changed_at'],
                'notes' => $validated['notes'] ?? null,
            ]);

            $athlete->update(['level' => $validated['level']]);
        });

        return redirect()
            ->route('athletes.show', $athlete)
            ->with('success', 'Level aktualisiert.');
    }

    private function validateAthlete(Request $request): array
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'name_prefix' => 'nullable|string|max:50',
            'birth_date' => 'nullable|date',
            'gender' => 'required|in:M,F,N',
            'nation_id' => 'required|exists:nations,id',
            'club_id' => 'nullable|exists:clubs,id',
            'license' => 'nullable|string|max:50',
            'license_ipc' => 'nullable|string|max:50',
            'status' => 'nullable|in:EXHIBITION,FOREIGNER,ROOKIE',
            'disability_type' => 'nullable|string|max:30',
            // Neu:
            'is_active' => 'boolean',
            'notes' => 'nullable|string|max:5000',
            'email' => 'nullable|email|max:200',
            'phone' => 'nullable|string|max:50',
            'address_street' => 'nullable|string|max:200',
            'address_city' => 'nullable|string|max:100',
            'address_zip' => 'nullable|string|max:20',
            'address_country' => 'nullable|string|size:3',
            'level' => 'nullable|string|max:50',

            // Sport-Klassen
            'sport_classes' => 'nullable|array',
            'sport_classes.*.category' => 'nullable|in:S,SB,SM',
            'sport_classes.*.class_number' => 'nullable|string|max:10',
            'sport_classes.*.classification_scope' => 'nullable|in:INTL,NAT',
            'sport_classes.*.classification_status' => 'nullable|in:NEW,CONFIRMED,REVIEW,FRD,NE',
            'sport_classes.*.frd_year' => 'nullable|integer|min:2000|max:2100',

            // Exceptions
            'exceptions' => 'nullable|array',
            'exceptions.*.code_id' => 'nullable|exists:exception_codes,id',
            'exceptions.*.category' => 'nullable|in:S,SB,SM',
            'exceptions.*.note' => 'nullable|string|max:255',
        ]);

        $sportClasses = collect($validated['sport_classes'] ?? [])
            ->filter(fn ($c) => ! empty($c['class_number']))
            ->values()
            ->all();

        $exceptions = collect($validated['exceptions'] ?? [])
            ->filter(fn ($e) => ! empty($e['code_id']))
            ->values()
            ->all();

        return [
            'athlete' => collect($validated)->except(['sport_classes', 'exceptions'])->toArray(),
            'sport_classes' => $sportClasses,
            'exceptions' => $exceptions,
        ];
    }

    private function syncSportClasses(Athlete $athlete, array $sportClasses): void
    {
        $athlete->sportClasses()->delete();

        foreach ($sportClasses as $classData) {
            $status = $classData['classification_status'] ?? null;
            $athlete->sportClasses()->create([
                'category' => $classData['category'],
                'class_number' => $classData['class_number'],
                'sport_class' => $classData['category'].$classData['class_number'],
                'classification_scope' => $classData['classification_scope'] ?? 'INTL',
                'classification_status' => $status,
                'frd_year' => $status === 'FRD' ? ($classData['frd_year'] ?? null) : null,
            ]);
        }
    }

    private function syncExceptions(Athlete $athlete, array $exceptions): void
    {
        $syncData = [];
        foreach ($exceptions as $exception) {
            $syncData[$exception['code_id']] = [
                'category' => $exception['category'] ?? null,
                'note' => $exception['note'] ?? null,
            ];
        }
        $athlete->exceptions()->sync($syncData);
    }

    /**
     * Validierungsregeln für Klassifikations-Formulare (store + update).
     */
    private function validateClassification(Request $request): array
    {
        return $request->validate([
            'classified_at' => 'required|date',
            'location' => 'nullable|string|max:200',
            'med_classifier_id' => 'nullable|exists:classifiers,id',
            'tech1_classifier_id' => 'nullable|exists:classifiers,id',
            'tech2_classifier_id' => 'nullable|exists:classifiers,id',
            'result_s' => 'nullable|string|max:15',
            'result_sb' => 'nullable|string|max:15',
            'result_sm' => 'nullable|string|max:15',
            'classification_scope' => 'required|in:INTL,NAT',
            'classification_status' => 'nullable|in:NEW,CONFIRMED,REVIEW,FRD,NE',
            'frd_year' => 'nullable|integer|min:2000|max:2100|required_if:classification_status,FRD',
            'notes' => 'nullable|string|max:1000',
            // Exceptions
            'exceptions' => 'nullable|array',
            'exceptions.*.code_id' => 'nullable|exists:exception_codes,id',
            'exceptions.*.category' => 'nullable|in:S,SB,SM',
            'exceptions.*.note' => 'nullable|string|max:255',
        ]);
    }

    /**
     * Normalisiert eine Klassennummer zum vollständigen Sport-Klassen-String.
     * Akzeptiert sowohl "4" als auch "S4" / "SB3" / "SM14".
     * Gibt null zurück, wenn leer.
     */
    private function normalizeClassNumber(string $category, ?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $value = trim($value);

        // Bereits vollständiges Format: "S4", "SB3", "SM14"
        if (preg_match('/^(SB|SM|S)\d+$/', $value)) {
            return $value;
        }

        // Nur Nummer: "4" → "S4", "3" → "SB3"
        if (preg_match('/^\d+$/', $value)) {
            return $category.$value;
        }

        return null; // ungültiges Format → ignorieren
    }

    /**
     * Sync Exceptions einer Klassifikation und übernimmt sie in die Stammdaten (athlete_exceptions).
     *
     * Ablauf:
     *   1. Klassifikations-Exceptions ersetzen (athlete_classification_exceptions)
     *   2. Athleten-Exceptions in Stammdaten übernehmen (athlete_exceptions)
     *      → bestehende Stammdaten-Exceptions werden vollständig ersetzt
     *
     * Wichtig: Da der Unique-Constraint auf (id, exception_code_id, category) liegt,
     * kann derselbe Code mit verschiedenen Kategorien mehrfach vorkommen.
     * Daher kein sync(), sondern detach + attach.
     */
    private function syncClassificationExceptions(
        AthleteClassification $classification,
        Athlete $athlete,
        array $exceptions
    ): void {
        // Exceptions aus dem Formular aufbereiten — leere code_id herausfiltern
        $rows = collect($exceptions)
            ->filter(fn ($e) => ! empty($e['code_id']))
            ->map(fn ($e) => [
                'code_id' => (int) $e['code_id'],
                'category' => $e['category'] ?? null,
                'note' => $e['note'] ?? null,
            ])
            ->values();

        // 1. Klassifikations-Exceptions: alle löschen, neu anlegen
        $classification->exceptions()->detach();
        foreach ($rows as $row) {
            $classification->exceptions()->attach($row['code_id'], [
                'category' => $row['category'],
                'note' => $row['note'],
            ]);
        }

        // 2. Stammdaten-Exceptions übernehmen: alle löschen, neu anlegen
        // athlete_exceptions hat denselben Unique-Constraint (athlete_id, code_id, category)
        $athlete->exceptions()->detach();
        foreach ($rows as $row) {
            $athlete->exceptions()->attach($row['code_id'], [
                'category' => $row['category'],
                'note' => $row['note'],
            ]);
        }
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

    /**
     * Synchronisiert AthleteSportClass aus einem Klassifikations-Ergebnis.
     *
     * Verarbeitet alle drei Kategorien (S, SB, SM) separat.
     * Regel: Nur updaten, wenn bestehende Sportklasse NICHT CONFIRMED + INTL ist.
     */
    private function syncSportClassFromClassification(Athlete $athlete, array $validated): void
    {
        $scope = $validated['classification_scope'] ?? 'INTL';
        $status = $validated['classification_status'] ?? null;
        $frdYear = $status === 'FRD' ? ($validated['frd_year'] ?? null) : null;

        // result_s/sb/sm können entweder nur die Nummer ("4") oder den vollen String ("S4") enthalten.
        // Der Controller normalisiert beides zum vollständigen Format.
        $categoryMap = [
            'S' => $this->normalizeClassNumber('S', $validated['result_s'] ?? null),
            'SB' => $this->normalizeClassNumber('SB', $validated['result_sb'] ?? null),
            'SM' => $this->normalizeClassNumber('SM', $validated['result_sm'] ?? null),
        ];

        foreach ($categoryMap as $category => $sportClassResult) {
            if ($sportClassResult === null) {
                continue;
            }

            $classNumber = ltrim($sportClassResult, 'A..Za..z');

            $existing = $athlete->sportClasses()->where('category', $category)->first();

            if ($existing
                && $existing->classification_status === 'CONFIRMED'
                && $existing->classification_scope === 'INTL') {
                continue;
            }

            $athlete->sportClasses()->updateOrCreate(
                ['category' => $category],
                [
                    'class_number' => $classNumber,
                    'sport_class' => $sportClassResult,
                    'classification_scope' => $scope,
                    'classification_status' => $status,
                    'frd_year' => $frdYear,
                ]
            );
        }
    }
}
