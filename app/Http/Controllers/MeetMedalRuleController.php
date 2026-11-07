<?php

namespace App\Http\Controllers;

use App\Models\Meet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Schaltet die Medaillenregel einer Veranstaltung (weniger Medaillen bei wenigen Teilnehmern, App\Support\MedalRule)
 * ein oder aus. Der Schalter steht im Block "Kennzahlen" unter "Alle Meldungen" (docs/specs/meet-entry-summary.md);
 * Route mit module:meets,write.
 */
class MeetMedalRuleController extends Controller
{
    public function update(Request $request, Meet $meet): RedirectResponse
    {
        $data = $request->validate(['medal_reduction_rule' => 'required|boolean']);
        $meet->update(['medal_reduction_rule' => (bool) $data['medal_reduction_rule']]);

        return redirect()
            ->route('meets.entries-overview', $meet)
            ->with('success', 'Medaillenregel '.($meet->medal_reduction_rule ? 'eingeschaltet' : 'ausgeschaltet').'.');
    }
}
