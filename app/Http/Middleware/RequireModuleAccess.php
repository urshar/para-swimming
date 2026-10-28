<?php

namespace App\Http\Middleware;

use App\Enums\Module;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-Middleware (Alias "module") für Rechte je Modul (Spec club-permissions §4.1), z. B.
 * "module:athletes,write". Prüft nur, ob der Benutzer im Modul überhaupt lesen bzw. schreiben darf; ob ein
 * bestimmter Datensatz zum eigenen Verein gehört, regeln die Policies.
 */
class RequireModuleAccess
{
    public function handle(Request $request, Closure $next, string $module, string $access): Response
    {
        $moduleEnum = Module::tryFrom($module)
            ?? throw new InvalidArgumentException("Unbekanntes Modul \"$module\" in der Route-Middleware.");

        $allowed = match ($access) {
            'read' => $request->user()?->canRead($moduleEnum),
            'write' => $request->user()?->canWrite($moduleEnum),
            default => throw new InvalidArgumentException("Unbekannte Stufe \"$access\" (erwartet: read, write)."),
        };

        if ($allowed !== true) {
            abort(403, 'Kein Zugriff auf diesen Bereich.');
        }

        return $next($request);
    }
}
