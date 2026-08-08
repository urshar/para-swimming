<?php

namespace App\Livewire\Admin;

use App\Models\Championship;
use App\Models\ChampionshipStandard;
use App\Models\StrokeType;
use App\Models\WpsPointVersion;
use App\Services\ChampionshipStandardService;
use App\Services\WpsPointCalculator;
use App\Services\WpsPointVersionResolver;
use App\Support\SportClassSorter;
use App\Support\TimeParser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * ChampionshipStandardTable
 *
 * Pflege der Qualifikationsnormen einer Meisterschaft (Spec §9.1, §10):
 * Filterung, Inline-Bearbeitung, Massenaktion für den ÖBSV-Prozentsatz,
 * Anlegen und Löschen einzelner Zeilen.
 *
 * Neben der errechneten ÖBSV-Zeit steht die zugehörige WPS-Punktzahl (§5.3): Ein fester
 * Prozentsatz auf die Zeit wirkt über die Bewerbe hinweg ungleich — zwei Prozent sind bei
 * 50 m Freistil rund eine halbe Sekunde, bei 400 m fast acht. In Punkten ist unmittelbar
 * sichtbar, ob die Normen gleich streng sind (Risiko Q-R6).
 *
 * Die Komponente prüft die Adminrechte selbst. Die Route-Middleware greift beim
 * Livewire-Update nicht erneut — ohne eigene Prüfung wäre die Tabelle für jeden
 * angemeldeten Nutzer beschreibbar.
 */
class ChampionshipStandardTable extends Component
{
    /** Von saveCell() entgegengenommene Feldnamen — begrenzt, was von außen setzbar ist. */
    private const array EDITABLE_FIELDS = ['mqs', 'met', 'percent', 'obsv'];

    public Championship $championship;

    /** @var array<int, array<string, string>> [standardId] => ['mqs' => …, 'met' => …, 'percent' => …, 'obsv' => …] */
    public array $rows = [];

    public string $filterStroke = '';

    public string $filterGender = '';

    public string $filterSportClass = '';

    /** Prozentsatz der Massenaktion — als String, weil das Eingabefeld leer sein darf. */
    public string $bulkPercent = '';

    // ── Formular "neue Zeile" ────────────────────────────────────────────────

    public string $newStrokeTypeId = '';

    public string $newDistance = '';

    public string $newGender = 'M';

    public string $newSportClass = '';

    public ?string $statusMessage = null;

    public function mount(Championship $championship): void
    {
        $this->championship = $championship;
        $this->loadRows();
    }

    /** Livewire-Lifecycle-Hook — greift nur für die Filterfelder. */
    public function updated(string $name): void
    {
        if (str_starts_with($name, 'filter')) {
            $this->loadRows();
        }
    }

    public function addRow(ChampionshipStandardService $service): void
    {
        $this->requireAdmin();

        $daten = $this->validate([
            'newStrokeTypeId' => ['required', 'integer', 'exists:stroke_types,id'],
            'newDistance' => ['required', 'integer', 'min:25', 'max:5000'],
            'newGender' => ['required', 'in:M,F'],
            'newSportClass' => ['required', 'string', 'max:15'],
        ]);

        try {
            $service->upsertStandard(
                $this->championship,
                (int) $daten['newStrokeTypeId'],
                (int) $daten['newDistance'],
                $daten['newGender'],
                $daten['newSportClass'],
                [],
            );
        } catch (ValidationException $e) {
            $this->addError('newSportClass', $e->getMessage());

            return;
        }

        $this->newDistance = '';
        $this->newSportClass = '';
        $this->statusMessage = 'Zeile angelegt.';

        $this->loadRows();
    }

    public function deleteRow(int $standardId, ChampionshipStandardService $service): void
    {
        $this->requireAdmin();

        $standard = $this->findStandard($standardId);

        if ($standard === null) {
            return;
        }

        $service->deleteStandard($standard);

        $this->statusMessage = 'Zeile gelöscht.';
        $this->loadRows();
    }

    /**
     * Massenaktion: Prozentsatz auf alle offenen Zeilen anwenden (§5.3).
     *
     * Wirkt auf alle offenen Zeilen der Meisterschaft, nicht nur auf die gerade
     * gefilterten — ein Filter ist eine Sicht, keine Auswahl. Andernfalls hinge das
     * Ergebnis davon ab, was gerade im Filterfeld stand.
     */
    public function applyBulkPercent(ChampionshipStandardService $service): void
    {
        $this->requireAdmin();

        $daten = $this->validate([
            'bulkPercent' => ['required', 'numeric', 'min:0', 'max:99.99'],
        ]);

        $anzahl = $service->applyPercentToOpenRows($this->championship, (float) $daten['bulkPercent']);

        $this->statusMessage = $anzahl === 0
            ? 'Keine offenen Zeilen — es wurde nichts geändert.'
            : "$anzahl offene Zeile(n) auf {$daten['bulkPercent']} % gesetzt. "
                .'Von Hand gesetzte Werte blieben unverändert.';

        $this->loadRows();
    }

    public function resetFilters(): void
    {
        $this->filterStroke = '';
        $this->filterGender = '';
        $this->filterSportClass = '';

        $this->loadRows();
    }

    /**
     * Die WPS-Punkteversion, mit der die Punktanzeige rechnet.
     *
     * Stichtag ist das Ende des Qualifikationszeitraums — zu diesem Zeitpunkt wird
     * abschließend bewertet, ob eine Norm erfüllt ist.
     */
    #[Computed]
    public function pointVersion(): ?WpsPointVersion
    {
        return app(WpsPointVersionResolver::class)
            ->resolveForDate($this->championship->qualification_end->format('Y-m-d'));
    }

    /** @return Collection<int, ChampionshipStandard> */
    #[Computed]
    public function standards(): Collection
    {
        $abfrage = $this->championship->standards()->with('strokeType');

        if ($this->filterStroke !== '') {
            $abfrage->where('stroke_type_id', (int) $this->filterStroke);
        }

        if ($this->filterGender !== '') {
            $abfrage->where('gender', $this->filterGender);
        }

        if ($this->filterSportClass !== '') {
            $abfrage->where('sport_class', $this->filterSportClass);
        }

        // Zusammengesetzter Sortierschlüssel statt sortBy() mit Closure-Array: Letzteres
        // ist bei mehreren Kriterien unzuverlässig. SportClassSorter sortiert S2 vor S10.
        return $abfrage->get()->sortBy(fn (ChampionshipStandard $s): string => sprintf(
            '%-10s|%05d|%s|%s',
            $s->strokeType?->lenex_code ?? '',
            $s->getAttribute('distance'),
            $s->getAttribute('gender'),
            SportClassSorter::key($s->getAttribute('sport_class')),
        ))->values();
    }

    /** @return Collection<int, StrokeType> */
    #[Computed]
    public function strokeTypes(): Collection
    {
        return StrokeType::query()->orderBy('id')->get();
    }

    /**
     * Sportklassen, die in dieser Meisterschaft tatsächlich vorkommen — Grundlage des
     * Filters. Bewusst nicht alle denkbaren Klassen: Die Normlisten sind lückenhaft (§2.2),
     * ein Filter auf eine nirgends ausgeschriebene Klasse wäre nur irreführend.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function availableSportClasses(): array
    {
        return $this->championship->standards()
            ->distinct()
            ->pluck('sport_class')
            ->sortBy(static fn (string $klasse): string => SportClassSorter::key($klasse))
            ->values()
            ->all();
    }

    /**
     * WPS-Punkte einer Zeit, oder null, wenn keine Version oder kein Parametersatz
     * vorliegt. Die Anzeige lässt die Spalte dann leer, statt eine Null vorzutäuschen.
     */
    public function pointsFor(?int $centiseconds, ChampionshipStandard $standard): ?int
    {
        $version = $this->pointVersion();

        if ($centiseconds === null || $version === null) {
            return null;
        }

        return app(WpsPointCalculator::class)->pointsForTime(
            $centiseconds,
            $this->championship->course,
            $standard->getAttribute('gender'),
            $standard->getAttribute('stroke_type_id'),
            $standard->getAttribute('distance'),
            $standard->getAttribute('sport_class'),
            $version,
        );
    }

    /**
     * Speichert eine einzelne bearbeitete Zelle.
     *
     * Wird von der Alpine-Komponente timeMaskCell beim Verlassen des Feldes aufgerufen und
     * bekommt den Wert ausdrücklich mitgeliefert. Bewusst KEINE Bindung über wire:model:
     * Flux rendert ein inneres <input>, an dem wire:model nicht greift — im Projekt wird
     * deshalb durchgehend x-model verwendet (CLAUDE.md).
     *
     * MQS und MET werden direkt gesetzt. Beim Prozentsatz greift applyPercent() (Zeit wird
     * errechnet, obsv_is_manual zurückgesetzt), bei der ÖBSV-Zeit setObsvTimeManually()
     * (obsv_is_manual wird gesetzt, der Prozentsatz bleibt zur Information stehen) — §5.3.
     */
    public function saveCell(int $standardId, string $feld, string $wert): void
    {
        $this->requireAdmin();

        if (! in_array($feld, self::EDITABLE_FIELDS, true)) {
            return;
        }

        $standard = $this->findStandard($standardId);

        if ($standard === null) {
            return;
        }

        $eingabe = trim($wert);
        $fehlerSchluessel = "rows.$standardId.$feld";

        $this->resetErrorBag($fehlerSchluessel);
        $this->statusMessage = null;

        $service = app(ChampionshipStandardService::class);

        if ($feld === 'percent') {
            // Komma erlauben — auf einer deutschen Tastatur ist es die naheliegende Eingabe.
            $normalisiert = str_replace(',', '.', $eingabe);

            if ($normalisiert !== '' && ! is_numeric($normalisiert)) {
                $this->addError($fehlerSchluessel, 'Bitte eine Zahl eingeben, z.B. 2 oder 2,5.');

                return;
            }

            $service->applyPercent($standard, $normalisiert === '' ? null : (float) $normalisiert);
            $this->afterSave();

            return;
        }

        $centiseconds = null;

        if ($eingabe !== '') {
            $centiseconds = TimeParser::parse($eingabe);

            if ($centiseconds === null) {
                $this->addError($fehlerSchluessel, 'Ungültiges Zeitformat. Beispiel: 01:13.19');

                return;
            }
        }

        if ($feld === 'obsv') {
            $service->setObsvTimeManually($standard, $centiseconds);
            $this->afterSave();

            return;
        }

        $spalte = $feld === 'mqs' ? 'mqs_centiseconds' : 'met_centiseconds';

        $standard->update([$spalte => $centiseconds]);

        // Ändert sich die MQS, ist die daraus errechnete ÖBSV-Zeit veraltet. Von Hand
        // gesetzte Zeiten bleiben stehen — sie hängen nicht am Prozentsatz (§5.3).
        if ($spalte === 'mqs_centiseconds' && $standard->hasObsvPercent() && ! $standard->isObsvManual()) {
            $service->applyPercent($standard, (float) $standard->getAttribute('obsv_percent'));
        }

        $this->afterSave();
    }

    public function render(): View
    {
        return view('livewire.admin.championship-standard-table');
    }

    /** Lädt die Eingabefelder aus den gespeicherten Werten neu. */
    private function afterSave(): void
    {
        $this->statusMessage = 'Gespeichert.';
        $this->loadRows();
    }

    private function loadRows(): void
    {
        unset($this->standards, $this->availableSportClasses);

        $this->rows = [];

        foreach ($this->standards() as $standard) {
            $this->rows[$standard->getKey()] = [
                'mqs' => $this->displayTime($standard->getAttribute('mqs_centiseconds')),
                'met' => $this->displayTime($standard->getAttribute('met_centiseconds')),
                // Der Prozentsatz wird als leerer String dargestellt, wenn er null ist —
                // das ist die "offene Zeile" aus [Q3]. Eine 0 erscheint als "0".
                'percent' => $standard->hasObsvPercent()
                    ? rtrim(rtrim(number_format((float) $standard->getAttribute('obsv_percent'), 2, '.', ''), '0'), '.')
                    : '',
                'obsv' => $this->displayTime($standard->getAttribute('obsv_centiseconds')),
            ];
        }
    }

    private function displayTime(?int $centiseconds): string
    {
        return $centiseconds === null ? '' : TimeParser::display($centiseconds);
    }

    private function findStandard(int $standardId): ?ChampionshipStandard
    {
        return $this->championship->standards()->whereKey($standardId)->first();
    }

    /**
     * Die Route-Middleware prüft nur den ersten Seitenaufruf; jedes spätere Livewire-Update
     * kommt als eigener Request an der Komponente an. Ohne diese Prüfung könnte jeder
     * angemeldete Nutzer die Normen ändern.
     */
    private function requireAdmin(): void
    {
        abort_unless(auth()->user()?->is_admin === true, 403);
    }
}
