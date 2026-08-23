<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Meet;
use App\Services\AnnualBestService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public\AnnualBestController
 *
 * Öffentliche Jahresbestleistungen (Spec public-frontend §5.4, Phase 7) — pro Person das
 * punktbeste Einzelergebnis eines Kalenderjahrs, siehe AnnualBestService. Athletennamen sind
 * unverlinkter Text (§2.3 Regel 2).
 *
 * Jahresauswahl direkt auf der Seite (Dropdown), wie bei der Cup-Wertung — die Spec-Routentabelle
 * listet nur /de/bestleistungen/{jahr}. Die zur Auswahl stehenden Jahre werden aus den
 * vorhandenen Veranstaltungen abgeleitet (kein eigenes "Saison"-Model). Ohne (oder mit
 * unbekanntem) Jahr gilt das laufende Kalenderjahr.
 */
class AnnualBestController extends Controller
{
    public function __construct(
        private readonly AnnualBestService $annualBestService,
    ) {}

    /**
     * $jahr kommt aus dem hübschen Pfadsegment (JS-Jahresauswahl, siehe View); ?year= ist der
     * Fallback des <noscript>-Formulars, das ohne JavaScript nur einen Query-Parameter an die
     * aktuelle URL anhängen kann, kein neues Pfadsegment.
     *
     * Bewusst $request->route('jahr') statt eines eigenen Methodenparameters — siehe
     * Public\CupRankingController::index() für die Begründung (derselbe {locale}-Fallstrick).
     */
    public function index(Request $request): View
    {
        $jahr = $request->route('jahr');
        $years = Meet::query()->pluck('start_date')->map(fn ($date) => $date->year)->unique()->sortDesc()->values();

        $requestedYear = $jahr !== null ? (int) $jahr : ($request->integer('year') ?: null);
        $year = $requestedYear !== null && $requestedYear > 0 ? $requestedYear : now()->year;

        // forYear() bleibt unangetastet (Tests!) — die beiden Sammel-Optionen "Alle Klassen"/
        // "Damen & Herren" (Rückmeldung: "ich meinte das alle gemeinsam über die Punkte gewertet
        // werden") kommen als zusätzliche, bereits selbst neu gerankte Buckets aus eigenen
        // Service-Methoden dazu — siehe AnnualBestService für die Begründung.
        $buckets = $this->annualBestService->forYear($year)
            ->concat($this->annualBestService->mergedGenderBuckets($year)->all())
            ->concat($this->annualBestService->mergedGroupBuckets($year)->all())
            ->values();

        return view('public.annual-best.index', [
            'years' => $years,
            'year' => $year,
            'buckets' => $buckets,
        ]);
    }
}
