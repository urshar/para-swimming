<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\Club;
use App\Models\ExceptionCode;
use App\Models\Nation;
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

        $athletes = $query->paginate(25)->withQueryString();
        $nations = Nation::active()->orderBy('name_de')->get();
        $clubs = Club::orderBy('name')->get();

        return view('athletes.index', compact('athletes', 'nations', 'clubs'));
    }

    public function create(): View
    {
        $nations = Nation::active()->orderBy('name_de')->get();
        $clubs = Club::with('nation')->orderBy('name')->get();
        $exceptionCodes = ExceptionCode::active()->orderBy('code')->get();

        return view('athletes.form', compact('nations', 'clubs', 'exceptionCodes'));
    }

    /**
     * @throws Throwable
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateAthlete($request);

        DB::transaction(function () use ($data) {
            $athlete = Athlete::create($data['athlete']);
            $this->syncSportClasses($athlete, $data['sport_classes']);
            $this->syncExceptions($athlete, $data['exceptions']);
        });

        return redirect()
            ->route('athletes.index')
            ->with('success', 'Athlet erfolgreich angelegt.');
    }

    public function show(Athlete $athlete): View
    {
        $athlete->load([
            'club.nation',
            'nation',
            'sportClasses',
            'exceptions',
        ]);

        $results = $athlete->results()
            ->with(['meet', 'swimEvent.strokeType'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('athletes.show', compact('athlete', 'results'));
    }

    public function edit(Athlete $athlete): View
    {
        $athlete->load(['sportClasses', 'exceptions']);

        $nations = Nation::active()->orderBy('name_de')->get();
        $clubs = Club::with('nation')->orderBy('name')->get();
        $exceptionCodes = ExceptionCode::active()->orderBy('code')->get();

        return view('athletes.form', compact('athlete', 'nations', 'clubs', 'exceptionCodes'));
    }

    /**
     * @throws Throwable
     */
    public function update(Request $request, Athlete $athlete): RedirectResponse
    {
        $data = $this->validateAthlete($request);

        DB::transaction(function () use ($athlete, $data) {
            $athlete->update($data['athlete']);
            $this->syncSportClasses($athlete, $data['sport_classes']);
            $this->syncExceptions($athlete, $data['exceptions']);
        });

        return redirect()
            ->route('athletes.show', $athlete)
            ->with('success', 'Athlet aktualisiert.');
    }

    public function destroy(Athlete $athlete): RedirectResponse
    {
        $athlete->delete();

        return redirect()
            ->route('athletes.index')
            ->with('success', 'Athlet gelöscht.');
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

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

            // Sport-Klassen: 3 fixe Zeilen, class_number kann leer sein
            // → nullable statt required_with, Filterung in syncSportClasses()
            'sport_classes' => 'nullable|array',
            'sport_classes.*.category' => 'nullable|in:S,SB,SM',
            'sport_classes.*.class_number' => 'nullable|string|max:10',
            'sport_classes.*.status' => 'nullable|in:NATIONAL,NEW,REVIEW,OBSERVATION,CONFIRMED',

            // Exceptions: Checkboxen — nur angehakte werden submitted,
            // aber das category-Select sendet auch nicht-angehakte Zeilen mit.
            // code_id nullable — Filterung in syncExceptions()
            'exceptions' => 'nullable|array',
            'exceptions.*.code_id' => 'nullable|exists:exception_codes,id',
            'exceptions.*.category' => 'nullable|in:S,SB,SM',
            'exceptions.*.note' => 'nullable|string|max:255',
        ]);

        // Sport-Klassen ohne class_number herausfiltern
        $sportClasses = collect($validated['sport_classes'] ?? [])
            ->filter(fn ($c) => ! empty($c['class_number']))
            ->values()
            ->all();

        // Exceptions ohne code_id herausfiltern (nicht angehakte Checkboxen)
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
}
