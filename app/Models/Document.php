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
}
