<?php

namespace App\Http\Controllers;

use App\Models\Qualification;
use App\Models\QualifyingTargetPoint;
use App\Models\QualifyingTime;
use App\Models\QualifyingTimeList;
use App\Models\SportClassGroup;
use App\Models\SportClassGroupMember;
use App\Models\StrokeType;
use App\Services\PdfExportService;
use App\Services\QualificationDeterminationService;
use App\Services\QualifyingTimeCalculationService;
use App\Services\QualifyingTimeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * QualifyingTimeListController
 *
 * CRUD für die Richtzeitenlisten "ÖSTM & ÖM" (Phase 1 der Spec). Anzeige ist
 * für alle authentifizierten User offen (Leserechte laut Spec-Abschnitt
 * "Berechtigungen"); Anlegen/Bearbeiten/Löschen sowie das Pflegen von
 * Zielpunkten und Richtzeiten-Zeilen ist admin-only.
 */
class QualifyingTimeListController extends Controller
{
    public function __construct(
        private readonly QualifyingTimeService $qualifyingTimeService,
        private readonly QualifyingTimeCalculationService $qualifyingTimeCalculationService,
        private readonly QualificationDeterminationService $qualificationDeterminationService,
        private readonly PdfExportService $pdfExportService,
    ) {}

    // ── Richtzeitenliste ──────────────────────────────────────────────────────

    public function index(): View
    {
        $lists = QualifyingTimeList::withCount(['targetPoints', 'times'])
            ->orderByDesc('year')
            ->get();

        return view('qualifying-time-lists.index', compact('lists'));
    }

    public function create(): View
    {
        $this->authorizeAdmin();

        return view('qualifying-time-lists.form', ['list' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdmin();

        $validated = $this->validateList($request);

        $list = $this->qualifyingTimeService->createList($validated);

        return redirect()
            ->route('qualifying-time-lists.edit', $list)
            ->with('success', "Richtzeitenliste $list->year angelegt. Jetzt Zielpunkte und Richtzeiten pflegen.");
    }

    /** Read-only Ansicht — für alle authentifizierten User. */
    public function show(QualifyingTimeList $qualifyingTimeList): View
    {
        $qualifyingTimeList->load(['targetPoints', 'times.strokeType']);

        return view('qualifying-time-lists.show', ['list' => $qualifyingTimeList]);
    }

    /**
     * GET /qualifying-time-lists/{qualifyingTimeList}/qualifications
     *
     * Anzeige der ermittelten Qualifikationen mit Filtern (Phase 6 der Spec).
     * Lesezugriff für alle authentifizierten User. Zeigt ausschließlich die
     * zum Berechnungszeitpunkt eingefrorenen Snapshot-Werte (sport_class,
     * club_id, points, swim_time) — keine Live-Nachladung aus Result/Athlete.
     */
    public function qualifications(Request $request, QualifyingTimeList $qualifyingTimeList): View
    {
        return view('qualifying-time-lists.qualifications', [
            'list' => $qualifyingTimeList,
            ...$this->filteredQualifications($request, $qualifyingTimeList),
        ]);
    }

    /**
     * GET /qualifying-time-lists/{qualifyingTimeList}/pdf
     *
     * PDF der Richtzeitenliste (Phase 7 der Spec). Lesezugriff für alle
     * authentifizierten User (wie show()) — auch für historisierte Listen.
     */
    public function pdfTimes(QualifyingTimeList $qualifyingTimeList): Response
    {
        $qualifyingTimeList->load(['targetPoints', 'times.strokeType']);

        return $this->pdfExportService->stream('pdf.qualifying-times', [
            'list' => $qualifyingTimeList,
        ], "richtzeiten-$qualifyingTimeList->year.pdf");
    }

    /**
     * GET /qualifying-time-lists/{qualifyingTimeList}/qualifications/pdf
     *
     * PDF der (ggf. gefilterten) Qualifikationsliste (Phase 7 der Spec).
     * Übernimmt dieselben Filter-Query-Parameter wie die normale Anzeige.
     */
    public function pdfQualifications(Request $request, QualifyingTimeList $qualifyingTimeList): Response
    {
        return $this->pdfExportService->stream('pdf.qualifications', [
            'list' => $qualifyingTimeList,
            'filters' => $request->only(['stroke_type_id', 'distance', 'gender', 'sport_class', 'club_id', 'search']),
            ...$this->filteredQualifications($request, $qualifyingTimeList),
        ], "qualifikation-$qualifyingTimeList->year.pdf");
    }

    public function edit(QualifyingTimeList $qualifyingTimeList): View
    {
        $this->authorizeEditableList($qualifyingTimeList);

        $qualifyingTimeList->load(['targetPoints', 'times.strokeType']);

        $strokeTypes = StrokeType::active()->orderBy('name_de')->get();

        return view('qualifying-time-lists.form', [
            'list' => $qualifyingTimeList,
            'strokeTypes' => $strokeTypes,
        ]);
    }

    public function update(Request $request, QualifyingTimeList $qualifyingTimeList): RedirectResponse
    {
        $this->authorizeEditableList($qualifyingTimeList);

        $validated = $this->validateList($request, $qualifyingTimeList->id);

        $this->qualifyingTimeService->updateList($qualifyingTimeList, $validated);

        return redirect()
            ->route('qualifying-time-lists.edit', $qualifyingTimeList)
            ->with('success', "Richtzeitenliste $qualifyingTimeList->year aktualisiert.");
    }

    public function destroy(QualifyingTimeList $qualifyingTimeList): RedirectResponse
    {
        $this->authorizeEditableList($qualifyingTimeList);

        $year = $qualifyingTimeList->year;
        $this->qualifyingTimeService->deleteList($qualifyingTimeList);

        return redirect()
            ->route('qualifying-time-lists.index')
            ->with('success', "Richtzeitenliste $year gelöscht.");
    }

    /**
     * POST /qualifying-time-lists/{qualifyingTimeList}/calculate
     *
     * Löst die automatische Berechnung der Richtzeiten aus den bestehenden
     * Basiswerten aus (Phase 2 der Spec). Manuell gesetzte Zeiten werden nur
     * mit explizit gesetzter Checkbox "overwrite_manual" überschrieben.
     */
    public function calculate(Request $request, QualifyingTimeList $qualifyingTimeList): RedirectResponse
    {
        $this->authorizeEditableList($qualifyingTimeList);

        $overwriteManual = $request->boolean('overwrite_manual');

        $result = $this->qualifyingTimeCalculationService->calculateForList($qualifyingTimeList, $overwriteManual);

        if (isset($result['error'])) {
            return redirect()
                ->route('qualifying-time-lists.edit', $qualifyingTimeList)
                ->with('error', $result['error']);
        }

        $message = "{$result['calculated']} Richtzeiten berechnet, {$result['skipped']} übersprungen (kein Basiswert)";
        if ($result['skipped_manual_protected'] > 0) {
            $message .= ", {$result['skipped_manual_protected']} manuell gesetzte Zeiten unverändert gelassen";
        }
        $message .= ". (Basiswert-Version: {$result['version']}, Meet: {$result['reference_meet']})";

        return redirect()
            ->route('qualifying-time-lists.edit', $qualifyingTimeList)
            ->with('success', $message);
    }

    /**
     * POST /qualifying-time-lists/{qualifyingTimeList}/qualifications/calculate
     *
     * Löst die automatische Qualifikationsermittlung aus (Phase 4 der Spec).
     * Der Zeitraum wird direkt an der Liste gepflegt (siehe
     * qualification_period_start/-end), nicht aus einem Ziel-Meet abgeleitet
     * — das Ziel-Meet des Folgejahres existiert zum Zeitpunkt der Ermittlung
     * oft noch nicht.
     *
     * @throws Throwable bei einem unerwarteten Fehler innerhalb der Berechnung
     */
    public function calculateQualifications(QualifyingTimeList $qualifyingTimeList): RedirectResponse
    {
        $this->authorizeEditableList($qualifyingTimeList);

        $result = $this->qualificationDeterminationService->calculateForList($qualifyingTimeList);

        if (isset($result['error'])) {
            return redirect()
                ->route('qualifying-time-lists.edit', $qualifyingTimeList)
                ->with('error', $result['error']);
        }

        $message = "{$result['qualified']} Qualifikationen ermittelt (von {$result['candidates_checked']} geprüften Ergebnissen im Zeitraum {$result['period_start']} bis {$result['period_end']}).";
        if ($result['period_end_is_provisional']) {
            $message .= ' Achtung: Zeitraum-Ende ist noch nicht gesetzt, es wurde vorläufig bis heute gerechnet — sobald der ÖSTM & ÖM-Termin feststeht, Ende eintragen und neu berechnen.';
        }

        return redirect()
            ->route('qualifying-time-lists.edit', $qualifyingTimeList)
            ->with('success', $message);
    }

    // ── Zielpunkte ────────────────────────────────────────────────────────────

    public function storeTargetPoint(Request $request, QualifyingTimeList $qualifyingTimeList): RedirectResponse
    {
        $this->authorizeEditableList($qualifyingTimeList);

        $validated = $request->validate([
            'sport_class' => 'required|string|max:15',
            'points' => 'required|integer|min:0|max:2000',
        ]);

        $this->qualifyingTimeService->upsertTargetPoint(
            $qualifyingTimeList,
            $validated['sport_class'],
            $validated['points']
        );

        return redirect()
            ->route('qualifying-time-lists.edit', $qualifyingTimeList)
            ->with('success', "Zielpunkte für \"{$validated['sport_class']}\" gespeichert.");
    }

    public function destroyTargetPoint(
        QualifyingTimeList $qualifyingTimeList,
        QualifyingTargetPoint $targetPoint
    ): RedirectResponse {
        $this->authorizeEditableList($qualifyingTimeList);

        abort_unless($targetPoint->qualifying_time_list_id === $qualifyingTimeList->id, 404);

        $sportClass = $targetPoint->sport_class;
        $this->qualifyingTimeService->deleteTargetPoint($targetPoint);

        return redirect()
            ->route('qualifying-time-lists.edit', $qualifyingTimeList)
            ->with('success', "Zielpunkte-Override für \"$sportClass\" entfernt (Standard 100 gilt wieder).");
    }

    // ── Richtzeiten-Zeilen ────────────────────────────────────────────────────

    public function storeTime(Request $request, QualifyingTimeList $qualifyingTimeList): RedirectResponse
    {
        $this->authorizeEditableList($qualifyingTimeList);

        $validated = $request->validate([
            'stroke_type_id' => 'required|integer|exists:stroke_types,id',
            'distance' => 'required|integer|min:1|max:20000',
            'gender' => 'required|in:M,F',
            'sport_class' => 'required|string|max:15',
            'value' => 'nullable|string|max:20',
        ]);

        $this->qualifyingTimeService->upsertTime(
            $qualifyingTimeList,
            $validated['stroke_type_id'],
            $validated['distance'],
            $validated['gender'],
            $validated['sport_class'],
            $validated['value'] ?? null,
        );

        return redirect()
            ->route('qualifying-time-lists.edit', $qualifyingTimeList)
            ->with('success', 'Richtzeit gespeichert.');
    }

    public function destroyTime(QualifyingTimeList $qualifyingTimeList, QualifyingTime $time): RedirectResponse
    {
        $this->authorizeEditableList($qualifyingTimeList);

        abort_unless($time->qualifying_time_list_id === $qualifyingTimeList->id, 404);

        $this->qualifyingTimeService->deleteTime($time);

        return redirect()
            ->route('qualifying-time-lists.edit', $qualifyingTimeList)
            ->with('success', 'Richtzeit gelöscht.');
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    /**
     * Gemeinsame Filterlogik für die Qualifikations-Anzeige (Phase 6) und den
     * PDF-Export (Phase 7), damit beide exakt dieselben Ergebnisse liefern.
     *
     * @return array{qualifications: Collection, events: Collection, genders: Collection, sportClasses: Collection, clubs: Collection}
     */
    private function filteredQualifications(Request $request, QualifyingTimeList $qualifyingTimeList): array
    {
        $base = Qualification::where('qualifying_time_list_id', $qualifyingTimeList->id)
            ->with(['athlete', 'club', 'qualifyingTime.strokeType']);

        // Filteroptionen aus dem ungefilterten Gesamtbestand ableiten, damit
        // sie beim Filtern nicht verschwinden.
        $all = (clone $base)->get();

        $events = $all->map(fn (Qualification $q) => [
            'stroke_type_id' => $q->qualifyingTime->stroke_type_id,
            'distance' => $q->qualifyingTime->distance,
            'label' => "{$q->qualifyingTime->distance}m {$q->qualifyingTime->strokeType?->name_de}",
        ])->unique(fn ($e) => "{$e['stroke_type_id']}-{$e['distance']}")->sortBy('distance')->values();

        $genders = $all->pluck('qualifyingTime.gender')->unique()->sort()->values();
        $sportClasses = $all->pluck('sport_class')->unique()->sort()->values();
        $clubs = $all->pluck('club')->filter()->unique('id')->sortBy('name')->values();

        $filtered = $base;

        if ($request->filled('stroke_type_id') && $request->filled('distance')) {
            $filtered->whereHas('qualifyingTime', fn ($q) => $q
                ->where('stroke_type_id', $request->integer('stroke_type_id'))
                ->where('distance', $request->integer('distance')));
        }
        if ($request->filled('gender')) {
            $filtered->whereHas('qualifyingTime', fn ($q) => $q->where('gender', $request->string('gender')));
        }
        if ($request->filled('sport_class')) {
            $filtered->where('sport_class', strtoupper($request->string('sport_class')));
        }
        if ($request->filled('club_id')) {
            $filtered->where('club_id', $request->integer('club_id'));
        }
        if ($request->filled('search')) {
            $search = $request->string('search');
            $filtered->where(function ($q) use ($search) {
                $q->whereHas('athlete', fn ($a) => $a
                    ->where('first_name', 'like', "%$search%")
                    ->orWhere('last_name', 'like', "%$search%"))
                    ->orWhereHas('club', fn ($c) => $c->where('name', 'like', "%$search%"));
            });
        }

        $qualifications = $filtered->get()->sortBy(
            fn (Qualification $q) => $q->athlete?->last_name.'|'.$q->athlete?->first_name
        );

        $sections = $this->groupByDisabilityGroupAndStroke($qualifications);

        return compact('qualifications', 'events', 'genders', 'sportClasses', 'clubs', 'sections');
    }

    /**
     * Gliedert Qualifikationen zuerst nach Behinderungsgruppe (PI/VI/II/T21/HI,
     * wiederverwendet aus dem Cup-Modul, siehe SportClassGroup), darin nach
     * Lage — für eine übersichtlichere Anzeige/PDF-Ausgabe.
     *
     * @return Collection<int, array{group: ?SportClassGroup, strokes: Collection}>
     */
    private function groupByDisabilityGroupAndStroke(Collection $qualifications): Collection
    {
        $memberMap = SportClassGroupMember::pluck('sport_class_group_id', 'sport_class');
        $groups = SportClassGroup::active()->orderBy('sort_order')->get();

        $sections = collect();

        foreach ($groups as $group) {
            $items = $qualifications->filter(
                fn (Qualification $q) => ($memberMap->get($q->sport_class)) === $group->id
            )->values();

            if ($items->isNotEmpty()) {
                $sections->push(['group' => $group, 'strokes' => $this->groupByStroke($items)]);
            }
        }

        // Sportklassen ohne zugeordnete Behinderungsgruppe landen gesammelt am Ende.
        $unassigned = $qualifications->filter(
            fn (Qualification $q) => ! $memberMap->has($q->sport_class)
        )->values();

        if ($unassigned->isNotEmpty()) {
            $sections->push(['group' => null, 'strokes' => $this->groupByStroke($unassigned)]);
        }

        return $sections;
    }

    /**
     * Gliedert eine Qualifikations-Teilmenge nach Bewerb (Lage + Distanz, z.B.
     * "50m Freistil", "100m Freistil" jeweils als eigener Abschnitt), in der
     * üblichen Wettkampf-Reihenfolge (Freistil, Rücken, Brust, Schmetterling,
     * Lagen) und aufsteigend nach Distanz (Erik, 2026-07-19: pro
     * Behinderungsgruppe sollen Distanzen innerhalb einer Lage eigene
     * Unterabschnitte bekommen, statt gemeinsam unter der Lage gelistet zu
     * werden).
     *
     * @return Collection<int, array{stroke: ?StrokeType, distance: int, items: Collection}>
     */
    private function groupByStroke(Collection $items): Collection
    {
        $strokeOrder = ['FREE' => 1, 'BACK' => 2, 'BREAST' => 3, 'FLY' => 4, 'MEDLEY' => 5, 'IMRELAY' => 6];

        return collect($items
            ->groupBy(fn (Qualification $q) => "{$q->qualifyingTime->stroke_type_id}|{$q->qualifyingTime->distance}")
            ->map(fn ($group) => [
                'stroke' => $group->first()->qualifyingTime->strokeType,
                'distance' => $group->first()->qualifyingTime->distance,
                'items' => $group
                    ->sortBy(fn (Qualification $q) => $q->athlete?->last_name.'|'.$q->athlete?->first_name)
                    ->values(),
            ])
            // Einzelner kombinierter Sortierschlüssel statt Mehrfachkriterien-Array,
            // da sortBy() mit mehreren Closures im Array sich nicht zuverlässig wie
            // eine echte Mehrfachsortierung verhalten hat (Lage vor Distanz).
            ->sortBy(fn ($s) => sprintf('%02d-%06d', $strokeOrder[$s['stroke']?->lenex_code] ?? 99, $s['distance']))
            ->values()
            ->all());
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->is_admin, 403, 'Nur für Administratoren.');
    }

    /**
     * Admin-Check plus Historisierungsregel (Phase 3): nur die aktuellste
     * Richtzeitenliste (höchstes Jahr) darf bearbeitet, berechnet oder
     * gelöscht werden. Ältere Jahre bleiben dauerhaft abrufbar, aber
     * schreibgeschützt.
     */
    private function authorizeEditableList(QualifyingTimeList $list): void
    {
        $this->authorizeAdmin();

        abort_unless(
            $list->isLatest(),
            403,
            "Richtzeitenliste $list->year ist historisiert und kann nicht mehr geändert werden — nur die aktuellste Liste ist bearbeitbar."
        );
    }

    private function validateList(Request $request, ?int $excludeId = null): array
    {
        return $request->validate([
            'year' => 'required|integer|min:2000|max:2100|unique:qualifying_time_lists,year,'.($excludeId ?? 'NULL').',id',
            'is_active' => 'boolean',
            'qualification_period_start' => 'nullable|date',
            'qualification_period_end' => 'nullable|date|after_or_equal:qualification_period_start',
        ]);
    }
}
