<?php

namespace App\Models;

use App\Enums\CoachLevel;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Aus- oder Fortbildung eines Trainers (Spec club-permissions N6, P8-2).
 *
 * @property int $id
 * @property int $coach_id
 * @property string $kind education oder training
 * @property CoachLevel|null $level nur bei Ausbildung
 * @property string|null $title
 * @property Carbon $completed_on
 * @property string|null $notes
 * @property-read Coach $coach
 * @property-read string $kind_label
 */
class CoachQualification extends Model
{
    use Auditable;

    public const string KIND_EDUCATION = 'education';

    public const string KIND_TRAINING = 'training';

    public const array KIND_LABELS = [
        self::KIND_EDUCATION => 'Ausbildung',
        self::KIND_TRAINING => 'Fortbildung',
    ];

    protected $fillable = ['coach_id', 'kind', 'level', 'title', 'completed_on', 'notes'];

    protected $casts = [
        'level' => CoachLevel::class,
        'completed_on' => 'date',
    ];

    public function coach(): BelongsTo
    {
        return $this->belongsTo(Coach::class);
    }

    public function getKindLabelAttribute(): string
    {
        return self::KIND_LABELS[$this->kind] ?? $this->kind;
    }

    /** Verlauf am Verein des Trainers (Trait Auditable). */
    public function auditClubId(): ?int
    {
        return $this->coach?->club_id;
    }

    /** Der Trainer steht im Betreff des Verlaufs (AuditHistory), nicht als geändertes Feld. */
    protected function auditExcluded(): array
    {
        return ['coach_id'];
    }
}
