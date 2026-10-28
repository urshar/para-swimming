<?php

namespace App\Providers;

use App\Enums\Module;
use App\Models\AthletePerformanceNote;
use App\Models\Meet;
use App\Models\User;
use App\Policies\AthletePerformanceNotePolicy;
use App\Policies\EntryPolicy;
use App\Services\AccessTeamManagerSource;
use App\Services\TeamManagerSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TeamManagerSource::class, AccessTeamManagerSource::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        Gate::policy(Meet::class, EntryPolicy::class);
        Gate::policy(AthletePerformanceNote::class, AthletePerformanceNotePolicy::class);

        // Rechte je Modul (Spec club-permissions §4.1), in Blade z. B. @can('module.write', Module::Athletes).
        // Admins laufen über User::readScope()/writeScope(), bewusst kein globales Gate::before.
        Gate::define('module.read', fn (User $user, Module $module) => $user->canRead($module));
        Gate::define('module.write', fn (User $user, Module $module) => $user->canWrite($module));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
