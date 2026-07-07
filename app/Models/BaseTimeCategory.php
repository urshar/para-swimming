<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BaseTimeCategory extends Model
{
    protected $fillable = [
        'code',
        'course',
        'gender',
        'label',
        'ratio_reference_category_id',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function baseTimes(): HasMany
    {
        return $this->hasMany(BaseTime::class);
    }

    public function derivationRules(): HasMany
    {
        return $this->hasMany(BaseTimeDerivationRule::class);
    }

    /** Kategorie, deren Durchschnitts-Wachstumsfaktoren diese Kategorie standardmäßig übernimmt. */
    public function ratioReferenceCategory(): BelongsTo
    {
        return $this->belongsTo(self::class, 'ratio_reference_category_id');
    }

    /** Kategorien, die diese Kategorie als Referenz für ihre Wachstumsfaktoren nutzen. */
    public function dependentCategories(): HasMany
    {
        return $this->hasMany(self::class, 'ratio_reference_category_id');
    }
}
