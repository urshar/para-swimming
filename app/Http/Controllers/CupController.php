<?php

namespace App\Http\Controllers;

use App\Models\AgeGroup;
use App\Models\BaseTimeVersion;
use App\Models\Cup;
use App\Models\CupAgeGroupSetting;
use App\Models\CupGroupSetting;
use App\Models\SportClassGroup;
use App\Services\CupStalenessService;
use App\Services\TopGroupClassificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * CupController
 *
 * CRUD für die ÖBSV-Cup-Konfiguration (ein Datensatz pro Cup-Jahr). Nur für
 * Admins zugänglich — analog zu BaseTimeVersionController.
 */
class CupController extends Controller
{
    public function __construct(
        private readonly TopGroupClassificationService $topGroupClassificationService,
        private readonly CupStalenessService $stalenessService,
    ) {}

    public function index(): View
    {
        $this->authorizeAdmin();

        $cups = Cup::withCount('meets')
            ->orderByDesc('year')
            ->get();

        $classificationStatus = $cups->mapWithKeys(
            fn (Cup $cup) => [$cup->id => $this->stalenessService->topGroupClassificationStatus($cup)]
        );

        return view('cups.index', compact('cups', 'classificationStatus'));
    }

    public function create(): View
    {
        $this->authorizeAdmin();

        $baseTimeVersions = BaseTimeVersion::orderByDesc('valid_from')->get();
        $sportClassGroups = SportClassGroup::active()->orderBy('sort_order')->get();
        $ageGroups = AgeGroup::active()->orderBy('sort_order')->get();

        return view('cups.form', [
            'cup' => null,
            'baseTimeVersions' => $baseTimeVersions,
            'sportClassGroups' => $sportClassGroups,
            'activeGroupIds' => $sportClassGroups->pluck('id')->all(),
            'genderCombinedGroupIds' => [],
            'ageGroups' => $ageGroups,
            'activeAgeGroupIdsByGroup' => $sportClassGroups->mapWithKeys(
                fn (SportClassGroup $group) => [$group->id => $ageGroups->pluck('id')->all()]
            ),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdmin();

        $validated = $this->validateCup($request);

        $cup = Cup::create($validated);

        $this->syncGroupSettings($cup, $request);
        $this->syncAgeGroupSettings($cup, $request);

        return redirect()
            ->route('cups.index')
            ->with('success', "Cup \"$cup->name\" angelegt.");
    }

    public function edit(Cup $cup): View
    {
        $this->authorizeAdmin();

        $cup->load(['groupSettings', 'ageGroupSettings']);

        $baseTimeVersions = BaseTimeVersion::orderByDesc('valid_from')->get();
        $sportClassGroups = SportClassGroup::active()->orderBy('sort_order')->get();
        $ageGroups = AgeGroup::active()->orderBy('sort_order')->get();

        $activeGroupIds = $sportClassGroups
            ->filter(fn (SportClassGroup $group) => $cup->isGroupActive($group))
            ->pluck('id')
            ->all();

        $genderCombinedGroupIds = $sportClassGroups
            ->filter(fn (SportClassGroup $group) => $cup->isGenderCombined($group))
            ->pluck('id')
            ->all();

        $activeAgeGroupIdsByGroup = $sportClassGroups->mapWithKeys(function (SportClassGroup $group) use ($ageGroups, $cup) {
            $activeIds = $ageGroups
                ->filter(fn (AgeGroup $ageGroup) => $cup->isAgeGroupActive($ageGroup, $group))
                ->pluck('id')
                ->all();

            return [$group->id => $activeIds];
        });

        return view('cups.form', compact(
            'cup', 'baseTimeVersions', 'sportClassGroups', 'activeGroupIds', 'genderCombinedGroupIds',
            'ageGroups', 'activeAgeGroupIdsByGroup'
        ));
    }

    public function update(Request $request, Cup $cup): RedirectResponse
    {
        $this->authorizeAdmin();

        $validated = $this->validateCup($request, $cup->id);

        $cup->update($validated);

        $this->syncGroupSettings($cup, $request);
        $this->syncAgeGroupSettings($cup, $request);

        return redirect()
            ->route('cups.index')
            ->with('success', "Cup \"$cup->name\" aktualisiert.");
    }

    public function destroy(Cup $cup): RedirectResponse
    {
        $this->authorizeAdmin();

        $name = $cup->name;
        $cup->delete();

        return redirect()
            ->route('cups.index')
            ->with('success', "Cup \"$name\" gelöscht.");
    }

    /**
     * POST /cups/{cup}/classify-top-group
     *
     * Löst die saisonale Top-Gruppen-Klassifizierung aus (siehe
     * TopGroupClassificationService). MUSS vor der Tageswertungs-Berechnung
     * für dieses Cup-Jahr laufen.
     *
     * @throws Throwable bei einem Fehler innerhalb der Berechnung
     */
    public function classifyTopGroup(Cup $cup): RedirectResponse
    {
        $this->authorizeAdmin();

        $this->topGroupClassificationService->calculateForCup($cup);

        return redirect()
            ->route('cups.index')
            ->with('success', "Top-Gruppen-Klassifizierung für \"$cup->name\" berechnet.");
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    private function validateCup(Request $request, ?int $excludeId = null): array
    {
        return $request->validate([
            'year' => 'required|integer|min:2000|max:2100|unique:cups,year,'.($excludeId ?? 'NULL').',id',
            'name' => 'required|string|max:150',
            'base_time_version_id' => 'required|exists:base_time_versions,id',
            'rounds_count' => 'required|integer|min:1|max:50',
            'best_of_count' => 'required|integer|min:1|max:50',
            'top_group_points_threshold' => 'required|integer|min:0|max:1200',
            'is_active' => 'boolean',
        ]);
    }

    /**
     * Speichert, welche Sportklassengruppen für diesen Cup aktiv sind und ob
     * Damen/Herren dort gemeinsam gewertet werden (Checkboxen im Formular —
     * nicht angehakte Gruppen werden als inaktiv hinterlegt statt gelöscht,
     * damit die Historie nachvollziehbar bleibt).
     */
    private function syncGroupSettings(Cup $cup, Request $request): void
    {
        $selectedGroupIds = collect($request->input('active_group_ids', []))->map(fn ($id) => (int) $id);
        $combinedGroupIds = collect($request->input('gender_combined_group_ids', []))->map(fn ($id) => (int) $id);

        foreach (SportClassGroup::active()->get() as $group) {
            CupGroupSetting::updateOrCreate(
                ['cup_id' => $cup->id, 'sport_class_group_id' => $group->id],
                [
                    'is_active' => $selectedGroupIds->contains($group->id),
                    'gender_combined' => $combinedGroupIds->contains($group->id),
                ]
            );
        }
    }

    /**
     * Speichert, welche Altersgruppen für diesen Cup aktiv sind (Erik: generisch
     * über alle Altersgruppen, nicht nur "Jugend" fest verdrahtet).
     */
    /**
     * Erwartet Checkbox-Input in Matrix-Form: active_age_group_ids[{sportClassGroupId}][]
     * (Erik, 2026-07-19: Altersgruppen-Aktivierung ist pro Sportklassengruppe
     * steuerbar statt cup-weit global).
     */
    private function syncAgeGroupSettings(Cup $cup, Request $request): void
    {
        $matrix = $request->input('active_age_group_ids', []);

        foreach (SportClassGroup::active()->get() as $sportClassGroup) {
            $selectedAgeGroupIds = collect($matrix[$sportClassGroup->id] ?? [])->map(fn ($id) => (int) $id);

            foreach (AgeGroup::active()->get() as $ageGroup) {
                CupAgeGroupSetting::updateOrCreate(
                    [
                        'cup_id' => $cup->id,
                        'sport_class_group_id' => $sportClassGroup->id,
                        'age_group_id' => $ageGroup->id,
                    ],
                    ['is_active' => $selectedAgeGroupIds->contains($ageGroup->id)]
                );
            }
        }
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->is_admin, 403, 'Nur für Administratoren.');
    }
}
