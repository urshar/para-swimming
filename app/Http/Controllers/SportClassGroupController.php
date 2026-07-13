<?php

namespace App\Http\Controllers;

use App\Models\SportClassGroup;
use App\Models\SportClassGroupMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * SportClassGroupController
 *
 * CRUD für die administrierbaren Sportklassengruppen der Cupwertung (Punkt 7
 * der Spec: PI, VI, II, T21, HI) sowie die Top-Gruppe (Punkt 8, is_virtual).
 * Nur für Admins zugänglich.
 */
class SportClassGroupController extends Controller
{
    public function index(): View
    {
        $this->authorizeAdmin();

        $groups = SportClassGroup::withCount('members')
            ->orderBy('sort_order')
            ->orderBy('name_de')
            ->get();

        return view('sport-class-groups.index', compact('groups'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdmin();

        $validated = $this->validateGroup($request);

        $group = SportClassGroup::create($validated);

        return redirect()
            ->route('sport-class-groups.edit', $group)
            ->with('success', "Gruppe \"$group->name_de\" angelegt. Jetzt die Sportklassen zuordnen.");
    }

    public function create(): View
    {
        $this->authorizeAdmin();

        return view('sport-class-groups.form', ['group' => null]);
    }

    public function edit(SportClassGroup $sportClassGroup): View
    {
        $this->authorizeAdmin();

        $sportClassGroup->load('members');

        return view('sport-class-groups.form', ['group' => $sportClassGroup]);
    }

    // ── Mitglieder (Sportklassen-Zuordnung) ──────────────────────────────────

    public function update(Request $request, SportClassGroup $sportClassGroup): RedirectResponse
    {
        $this->authorizeAdmin();

        $validated = $this->validateGroup($request, $sportClassGroup->id);

        $sportClassGroup->update($validated);

        return redirect()
            ->route('sport-class-groups.index')
            ->with('success', "Gruppe \"$sportClassGroup->name_de\" aktualisiert.");
    }

    public function destroy(SportClassGroup $sportClassGroup): RedirectResponse
    {
        $this->authorizeAdmin();

        $name = $sportClassGroup->name_de;
        $sportClassGroup->delete(); // kaskadiert Mitglieder und Cup-Einstellungen

        return redirect()
            ->route('sport-class-groups.index')
            ->with('success', "Gruppe \"$name\" gelöscht.");
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    /**
     * POST /sport-class-groups/{sportClassGroup}/members
     * fügt eine oder mehrere Sportklassen (kommagetrennt) der Gruppe hinzu.
     */
    public function storeMember(Request $request, SportClassGroup $sportClassGroup): RedirectResponse
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'sport_classes' => 'required|string|max:500',
        ]);

        $classes = collect(explode(',', $validated['sport_classes']))
            ->map(fn (string $class) => strtoupper(trim($class)))
            ->filter()
            ->unique()
            ->values();

        $skipped = [];

        foreach ($classes as $class) {
            if (SportClassGroupMember::where('sport_class', $class)->exists()) {
                $skipped[] = $class;

                continue;
            }

            SportClassGroupMember::create([
                'sport_class_group_id' => $sportClassGroup->id,
                'sport_class' => $class,
            ]);
        }

        $message = 'Sportklassen hinzugefügt.';
        if ($skipped !== []) {
            $message .= ' Übersprungen (bereits einer anderen Gruppe zugeordnet): '.implode(', ', $skipped);
        }

        return redirect()
            ->route('sport-class-groups.edit', $sportClassGroup)
            ->with('success', $message);
    }

    public function destroyMember(SportClassGroup $sportClassGroup, SportClassGroupMember $member): RedirectResponse
    {
        $this->authorizeAdmin();

        abort_unless($member->sport_class_group_id === $sportClassGroup->id, 404);

        $member->delete();

        return redirect()
            ->route('sport-class-groups.edit', $sportClassGroup)
            ->with('success', "Sportklasse \"$member->sport_class\" entfernt.");
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->is_admin, 403, 'Nur für Administratoren.');
    }

    private function validateGroup(Request $request, ?int $excludeId = null): array
    {
        return $request->validate([
            'code' => 'required|string|max:20|unique:sport_class_groups,code,'.($excludeId ?? 'NULL').',id',
            'name_de' => 'required|string|max:150',
            'is_virtual' => 'boolean',
            'sort_order' => 'nullable|integer|min:0|max:1000',
            'is_active' => 'boolean',
        ]);
    }
}
