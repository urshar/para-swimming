<?php

namespace App\Http\Controllers;

use App\Models\Championship;
use App\Services\PdfExportService;
use App\Services\QualificationEvaluationService;
use App\Support\QualificationAthleteSummary;
use App\Support\QualificationOverviewFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Die beiden Erfüllungsansichten und ihre PDF-Ausgabe (Spec §7.5, §11).
 *
 *   qualified()   — Wer hat sich qualifiziert, und wie weit fehlt den übrigen?
 *                   Nur reale Zeiten aus WPS-anerkannten Wettkämpfen.
 *   development() — Hat der Athlet international eine Chance?
 *                   Alles, einschließlich umgerechneter Kurzbahnzeiten, gekennzeichnet.
 *
 * Beide Bildschirmansichten sind Livewire-Komponenten; der Controller reicht nur die
 * Meisterschaft und die Vereinsbeschränkung durch. Die PDF-Ausgaben laufen dagegen hier, weil
 * sie einen vollständigen Antwortkörper liefern und keinen Zustand halten.
 *
 * Lesend, deshalb ohne RequireAdmin; Vereinsnutzer sehen nur die Athleten ihres Vereins.
 */
class ChampionshipQualificationController extends Controller
{
    public function __construct(
        private readonly QualificationEvaluationService $service,
        private readonly PdfExportService $pdfExportService,
    ) {}

    public function qualified(Championship $championship): View
    {
        return view('championships.qualified', [
            'championship' => $championship,
            'clubId' => $this->clubFilter(),
        ]);
    }

    public function development(Championship $championship): View
    {
        return view('championships.development', [
            'championship' => $championship,
            'clubId' => $this->clubFilter(),
        ]);
    }

    /**
     * PDF der Qualifikantenansicht (§11).
     *
     * Übernimmt den Filterstand aus der Adresse, damit das PDF denselben Ausschnitt zeigt wie
     * der Bildschirm, von dem aus es erzeugt wurde. Der Filter wird im Kopf des Dokuments
     * genannt — sonst sähe ein gefiltertes Blatt aus wie der vollständige Stand.
     *
     * Enthält keine umgerechneten Zeiten und damit regelmäßig auch nicht den Hinweis aus §11.
     */
    public function qualifiedPdf(Request $request, Championship $championship): Response
    {
        $filter = QualificationOverviewFilter::fromQuery($request->query());

        return $this->pdfExportService->stream(
            'pdf.championship-qualified',
            [
                'championship' => $championship,
                'groups' => $this->groupedOverview($championship, $filter),
                'filter' => $filter,
                'kaderReferenceDate' => $this->service->kaderReferenceDate($championship),
                'generatedAt' => now(),
            ],
            $this->filename($championship, 'qualifikanten'),
        );
    }

    /**
     * PDF der Förderansicht (§11) — hier kommen umgerechnete Zeiten und der Hinweis vor.
     *
     * Ohne Athletenauswahl enthält es alle gefilterten Athleten. Das ist der häufigere Fall
     * und soll keinen zusätzlichen Handgriff kosten.
     */
    public function developmentPdf(Request $request, Championship $championship): Response
    {
        $kader = trim((string) $request->query('kader', ''));
        $suche = trim((string) $request->query('q', ''));
        $auswahl = $this->athleteIds($request);

        $athleten = $this->service->developmentOverview($championship, $this->clubFilter());

        if ($kader !== '') {
            $athleten = $athleten->filter(
                static fn (QualificationAthleteSummary $e): bool => $e->kaderName === $kader
            );
        }

        if ($suche !== '') {
            $athleten = $athleten->filter(static fn (QualificationAthleteSummary $e): bool => str_contains(
                mb_strtolower((string) $e->athlete->full_name),
                mb_strtolower($suche)
            ));
        }

        if ($auswahl !== []) {
            $athleten = $athleten->filter(
                static fn (QualificationAthleteSummary $e): bool => in_array($e->athlete->getKey(), $auswahl, true)
            );
        }

        return $this->pdfExportService->stream(
            'pdf.championship-development',
            [
                'championship' => $championship,
                // Ohne Seiteneinteilung: Das PDF ist die vollständige Fassung, dafür ist es da.
                'entries' => $athleten
                    ->sortBy(static fn (QualificationAthleteSummary $e): string => (string) $e->athlete->last_name)
                    ->values(),
                'selectionActive' => $auswahl !== [],
                'kader' => $kader,
                'search' => $suche,
                'generatedAt' => now(),
            ],
            $this->filename($championship, 'foerderansicht'),
        );
    }

    /**
     * Die Athleten der Qualifikantenansicht, gefiltert und nach Kaderart gruppiert.
     *
     * Dieselbe Gliederung und derselbe Filter wie in der Livewire-Komponente — die Regeln
     * liegen in QualificationOverviewFilter, damit es sie nur einmal gibt.
     *
     * @return Collection<string, Collection<int, QualificationAthleteSummary>>
     */
    private function groupedOverview(Championship $championship, QualificationOverviewFilter $filter): Collection
    {
        return $filter
            ->applyToAthletes($this->service->qualificationOverview($championship, $this->clubFilter()))
            ->sortBy([
                static fn (QualificationAthleteSummary $e): int => $e->kaderSortOrder,
                static fn (QualificationAthleteSummary $e): string => (string) $e->athlete->last_name,
            ])
            ->groupBy(static fn (QualificationAthleteSummary $e): string => $e->kaderName ?? 'Ohne Kaderzuordnung');
    }

    /**
     * Die im PDF gewünschten Athleten aus der Adresse.
     *
     * Kommaliste, weil sie aus einem Link stammt und nicht aus einem Formular. Nicht
     * numerische Werte werden verworfen statt zu einem Fehler zu führen — ein von Hand
     * verändertes Adressfeld soll ein vollständiges PDF liefern, keine Ausnahme.
     *
     * @return list<int>
     */
    private function athleteIds(Request $request): array
    {
        $roh = (string) $request->query('athletes', '');

        if ($roh === '') {
            return [];
        }

        return collect(explode(',', $roh))
            ->map(static fn (string $wert): int => (int) trim($wert))
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function filename(Championship $championship, string $art): string
    {
        return sprintf(
            '%s-%s-%s.pdf',
            $art,
            str($championship->display_name)->slug(),
            now()->format('Y-m-d'),
        );
    }

    /** Vereinsnutzer sehen nur die Athleten ihres Vereins; Admins alle. */
    private function clubFilter(): ?int
    {
        $nutzer = auth()->user();

        return $nutzer?->is_admin === true ? null : $nutzer?->club_id;
    }
}
