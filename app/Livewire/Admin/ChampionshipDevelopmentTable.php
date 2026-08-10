<?php

namespace App\Livewire\Admin;

use App\Models\Championship;
use App\Models\KaderType;
use App\Services\QualificationEvaluationService;
use App\Support\QualificationAthleteSummary;
use App\Support\QualificationRow;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * ChampionshipDevelopmentTable
 *
 * Förderansicht (Spec §7.7): je Athlet alle Bewerbe mit Norm, mit umgerechneten Zeiten,
 * Abstand und Zielzeiten.
 *
 * Als Livewire-Komponente wegen der Athletenauswahl. Als gewöhnliche Seite mit
 * Seiteneinteilung verfiele jedes Häkchen beim Blättern, weil jeder Seitenwechsel ein neuer
 * Aufruf ist — und genau die Auswahl über mehrere Seiten hinweg wird gebraucht, wenn
 * dreißig Athleten in der Liste stehen.
 *
 * Rein lesend; die Beschränkung auf den eigenen Verein liegt beim Aufrufer.
 */
class ChampionshipDevelopmentTable extends Component
{
    use WithPagination;

    private const int ATHLETES_PER_PAGE = 10;

    public Championship $championship;

    /** Einschränkung auf einen Verein; null für Admins. */
    public ?int $clubId = null;

    public string $search = '';

    public string $filterKader = '';

    /** @var array<int, int> Athleten-IDs, die ins PDF sollen. */
    public array $selected = [];

    /**
     * Wirkt die Auswahl auch auf den Bildschirm?
     *
     * Standardmäßig nicht: Zum Vergleichen will man die übrigen Athleten weiter sehen. Wer
     * die Auswahl prüfen möchte, schaltet um.
     */
    public bool $onlySelected = false;

    private ?QualificationEvaluationService $service = null;

    public function mount(Championship $championship, ?int $clubId): void
    {
        $this->championship = $championship;
        $this->clubId = $clubId;
    }

    public function setFilter(string $feld, string $wert): void
    {
        match ($feld) {
            'kader' => $this->filterKader = $wert,
            default => null,
        };

        $this->resetPage();
        $this->refresh();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->refresh();
    }

    public function toggleAthlete(int $athleteId): void
    {
        if (in_array($athleteId, $this->selected, true)) {
            $this->selected = array_values(array_diff($this->selected, [$athleteId]));

            return;
        }

        $this->selected[] = $athleteId;
    }

    /** Alle Athleten der aktuellen Seite auswählen. */
    public function selectPage(): void
    {
        foreach ($this->page() as $eintrag) {
            $id = $eintrag->athlete->getKey();

            if (! in_array($id, $this->selected, true)) {
                $this->selected[] = $id;
            }
        }
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->onlySelected = false;

        $this->resetPage();
        $this->refresh();
    }

    public function toggleOnlySelected(): void
    {
        $this->onlySelected = ! $this->onlySelected;

        $this->resetPage();
        $this->refresh();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->filterKader = '';

        $this->resetPage();
        $this->refresh();
    }

    public function isSelected(int $athleteId): bool
    {
        return in_array($athleteId, $this->selected, true);
    }

    /**
     * Abfrageparameter für den PDF-Link.
     *
     * Ohne Auswahl bleibt der Parameter leer und das PDF enthält alle gefilterten Athleten —
     * das ist der häufigere Fall und soll keinen zusätzlichen Handgriff kosten.
     *
     * @return array<string, string>
     */
    public function pdfQuery(): array
    {
        return array_filter([
            'athletes' => implode(',', $this->selected),
            'kader' => $this->filterKader,
            'q' => $this->search,
        ], static fn (string $wert): bool => $wert !== '');
    }

    /**
     * Vollständige Adresse der PDF-Ausgabe.
     *
     * Im Code gebaut, nicht im Blade-Attribut: Dort wäre es eine Ternäroperation mit leerem
     * String, an der sich der Blade-Parser von PhpStorm verschluckt.
     */
    public function pdfUrl(): string
    {
        $parameter = $this->pdfQuery();

        return route('championships.development.pdf', $this->championship)
            .($parameter === [] ? '' : '?'.http_build_query($parameter));
    }

    /**
     * Alle Athleten nach Filter und gegebenenfalls Auswahl.
     *
     * @return Collection<int, QualificationAthleteSummary>
     */
    #[Computed]
    public function athletes(): Collection
    {
        $athleten = $this->service()->developmentOverview($this->championship, $this->clubId);

        if ($this->search !== '') {
            $athleten = $athleten->filter(fn (QualificationAthleteSummary $e): bool => str_contains(
                mb_strtolower((string) $e->athlete->full_name),
                mb_strtolower($this->search)
            ));
        }

        if ($this->filterKader !== '') {
            $athleten = $athleten->filter(
                fn (QualificationAthleteSummary $e): bool => $e->kaderName === $this->filterKader
            );
        }

        if ($this->onlySelected && $this->selected !== []) {
            $athleten = $athleten->filter(
                fn (QualificationAthleteSummary $e): bool => $this->isSelected($e->athlete->getKey())
            );
        }

        return $athleten
            ->sortBy(static fn (QualificationAthleteSummary $e): string => (string) $e->athlete->last_name)
            ->values();
    }

    /**
     * Die Athleten der aktuellen Seite.
     *
     * Gezählt werden Athleten, nicht Zeilen: Jeder Athlet ist eine eigene Tabelle, ihn über
     * zwei Seiten zu zerreißen wäre unlesbar.
     *
     * @return LengthAwarePaginator<int, QualificationAthleteSummary>
     */
    #[Computed]
    public function page(): LengthAwarePaginator
    {
        $alle = $this->athletes();
        $seite = Paginator::resolveCurrentPage();

        return new Paginator(
            $alle->forPage($seite, self::ATHLETES_PER_PAGE)->values(),
            $alle->count(),
            self::ATHLETES_PER_PAGE,
            $seite,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }

    /** @return Collection<int, KaderType> */
    #[Computed]
    public function kaderTypes(): Collection
    {
        return KaderType::query()->active()->orderBy('sort_order')->get();
    }

    /**
     * Bewerbe ohne ausgeschriebene Norm, je Athlet.
     *
     * Sie werden nicht als Zeile geführt, aber benannt — sonst entstünde der Eindruck, der
     * Athlet sei dort gar nicht angetreten (§7.4).
     *
     * @return Collection<int, string>
     */
    public function eventsWithoutStandard(QualificationAthleteSummary $eintrag): Collection
    {
        return $eintrag->rowsWithoutStandard
            ->map(static fn (QualificationRow $z): string => $z->eventLabel)
            ->unique()
            ->sort()
            ->values();
    }

    public function render(): View
    {
        return view('livewire.admin.championship-development-table');
    }

    private function refresh(): void
    {
        unset($this->athletes, $this->page);
    }

    private function service(): QualificationEvaluationService
    {
        return $this->service ??= app(QualificationEvaluationService::class);
    }
}
