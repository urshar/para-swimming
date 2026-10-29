<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /dashboard ist kein eigenes Dashboard, sondern das Ziel nach dem Login: Weiterleitung auf den ersten Bereich,
 * den der Benutzer öffnen darf (Spec club-permissions Phase 3). Ohne freigegebenen Bereich ein Hinweis statt
 * einer 403-Seite. Gäste landen wie bisher über die Wettkampfübersicht auf der Login-Seite.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|View
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('meets.index');
        }

        $home = $user->homeRoute();

        return $home !== null ? redirect()->route($home) : view('no-access');
    }
}
