<?php

namespace App\Livewire;

use App\Models\Athlete;
use App\Models\AthletePerformanceNote;
use App\Models\Result;
use App\Services\AthletePerformanceNoteService;
use App\Services\WpsChartService;
use App\Services\WpsAthleteAnalysisService;
use App\Support\WpsAthleteProfile;
use App\Support\WpsChartSeries;
use App\Support\WpsRankingFilter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * WpsAthleteAnalysis
 *
 * Athletenprofil mit Leistungsentwicklung (Spec "WPS Rankings" §7).
 *
 * Zeigt standardmäßig die gesamte Historie; der Zeitraum lässt sich einschränken. Rein
 * lesend, verbandsweit wie die Ranglisten.
 */
class WpsAthleteAnalysis extends Component
{
    public Athlete $athlete;

    public string $fromYear = '';

    public string $toYear = '';

    public string $course = WpsRankingFilter::COURSE_MIXED;

    /** Jeden Start zeigen statt nur der besten Leistung je Saison. */
    public bool $allStarts = false;

    /** Verlaufsgrafik je Bewerb einblenden. */
    public bool $showCharts = true;

    /**
     * Maß der Grafik: Zeit oder WPS-Punkte.
     *
     * Zeit als Vorbelegung — sie liegt bei jedem Ergebnis vor, Punkte oft nur bei einem
     * Bruchteil.
     */
    public string $chartMetric = WpsChartService::METRIC_TIME;

    // ── Formular "Notiz hinzufügen" ──────────────────────────────────────────

    /**
     * Ist das Notizformular geöffnet?
     *
     * Eine eigene Eigenschaft statt einer Ableitung aus den Feldinhalten: Ob das Formular
     * offen ist, ist ein Zustand für sich — aus "Text leer" ließe sich nicht unterscheiden,
     * ob es geschlossen oder gerade erst geöffnet ist.
     */
    public bool $noteFormOpen = false;

    public ?int $noteResultId = null;

    public string $noteCategory = AthletePerformanceNote::CATEGORY_OTHER;

    public string $noteText = '';

    public string $noteDate = '';

    public ?string $statusMessage = null;

    /**
     * Bewerbe, die ins PDF sollen.
     *
     * Leer heißt: alle. Das ist der häufigere Fall und soll keinen Handgriff kosten — dieselbe
     * Regel wie bei der Athletenauswahl der Förderansicht.
     *
     * @var array<int, string>
     */
    public array $selectedEvents = [];

    private ?WpsAthleteAnalysisService $service = null;

    public function mount(Athlete $athlete): void
    {
        $this->athlete = $athlete;
    }

    public function setInput(string $feld, string $wert): void
    {
        match ($feld) {
            'fromYear' => $this->fromYear = $wert,
            'toYear' => $this->toYear = $wert,
            'course' => $this->course = $wert,
            'chartMetric' => $this->chartMetric = $wert,
            default => null,
        };

        // Ein Zeitraum, dessen Ende vor dem Beginn liegt, ergibt keine Auswertung; statt einer
        // Fehlermeldung wird die andere Grenze nachgezogen.
        $von = $this->intOrNull($this->fromYear);
        $bis = $this->intOrNull($this->toYear);

        if ($von !== null && $bis !== null && $bis < $von) {
            $feld === 'fromYear' ? $this->toYear = $this->fromYear : $this->fromYear = $this->toYear;
        }

        $this->refresh();
    }

    public function toggleAllStarts(): void
    {
        $this->allStarts = ! $this->allStarts;

        $this->refresh();
    }

    /**
     * Bewerb für die PDF-Ausgabe an- oder abwählen.
     *
     * Wirkt nur auf das PDF, nicht auf den Bildschirm: Am Bildschirm scrollt man ohnehin, und
     * eine Auswahl, die auch die Ansicht beschneidet, machte das Vergleichen umständlich.
     */
    public function toggleEvent(string $eventLabel): void
    {
        $this->selectedEvents = in_array($eventLabel, $this->selectedEvents, true)
            ? array_values(array_diff($this->selectedEvents, [$eventLabel]))
            : [...$this->selectedEvents, $eventLabel];
    }

    public function isEventSelected(string $eventLabel): bool
    {
        return in_array($eventLabel, $this->selectedEvents, true);
    }

    public function clearEventSelection(): void
    {
        $this->selectedEvents = [];
    }

    public function toggleCharts(): void
    {
        $this->showCharts = ! $this->showCharts;

        $this->refresh();
    }

    /**
     * Die Verlaufsgrafiken je Bewerb.
     *
     * Notizen fließen als Markierungen ein — aber nur, wenn der Nutzer sie sehen darf: Über
     * eine Markierung ohne Beschriftung ließe sich sonst erschließen, dass es zu einem
     * Zeitpunkt eine Gesundheitsangabe gibt (§7.5).
     *
     * @return array<string, WpsChartSeries>
     */
    #[Computed]
    public function charts(): array
    {
        if (! $this->showCharts) {
            return [];
        }

        $dienst = app(WpsChartService::class);
        $notizen = $this->canViewNotes() ? $this->notes() : collect();
        $grafiken = [];

        foreach ($this->profile()->byEvent as $bewerb => $zeilen) {
            $grafiken[$bewerb] = $dienst->series($bewerb, $zeilen, $notizen, $this->chartMetric);
        }

        return $grafiken;
    }

    /**
     * Öffnet das Notizformular für einen Start — oder für keinen.
     *
     * Bei einem Start wird das Datum aus dem Wettkampf übernommen; zwei abweichende Daten
     * wären eine Widersprüchlichkeit, die niemand auflösen kann.
     */
    public function startNote(?int $resultId): void
    {
        $this->requireNotePermission();

        $this->noteFormOpen = true;
        $this->noteResultId = $resultId;
        $this->noteCategory = AthletePerformanceNote::CATEGORY_OTHER;
        $this->noteText = '';
        $this->noteDate = date('Y-m-d');
        $this->statusMessage = null;
    }

    public function cancelNote(): void
    {
        $this->noteFormOpen = false;
        $this->noteResultId = null;
        $this->noteText = '';
        $this->resetErrorBag();
    }

    public function saveNote(AthletePerformanceNoteService $service): void
    {
        $this->requireNotePermission();

        $daten = $this->validate([
            'noteCategory' => ['required', 'in:'.implode(',', AthletePerformanceNote::CATEGORIES)],
            'noteText' => ['required', 'string', 'min:3', 'max:2000'],
            'noteDate' => ['required', 'date'],
        ]);

        $ergebnis = $this->noteResultId === null
            ? null
            : Result::query()->where('athlete_id', $this->athlete->getKey())
                ->whereKey($this->noteResultId)
                ->first();

        $service->create(
            $this->athlete,
            $daten['noteCategory'],
            $daten['noteText'],
            $ergebnis,
            $daten['noteDate'],
            auth()->id(),
        );

        $this->cancelNote();
        $this->statusMessage = 'Notiz gespeichert.';

        $this->refresh();
    }

    public function deleteNote(int $noteId, AthletePerformanceNoteService $service): void
    {
        $notiz = AthletePerformanceNote::query()
            ->where('athlete_id', $this->athlete->getKey())
            ->whereKey($noteId)
            ->first();

        if ($notiz === null) {
            return;
        }

        // Die Route-Middleware prüft nur den ersten Seitenaufruf; jedes spätere
        // Livewire-Update kommt als eigener Request an.
        abort_unless(auth()->user()?->can('delete', $notiz) === true, 403);

        $service->delete($notiz);

        $this->statusMessage = 'Notiz gelöscht.';
        $this->refresh();
    }

    /** Darf der angemeldete Nutzer die Notizen dieses Athleten sehen? */
    public function canViewNotes(): bool
    {
        return auth()->user()?->can('viewForAthlete', [AthletePerformanceNote::class, $this->athlete]) === true;
    }

    public function canDeleteNote(AthletePerformanceNote $note): bool
    {
        return auth()->user()?->can('delete', $note) === true;
    }

    /**
     * Notizen des Athleten im gewählten Zeitraum.
     *
     * Leer, wenn der Nutzer sie nicht sehen darf: Krankheit und Verletzung sind
     * Gesundheitsangaben und nicht verbandsweit sichtbar (§7.5).
     *
     * @return Collection<int, AthletePerformanceNote>
     */
    #[Computed]
    public function notes(): Collection
    {
        if (! $this->canViewNotes()) {
            return collect();
        }

        return app(AthletePerformanceNoteService::class)->forAthlete(
            $this->athlete,
            $this->intOrNull($this->fromYear),
            $this->intOrNull($this->toYear),
        );
    }

    /**
     * Notizen je Ergebnis — für die Anzeige an der jeweiligen Zeile.
     *
     * @return array<int, list<AthletePerformanceNote>>
     */
    #[Computed]
    public function notesByResult(): array
    {
        return app(AthletePerformanceNoteService::class)->indexByResult($this->notes());
    }

    /**
     * Notizen ohne Ergebnisbezug — sie gelten für einen Zeitpunkt, nicht für einen Start.
     *
     * @return Collection<int, AthletePerformanceNote>
     */
    #[Computed]
    public function generalNotes(): Collection
    {
        return app(AthletePerformanceNoteService::class)->withoutResult($this->notes());
    }

    public function resetPeriod(): void
    {
        $this->fromYear = '';
        $this->toYear = '';

        $this->refresh();
    }

    #[Computed]
    public function profile(): WpsAthleteProfile
    {
        return $this->service()->profile(
            $this->athlete,
            $this->intOrNull($this->fromYear),
            $this->intOrNull($this->toYear),
            $this->course,
            $this->allStarts,
        );
    }

    /** @return list<int> */
    #[Computed]
    public function years(): array
    {
        return $this->service()->yearsWithResults($this->athlete);
    }

    /**
     * @param  bool  $withNotes  Notizen ins PDF übernehmen — standardmäßig nicht (§7.5)
     */
    public function pdfUrl(bool $withNotes = false): string
    {
        $parameter = array_filter([
            'from' => $this->fromYear,
            'to' => $this->toYear,
            'course' => $this->course,
            'metric' => $this->chartMetric,
            // Bewerbe als Liste; der Trenner ist ein senkrechter Strich, weil die
            // Bezeichnungen selbst Kommas enthalten könnten.
            'events' => implode('|', $this->selectedEvents),
            'notes' => $withNotes ? '1' : '',
        ], static fn (string $wert): bool => $wert !== '');

        return route('wps.athletes.pdf', $this->athlete)
            .($parameter === [] ? '' : '?'.http_build_query($parameter));
    }

    public function render(): View
    {
        return view('livewire.wps-athlete-analysis');
    }

    private function refresh(): void
    {
        unset(
            $this->profile,
            $this->notes,
            $this->notesByResult,
            $this->generalNotes,
            $this->charts,
        );
    }

    /**
     * Notizen darf nur schreiben, wer sie auch sehen darf: die Verbandsverwaltung und der
     * eigene Verein.
     */
    private function requireNotePermission(): void
    {
        abort_unless($this->canViewNotes(), 403);
    }

    private function intOrNull(string $wert): ?int
    {
        $bereinigt = trim($wert);

        return $bereinigt === '' || ! is_numeric($bereinigt) ? null : (int) $bereinigt;
    }

    private function service(): WpsAthleteAnalysisService
    {
        return $this->service ??= app(WpsAthleteAnalysisService::class);
    }
}
