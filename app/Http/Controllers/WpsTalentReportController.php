<?php

namespace App\Http\Controllers;

use App\Models\Championship;
use App\Services\PdfExportService;
use App\Services\WpsTalentReportService;
use App\Support\WpsRankingFilter;
use App\Support\WpsTalentReportConfiguration;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Förderauswertung (Spec "WPS Rankings" §6.6).
 *
 * Die Bildschirmansicht ist eine Livewire-Komponente; der Controller reicht nur durch. Die
 * PDF-Ausgabe läuft hier, weil sie einen vollständigen Antwortkörper liefert und keinen
 * Zustand hält.
 *
 * Lesend; die Auswertung steht allen Angemeldeten offen.
 */
class WpsTalentReportController extends Controller
{
    public function __construct(
        private readonly WpsTalentReportService $service,
        private readonly PdfExportService $pdfExportService,
    ) {}

    public function show(): View
    {
        return view('wps.talent.index');
    }

    public function pdf(Request $request): Response
    {
        $config = $this->configFromQuery($request);

        if ($config === null) {
            return redirect()->route('wps.talent-report')
                ->withErrors(['reference' => 'Ohne Referenznorm lässt sich keine Auswertung erstellen.']);
        }

        return $this->pdfExportService->stream(
            'pdf.wps-talent-report',
            [
                'config' => $config,
                'groups' => $this->service->report($config),
                'withoutBirthDate' => $this->service->withoutBirthDate($config),
                'generatedAt' => now(),
            ],
            sprintf('Förderauswertung-%s.pdf', now()->format('Y-m-d')),
            'landscape',
        );
    }

    /**
     * Baut die Konfiguration aus der Adresse.
     *
     * Unbrauchbare Werte fallen auf die Vorschlagswerte zurück, statt einen Fehler zu
     * erzeugen. Fehlt die Referenznorm, gibt es dagegen nichts auszuwerten — dann liefert die
     * Methode null, denn ein Prozentwert ohne Bezugsgröße ist wertlos (§6.6.1).
     */
    private function configFromQuery(Request $request): ?WpsTalentReportConfiguration
    {
        $referenz = Championship::query()->find($request->integer('reference'));

        if ($referenz === null) {
            return null;
        }

        $von = $request->integer('from') ?: (int) date('Y');
        $bis = $request->integer('to') ?: $von;
        $bahn = strtoupper((string) $request->query('course', WpsRankingFilter::COURSE_SCM));
        $norm = strtolower((string) $request->query('norm', WpsTalentReportConfiguration::NORM_MQS));

        return new WpsTalentReportConfiguration(
            fromYear: $von,
            toYear: max($von, $bis),
            reference: $referenz,
            youthThreshold: $this->percent($request->query('youth'),
                WpsTalentReportConfiguration::DEFAULT_YOUTH_THRESHOLD),
            generalThreshold: $this->percent($request->query('general'),
                WpsTalentReportConfiguration::DEFAULT_GENERAL_THRESHOLD),
            course: in_array($bahn, [WpsRankingFilter::COURSE_LCM, WpsRankingFilter::COURSE_SCM], true)
                ? $bahn
                : WpsRankingFilter::COURSE_SCM,
            normType: in_array($norm, WpsTalentReportConfiguration::NORM_TYPES, true)
                ? $norm
                : WpsTalentReportConfiguration::NORM_MQS,
        );
    }

    private function percent(mixed $wert, float $standard): float
    {
        if (! is_numeric($wert)) {
            return $standard;
        }

        $zahl = (float) $wert;

        return $zahl > 0 && $zahl <= 100 ? $zahl : $standard;
    }
}
