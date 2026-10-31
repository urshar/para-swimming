<?php

namespace App\Http\Controllers;

use App\Enums\Module;
use App\Models\AgeGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * AgeGroupController
 *
 * CRUD für die administrierbaren Altersgruppen der Cupwertung (Punkt 5 der
 * Spec, z.B. Jugend/Offen). Nur für Admins zugänglich.
 */
class AgeGroupController extends Controller
{
    public function index(): View
    {
        $this->authorizeWrite();

        $ageGroups = AgeGroup::orderBy('sort_order')->orderBy('name_de')->get();

        return view('age-groups.index', compact('ageGroups'));
    }

    public function create(): View
    {
        $this->authorizeWrite();

        return view('age-groups.form', ['ageGroup' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeWrite();

        $validated = $this->validateAgeGroup($request);

        $ageGroup = AgeGroup::create($validated);

        return redirect()
            ->route('age-groups.index')
            ->with('success', "Altersgruppe \"$ageGroup->name_de\" angelegt.");
    }

    public function edit(AgeGroup $ageGroup): View
    {
        $this->authorizeWrite();

        return view('age-groups.form', compact('ageGroup'));
    }

    public function update(Request $request, AgeGroup $ageGroup): RedirectResponse
    {
        $this->authorizeWrite();

        $validated = $this->validateAgeGroup($request, $ageGroup->id);

        $ageGroup->update($validated);

        return redirect()
            ->route('age-groups.index')
            ->with('success', "Altersgruppe \"$ageGroup->name_de\" aktualisiert.");
    }

    public function destroy(AgeGroup $ageGroup): RedirectResponse
    {
        $this->authorizeWrite();

        $name = $ageGroup->name_de;
        $ageGroup->delete();

        return redirect()
            ->route('age-groups.index')
            ->with('success', "Altersgruppe \"$name\" gelöscht.");
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    /** @throws ValidationException wenn max_age < min_age */
    private function validateAgeGroup(Request $request, ?int $excludeId = null): array
    {
        $validated = $request->validate([
            'code' => 'required|string|max:30|unique:age_groups,code,'.($excludeId ?? 'NULL').',id',
            'name_de' => 'required|string|max:100',
            'min_age' => 'nullable|integer|min:0|max:120',
            'max_age' => 'nullable|integer|min:0|max:120',
            'sort_order' => 'nullable|integer|min:0|max:1000',
            'is_active' => 'boolean',
        ]);

        if (($validated['min_age'] ?? null) !== null && ($validated['max_age'] ?? null) !== null
            && $validated['min_age'] > $validated['max_age']) {
            throw ValidationException::withMessages([
                'max_age' => 'Max. Alter muss größer oder gleich Min. Alter sein.',
            ]);
        }

        return $validated;
    }

    private function authorizeWrite(): void
    {
        abort_unless(auth()->user()?->canWrite(Module::MasterData), 403, 'Kein Schreibrecht für Stammdaten.');
    }
}
