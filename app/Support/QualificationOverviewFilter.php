<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Filterzustand der Qualifikantenansicht (Spec "WPS Qualification" §7.6).
 *
 * Als eigenes Objekt, weil zwei Stellen dieselbe Filterung brauchen: die Livewire-Komponente
 * am Bildschirm und der Controller für die PDF-Ausgabe. Zweimal ausprogrammiert liefen die
 * Regeln früher oder später auseinander, und dann zeigte das PDF etwas anderes als der
 * Bildschirm, von dem aus es erzeugt wurde.
 */
final readonly class QualificationOverviewFilter
{
    /** Alle Bewerbe mit Norm. */
    public const string FULFILMENT_ALL = 'alle';

    /** Nur Bewerbe, in denen MQS oder MET erreicht wurde. */
    public const string FULFILMENT_MET = 'met';

    /** Nur Bewerbe, in denen bislang keine Norm erreicht wurde. */
    public const string FULFILMENT_OPEN = 'open';

    public function __construct(
        public string $fulfilment = self::FULFILMENT_ALL,
        public string $kader = '',
        public string $search = '',
    ) {}

    /**
     * Baut den Filter aus Abfrageparametern — für die PDF-Route.
     *
     * Unbekannte Werte fallen auf den Standard zurück, statt eine leere Liste zu erzeugen:
     * Ein vertippter Parameter in der Adresszeile soll ein vollständiges PDF liefern, kein
     * leeres.
     *
     * @param  array<string, mixed>  $query
     */
    public static function fromQuery(array $query): self
    {
        $erfuellung = (string) ($query['fulfilment'] ?? self::FULFILMENT_ALL);

        return new self(
            in_array($erfuellung, self::fulfilmentValues(), true) ? $erfuellung : self::FULFILMENT_ALL,
            trim((string) ($query['kader'] ?? '')),
            trim((string) ($query['q'] ?? '')),
        );
    }

    /** @return list<string> */
    public static function fulfilmentValues(): array
    {
        return [self::FULFILMENT_ALL, self::FULFILMENT_MET, self::FULFILMENT_OPEN];
    }

    /**
     * Als Abfrageparameter — damit der PDF-Link denselben Stand mitnimmt wie der Bildschirm.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'fulfilment' => $this->fulfilment === self::FULFILMENT_ALL ? '' : $this->fulfilment,
            'kader' => $this->kader,
            'q' => $this->search,
        ], static fn (string $wert): bool => $wert !== '');
    }

    public function isActive(): bool
    {
        return $this->toQuery() !== [];
    }

    /**
     * Beschreibung des Filterstands für den PDF-Kopf.
     *
     * Ohne sie sähe ein ausgedrucktes, gefiltertes Blatt aus wie der vollständige Stand.
     */
    public function describe(): ?string
    {
        if (! $this->isActive()) {
            return null;
        }

        $teile = [];

        if ($this->fulfilment === self::FULFILMENT_MET) {
            $teile[] = 'nur erfüllte Bewerbe';
        }

        if ($this->fulfilment === self::FULFILMENT_OPEN) {
            $teile[] = 'nur nicht erfüllte Bewerbe';
        }

        if ($this->kader !== '') {
            $teile[] = "Kaderart: $this->kader";
        }

        if ($this->search !== '') {
            $teile[] = "Namenssuche: \"$this->search\"";
        }

        return implode(' · ', $teile);
    }

    /**
     * Schränkt die Athleten auf Kaderart und Namenssuche ein.
     *
     * @param  Collection<int, QualificationAthleteSummary>  $athleten
     * @return Collection<int, QualificationAthleteSummary>
     */
    public function applyToAthletes(Collection $athleten): Collection
    {
        if ($this->search !== '') {
            $athleten = $athleten->filter(fn (QualificationAthleteSummary $e): bool => str_contains(
                mb_strtolower((string) $e->athlete->full_name),
                mb_strtolower($this->search)
            ));
        }

        if ($this->kader !== '') {
            $athleten = $athleten->filter(
                fn (QualificationAthleteSummary $e): bool => $e->kaderName === $this->kader
            );
        }

        return $athleten->values();
    }

    /**
     * Die anzuzeigenden Bewerbszeilen eines Athleten.
     *
     * Der Erfüllungsfilter wirkt auf Zeilen, nicht auf Athleten; ein Athlet ohne passende
     * Zeile wird von der Anzeige übersprungen.
     *
     * @return Collection<int, QualificationRow>
     */
    public function visibleRows(QualificationAthleteSummary $eintrag): Collection
    {
        return match ($this->fulfilment) {
            self::FULFILMENT_MET => $eintrag->rows->filter(
                static fn (QualificationRow $z): bool => $z->status->isProof()
                    || $z->status->status === QualificationStatus::MET_ONLY
            )->values(),
            self::FULFILMENT_OPEN => $eintrag->rows->filter(
                static fn (QualificationRow $z): bool => ! $z->status->isProof()
                    && $z->status->status !== QualificationStatus::MET_ONLY
            )->values(),
            default => $eintrag->rows,
        };
    }
}
