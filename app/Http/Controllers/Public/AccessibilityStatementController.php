<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Public\AccessibilityStatementController — Erklärung zur Barrierefreiheit (Spec
 * public-frontend §6, Phase 9; docs/accessibility.md §Erklärung zur Barrierefreiheit).
 *
 * Zeigt aktuell nur die Kontaktmöglichkeit für Rückmeldungen — Konformitätsstand und
 * Schlichtungsverfahren fehlen bewusst, siehe docs/open-points.md. Kein Modell/Service
 * nötig: statischer Inhalt, die Kontaktadresse steht als Übersetzungswert in
 * lang/{de,en}/public.php (keine Konfiguration/Umgebungsvariable, da sie nirgends sonst
 * gebraucht wird).
 */
class AccessibilityStatementController extends Controller
{
    public function index(): View
    {
        return view('public.accessibility-statement.index');
    }
}
