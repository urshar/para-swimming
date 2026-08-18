<?php

namespace App\Support;

/**
 * Einstellungen der WPS-Vereinsauswertung (Spec "WPS Rankings" §9).
 *
 * Nachgebildet nach `ClubRankingConfiguration` des Cup-Moduls, aber eigenständig: Die
 * Cup-Fassung bleibt unangetastet, weil sie eine offizielle ÖBSV-Wertung trägt und deren
 * Regeln nicht von einem Analysewerkzeug mitbewegt werden dürfen.
 *
 * Anders als dort **keine Konfigurationsdatei**: Diese Auswertung lebt davon, dass man ihre
 * Werte im Betrieb verändert und die Wirkung sofort sieht. Werte in einer Datei wären dafür
 * am falschen Ort.
 */
final readonly class WpsClubRankingConfiguration
{
    /** Punkte der besten Leistungen je Athlet, über den Verein summiert. */
    public const string METHOD_SUM = 'sum';

    /** Dieselben Werte, aber gemittelt. */
    public const string METHOD_AVERAGE = 'average';

    /** Zahl der Leistungen, die eine Punktschwelle erreichen. */
    public const string METHOD_COUNT = 'count';

    /** @var list<string> */
    public const array METHODS = [self::METHOD_SUM, self::METHOD_AVERAGE, self::METHOD_COUNT];

    /**
     * Standardmäßig zählt je Athlet die beste Leistung.
     *
     * Mehr Leistungen einzubeziehen bevorzugt Athleten, die viele Bewerbe schwimmen — eine
     * Aussage über den Verein wird dadurch nicht genauer, nur breiter.
     */
    public const int DEFAULT_COUNTED_PER_ATHLETE = 1;

    /**
     * Vorschlagswert der Punktschwelle.
     *
     * 600 Punkte liegen im Bereich einer soliden nationalen Leistung — hoch genug, um zu
     * unterscheiden, niedrig genug, dass die Liste nicht leer bleibt.
     */
    public const int DEFAULT_THRESHOLD = 600;

    /**
     * @param  string  $method  eine der Konstanten oben
     * @param  int  $countedPerAthlete  wie viele Leistungen je Athlet zählen
     * @param  int  $threshold  Punktschwelle; nur bei METHOD_COUNT von Belang
     * @param  int  $minEntriesPerClub  Mindestzahl gewerteter Leistungen für die Hauptliste
     */
    public function __construct(
        public string $method = self::METHOD_SUM,
        public int $countedPerAthlete = self::DEFAULT_COUNTED_PER_ATHLETE,
        public int $threshold = self::DEFAULT_THRESHOLD,
        public int $minEntriesPerClub = 1,
    ) {}

    /**
     * Baut die Einstellungen aus Abfrageparametern — für die PDF-Route.
     *
     * Unbrauchbare Werte fallen auf die Vorschlagswerte zurück, statt eine leere Auswertung zu
     * erzeugen.
     *
     * @param  array<string, mixed>  $query
     */
    public static function fromQuery(array $query): self
    {
        $methode = (string) ($query['method'] ?? self::METHOD_SUM);

        return new self(
            in_array($methode, self::METHODS, true) ? $methode : self::METHOD_SUM,
            self::positiveOr($query['counted'] ?? null, self::DEFAULT_COUNTED_PER_ATHLETE),
            self::positiveOr($query['threshold'] ?? null, self::DEFAULT_THRESHOLD),
            self::positiveOr($query['minEntries'] ?? null, 1),
        );
    }

    /**
     * Als Abfrageparameter — damit der PDF-Link dieselben Einstellungen mitnimmt.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'method' => $this->method === self::METHOD_SUM ? '' : $this->method,
            'counted' => $this->countedPerAthlete === self::DEFAULT_COUNTED_PER_ATHLETE
                ? ''
                : (string) $this->countedPerAthlete,
            'threshold' => $this->method === self::METHOD_COUNT ? (string) $this->threshold : '',
            'minEntries' => $this->minEntriesPerClub === 1 ? '' : (string) $this->minEntriesPerClub,
        ], static fn (string $wert): bool => $wert !== '');
    }

    public function methodLabel(): string
    {
        return match ($this->method) {
            self::METHOD_AVERAGE => 'Durchschnitt der besten Leistungen',
            self::METHOD_COUNT => "Leistungen ab $this->threshold Punkten",
            default => 'Summe der besten Leistungen',
        };
    }

    /**
     * Beschreibung für Kopfbereich und PDF.
     *
     * Nennt die Methode ausdrücklich: Summe und Durchschnitt ergeben völlig verschiedene
     * Reihenfolgen, und eine Liste ohne Angabe ihrer Rechenweise ist nicht deutbar.
     */
    public function describe(): string
    {
        $teile = [$this->methodLabel()];

        if ($this->method !== self::METHOD_COUNT) {
            $teile[] = $this->countedPerAthlete === 1
                ? 'beste Leistung je Athlet'
                : "beste $this->countedPerAthlete Leistungen je Athlet";
        }

        if ($this->minEntriesPerClub > 1) {
            $teile[] = "ab $this->minEntriesPerClub gewerteten Leistungen";
        }

        return implode(' · ', $teile);
    }

    /** Ist das Ergebnis eine Anzahl statt einer Punktzahl? */
    public function countsEntries(): bool
    {
        return $this->method === self::METHOD_COUNT;
    }

    private static function positiveOr(mixed $wert, int $standard): int
    {
        if (! is_numeric($wert)) {
            return $standard;
        }

        $zahl = (int) $wert;

        return $zahl > 0 ? $zahl : $standard;
    }
}
