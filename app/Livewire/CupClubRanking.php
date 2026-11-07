<?php

namespace App\Livewire;

use App\Models\Cup;
use App\Models\Meet;
use App\Services\ClubRankingEntryProjectionService;
use App\Services\CupClubRankingForecastService;
use App\Services\CupClubRankingService;
use App\Services\CupStalenessService;
use App\Support\ClubForecastComparison;
use App\Support\ClubRankingConfiguration;
use App\Support\ClubRankingForecast;
use App\Support\CountedAthleteBreakdown;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * CupClubRanking
 *
 * Vereinswertung des ÖBSV Cups (Spec §13) als Livewire-Komponente — Umstellung von
 * GET-Parametern + vollem Reload auf reaktive Filter (Admin-UI-Rework Phase 12, Erik,
 * 04.09.2026: "Da wir ja alles einheitlich machen werden, würde ich [Livewire] bevorzugen"),
 * konsistent mit den WPS-Auswertungen aus Phase 11.
 *
 * Zeigt wahlweise die klassische Startwertung (System A) oder die leistungsorientierte
 * Wertung (System B) für ein Cup-Jahr. Beide werden dynamisch berechnet und nicht
 * persistiert. Die Anzeige ist für alle angemeldeten Nutzer offen (analog Tages-/
 * Gesamtwertung). Der PDF-Export bleibt ein klassischer Controller-Download mit denselben
 * Filtern als Query-Parameter — ein Download lässt sich nicht über eine Livewire-Aktion
 * ausliefern.
 *
 * Prognose (docs/specs/cup-club-ranking-forecast.md): Gibt es im Cup ein Meet mit Meldungen,
 * aber ohne Ergebnisse, können Admins auf die voraussichtliche Endwertung umschalten. Der
 * Admin-Check läuft bei jeder Auswertung serverseitig (canForecast()), nicht nur beim
 * Umschalten — $forecast ist eine öffentliche, vom Client setzbare Eigenschaft.
 */
class CupClubRanking extends Component
{
    /** @var list<string> */
    private const array SYSTEMS = ['start', 'performance'];

    public int $cupId;

    public string $system = 'performance';

    public bool $includeForeign;

    /** String statt bool: Läuft über den x-model/$watch-Umweg für flux:select (bubbles:false). */
    public string $kaderCount;

    /** Prognose angefordert (wirksam nur für Admins und mit Prognose-Meet, siehe forecastActive()). */
    public bool $forecast = false;

    private ?CupClubRankingService $service = null;

    private ?CupStalenessService $stalenessService = null;

    /*
     * Zwischenspeicher der Prognose innerhalb eines Requests. Bewusst eigene Felder statt
     * #[Computed]: Livewire cached Computed-Werte nur beim Zugriff als Eigenschaft
     * ($this->forecastResult), nicht beim Methodenaufruf ($this->forecastResult()), wie ihn die
     * View je Tabellenzeile macht — die Prognose (mehrere Sekunden) lief dadurch je Zeile neu
     * und die Seite brach mit einem Gateway-Timeout ab. Private Felder werden von Livewire nicht
     * serialisiert und sind bei jedem Request leer.
     */
    private ?EloquentCollection $forecastMeetsMemo = null;

    private ?ClubRankingForecast $forecastResultMemo = null;

    private bool $forecastResultResolved = false;

    /**
     * Anfangszustand kommt aus der Query-String (?system=&foreign=&kader=), fehlt sie, aus der
     * Konfiguration — so bleibt eine bestehende URL (Lesezeichen, geteilter Link, vorheriger
     * PDF-Export) weiterhin direkt aufrufbar und zeigt denselben Stand. Änderungen danach
     * laufen rein reaktiv über die Livewire-Komponente, ohne Query-String-Sync (wie die
     * WPS-Auswertungen aus Phase 11).
     */
    public function mount(Cup $cup, Request $request): void
    {
        $this->cupId = $cup->id;

        $this->system = in_array($request->query('system'), self::SYSTEMS, true)
            ? $request->query('system')
            : 'performance';

        $this->includeForeign = $request->has('foreign')
            ? $request->boolean('foreign')
            : (bool) config('cup_club_ranking.include_foreign_clubs', false);

        $this->kaderCount = $request->has('kader')
            ? (string) max(0, min($this->maxCountedAthletes(), (int) $request->query('kader')))
            : (string) config('cup_club_ranking.counted_kader_athletes_per_club', 0);

        $this->forecast = $request->boolean('forecast') && $this->isAdmin();
    }

    /**
     * Whitelist statt dynamischer Zuweisung, wie bei den WPS-Auswertungen (siehe
     * resources/js/wps-livewire-filters.js) — bedient Cup-Wahl und Kaderathleten-Anzahl, beide
     * über flux:select (Erik, 04.09.2026: bleiben bewusst Dropdowns, nur Wertungssystem/Ausland
     * dazwischen sind Buttons).
     */
    public function setFilter(string $feld, string $wert): void
    {
        match ($feld) {
            'cupId' => $this->cupId = (int) $wert,
            'kaderCount' => $this->kaderCount = (string) max(0, min($this->maxCountedAthletes(), (int) $wert)),
            default => null,
        };

        $this->afterFilterChange();
    }

    public function setSystem(string $system): void
    {
        if (in_array($system, self::SYSTEMS, true)) {
            $this->system = $system;
        }

        $this->afterFilterChange();
    }

    public function toggleForeign(): void
    {
        $this->includeForeign = ! $this->includeForeign;

        $this->afterFilterChange();
    }

    /** Prognose an/aus — nur Admins (Livewire-Aktionen prüfen selbst, siehe CLAUDE.md). */
    public function toggleForecast(): void
    {
        abort_unless($this->isAdmin(), 403);

        $this->forecast = ! $this->forecast;

        $this->afterFilterChange();
    }

    /**
     * Prognose-Meets des gewählten Cups (mit Meldungen, ohne Ergebnisse); für Nicht-Admins
     * immer leer, damit weder Umschalter noch Hinweis erscheinen.
     *
     * @return EloquentCollection<int, Meet>
     */
    public function forecastMeets(): EloquentCollection
    {
        if (! $this->isAdmin()) {
            return new EloquentCollection;
        }

        return $this->forecastMeetsMemo ??= app(ClubRankingEntryProjectionService::class)->forecastMeets($this->cup());
    }

    /** Darf und kann der Nutzer die Prognose für diesen Cup einschalten? */
    public function canForecast(): bool
    {
        return $this->forecastMeets()->isNotEmpty();
    }

    /** Zeigt die Seite gerade die Prognose? */
    public function forecastActive(): bool
    {
        return $this->forecast && $this->canForecast();
    }

    /**
     * Prognose für das gewählte Wertungssystem; null, wenn die Prognose nicht aktiv ist. Je
     * Request nur einmal berechnet (siehe $forecastResultMemo).
     */
    public function forecastResult(): ?ClubRankingForecast
    {
        if ($this->forecastResultResolved) {
            return $this->forecastResultMemo;
        }

        $this->forecastResultResolved = true;

        if (! $this->forecastActive()) {
            return $this->forecastResultMemo = null;
        }

        $service = app(CupClubRankingForecastService::class);

        return $this->forecastResultMemo = $this->system === 'start'
            ? $service->forecastStartRanking($this->cup(), $this->includeForeign)
            : $service->forecastPerformanceRanking($this->cup(), $this->clubRankingConfiguration());
    }

    /** Vergleich eines Vereins mit der aktuellen Wertung (nur bei aktiver Prognose). */
    public function comparisonFor(int $clubId): ?ClubForecastComparison
    {
        return $this->forecastResult()?->comparisons->get($clubId);
    }

    /**
     * Anzeige der Rangänderung: sichtbarer Kurztext (Pfeil + Zahl bzw. "neu") und ein
     * ausgeschriebener Text für Screenreader — die Richtung steckt nicht nur in der Farbe.
     *
     * @return array{text: string, label: string, tone: string}
     */
    public function rankChangeDisplay(int $clubId): array
    {
        $change = $this->comparisonFor($clubId)?->rankChange;

        return match (true) {
            $change === null => ['text' => 'neu', 'label' => 'bisher nicht gewertet', 'tone' => 'neutral'],
            $change > 0 => [
                'text' => '▲ '.$change, 'label' => $change.($change === 1 ? ' Platz' : ' Plätze').' besser',
                'tone' => 'up',
            ],
            $change < 0 => [
                'text' => '▼ '.abs($change), 'label' => abs($change).($change === -1 ? ' Platz' : ' Plätze').' schlechter',
                'tone' => 'down',
            ],
            default => ['text' => '–', 'label' => 'unverändert', 'tone' => 'neutral'],
        };
    }

    /**
     * Differenz Prognose − aktuelle Wertung mit Vorzeichen: Starts ganzzahlig, Punkte mit zwei
     * Nachkommastellen (deutsches Zahlenformat wie die Gesamtpunkte).
     */
    public function valueDeltaDisplay(int $clubId): string
    {
        return $this->comparisonFor($clubId)?->formattedValueDelta($this->system === 'start' ? 0 : 2) ?? '';
    }

    /**
     * Gewertete Meet-Punkte eines Athleten mit Kennzeichnung prognostizierter Beiträge (aus
     * Meldezeit, ggf. aus anderer Bahnlänge).
     *
     * @return list<array{points: int, note: ?string}>
     */
    public function meetPointParts(CountedAthleteBreakdown $athlete): array
    {
        $forecast = $this->forecastResult();

        if ($forecast) {
            return $forecast->meetPointParts($athlete);
        }

        return array_map(fn (int $points): array => ['points' => $points, 'note' => null], $athlete->meetPoints);
    }

    /**
     * Nicht bewertbare Meldungen der Prognose, gezählt je Grund (nur Leistungswertung).
     *
     * @return Collection<string, int>
     */
    #[Computed]
    public function unratedSummary(): Collection
    {
        return $this->forecastResult()?->unratedSummary() ?? collect();
    }

    #[Computed]
    public function cup(): Cup
    {
        return Cup::findOrFail($this->cupId);
    }

    /** @return Collection<int, Cup> */
    #[Computed]
    public function cups(): Collection
    {
        return Cup::orderByDesc('year')->get(['id', 'year', 'name']);
    }

    #[Computed]
    public function maxCountedAthletes(): int
    {
        return (int) config('cup_club_ranking.max_counted_athletes_per_club', 5);
    }

    /** @return Collection<int, object> */
    #[Computed]
    public function ranking(): Collection
    {
        $forecast = $this->forecastResult();

        if ($forecast) {
            return $forecast->ranking;
        }

        if ($this->system === 'start') {
            return $this->service()->calculateStartRanking($this->cup(), $this->includeForeign);
        }

        return $this->service()->calculatePerformanceRanking($this->cup(), $this->clubRankingConfiguration());
    }

    #[Computed]
    public function calculatedAt(): ?Carbon
    {
        return $this->system === 'performance' ? $this->stalenessStatus()['calculatedAt'] : null;
    }

    #[Computed]
    public function isStale(): bool
    {
        return $this->system === 'performance' && $this->stalenessStatus()['isStale'];
    }

    #[Computed]
    public function staleReason(): ?string
    {
        return $this->system === 'performance' ? $this->stalenessStatus()['reason'] : null;
    }

    /**
     * Link zum PDF-Export mit dem aktuellen Filterstand — im Code gebaut, nicht im
     * Blade-Attribut (siehe WpsRankings::pdfUrl()).
     */
    public function pdfUrl(int $detail): string
    {
        return route('cups.club-ranking.pdf', [
            'cup' => $this->cupId,
            'system' => $this->system,
            'foreign' => $this->includeForeign ? 1 : 0,
            'kader' => $this->kaderCount,
            'detail' => $detail,
            'forecast' => $this->forecastActive() ? 1 : 0,
        ]);
    }

    public function render(): View
    {
        return view('livewire.cup-club-ranking');
    }

    /**
     * Nach jeder Filteränderung: zwischengespeicherte Computed-Werte verwerfen, damit sie
     * innerhalb desselben Requests neu ausgewertet werden.
     */
    private function afterFilterChange(): void
    {
        unset($this->cup, $this->ranking, $this->calculatedAt, $this->isStale, $this->staleReason,
            $this->unratedSummary);

        $this->forecastMeetsMemo = null;
        $this->forecastResultMemo = null;
        $this->forecastResultResolved = false;
    }

    private function isAdmin(): bool
    {
        return auth()->user()?->isAdmin() === true;
    }

    private function clubRankingConfiguration(): ClubRankingConfiguration
    {
        return ClubRankingConfiguration::fromConfig()
            ->withIncludeForeignClubs($this->includeForeign)
            ->withCountedKaderAthletesPerClub(max(0, min($this->maxCountedAthletes(), (int) $this->kaderCount)));
    }

    /** @return array{calculatedAt: ?Carbon, isStale: bool, reason: ?string} */
    private function stalenessStatus(): array
    {
        return $this->stalenessService()->clubRankingStatus($this->cup());
    }

    private function service(): CupClubRankingService
    {
        return $this->service ??= app(CupClubRankingService::class);
    }

    private function stalenessService(): CupStalenessService
    {
        return $this->stalenessService ??= app(CupStalenessService::class);
    }
}
