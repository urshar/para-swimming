<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Models\Meet;
use DOMDocument;
use DOMException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public\SitemapController — sitemap.xml für Suchmaschinen (Spec public-frontend §6, Phase 9).
 *
 * Nur Seiten, die auch tatsächlich indexiert werden sollen — dieselbe Ausschlussliste wie
 * public/robots.txt (Cup-Wertung, Startberechtigung, Jahresbestleistungen, Ergebnislisten) fehlt
 * hier bewusst: eine Sitemap, die auf per robots.txt gesperrte Seiten verweist, wäre
 * widersprüchlich. Statische Routen fest verdrahtet (kurze, stabile Liste, ändert sich nur mit
 * neuen Seiten), dynamisch nur veröffentlichte Veranstaltungen. Jede URL einmal je Sprache
 * (SetLocale::SUPPORTED) — kein hreflang-Markup im Sitemap-XML selbst, das trägt schon jede
 * Seite via <link rel="alternate"> im <head> (layouts/public.blade.php).
 */
class SitemapController extends Controller
{
    /** @var list<string> */
    private const array STATIC_ROUTES = [
        'public.home',
        'public.meets.index',
        'public.meets.archive',
        'public.records.index',
        'public.base-times.index',
        'public.point-calculator.index',
        'public.wps-point-calculator.index',
        'public.regulations.index',
        'public.accessibility-statement.index',
    ];

    /**
     * @throws DOMException
     */
    public function index(): Response
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        // http:// ist hier korrekt und nicht änderbar: das ist der feste XML-Namespace-Bezeichner
        // aus dem Sitemap-Protokoll (sitemaps.org), keine tatsächlich aufgerufene URL — mit
        // https:// wäre der Namespace-String falsch und Suchmaschinen-Crawler würden die Datei
        // ablehnen.
        /** @noinspection HttpUrlsUsage */
        $urlset = $dom->createElementNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'urlset');
        $dom->appendChild($urlset);

        foreach ($this->urls() as $url) {
            $urlEl = $dom->createElement('url');
            $urlEl->appendChild($dom->createElement('loc', $url));
            $urlset->appendChild($urlEl);
        }

        return response($dom->saveXML(), 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * @return list<string>
     */
    private function urls(): array
    {
        $urls = [];

        foreach (self::STATIC_ROUTES as $routeName) {
            foreach (SetLocale::SUPPORTED as $locale) {
                $urls[] = route($routeName, ['locale' => $locale]);
            }
        }

        foreach (Meet::published()->get() as $meet) {
            foreach (SetLocale::SUPPORTED as $locale) {
                $urls[] = route('public.meets.show', ['locale' => $locale, 'meet' => $meet]);
            }
        }

        return $urls;
    }
}
