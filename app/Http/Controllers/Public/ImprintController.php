<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Public\ImprintController — Impressum (Offenlegung nach § 5 ECG), Phase 9 Nachtrag.
 *
 * Entwurf mit Platzhaltern statt echtem Inhalt (Rückmeldung: "Entwurf mit Platzhaltern, klar als
 * Entwurf markiert") — Vereinsname, Anschrift, ZVR-Zahl und vertretungsbefugte Person(en) sind
 * echte Pflichtangaben, die ich nicht erfinden darf. `robots: noindex, nofollow` in der View,
 * zusätzlich in robots.txt gesperrt (RobotsController) und nicht in der Sitemap
 * (SitemapController) — ein unvollständiges Impressum soll nicht indexiert werden.
 */
class ImprintController extends Controller
{
    public function index(): View
    {
        return view('public.imprint.index');
    }
}
