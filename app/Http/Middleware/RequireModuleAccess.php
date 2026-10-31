<?php

namespace App\Http\Middleware;

use App\Enums\AccessScope;
use App\Enums\Module;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-Middleware (Alias "module") für Rechte je Modul (Spec club-permissions §4.1), z. B.
 * "module:athletes,write". Prüft nur, ob der Benutzer im Modul überhaupt lesen bzw. schreiben darf; ob ein
 * bestimmter Datensatz zum eigenen Verein gehört, regeln die Policies.
 *
 * Optionaler dritter Parameter "all" verlangt den Bereich "alle" — für verbandsweite Ansichten und Aktionen, die
 * keinen Vereinsbezug haben (z. B. "module:entries,read,all" für das Melde-Cockpit).
 */
class RequireModuleAccess
{
    public function handle(Request $request, Closure $next, string $module, string $access, ?string $scope = null): Response
    {
        $moduleEnum = Module::tryFrom($module)
            ?? throw new InvalidArgumentException("Unbekanntes Modul \"$module\" in der Route-Middleware.");

        if ($scope !== null && $scope !== 'all') {
            throw new InvalidArgumentException("Unbekannter Bereich \"$scope\" (erwartet: all).");
        }

        $user = $request->user();
        $granted = match ($access) {
            'read' => $user?->readScope($moduleEnum),
            'write' => $user?->writeScope($moduleEnum),
            default => throw new InvalidArgumentException("Unbekannte Stufe \"$access\" (erwartet: read, write)."),
        };

        $allowed = $scope === 'all'
            ? $granted === AccessScope::All
            : $granted !== null && $granted !== AccessScope::None;

        if (! $allowed) {
            abort(403, 'Kein Zugriff auf diesen Bereich.');
        }

        return $next($request);
    }
}
