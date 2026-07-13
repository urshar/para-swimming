<?php

namespace App\Http\Controllers;

use App\Models\BaseTimeVersion;
use App\Models\Cup;
use App\Models\CupGroupSetting;
use App\Models\SportClassGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CupController
 *
 * CRUD für die ÖBSV-Cup-Konfiguration (ein Datensatz pro Cup-Jahr). Nur für
 * Admins zugänglich — analog zu BaseTimeVersionController.
 */
class CupController extends Controller
{
    public function index(): View
    {
        $this->authorizeAdmin();

        $cups = Cup::withCount('meets')
            ->orderByDesc('year')
            ->get();

        return view('cups.index', compact('cups'));
    }

    public function create(): View
    {
        $this->authorizeAdmin();

        $baseTimeVersions = BaseTimeVersion::orderByDesc('valid_from')->get();
        $sportClassGroups = SportClassGroup::active()->orderBy('sort_order')->get();

        return view('cups.form', [
            'cup' => null,
            'baseTimeVersions' => $baseTimeVersions,
            'sportClassGroups' => $sportClassGroups,
            'activeGroupIds' => $sportClassGroups->pluck('id')->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdmin();

        $validated = $this->validateCup($request);

        $cup = Cup::create($validated);

        $this->syncGroupSettings($cup, $request);

        return redirect()
            ->route('cups.index')
            ->with('success', "Cup \"$cup->name\" angelegt.");
    }

    public function edit(Cup $cup): View
    {
        $this->authorizeAdmin();

        $cup->load('groupSettings');

        $baseTimeVersions = BaseTimeVersion::orderByDesc('valid_from')->get();
        $sportClassGroups = SportClassGroup::active()->orderBy('sort_order')->get();

        $activeGroupIds = $sportClassGroups
            ->filter(fn (SportClassGroup $group) => $cup->isGroupActive($group))
            ->pluck('id')
            ->all();

        return view('cups.form', compact('cup', 'baseTimeVersions', 'sportClassGroups', 'activeGroupIds'));
    }

    public function update(Request $request, Cup $cup): RedirectResponse
    {
        $this->authorizeAdmin();

        $validated = $this->validateCup($request, $cup->id);

        $cup->update($validated);

        $this->syncGroupSettings($cup, $request);

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
     * Speichert, welche Sportklassengruppen für diesen Cup aktiv sind
     * (Checkboxen im Formular — nicht angehakte Gruppen werden als inaktiv
     * hinterlegt statt gelöscht, damit die Historie nachvollziehbar bleibt).
     */
    private function syncGroupSettings(Cup $cup, Request $request): void
    {
        $selectedGroupIds = collect($request->input('active_group_ids', []))->map(fn ($id) => (int) $id);

        foreach (SportClassGroup::active()->get() as $group) {
            CupGroupSetting::updateOrCreate(
                ['cup_id' => $cup->id, 'sport_class_group_id' => $group->id],
                ['is_active' => $selectedGroupIds->contains($group->id)]
            );
        }
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->is_admin, 403, 'Nur für Administratoren.');
    }
}
