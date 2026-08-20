<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * SetLocale — Spracherkennung für den öffentlichen Bereich (Spec public-frontend §3.2).
 *
 * Sitzt auf der `{locale}`-Präfixgruppe in routes/public.php und setzt die Anwendungssprache
 * aus dem URL-Segment. Die Ermittlung, welches Präfix ein Besucher überhaupt zu sehen
 * bekommt (Cookie vor Browsersprache), passiert davor in der Root-Route — hier wird nur noch
 * durchgereicht und die Wahl für den nächsten Besuch im Cookie gehalten.
 */
class SetLocale
{
    public const array SUPPORTED = ['de', 'en'];

    public const string DEFAULT = 'de';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->route('locale');

        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = self::DEFAULT;
        }

        App::setLocale($locale);

        /** @var HttpResponse $response */
        $response = $next($request);

        return $response->withCookie(cookie('locale', $locale, 60 * 24 * 365));
    }
}
