<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Cup;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\PointSystem;
use App\Models\QualifyingTimeList;
use App\Models\Result;
use App\Models\WpsPointVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MeetController extends Controller
{
    public function index(Request $request): View
    {
        $query = Meet::with('nation')->latest('start_date');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('city', 'like', '%'.$search.'%');
            });
        }

        if ($course = $request->query('course')) {
            $query->where('course', $course);
        }

        if ($year = $request->query('year')) {
            $query->whereYear('start_date', $year);
        }

        $meets = $query->paginate(20)->withQueryString();
        $meetCount = Meet::count();
        $athleteCount = Athlete::count();
        $clubCount = Club::count();
        $resultCount = Result::count();

        return view('meets.index', compact(
            'meets', 'meetCount', 'athleteCount', 'clubCount', 'resultCount'
        ));
    }

    public function create(): View
    {
        $nations = Nation::active()->orderBy('name_de')->get();
        $cups = Cup::orderByDesc('year')->get();
        $qualifyingTimeLists = QualifyingTimeList::orderByDesc('year')->get();

        return view('meets.form', compact('nations', 'cups', 'qualifyingTimeLists') + $this->pointSystemData());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateMeet($request);
        $data['is_open'] = $request->boolean('is_open');

        if (! auth()->user()?->is_admin) {
            unset($data['cup_id'], $data['qualifying_time_list_id']);
        }

        $meet = Meet::create($data);

        $this->syncPointSystems($request, $meet);

        return redirect()
            ->route('meets.show', $meet)
            ->with('success', 'Wettkampf erfolgreich erstellt.');
    }

    public function show(Meet $meet): View
    {
        $meet->load(['nation', 'cup', 'clubs.nation', 'pointSystems']);
        $meet->loadCount(['swimEvents', 'entries', 'results']);

        $swimEvents = $meet->swimEvents()
            ->with('strokeType')
            ->orderBy('session_number')
            ->orderBy('event_number')
            ->get();

        return view('meets.show', compact('meet', 'swimEvents'));
    }

    public function edit(Meet $meet): View
    {
        $nations = Nation::active()->orderBy('name_de')->get();
        $cups = Cup::orderByDesc('year')->get();
        $qualifyingTimeLists = QualifyingTimeList::orderByDesc('year')->get();

        return view('meets.form', compact('meet', 'nations', 'cups', 'qualifyingTimeLists') + $this->pointSystemData($meet));
    }

    public function update(Request $request, Meet $meet): RedirectResponse
    {
        $data = $this->validateMeet($request);
        $data['is_open'] = $request->boolean('is_open');

        if (! auth()->user()?->is_admin) {
            unset($data['cup_id'], $data['qualifying_time_list_id']);
        }

        $meet->update($data);

        $this->syncPointSystems($request, $meet);

        return redirect()
            ->route('meets.show', $meet)
            ->with('success', 'Wettkampf aktualisiert.');
    }

    public function destroy(Meet $meet): RedirectResponse
    {
        $meet->delete();

        return redirect()
            ->route('meets.index')
            ->with('success', 'Wettkampf gelöscht.');
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

    /**
     * Auswahldaten für den Abschnitt "Punkteberechnung" im Wettkampf-Formular.
     *
     * @return array<string, mixed>
     */
    private function pointSystemData(?Meet $meet = null): array
    {
        return [
            'pointSystems' => PointSystem::active()->orderBy('name')->get(),
            'selectedPointSystems' => $meet?->pointSystems->pluck('id')->all() ?? [],
            // Bewusst auf int normalisiert: value() liefert je nach Treiber eine
            // Zeichenkette, und die View vergleicht strikt gegen die Model-IDs.
            'wpsSystemId' => $this->wpsSystemId(),
            'wpsVersions' => WpsPointVersion::active()->orderByDesc('year')->get(),
            'selectedWpsVersionId' => $this->currentWpsVersionId($meet),
        ];
    }

    private function wpsSystemId(): ?int
    {
        $id = PointSystem::where('code', PointSystem::CODE_WPS)->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function currentWpsVersionId(?Meet $meet): ?int
    {
        $system = $meet?->pointSystems->firstWhere('code', PointSystem::CODE_WPS);

        return $system?->getRelation('pivot')->getAttribute('wps_point_version_id');
    }

    /**
     * Schreibt die Zuordnung Wettkampf ↔ Punktesystem.
     *
     * Nur Administratoren dürfen sie ändern — die Auswahl entscheidet mit, welche Punkte
     * offiziell ausgewiesen werden. Für alle anderen bleibt die bestehende Zuordnung
     * unverändert; ein fehlendes Feld im Request darf sie nicht stillschweigend leeren.
     */
    private function syncPointSystems(Request $request, Meet $meet): void
    {
        if (! auth()->user()?->is_admin) {
            return;
        }

        $validated = $request->validate([
            'point_systems' => 'nullable|array',
            'point_systems.*' => 'integer|exists:point_systems,id',
            'wps_point_version_id' => 'nullable|integer|exists:wps_point_versions,id',
        ]);

        $wpsId = $this->wpsSystemId();
        $versionId = $validated['wps_point_version_id'] ?? null;

        $sync = [];

        foreach ($validated['point_systems'] ?? [] as $systemId) {
            $sync[(int) $systemId] = [
                // Die Versionsübersteuerung gilt ausschließlich für WPS.
                'wps_point_version_id' => (int) $systemId === $wpsId ? $versionId : null,
            ];
        }

        $meet->pointSystems()->sync($sync);
    }

    private function validateMeet(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'city' => 'required|string|max:100',
            'nation_id' => 'required|exists:nations,id',
            'course' => 'required|in:LCM,SCM,SCY,SCM16,SCM20,SCM33,SCY20,SCY27,SCY33,SCY36,OPEN',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'organizer' => 'nullable|string|max:255',
            'altitude' => 'nullable|integer|min:0|max:9000',
            'timing' => 'nullable|in:AUTOMATIC,SEMIAUTOMATIC,MANUAL3,MANUAL2,MANUAL1',
            'entry_type' => 'nullable|in:OPEN,INVITATION',
            'is_open' => 'boolean',
            'cup_id' => 'nullable|exists:cups,id',
            'qualifying_time_list_id' => 'nullable|exists:qualifying_time_lists,id',
        ]);
    }
}
