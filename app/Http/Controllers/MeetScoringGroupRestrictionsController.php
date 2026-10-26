<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Meet;
use App\Models\ScoringGroup;
use App\Rules\CodeList;
use App\Services\ScoringGroupService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * MeetScoringGroupRestrictionsController
 *
 * Seite "Wertungsgruppen einschränken" einer Veranstaltung: Landesverband und Level gesammelt an allen
 * Wertungsgruppen setzen, deren Name mit einem Text beginnt — z. B. "LM Wien" → nur WBSV, "Gäste" → alle außer WBSV
 * (docs/specs/scoring-group-restrictions.md). LENEX kennt dafür kein Attribut, deshalb nach einem Import hier
 * nachziehen. Nur Admin (Route-Middleware RequireAdmin).
 */
class MeetScoringGroupRestrictionsController extends Controller
{
    /** Sentinel für "keine Einschränkung nach Landesverband" (leere Flux-Optionen kommen nicht an, siehe CLAUDE.md). */
    public const string MODE_NONE = 'NONE';

    public function __construct(
        private readonly ScoringGroupService $scoring,
    ) {}

    public function index(Meet $meet): View
    {
        $groups = ScoringGroup::whereHas('swimEvent', fn ($q) => $q->where('meet_id', $meet->id))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // Je Gruppenname: Anzahl Bewerbe und die (ggf. unterschiedlichen) Einschränkungen.
        $names = $groups->groupBy('name')
            ->map(fn (Collection $byName, string $name): array => [
                'name' => $name,
                'count' => $byName->count(),
                'restrictions' => $byName->map(fn (ScoringGroup $g): string => $g->restrictionLabel() ?? '–')->unique()->values()->all(),
            ])
            ->values();

        return view('meets.scoring-group-restrictions', [
            'meet' => $meet,
            'names' => $names,
            'levelOptions' => ScoringGroup::levelOptions([...$groups->pluck('levels')->all(), old('levels')]),
            'backUrl' => MeetResultsOverviewController::backUrl($meet),
        ]);
    }

    public function update(Request $request, Meet $meet): RedirectResponse
    {
        $modes = [self::MODE_NONE, ...array_keys(ScoringGroup::REGION_MODES)];
        $data = $request->validate([
            'prefix' => 'required|string|max:100',
            'regional_mode' => 'required|in:'.implode(',', $modes),
            'regional_associations' => [
                'nullable', 'string', 'max:100', 'required_unless:regional_mode,'.self::MODE_NONE,
                new CodeList(array_keys(Club::REGIONAL_ASSOCIATIONS)),
            ],
            'levels' => 'nullable|string|max:100',
        ]);

        $none = $data['regional_mode'] === self::MODE_NONE;
        $outcome = $this->scoring->restrictByNamePrefix(
            $meet,
            $data['prefix'],
            $none ? [] : ScoringGroup::parseCodes($data['regional_associations'] ?? ''),
            $none ? null : $data['regional_mode'],
            ScoringGroup::parseLevels($data['levels'] ?? ''),
        );

        if ($outcome['groups'] === 0) {
            return back()->withInput()->with('error', 'Keine Wertungsgruppe beginnt mit "'.trim($data['prefix']).'".');
        }

        return redirect()
            ->route('meets.scoring-group-restrictions', $meet)
            ->with('success', 'Einschränkung an '.$outcome['groups'].' '.($outcome['groups'] === 1 ? 'Wertungsgruppe' : 'Wertungsgruppen')
                .' in '.$outcome['events'].' '.($outcome['events'] === 1 ? 'Bewerb' : 'Bewerben').' gesetzt, Plätze neu berechnet.');
    }
}
