<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AccessScope;
use App\Enums\Module;
use App\Enums\RoleTemplate;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password', 'is_admin', 'club_id', 'role_template'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function hasClub(): bool
    {
        return $this->club_id !== null;
    }

    public function modulePermissions(): HasMany
    {
        return $this->hasMany(UserModulePermission::class);
    }

    /**
     * Lesebereich für ein Modul (Spec club-permissions §4.1). Admins immer "alle"; "eigener Verein" ohne Verein
     * zählt als kein Zugriff. Die Rechte werden einmal je Benutzer-Instanz geladen (Relation).
     */
    public function readScope(Module $module): AccessScope
    {
        return $this->effectiveScope($this->modulePermission($module)?->read_scope);
    }

    /** Schreibbereich für ein Modul, Regeln wie readScope(). */
    public function writeScope(Module $module): AccessScope
    {
        return $this->effectiveScope($this->modulePermission($module)?->write_scope);
    }

    public function canRead(Module $module): bool
    {
        return $this->readScope($module) !== AccessScope::None;
    }

    public function canWrite(Module $module): bool
    {
        return $this->writeScope($module) !== AccessScope::None;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'role_template' => RoleTemplate::class,
        ];
    }

    private function modulePermission(Module $module): ?UserModulePermission
    {
        return $this->modulePermissions->first(fn (UserModulePermission $p) => $p->module === $module);
    }

    private function effectiveScope(?AccessScope $stored): AccessScope
    {
        if ($this->is_admin) {
            return AccessScope::All;
        }

        if ($stored === AccessScope::Own && $this->club_id === null) {
            return AccessScope::None;
        }

        return $stored ?? AccessScope::None;
    }
}
