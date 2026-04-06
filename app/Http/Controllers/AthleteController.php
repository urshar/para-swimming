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

        return view('athletes.show', compact(
            'athlete', 'results',
            'clubs', 'medClassifiers', 'techClassifiers', 'users'
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
        $validated = $request->validate([
            'classified_at' => 'required|date',
            'location' => 'nullable|string|max:200',
            'med_classifier_id' => 'nullable|exists:classifiers,id',
            'tech1_classifier_id' => 'nullable|exists:classifiers,id',
            'tech2_classifier_id' => 'nullable|exists:classifiers,id',
            'sport_class_result' => 'nullable|string|max:10',
            'status' => 'nullable|in:CONFIRMED,NEW,REVIEW,OBSERVATION',
            'notes' => 'nullable|string|max:1000',
        ]);

        DB::transaction(function () use ($athlete, $validated) {
            $athlete->classifications()->create($validated);
            $this->syncSportClassFromClassification($athlete, $validated);
        });

        return redirect()
            ->route('athletes.show', $athlete)
            ->with('success', 'Klassifikation eingetragen und Sportklasse aktualisiert.');
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

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

        $validated = $request->validate([
            'classified_at' => 'required|date',
            'location' => 'nullable|string|max:200',
            'med_classifier_id' => 'nullable|exists:classifiers,id',
            'tech1_classifier_id' => 'nullable|exists:classifiers,id',
            'tech2_classifier_id' => 'nullable|exists:classifiers,id',
            'sport_class_result' => 'nullable|string|max:10',
            'status' => 'nullable|in:CONFIRMED,NEW,REVIEW,OBSERVATION',
            'notes' => 'nullable|string|max:1000',
        ]);

        DB::transaction(function () use ($athlete, $classification, $validated) {
            $classification->update($validated);
            $this->syncSportClassFromClassification($athlete, $validated);
        });

        return redirect()
            ->route('athletes.show', $athlete)
            ->with('success', 'Klassifikation aktualisiert und Sportklasse geprüft.');
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
            'sport_classes.*.status' => 'nullable|in:NATIONAL,NEW,REVIEW,OBSERVATION,CONFIRMED',

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
            $athlete->sportClasses()->create([
                'category' => $classData['category'],
                'class_number' => $classData['class_number'],
                'sport_class' => $classData['category'].$classData['class_number'],
                'status' => $classData['status'] ?? null,
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
     * Synchronisiert AthleteSportClass aus einem Klassifikationsergebnis.
     *
     * Regel: Nur updaten, wenn die bestehende Sportklasse NICHT den Status CONFIRMED hat.
     * Format: "S4" → category=S/4 | "SB3" → SB/3 | "SM14" → SM/14
     */
    private function syncSportClassFromClassification(Athlete $athlete, array $validated): void
    {
        if (empty($validated['sport_class_result'])) {
            return;
        }

        if (! preg_match('/^(SB|SM|S)(\d+)$/', $validated['sport_class_result'], $m)) {
            return;
        }

        $category = $m[1];
        $classNumber = $m[2];

        // Bestehende Sportklasse laden (fresh, nicht aus Cache)
        $existing = $athlete->sportClasses()->where('category', $category)->first();

        // Nicht überschreiben wenn bereits CONFIRMED
        if ($existing && $existing->status === 'CONFIRMED') {
            return;
        }

        $athlete->sportClasses()->updateOrCreate(
            ['category' => $category],
            [
                'class_number' => $classNumber,
                'sport_class' => $validated['sport_class_result'],
                'status' => $validated['status'] ?? null,
            ]
        );
    }
}
