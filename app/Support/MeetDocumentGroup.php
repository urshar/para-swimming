<?php

namespace App\Support;

use App\Models\Document;
use App\Models\Meet;
use Illuminate\Support\Collection;

/**
 * MeetDocumentGroup — ein sprachaufgelöstes Dokument einer Veranstaltung (Spec public-frontend
 * §4.1). "Gruppe" meint: alle Sprachfassungen desselben logischen Dokuments, reduziert auf die
 * für die aktive Sprache zu zeigende Fassung plus, falls vorhanden, die andere Sprachfassung
 * zum Verlinken daneben.
 *
 * Sprachfassungen desselben Dokuments werden über `category` + `sort_order` gepaart — die
 * Tabelle hat kein eigenes Feld dafür (§4.1 nennt keins). Beim Anlegen von Dokumenten (Phase 3)
 * müssen eine de- und eine en-Fassung desselben Dokuments daher denselben `sort_order`
 * bekommen; unterschiedliche Dokumente derselben Kategorie (z. B. Ergebnislisten mehrerer
 * Wettkampftage) brauchen unterschiedliche Werte, sonst würden sie hier fälschlich als
 * Sprachpaar zusammengefasst.
 */
final readonly class MeetDocumentGroup
{
    public function __construct(
        public Document $document,
        public ?Document $alternate,
    ) {}

    /**
     * @return Collection<int, self>
     */
    public static function forMeet(Meet $meet, string $locale): Collection
    {
        return $meet->documents()
            ->public()
            ->published()
            ->orderBy('sort_order')
            ->get()
            ->groupBy(fn (Document $document): string => $document->category.'|'.$document->sort_order)
            ->map(fn (Collection $siblings): self => self::resolve($siblings, $locale))
            ->values();
    }

    /**
     * @param  Collection<int, Document>  $siblings  alle Sprachfassungen desselben Dokuments
     */
    private static function resolve(Collection $siblings, string $locale): self
    {
        // Reihenfolge: passende Sprache, sonst sprachneutral, sonst die einzig vorhandene
        // (andere) Sprachfassung — besser gezeigt als gar nicht (§4.1 regelt nur die ersten
        // beiden Fälle ausdrücklich).
        $active = $siblings->firstWhere('locale', $locale)
            ?? $siblings->firstWhere('locale', null)
            ?? $siblings->first();

        $alternate = $siblings->first(fn (Document $candidate): bool => $candidate->isNot($active));

        return new self($active, $alternate);
    }
}
