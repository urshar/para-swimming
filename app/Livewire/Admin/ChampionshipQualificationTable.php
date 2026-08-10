<?php

namespace App\Livewire\Admin;

use App\Models\Championship;
use App\Models\KaderType;
use App\Services\QualificationEvaluationService;
use App\Support\QualificationAthleteSummary;
use App\Support\QualificationOverviewFilter;
use App\Support\QualificationRow;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * ChampionshipQualificationTable
 *
 * Qualifikantenansicht je Athlet (Spec §7.5): Kaderart → Athlet → Bewerbe mit Norm.
 *
 * Als Livewire-Komponente wegen der Filter und des Aufklappens des Leistungsverlaufs. Rein
 * lesend; sie schreibt nichts und braucht deshalb auch keine Adminprüfung — die Beschränkung
 * auf den eigenen Verein liegt beim Aufrufer.
 */
class ChampionshipQualificationTable extends Component
{
    public Championship $championship;

    /** Einschränkung auf einen Verein; null für Admins. */
    public ?int $clubId = null;

    /** alle | met | open — welche Bewerbszeilen gezeigt werden. */
    public string $filterFulfilment = 'alle';

    public string $filterKader = '';

    public string $search = '';

    /** Aufgeklappte Bewerbszeilen, als "athletId-bewerb". */
    public array $expanded = [];

    /** Nicht öffentlich, damit Livewire den Service nicht zu serialisieren versucht. */
    private ?QualificationEvaluationService $service = null;

    public function mount(Championship $championship, ?int $clubId): void
    {
        $this->championship = $championship;
        $this->clubId = $clubId;
    }

    /**
     * Der Filterstand als Objekt — dieselbe Fassung, die auch der PDF-Controller verwendet.
     *
     * Zwei Ausprägungen derselben Regeln liefen früher oder später auseinander, und dann
     * zeigte das PDF etwas anderes als der Bildschirm, von dem aus es erzeugt wurde.
     */
    #[Computed]
    public function filter(): QualificationOverviewFilter
    {
        return new QualificationOverviewFilter(
            $this->filterFulfilment,
            $this->filterKader,
            $this->search,
        );
    }

    /**
     * Vollständige Adresse der PDF-Ausgabe, mit dem aktuellen Filterstand.
     *
     * Im Code gebaut, nicht im Blade-Attribut: Dort wäre es eine Ternäroperation mit leerem
     * String, an der sich der Blade-Parser von PhpStorm verschluckt.
     */
    public function pdfUrl(): string
    {
        $parameter = $this->filter()->toQuery();

        return route('championships.qualified.pdf', $this->championship)
            .($parameter === [] ? '' : '?'.http_build_query($parameter));
    }

    public function setFilter(string $feld, string $wert): void
    {
        match ($feld) {
            'fulfilment' => $this->filterFulfilment = $wert,
            'kader' => $this->filterKader = $wert,
            default => null,
        };

        // Aufgeklappte Zeilen zurücksetzen: Nach dem Filtern zeigen sie auf Bewerbe, die
        // womöglich gar nicht mehr sichtbar sind.
        $this->expanded = [];

        unset($this->filter, $this->groups);
    }

    public function toggle(string $schluessel): void
    {
        if (in_array($schluessel, $this->expanded, true)) {
            $this->expanded = array_values(array_diff($this->expanded, [$schluessel]));

            return;
        }

        $this->expanded[] = $schluessel;
    }

    public function updatedSearch(): void
    {
        unset($this->filter, $this->groups);
    }

    public function resetFilters(): void
    {
        $this->filterFulfilment = 'alle';
        $this->filterKader = '';
        $this->search = '';
        $this->expanded = [];

        unset($this->filter, $this->groups);
    }

    /**
     * Athleten, gruppiert nach Kaderart.
     *
     * Athleten ohne Kaderzugehörigkeit stehen in einem eigenen Abschnitt am Ende, damit sie
     * nicht stillschweigend verschwinden — sie können durchaus eine Norm erfüllt haben.
     *
     * @return Collection<string, Collection<int, QualificationAthleteSummary>>
     */
    #[Computed]
    public function groups(): Collection
    {
        $athleten = $this->filter()->applyToAthletes(
            $this->service()->qualificationOverview($this->championship, $this->clubId)
        );

        /** @var Collection<string, Collection<int, QualificationAthleteSummary>> $gruppen */
        $gruppen = $athleten
            ->sortBy([
                static fn (QualificationAthleteSummary $e): int => $e->kaderSortOrder,
                static fn (QualificationAthleteSummary $e): string => (string) $e->athlete->last_name,
            ])
            ->groupBy(static fn (QualificationAthleteSummary $e): string => $e->kaderName ?? 'Ohne Kaderzuordnung');

        return $gruppen;
    }

    /**
     * Die anzuzeigenden Bewerbszeilen eines Athleten nach dem Erfüllungsfilter.
     *
     * @return Collection<int, QualificationRow>
     */
    public function visibleRows(QualificationAthleteSummary $eintrag): Collection
    {
        return $this->filter()->visibleRows($eintrag);
    }

    public function isExpanded(string $schluessel): bool
    {
        return in_array($schluessel, $this->expanded, true);
    }

    /** @return Collection<int, KaderType> */
    #[Computed]
    public function kaderTypes(): Collection
    {
        return KaderType::query()->active()->orderBy('sort_order')->get();
    }

    /**
     * Der Stichtag, auf den sich die Kaderangabe bezieht.
     *
     * Wird in der Ansicht ausgewiesen: Bei abgelaufenem Qualifikationszeitraum ist es dessen
     * Ende, nicht der heutige Tag — sonst stünde bei einer späteren Auswertung eine
     * Kadereinteilung, die es damals nicht gab.
     */
    #[Computed]
    public function kaderReferenceDate(): string
    {
        return $this->service()->kaderReferenceDate($this->championship);
    }

    /** @return Collection<int, QualificationRow> */
    #[Computed]
    public function excluded(): Collection
    {
        return $this->service()->excludedForMissingApproval($this->championship, $this->clubId);
    }

    public function render(): View
    {
        return view('livewire.admin.championship-qualification-table');
    }

    /**
     * Der Service wird einmal je Request geholt und behalten. Er lädt Normen und
     * Umrechnungsfaktoren beim ersten Zugriff; ihn je Aufruf neu aus dem Container zu holen
     * würde diese Zwischenspeicherung zunichtemachen.
     */
    private function service(): QualificationEvaluationService
    {
        return $this->service ??= app(QualificationEvaluationService::class);
    }
}
