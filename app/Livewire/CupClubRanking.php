<?php

namespace App\Livewire;

use App\Models\Cup;
use App\Services\CupClubRankingService;
use App\Services\CupStalenessService;
use App\Support\ClubRankingConfiguration;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
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

    private ?CupClubRankingService $service = null;

    private ?CupStalenessService $stalenessService = null;

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
        unset($this->cup, $this->ranking, $this->calculatedAt, $this->isStale, $this->staleReason);
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
