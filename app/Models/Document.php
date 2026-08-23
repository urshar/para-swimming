<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

class Document extends Model
{
    protected $fillable = [
        'documentable_type',
        'documentable_id',
        'category',
        'title',
        'locale',
        'path',
        'mime_type',
        'size_bytes',
        'is_public',
        'published_at',
        'sort_order',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'is_public' => 'boolean',
        'published_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    protected $attributes = [
        'is_public' => false,
        'sort_order' => 0,
    ];

    /**
     * Veranstaltung, zu der das Dokument gehört — oder null bei sprachneutralen bzw.
     * -spezifischen Regelmenten/Formularen ohne Bezug (Spec public-frontend §4.1).
     */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Grundsätzlich für eine öffentliche Auslieferung freigegeben (§4.3). Sagt nichts über
     * die aktuelle Sichtbarkeit — dafür scopePublished().
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    /**
     * Aktuell sichtbar: veröffentlicht und der Zeitpunkt liegt nicht in der Zukunft.
     * null bei published_at bedeutet Entwurf (§4.1).
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->whereNotNull('published_at')
            ->where('published_at', '<=', Carbon::now());
    }

    /**
     * Dokumente, die für die angegebene Sprache in Frage kommen: passende Sprachfassung
     * plus sprachneutrale Dokumente. Welche Fassung im Zweifel den Vorrang hat ("zeige die
     * passende Fassung, verlinke die andere"), entscheidet die Sprachauflösung in Phase 2
     * (§4.1), nicht dieser Scope.
     */
    public function scopeForLocale(Builder $query, string $locale): Builder
    {
        return $query->where(function (Builder $query) use ($locale): void {
            $query->where('locale', $locale)->orWhereNull('locale');
        });
    }

    // ── Linktext-Bausteine (accessibility.md: Art, Format und Größe im Linktext) ────────────

    /**
     * Kurzes Format-Kürzel aus dem MIME-Type für Linktexte ("Ausschreibung (PDF, 240 kB)").
     * Unbekannte MIME-Types liefern null statt eines rohen "application/octet-stream".
     */
    public function formatLabel(): ?string
    {
        return match ($this->mime_type) {
            'application/pdf' => 'PDF',
            'application/zip' => 'ZIP',
            'application/msword' => 'DOC',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'DOCX',
            'application/vnd.ms-excel' => 'XLS',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'XLSX',
            default => null,
        };
    }

    /**
     * Menschenlesbare Dateigröße ("240 kB"), dezimal (1000er-Schritte) wie im Dateisystem
     * üblich, nicht binär (KiB) — deckt sich damit mit der Anzeige des Browsers beim Download.
     */
    public function sizeLabel(): ?string
    {
        if ($this->size_bytes === null) {
            return null;
        }

        $units = ['B', 'kB', 'MB', 'GB'];
        $value = (float) $this->size_bytes;
        $unitIndex = 0;

        while ($value >= 1000 && $unitIndex < count($units) - 1) {
            $value /= 1000;
            $unitIndex++;
        }

        $decimals = $unitIndex === 0 ? 0 : 1;
        $decimalSeparator = app()->getLocale() === 'de' ? ',' : '.';

        return number_format($value, $decimals, $decimalSeparator, '').' '.$units[$unitIndex];
    }
}
