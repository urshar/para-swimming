<?php

namespace App\Http\Controllers;

use App\Models\Meet;
use App\Services\PdfExportService;
use App\Services\WpsRankingService;
use App\Support\WpsRankingEntry;
use App\Support\WpsRankingFilter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF-Ausgabe der WPS-Ranglisten (Spec "WPS Rankings" §11).
 *
 * Die Bildschirmansicht ist eine Livewire-Komponente; hier läuft nur die PDF-Ausgabe, weil
 * sie einen vollständigen Antwortkörper liefert und keinen Zustand hält.
 *
 * Der Filter kommt über `WpsRankingFilter::fromQuery()` aus der Adresse — dieselbe Definition
 * wie am Bildschirm, damit das PDF denselben Ausschnitt zeigt wie die Ansicht, von der aus es
 * erzeugt wurde.
 *
 * Lesend und verbandsweit ([R2]).
 */
class WpsRankingController extends Controller
{
    public function __construct(
        private readonly WpsRankingService $service,
        private readonly PdfExportService $pdfExportService,
    ) {}

    public function pdf(Request $request): Response
    {
        $filter = WpsRankingFilter::fromQuery($request->query());
        $eintraege = $this->service->ranking($filter);

        return $this->pdfExportService->stream(
            'pdf.wps-ranking',
            [
                'filter' => $filter,
                'entries' => $eintraege,
                'withoutBirthDate' => $this->service->withoutBirthDate($filter),
                'versions' => $this->service->usedVersions($eintraege),
                'meetName' => $this->meetName($filter),
                // Der Hinweis nach §11.4 erscheint, sobald ein Ergebnis auf umgerechneten
                // Parametern beruht.
                'hasEstimated' => $eintraege->contains(
                    static fn (WpsRankingEntry $e): bool => $e->isEstimated()
                ),
                // Der Zusatz aus wps-points §9.6 gilt für Jugend- und Nachwuchsranglisten:
                // Der Umrechnungsfaktor ist an international startenden Athletinnen und
                // Athleten geeicht und fällt für den Nachwuchs zu optimistisch aus.
                'isYouth' => $filter->maxAge !== null || $filter->ageGroupId !== null,
                'generatedAt' => now(),
            ],
            $this->filename($filter),
            'landscape',
        );
    }

    /** Name der Veranstaltung, sofern die Rangliste auf eine eingeschränkt ist. */
    private function meetName(WpsRankingFilter $filter): ?string
    {
        return $filter->meetId === null
            ? null
            : Meet::query()->whereKey($filter->meetId)->value('name');
    }

    private function filename(WpsRankingFilter $filter): string
    {
        return sprintf(
            'wps-rangliste-%s-%s.pdf',
            str($filter->typeLabel())->slug(),
            now()->format('Y-m-d'),
        );
    }
}
