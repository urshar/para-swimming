<?php

namespace App\Http\Controllers;

use App\Models\BaseTimeVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * BaseTimeVersionController
 *
 * CRUD für Basiswert-Versionen. Nur für Admins zugänglich — Basiswerte sind eine
 * ÖBSV-weite Verwaltungsaufgabe, keine Club-Funktion.
 */
class BaseTimeVersionController extends Controller
{
    public function index(): View
    {
        $this->authorizeAdmin();

        $versions = BaseTimeVersion::withCount('baseTimes')
            ->orderByDesc('valid_from')
            ->get();

        return view('base-times.versions.index', compact('versions'));
    }

    public function create(): View
    {
        $this->authorizeAdmin();

        return view('base-times.versions.form', ['version' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdmin();

        $validated = $this->validateVersion($request);

        $version = BaseTimeVersion::create($validated);

        return redirect()
            ->route('base-times.versions.index')
            ->with('success', "Version \"$version->label\" angelegt.");
    }

    public function edit(BaseTimeVersion $version): View
    {
        $this->authorizeAdmin();

        return view('base-times.versions.form', compact('version'));
    }

    public function update(Request $request, BaseTimeVersion $version): RedirectResponse
    {
        $this->authorizeAdmin();

        $validated = $this->validateVersion($request, $version->id);

        $version->update($validated);

        return redirect()
            ->route('base-times.versions.index')
            ->with('success', "Version \"$version->label\" aktualisiert.");
    }

    public function destroy(BaseTimeVersion $version): RedirectResponse
    {
        $this->authorizeAdmin();

        $label = $version->label;
        $version->delete(); // kaskadiert base_times über cascadeOnDelete

        return redirect()
            ->route('base-times.versions.index')
            ->with('success', "Version \"$label\" gelöscht.");
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    /**
     * @throws ValidationException wenn sich der Zeitraum mit einer bestehenden Version überschneidet
     */
    private function validateVersion(Request $request, ?int $excludeId = null): array
    {
        $validated = $request->validate([
            'label' => 'required|string|max:100',
            'valid_from' => 'required|date',
            'valid_until' => 'nullable|date|after:valid_from',
        ]);

        if (BaseTimeVersion::overlapsExisting($validated['valid_from'], $validated['valid_until'] ?? null, $excludeId)) {
            throw ValidationException::withMessages([
                'valid_from' => 'Der Gültigkeitszeitraum überschneidet sich mit einer bestehenden Version.',
            ]);
        }

        return $validated;
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->is_admin, 403, 'Nur für Administratoren.');
    }
}
