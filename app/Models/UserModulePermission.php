<?php

namespace App\Models;

use App\Enums\AccessScope;
use App\Enums\Module;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lese- und Schreibbereich eines Benutzers für ein Modul (Spec club-permissions §4.1). Gepflegt über
 * PermissionTemplateService, nicht direkt.
 *
 * @property int $id
 * @property int $user_id
 * @property Module $module
 * @property AccessScope $read_scope
 * @property AccessScope $write_scope
 */
class UserModulePermission extends Model
{
    protected $fillable = [
        'user_id',
        'module',
        'read_scope',
        'write_scope',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'module' => Module::class,
            'read_scope' => AccessScope::class,
            'write_scope' => AccessScope::class,
        ];
    }
}
