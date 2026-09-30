<?php

namespace App\Support;

/**
 * Merkt sich je Admin-Bereich die zuletzt aufgerufene Listen-URL (inkl. Filter, Sortierung, Seite) in
 * der Session und liefert sie als Rücksprungziel für Zurück-/Abbrechen-Buttons und für Weiterleitungen
 * nach Speichern/Löschen. Gesetzt wird der Wert über die Middleware RememberListUrl an den Listen-Routen
 * (Alias "remember.list:<bereich>"); ohne gemerkte URL (Direktaufruf, neue Session) fällt das Ziel auf
 * die ungefilterte Liste "<bereich>.index" zurück.
 *
 * In der Session liegt nur der URL-String, kein Modell (siehe CLAUDE.md "Session speichert nur IDs").
 */
final readonly class ListUrl
{
    private const string SESSION_PREFIX = 'list_url.';

    public static function remember(string $area, string $url): void
    {
        session([self::SESSION_PREFIX.$area => $url]);
    }

    public static function to(string $area): string
    {
        $url = session(self::SESSION_PREFIX.$area);

        // Nur interne Ziele übernehmen (Schutz gegen manipulierte Session-Werte / Open Redirect).
        return is_string($url) && str_starts_with($url, url('/'))
            ? $url
            : route($area.'.index');
    }
}
