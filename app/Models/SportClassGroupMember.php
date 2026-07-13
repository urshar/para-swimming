<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SportClassGroupMember extends Model
{
    protected $fillable = [
        'sport_class_group_id',
        'sport_class',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function sportClassGroup(): BelongsTo
    {
        return $this->belongsTo(SportClassGroup::class);
    }
}
