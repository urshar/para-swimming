<?php

namespace App\Http\Controllers;

use App\Models\Meet;
use App\Services\PdfExportService;
use App\Services\StatisticsService;
use App\Support\ReportConfiguration;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * StatisticsController
 *
 * Rendert den Jahresbericht (Spec Phase 13).
 *
 * Der Controller führt keine Auswertung durch: Er baut aus dem Request die
 * ReportConfiguration, holt die Abschnitte beim StatisticsService und übergibt
 * sie an die Ansicht. Welche Abschnitte erscheinen, steuert allein die
 * Konfiguration.
 */
class StatisticsController extends Controller
{
    public function report(Request $request, StatisticsService $statistics): View
    {
        $config = $this->configurationFrom($request);

        return view('statistics.report', [
            'config' => $config,
            'statistics' => $statistics->generate($config),
            'selectedMeets' => $this->selectedMeets($config),
        ]);
    }

    /**
     * Derselbe Bericht als PDF (Spec Phase 14).
     *
     * Nutzt die bestehende PDF-Infrastruktur (PdfExportService/dompdf) und
     * eine eigenständige PDF-Vorlage unter resources/views/pdf. Der
     * inhaltliche Teil ist mit der Browser-Ansicht identisch, weil beide
     * dasselbe Partial einbinden.
     */
    public function reportPdf(
        Request $request,
        StatisticsService $statistics,
        PdfExportService $pdf,
    ): Response {
        $config = $this->configurationFrom($request);

        return $pdf->stream(
            'pdf.statistics-report',
            [
                'config' => $config,
                'statistics' => $statistics->generate($config),
                'selectedMeets' => $this->selectedMeets($config),
            ],
            "jahresbericht-$config->year.pdf",
        );
    }

    /**
     * Baut die Berichtskonfiguration aus dem Request. Gemeinsam genutzt von
     * Browser- und PDF-Ausgabe, damit beide zwingend dieselbe Auswertung
     * zeigen.
     */
    private function configurationFrom(Request $request): ReportConfiguration
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:1900', 'max:2999'],
            'meet_ids' => ['array'],
            'meet_ids.*' => ['integer'],
            'oebm_meet_ids' => ['array'],
            'oebm_meet_ids.*' => ['integer'],
            'oejm_meet_ids' => ['array'],
            'oejm_meet_ids.*' => ['integer'],
            'min_participations' => ['nullable', 'integer', 'min:1'],
            'sections' => ['array'],
        ]);

        // Nicht angehakte Abschnitte fehlen im Request vollständig; sie müssen
        // deshalb ausdrücklich als deaktiviert übergeben werden, sonst greift
        // der Default "alle aktiv".
        $data['sections'] = collect(ReportConfiguration::SECTION_KEYS)
            ->mapWithKeys(fn (string $key): array => [
                $key => (bool) ($data['sections'][$key] ?? false),
            ])
            ->all();

        return ReportConfiguration::fromArray($data);
    }

    /**
     * Die ausgewerteten Veranstaltungen für den Berichtskopf. Ohne Auswahl
     * werden alle Veranstaltungen des Zeitraums herangezogen.
     *
     * @return Collection<int, Meet>
     */
    private function selectedMeets(ReportConfiguration $config): Collection
    {
        $query = Meet::query()
            ->orderBy('start_date')
            ->orderBy('name');

        if ($config->isMeetFiltered()) {
            $query->whereIn('id', $config->meetIds);
        } else {
            $query->whereDate('start_date', '>=', $config->dateFrom->toDateString())
                ->whereDate('start_date', '<=', $config->dateTo->toDateString());
        }

        return $query->get(['id', 'name', 'start_date']);
    }
}
