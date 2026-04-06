<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Classifier extends Model
{
    use SoftDeletes;

    const string TYPE_MED = 'MED';

    const string TYPE_TECH = 'TECH';

    const array TYPES = [
        self::TYPE_MED => 'Medizinischer Klassifizierer',
        self::TYPE_TECH => 'Technischer Klassifizierer',
    ];

    protected $fillable = [
        'first_name',
        'last_name',
        'type',
        'email',
        'phone',
        'nation',
        'gender',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function classificationsAsMed(): HasMany
    {
        return $this->hasMany(AthleteClassification::class, 'med_classifier_id');
    }

    public function classificationsAsTech1(): HasMany
    {
        return $this->hasMany(AthleteClassification::class, 'tech1_classifier_id');
    }

    public function classificationsAsTech2(): HasMany
    {
        return $this->hasMany(AthleteClassification::class, 'tech2_classifier_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeMedical($query)
    {
        return $query->where('type', self::TYPE_MED);
    }

    public function scopeTechnical($query)
    {
        return $query->where('type', self::TYPE_TECH);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public function getFullNameAttribute(): string
    {
        return $this->last_name.', '.$this->first_name;
    }

    public function getTypeNameAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
