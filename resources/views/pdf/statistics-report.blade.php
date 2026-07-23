<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Jahresbericht {{ $config->year }} — ÖBSV Para Swimming</title>
    <style>
        /*
         * PDF-Fassung des Jahresberichts (Spec Phase 14).
         *
         * Eigenständiges HTML/CSS für dompdf — kein Tailwind, kein Flux.
         * Hier stehen ausschließlich Seitenrahmen, laufende Kopf- und
         * Fußzeile sowie die Seitenzahlen; die Gestaltung des Inhalts kommt
         * aus dem gemeinsamen Partial.
         */

        /* Platz oben und unten für die fest positionierte Kopf-/Fußzeile. */
        @page {
            margin: 70px 25px 55px 25px;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #1a1a1a;
            margin: 0;
        }

        .page-header {
            position: fixed;
            top: -55px;
            left: 0;
            right: 0;
            height: 40px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 4px;
        }

        .page-header .title { font-size: 13px; font-weight: bold; }
        .page-header .sub { font-size: 8px; color: #666; margin-top: 2px; }

        .page-footer {
            position: fixed;
            bottom: -40px;
            left: 0;
            right: 0;
            height: 30px;
            border-top: 1px solid #ddd;
            padding-top: 4px;
            font-size: 8px;
            color: #666;
        }

        .page-footer .left { float: left; }
        .page-footer .right { float: right; }

        /*
         * Seitenzahl. Bewusst ohne Gesamtseitenzahl: dompdf erzeugt den Inhalt
         * der Fußzeile, bevor die Anzahl der Seiten feststeht — counter(pages)
         * liefert dort verlässlich 0. "Seite X von Y" wäre nur über einen
         * zweiten Renderdurchlauf möglich (Canvas::page_text nach render()).
         */
        .page-numbering:before {
            content: "Seite " counter(page);
        }
    </style>
</head>
<body>

<div class="page-header">
    <div class="title">Jahresbericht {{ $config->year }} — ÖBSV Para Swimming</div>
    <div class="sub">
        Zeitraum {{ $config->dateFrom->format('d.m.Y') }} bis {{ $config->dateTo->format('d.m.Y') }}
    </div>
</div>

<div class="page-footer">
    <span class="left">Para Swimming NatDB · erzeugt am {{ now()->format('d.m.Y H:i') }} Uhr</span>
    <span class="right page-numbering"></span>
</div>

@include('statistics.partials.sections')

</body>
</html>
