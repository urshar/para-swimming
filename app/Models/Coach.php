<?php

namespace App\Models;

use App\Enums\AccessScope;
use App\Enums\CoachLevel;
use App\Enums\Module;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Trainer eines Vereins (Spec club-permissions §4.6, N6, N7, Phase 8).
 *
 * @property int $id
 * @property int $club_id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $notes
 * @property bool $is_active
 * @property-read Club $club
 * @property-read User|null $user
 * @property-read string $display_name
 * @property-read CoachLevel $level
 */
class Coach extends Model
{
    use Auditable;

    protected $fillable = ['club_id', 'first_name', 'last_name', 'email', 'phone', 'notes', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class)->withTrashed();
    }

    public function qualifications(): HasMany
    {
        return $this->hasMany(CoachQualification::class);
    }

    /** Verknüpftes Login (N7), höchstens eines. */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /** Trainer, die der Benutzer sehen darf: Lesen "alle" bzw. nur die des eigenen Vereins. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return match ($user->readScope(Module::Coaches)) {
            AccessScope::All => $query,
            AccessScope::Own => $query->where('club_id', $user->club_id),
            AccessScope::None => $query->whereRaw('1 = 0'),
        };
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->last_name.' '.$this->first_name;
    }

    /** Aktuelle Stufe: höchste eingetragene Ausbildung, sonst "ohne Ausbildung" (P8-2). */
    public function getLevelAttribute(): CoachLevel
    {
        return $this->qualifications
            ->where('kind', CoachQualification::KIND_EDUCATION)
            ->pluck('level')
            ->filter()
            ->sortByDesc(fn (CoachLevel $level) => $level->rank())
            ->first() ?? CoachLevel::None;
    }
}
