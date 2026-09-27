<?php

namespace App\Http\Controllers;

use App\Livewire\YearComparison;
use App\Services\MultiYearStatisticsService;
use App\Services\ParticipationStatisticsService;
use App\Services\PdfExportService;
use App\Support\YearComparisonCharts;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * YearComparisonController
 *
 * PDF-Ausgabe der Jahresvergleich-Seite. Der Bildschirm nutzt interaktive
 * flux:charts (Livewire-Komponente YearComparison); das PDF greift auf dieselben
 * Chart-Definitionen (YearComparisonCharts) zu, rendert sie aber als statisches
 * SVG (x-trend-chart), da dompdf kein JavaScript ausführt.
 *
 * Nur Admin (Route-Middleware RequireAdmin), wie das übrige Statistikmodul.
 */
class YearComparisonController extends Controller
{
    public function pdf(
        Request $request,
        YearComparisonCharts $charts,
        ParticipationStatisticsService $participation,
        PdfExportService $pdf,
    ): Response {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:1900', 'max:2999'],
            'span' => ['nullable', 'integer', 'min:'.YearComparison::MIN_SPAN, 'max:'.YearComparison::MAX_SPAN],
            'show_regular' => ['nullable', 'boolean'],
            'meets' => ['nullable', 'array'],
            'meets.*' => ['integer'],
        ]);

        $year = (int) $data['year'];
        $span = (int) ($data['span'] ?? MultiYearStatisticsService::DEFAULT_SPAN);
        $showRegular = (bool) ($data['show_regular'] ?? false);
        $meetIds = array_map('intval', $data['meets'] ?? []);

        return $pdf->stream(
            'pdf.year-comparison',
            [
                'charts' => $charts->charts($year, $span, $showRegular),
                'year' => $year,
                'span' => $span,
                'comparisonMeets' => $participation->statusForMeets($meetIds),
                'statusLabels' => YearComparison::STATUS_LABELS,
            ],
            "jahresvergleich-$year.pdf",
        );
    }
}
