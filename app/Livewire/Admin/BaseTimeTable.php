<?php

namespace App\Livewire\Admin;

use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeDiscipline;
use App\Models\BaseTimeSportClass;
use App\Models\BaseTimeVersion;
use App\Services\BaseTimeCalculationService;
use App\Support\TimeParser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * BaseTimeTable
 *
 * Zeigt die Basiswerte einer Kategorie/Version als Excel-artige Tabelle
 * (Bewerbe = Zeilen, Sportklassen = Spalten). Nur MANUAL-Zellen sind editierbar.
 * Die "Neu berechnen"-Aktion stößt die Live-Neuberechnung (BaseTimeCalculationService) manuell an.
 */
class BaseTimeTable extends Component
{
    public BaseTimeVersion $version;

    public BaseTimeCategory $category;

    /** @var array<int, array<int, string>> [disciplineId][sportClassId] => Anzeigewert (MM:SS.cs) */
    public array $cells = [];

    /** @var array<int, array<int, string>> [disciplineId][sportClassId] => value_type */
    public array $cellTypes = [];

    public ?string $recalcMessage = null;

    public function mount(BaseTimeVersion $version, BaseTimeCategory $category): void
    {
        $this->version = $version;
        $this->category = $category;
        $this->loadCells();
    }

    /** Livewire-Lifecycle-Hook: reagiert auf jede Änderung an cells.{disciplineId}.{sportClassId}. */
    public function updated(string $name): void
    {
        if (! str_starts_with($name, 'cells.')) {
            return;
        }

        [, $disciplineId, $sportClassId] = explode('.', $name);
        $this->saveCell((int) $disciplineId, (int) $sportClassId);
    }

    public function recalculate(BaseTimeCalculationService $service): void
    {
        $summary = $service->recalculateCategory($this->version, $this->category);

        $updated = collect($summary)->sum('updated');
        $unresolved = collect($summary)->sum(fn (array $s) => count($s['unresolved']));

        $this->recalcMessage = "$updated berechnete(r) Wert(e) aktualisiert".
            ($unresolved > 0 ? ", $unresolved konnte(n) nicht hergeleitet werden" : '').'.';

        $this->loadCells();
    }

    public function render(): View
    {
        return view('livewire.admin.base-time-table', [
            'disciplines' => $this->loadDisciplines(),
            'sportClasses' => $this->loadSportClasses(),
        ]);
    }

    // ── Daten laden ───────────────────────────────────────────────────────────

    private function loadDisciplines(): Collection
    {
        return BaseTimeDiscipline::query()
            ->whereHas('baseTimes', fn ($q) => $q->where('base_time_version_id', $this->version->id)
                ->where('base_time_category_id', $this->category->id))
            ->with('strokeType')
            ->get()
            ->sortBy([
                fn (BaseTimeDiscipline $d) => $d->strokeType?->name_de,
                fn (BaseTimeDiscipline $d) => $d->relay_count,
                fn (BaseTimeDiscipline $d) => $d->distance,
            ])
            ->values();
    }

    private function loadSportClasses(): Collection
    {
        return BaseTimeSportClass::query()
            ->whereHas('baseTimes', fn ($q) => $q->where('base_time_version_id', $this->version->id)
                ->where('base_time_category_id', $this->category->id))
            ->ordered()
            ->get();
    }

    private function loadCells(): void
    {
        $this->cells = [];
        $this->cellTypes = [];

        BaseTime::query()
            ->where('base_time_version_id', $this->version->id)
            ->where('base_time_category_id', $this->category->id)
            ->get(['base_time_discipline_id', 'base_time_sport_class_id', 'value_centiseconds', 'value_type'])
            ->each(function (BaseTime $row) {
                $disciplineId = $row->base_time_discipline_id;
                $sportClassId = $row->base_time_sport_class_id;

                $this->cellTypes[$disciplineId][$sportClassId] = $row->value_type;
                $this->cells[$disciplineId][$sportClassId] = $row->value_type === BaseTime::TYPE_NOT_APPLICABLE
                    ? ''
                    : TimeParser::display($row->value_centiseconds);
            });
    }

    // ── Speichern ─────────────────────────────────────────────────────────────

    private function saveCell(int $disciplineId, int $sportClassId): void
    {
        $errorKey = "cells.$disciplineId.$sportClassId";
        $this->resetErrorBag($errorKey);

        $row = BaseTime::query()
            ->where('base_time_version_id', $this->version->id)
            ->where('base_time_category_id', $this->category->id)
            ->where('base_time_discipline_id', $disciplineId)
            ->where('base_time_sport_class_id', $sportClassId)
            ->first();

        if (! $row || $row->value_type !== BaseTime::TYPE_MANUAL) {
            return; // nur MANUAL-Zellen sind editierbar — sicherheitshalber ignorieren statt fehlschlagen
        }

        $value = trim((string) ($this->cells[$disciplineId][$sportClassId] ?? ''));
        $parsed = $value === '' ? null : TimeParser::parse($value);

        if ($parsed === null) {
            $this->addError($errorKey, 'Ungültiges Zeitformat. Beispiel: 01:23.45');
            $this->cells[$disciplineId][$sportClassId] = TimeParser::display($row->value_centiseconds);

            return;
        }

        $row->update(['value_centiseconds' => $parsed]);
        $this->cells[$disciplineId][$sportClassId] = TimeParser::display($parsed);
        $this->recalcMessage = null; // Hinweis: berechnete Werte sind jetzt evtl. veraltet
    }
}
