<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zwei-Faktor-Pflicht (Spec club-permissions §4.7, Phase 10): Angemeldete Benutzer, die 2FA brauchen
 * (User::requiresTwoFactor), aber noch keine bestätigt haben, kommen nur auf die Sicherheitsseite und die Routen, die
 * das Einrichten braucht; jede andere Seite hinter dem Login leitet dorthin (JSON → 403). Das Deaktivieren über die
 * Fortify-Route ist bei Pflicht gesperrt (P10-3). Hängt an der web-Gruppe und läuft als persistente
 * Livewire-Middleware auch bei Livewire-Aktionen (dort zählt die Route der Seite).
 */
class EnsureTwoFactorEnabled
{
    /** Routen, die ohne bestätigte 2FA erreichbar bleiben. */
    private const array ALLOWED_ROUTES = [
        'security.edit',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        'two-factor.login',
        'two-factor.login.store',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! config('auth.two_factor_required') || ! $user?->requiresTwoFactor()) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        if ($routeName === 'two-factor.disable') {
            abort(403, 'Die Zwei-Faktor-Anmeldung ist für Ihr Konto Pflicht und kann nicht deaktiviert werden.');
        }

        if ($user->hasEnabledTwoFactorAuthentication()
            || in_array($routeName, self::ALLOWED_ROUTES, true)
            || ! $this->requiresLogin($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'Bitte zuerst die Zwei-Faktor-Anmeldung einrichten.');
        }

        // Den Hinweis auf die Pflicht zeigt die Sicherheitsseite selbst, solange 2FA fehlt.
        return redirect()->route('security.edit');
    }

    /** Nur Seiten hinter dem Login (Middleware "auth"); der öffentliche Bereich bleibt frei. */
    private function requiresLogin(Request $request): bool
    {
        return collect($request->route()?->gatherMiddleware() ?? [])
            ->contains(fn ($middleware): bool => is_string($middleware)
                && ($middleware === 'auth' || Str::startsWith($middleware, 'auth:')));
    }
}
