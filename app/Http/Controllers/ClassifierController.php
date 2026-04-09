<?php

namespace App\Http\Controllers;

use App\Models\AthleteClassification;
use App\Models\Classifier;
use App\Models\Nation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClassifierController extends Controller
{
    public function index(Request $request): View
    {
        $query = Classifier::withCount([
            'classificationsAsMed',
            'classificationsAsTech1',
            'classificationsAsTech2',
        ])->orderBy('last_name')->orderBy('first_name');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('last_name', 'like', '%'.$search.'%')
                    ->orWhere('first_name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($nationId = $request->query('nation_id')) {
            $query->where('nation_id', $nationId);
        }

        if ($request->query('active_only', '1') === '1') {
            $query->where('is_active', true);
        }

        $classifiers = $query->paginate(25)->withQueryString();

        $nations = Nation::active()->orderBy('name_de')->get();

        return view('classifiers.index', compact('classifiers', 'nations'));
    }

    public function create(): View
    {
        $nations = Nation::active()->orderBy('name_de')->get();

        return view('classifiers.form', compact('nations'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateClassifier($request);

        Classifier::create($data);

        return redirect()
            ->route('classifiers.index')
            ->with('success', 'Klassifizierer erfolgreich angelegt.');
    }

    public function show(Classifier $classifier): View
    {
        $classifier->load([
            'classificationsAsMed.athlete',
            'classificationsAsTech1.athlete',
            'classificationsAsTech2.athlete',
        ]);

        // Alle Klassifikationen dieses Klassifizierers (egal in welcher Rolle)
        $classifications = AthleteClassification::where('med_classifier_id', $classifier->id)
            ->orWhere('tech1_classifier_id', $classifier->id)
            ->orWhere('tech2_classifier_id', $classifier->id)
            ->with('athlete')
            ->orderByDesc('classified_at')
            ->paginate(20);

        return view('classifiers.show', compact('classifier', 'classifications'));
    }

    public function edit(Classifier $classifier): View
    {
        $nations = Nation::active()->orderBy('name_de')->get();

        return view('classifiers.form', compact('classifier', 'nations'));
    }

    public function update(Request $request, Classifier $classifier): RedirectResponse
    {
        $data = $this->validateClassifier($request);

        $classifier->update($data);

        return redirect()
            ->route('classifiers.show', $classifier)
            ->with('success', 'Klassifizierer aktualisiert.');
    }

    public function destroy(Classifier $classifier): RedirectResponse
    {
        $classifier->delete();

        return redirect()
            ->route('classifiers.index')
            ->with('success', 'Klassifizierer gelöscht.');
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

    private function validateClassifier(Request $request): array
    {
        return $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'type' => 'required|in:MED,TECH',
            'email' => 'nullable|email|max:200',
            'phone' => 'nullable|string|max:50',
            'nation_id' => 'nullable|exists:nations,id',
            'gender' => 'nullable|in:M,F,N',
            'is_active' => 'boolean',
            'notes' => 'nullable|string|max:2000',
        ]);
    }
}
