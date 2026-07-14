<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * PdfExportService
 *
 * Kapselt die PDF-Erzeugung aus Blade-Views (Punkt 12 der Spec). Nutzt
 * dompdf über das Paket barryvdh/laravel-dompdf.
 *
 * WICHTIG: dompdf unterstützt kein Tailwind/Flux/Alpine — die PDF-Views
 * (resources/views/pdf/*) sind bewusst eigenständige, einfache HTML/CSS-
 * Views und dürfen NICHT die normalen Flux-basierten Web-Views wiederverwenden.
 */
readonly class PdfExportService
{
    /**
     * Öffnet das PDF direkt im Browser (druckbar über den PDF-Viewer des
     * Browsers — deckt damit sowohl "druckbar" als auch "als PDF exportiert"
     * aus Punkt 11 der Spec ab, ohne zwei getrennte Ausgabewege zu pflegen).
     */
    public function stream(string $view, array $data, string $filename): Response
    {
        return Pdf::loadView($view, $data)->stream($filename);
    }

    /** Erzwingt den Download statt der Inline-Anzeige im Browser. */
    public function download(string $view, array $data, string $filename): Response
    {
        return Pdf::loadView($view, $data)->download($filename);
    }
}
