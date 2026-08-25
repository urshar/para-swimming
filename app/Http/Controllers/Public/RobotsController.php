<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public\RobotsController — robots.txt (Spec public-frontend §6, Phase 9).
 *
 * War bis Phase 9 eine statische Datei unter public/robots.txt. Umgestellt auf eine Route, damit
 * die Sitemap-Zeile eine echte absolute URL trägt (url(), löst gegen APP_URL auf) — eine
 * statische Datei kennt die aktuelle Umgebung (Dev-Domain vs. Produktions-Domain) nicht und
 * dürfte laut Sitemap-Protokoll ohnehin keine relative Angabe enthalten. Eine Route unter
 * demselben Pfad "/robots.txt" gewinnt nur, wenn keine gleichnamige Datei mehr unter public/
 * liegt (sonst liefert der Webserver die statische Datei direkt aus, ohne Laravel überhaupt zu
 * erreichen) — die alte Datei wurde deshalb entfernt.
 *
 * Die ersten sechs Disallow-Zeilen sind unverändert dieselben wie zuvor in der statischen Datei
 * (Cup-Wertung, Startberechtigung, Jahresbestleistungen, Ergebnislisten — siehe SitemapController
 * für dieselbe Ausschlussliste auf der anderen Seite). Impressum/Datenschutzerklärung kamen als
 * Phase-9-Nachtrag dazu, solange sie nur Platzhalter statt echtem Inhalt zeigen.
 */
class RobotsController extends Controller
{
    public function index(): Response
    {
        $lines = [
            'User-agent: *',
            'Disallow: /*/veranstaltungen/*/ergebnisse',
            'Disallow: /*/cup',
            'Disallow: /*/cup/*',
            'Disallow: /*/startberechtigung',
            'Disallow: /*/bestleistungen',
            'Disallow: /*/bestleistungen/*',
            // Entwürfe mit Platzhaltern statt echtem Inhalt (Phase 9 Nachtrag) — siehe
            // ImprintController/PrivacyPolicyController.
            'Disallow: /*/impressum',
            'Disallow: /*/datenschutz',
            '',
            'Sitemap: '.url('/sitemap.xml'),
        ];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain']);
    }
}
