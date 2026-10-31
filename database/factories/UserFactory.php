<?php

namespace Database\Factories;

use App\Enums\RoleTemplate;
use App\Models\User;
use App\Services\PermissionTemplateService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Nicht-Admins bekommen die Rechte ihrer Vorlage, ohne Angabe die Vorlage "Verein" — das entspricht dem
     * Zugriff, den Vereinsbenutzer vor den Rechten je Modul hatten (Spec club-permissions Phase 3).
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            if (! $user->is_admin) {
                app(PermissionTemplateService::class)->applyTemplate($user, $user->role_template ?? RoleTemplate::Club);
            }
        });
    }

    /**
     * Benutzer ohne Rechte (Vorlage "Ohne Rechte").
     */
    public function withoutPermissions(): static
    {
        return $this->state(fn (array $attributes) => [
            'role_template' => RoleTemplate::Empty,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
