<?php

namespace App\Http\Controllers;

use App\Enums\Module;
use App\Models\KaderType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * KaderTypeController
 *
 * CRUD für die administrierbaren Kaderarten des Nationalkaders (Punkt 3 der
 * Spec, z.B. Weltklasse, internationale Klasse, Sichtungspool, Nachwuchspool).
 * Nur für Admins zugänglich.
 */
class KaderTypeController extends Controller
{
    public function index(): View
    {
        $this->authorizeWrite();

        $kaderTypes = KaderType::withCount('memberships')
            ->orderBy('sort_order')
            ->orderBy('name_de')
            ->get();

        return view('kader-types.index', compact('kaderTypes'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeWrite();

        $validated = $this->validateKaderType($request);

        $kaderType = KaderType::create($validated);

        return redirect()
            ->route('kader-types.index')
            ->with('success', "Kaderart \"$kaderType->name_de\" angelegt.");
    }

    public function create(): View
    {
        $this->authorizeWrite();

        return view('kader-types.form', ['kaderType' => null]);
    }

    public function edit(KaderType $kaderType): View
    {
        $this->authorizeWrite();

        return view('kader-types.form', compact('kaderType'));
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public function update(Request $request, KaderType $kaderType): RedirectResponse
    {
        $this->authorizeWrite();

        $validated = $this->validateKaderType($request, $kaderType->id);

        $kaderType->update($validated);

        return redirect()
            ->route('kader-types.index')
            ->with('success', "Kaderart \"$kaderType->name_de\" aktualisiert.");
    }

    public function destroy(KaderType $kaderType): RedirectResponse
    {
        $this->authorizeWrite();

        if ($kaderType->memberships()->exists()) {
            return redirect()
                ->route('kader-types.index')
                ->with('error',
                    "Kaderart \"$kaderType->name_de\" kann nicht gelöscht werden, solange Athleten zugeordnet sind.");
        }

        $name = $kaderType->name_de;
        $kaderType->delete();

        return redirect()
            ->route('kader-types.index')
            ->with('success', "Kaderart \"$name\" gelöscht.");
    }

    private function authorizeWrite(): void
    {
        abort_unless(auth()->user()?->canWrite(Module::MasterData), 403, 'Kein Schreibrecht für Stammdaten.');
    }

    private function validateKaderType(Request $request, ?int $excludeId = null): array
    {
        return $request->validate([
            'code' => 'required|string|max:50|unique:kader_types,code,'.($excludeId ?? 'NULL').',id',
            'name_de' => 'required|string|max:150',
            'sort_order' => 'nullable|integer|min:0|max:1000',
            'is_active' => 'boolean',
        ]);
    }
}
