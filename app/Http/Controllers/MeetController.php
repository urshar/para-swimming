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
        $nations = Nation::active()->orderBy('code')->get();
        $cups = Cup::orderByDesc('year')->get();
        $qualifyingTimeLists = QualifyingTimeList::orderByDesc('year')->get();

        return view('meets.form', compact('nations', 'cups', 'qualifyingTimeLists') + $this->pointSystemData());
    }

    public function store(Request $request): RedirectResponse
    {
        $meet = Meet::create($this->prepareMeetData($request));

        $this->syncPointSystems($request, $meet);

        return redirect()
            ->route('meets.show', $meet)
            ->with('success', 'Wettkampf erfolgreich erstellt.');
    }

    public function show(Meet $meet): View
    {
        $meet->load(['nation', 'cup', 'clubs.nation', 'pointSystems']);
        $meet->loadCount(['swimEvents', 'entries', 'relayEntries', 'results', 'documents']);

        $swimEvents = $meet->swimEvents()
            ->with('strokeType')
            ->orderBy('session_number')
            ->orderBy('event_number')
            ->get();

        $participantsCount = $meet->participantsCount();
        $participatingClubsCount = $meet->participatingClubsCount();

        return view('meets.show', compact('meet', 'swimEvents', 'participantsCount', 'participatingClubsCount'));
    }

    public function edit(Meet $meet): View
    {
        $nations = Nation::active()->orderBy('code')->get();
        $cups = Cup::orderByDesc('year')->get();
        $qualifyingTimeLists = QualifyingTimeList::orderByDesc('year')->get();

        return view('meets.form',
            compact('meet', 'nations', 'cups', 'qualifyingTimeLists') + $this->pointSystemData($meet));
    }

    public function update(Request $request, Meet $meet): RedirectResponse
    {
        $meet->update($this->prepareMeetData($request));

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

    /**
     * Validierte Formulardaten plus die Felder, die validateMeet() bewusst nicht selbst
     * abdeckt: Checkboxen (werden bei "aus" gar nicht erst übertragen) und das Admin-Gate für
     * is_published/livetiming_url/cup_id/qualifying_time_list_id. Von store() und update()
     * gleichermaßen genutzt — vorher war dieser Block dort wortgleich dupliziert.
     */
    private function prepareMeetData(Request $request): array
    {
        $data = $this->validateMeet($request);
        $data['is_open'] = $request->boolean('is_open');
        // Nicht angehakte Checkboxen werden gar nicht übertragen; ohne diese Zeile ließe
        // sich die WPS-Anerkennung nie wieder zurücknehmen.
        $data['wps_approved'] = $request->boolean('wps_approved');

        if (auth()->user()?->is_admin) {
            // Steuert die tatsächliche öffentliche Sichtbarkeit (Spec public-frontend §4.2) —
            // das Feld ist im Formular bewusst nur für Admins sichtbar (siehe meets/form.blade.php);
            // ohne diese Bedingung ließe sich is_published auch über einen rohen Request setzen.
            $data['is_published'] = $request->boolean('is_published');
        } else {
            unset($data['cup_id'], $data['qualifying_time_list_id'], $data['livetiming_url']);
        }

        return $data;
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
            'entries_deadline' => 'nullable|date',
            'organizer' => 'nullable|string|max:255',
            'altitude' => 'nullable|integer|min:0|max:9000',
            'timing' => 'nullable|in:AUTOMATIC,SEMIAUTOMATIC,MANUAL3,MANUAL2,MANUAL1',
            'entry_type' => 'nullable|in:OPEN,INVITATION',
            'is_open' => 'boolean',
            'wps_approved' => 'boolean',
            'wps_approved_note' => 'nullable|string|max:255',
            'livetiming_url' => 'nullable|url|max:255',
            // is_published bewusst nicht hier: Es wird ausschließlich über den admin-gated
            // Zweig in store()/update() gesetzt (siehe dort) — stünde es hier, würde validate()
            // es schon vor der Admin-Prüfung in $data legen und die Prüfung wäre wirkungslos.
            'cup_id' => 'nullable|exists:cups,id',
            'qualifying_time_list_id' => 'nullable|exists:qualifying_time_lists,id',
        ]);
    }
}
