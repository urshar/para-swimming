<?php

namespace App\Http\Middleware;

use App\Support\ListUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Merkt sich beim Aufruf einer Listen-Route deren vollständige URL als Rücksprungziel des Bereichs —
 * Verwendung: ->middleware('remember.list:records'). Siehe App\Support\ListUrl.
 */
class RememberListUrl
{
    public function handle(Request $request, Closure $next, string $area): Response
    {
        // Nur echte Seitenaufrufe: keine AJAX-/JSON-Abfragen, keine Formular-POSTs.
        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            ListUrl::remember($area, $request->fullUrl());
        }

        return $next($request);
    }
}
