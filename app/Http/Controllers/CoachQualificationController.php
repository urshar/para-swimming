<?php

namespace App\Http\Controllers;

use App\Enums\CoachLevel;
use App\Models\Coach;
use App\Models\CoachQualification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Aus- und Fortbildungen eines Trainers auf dessen Detailseite (Spec club-permissions N6, P8-1/P8-2). Der Verein
 * trägt sie selbst ein; geprüft wird über die Pflege des Trainers (CoachPolicy::update).
 */
class CoachQualificationController extends Controller
{
    public function store(Request $request, Coach $coach): RedirectResponse
    {
        Gate::authorize('update', $coach);

        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(CoachQualification::KIND_LABELS))],
            'level' => [
                Rule::requiredIf($request->input('kind') === CoachQualification::KIND_EDUCATION),
                'nullable',
                Rule::in(array_map(fn (CoachLevel $level) => $level->value, CoachLevel::trained())),
            ],
            'title' => 'nullable|string|max:255',
            'completed_on' => 'required|date',
            'notes' => 'nullable|string|max:2000',
        ]);

        // Fortbildungen tragen keine Stufe (P8-2).
        if ($data['kind'] === CoachQualification::KIND_TRAINING) {
            $data['level'] = null;
        }

        $coach->qualifications()->create($data);

        return redirect()->route('coaches.show', $coach)
            ->with('success', CoachQualification::KIND_LABELS[$data['kind']].' eingetragen.');
    }

    public function destroy(Coach $coach, CoachQualification $qualification): RedirectResponse
    {
        Gate::authorize('update', $coach);

        $qualification->delete();

        return redirect()->route('coaches.show', $coach)->with('success', 'Eintrag gelöscht.');
    }
}
