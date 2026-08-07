<?php

namespace App\Http\Controllers;

use App\Models\Championship;
use App\Services\ChampionshipStandardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Verwaltung der internationalen Meisterschaften und ihrer Qualifikationsnormen
 * (Phase 2 der Spec "WPS Qualification", §10).
 *
 * Lesende Ansichten stehen allen angemeldeten Nutzern offen, die Verwaltung liegt
 * hinter RequireAdmin (§4). Die Normzeilen selbst werden nicht hier, sondern in der
 * Livewire-Komponente ChampionshipStandardTable gepflegt.
 */
class ChampionshipController extends Controller
{
    public function __construct(
        private readonly ChampionshipStandardService $service
    ) {}

    public function index(): View
    {
        // standards_count füllt die Spalte "Anzahl Normen", open_standards_count die
        // Spalte "offene ÖBSV-Zeilen" (§10) — beides als Unterabfrage, sonst je Zeile
        // eine eigene Abfrage.
        $championships = Championship::query()
            ->withCount([
                'standards',
                'standards as open_standards_count' => fn ($query) => $query->whereNull('obsv_percent'),
            ])
            ->orderByDesc('year')
            ->orderBy('type')
            ->get();

        return view('championships.index', ['championships' => $championships]);
    }

    public function create(): View
    {
        return view('championships.form', ['championship' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $championship = $this->service->createChampionship($this->validated($request));

        return redirect()
            ->route('championships.show', $championship)
            ->with('success', 'Meisterschaft angelegt.');
    }

    /** Normtabelle — die Pflege übernimmt die Livewire-Komponente. */
    public function show(Championship $championship): View
    {
        return view('championships.show', [
            'championship' => $championship,
            // Quellen für die Kopierfunktion: alle anderen Meisterschaften, die
            // überhaupt Normen enthalten.
            'copySources' => Championship::query()
                ->whereKeyNot($championship->getKey())
                ->has('standards')
                ->withCount('standards')
                ->orderByDesc('year')
                ->get(),
        ]);
    }

    public function edit(Championship $championship): View
    {
        return view('championships.form', ['championship' => $championship]);
    }

    public function update(Request $request, Championship $championship): RedirectResponse
    {
        $this->service->updateChampionship($championship, $this->validated($request));

        return redirect()
            ->route('championships.show', $championship)
            ->with('success', 'Meisterschaft aktualisiert.');
    }

    public function destroy(Championship $championship): RedirectResponse
    {
        $this->service->deleteChampionship($championship);

        return redirect()
            ->route('championships.index')
            ->with('success', 'Meisterschaft inklusive aller Normen gelöscht.');
    }

    /**
     * Übernimmt die MQS- und MET-Zeiten einer anderen Meisterschaft (§9.1).
     *
     * Die ÖBSV-Werte werden bewusst nicht mit kopiert — siehe
     * ChampionshipStandardService::copyStandards().
     */
    public function copyFrom(Request $request, Championship $championship): RedirectResponse
    {
        $daten = $request->validate([
            'source_id' => [
                'required',
                'integer',
                Rule::exists('championships', 'id')->whereNot('id', $championship->getKey()),
            ],
        ]);

        $quelle = Championship::query()->findOrFail($daten['source_id']);
        $anzahl = $this->service->copyStandards($quelle, $championship);

        return redirect()
            ->route('championships.show', $championship)
            ->with('success', sprintf(
                '%d Norm(en) aus "%s" übernommen. Bereits vorhandene Zeilen blieben unverändert; '
                .'ÖBSV-Prozentsätze und -Zeiten wurden nicht mit kopiert.',
                $anzahl,
                $quelle->display_name,
            ));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $daten = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'short_name' => ['nullable', 'string', 'max:50'],
            'type' => ['required', Rule::in(Championship::TYPES)],
            'year' => ['required', 'integer', 'min:1900', 'max:2200'],
            'course' => ['required', Rule::in(Championship::COURSES)],
            'qualification_start' => ['required', 'date'],
            'qualification_end' => ['required', 'date', 'after_or_equal:qualification_start'],
            'source' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // Eine nicht angehakte Checkbox wird gar nicht übertragen. Ohne diese Zeile
        // ließe sich eine Meisterschaft nie wieder deaktivieren.
        $daten['is_active'] = $request->boolean('is_active');

        return $daten;
    }
}
