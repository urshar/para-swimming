# Typdeklarationen für die statische Analyse

`alpine-components.d.ts` deklariert die Alpine.js-Komponenten des Projekts, damit PhpStorm
sie in Blade-Views auflösen kann. **Die Datei hat keine Laufzeitwirkung** und wird von Vite
nicht gebündelt.

## Wirkung

Behoben wird: `Missing import statement` bei `x-data="meetPointSystems({ … })"` und den
übrigen registrierten Komponenten.

**Nicht** behoben wird: `Element is not exported` bei `x-show="wps"`, `x-model="course"` und
ähnlichen Ausdrücken. Diese werden von Alpine im Scope der Komponenteninstanz ausgewertet,
den es zur Analysezeit nicht gibt — eine Deklarationsdatei kann daran nichts ändern.

Wer auch diese Meldungen loswerden will, schaltet sie in PhpStorm ab:

```
Settings → Editor → Inspections → JavaScript and TypeScript
    → General → Unresolved JavaScript reference     (Häkchen entfernen)
    → General → Unresolved JavaScript function      (Häkchen entfernen)
```

Alternativ lässt sich der Geltungsbereich einer Inspektion über *In All Scopes → Edit Scopes*
auf `resources/views` einschränken, damit die Prüfung in echten `.js`-Dateien erhalten bleibt.
Das ist die bessere Variante: dort ist sie sinnvoll, in Alpine-Attributen nicht.

## Neue Alpine-Komponente angelegt?

1. Datei unter `resources/js/` anlegen
2. in `resources/js/app.js` importieren und im `alpine:init`-Listener registrieren
3. in `alpine-components.d.ts` deklarieren

Schritt 3 wird leicht vergessen — die Folge ist lediglich eine IDE-Meldung, kein Fehler zur
Laufzeit.

## jsconfig.json

Die Datei im Projektwurzelverzeichnis sagt der IDE, welche Dateien zum JavaScript-Projekt
gehören, und bindet dieses Verzeichnis ein. Nach dem Einspielen einmal
*File → Invalidate Caches → Invalidate and Restart*, sonst greift sie nicht sofort.
