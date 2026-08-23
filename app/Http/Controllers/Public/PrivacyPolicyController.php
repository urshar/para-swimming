<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Public\PrivacyPolicyController — Datenschutzerklärung (Informationspflichten nach Art. 13
 * DSGVO), Phase 9 Nachtrag.
 *
 * Entwurf mit Platzhaltern statt echtem Inhalt — siehe ImprintController-Kommentar, hier
 * zusätzlich fachlich aufwendiger: welche Rechtsgrundlage für die öffentliche Veröffentlichung
 * von Athletennamen/Ergebnissen/Vereinszugehörigkeit gilt, ist eine Entscheidung des Vereins,
 * keine technische. Generische, gesetzlich vorgegebene Abschnitte (Betroffenenrechte,
 * Beschwerderecht bei der Datenschutzbehörde) sind bereits ausformuliert, siehe View —
 * die verifizierbaren technischen Fakten (Cookie/localStorage) ebenfalls, siehe dort.
 */
class PrivacyPolicyController extends Controller
{
    public function index(): View
    {
        return view('public.privacy-policy.index');
    }
}
