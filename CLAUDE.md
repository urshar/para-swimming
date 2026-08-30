# CLAUDE.md

Anweisungen für KI-gestützte Coding-Sessions (Claude Code o. Ä.) in diesem Repo. Diese Datei wird zu Beginn jeder
Session gelesen. Sie fasst zusammen, wie hier gearbeitet wird und welche Standards nicht verhandelbar sind.

## Projekt in einem Satz

Laravel-13-Anwendung zur Verwaltung von Para-Schwimm-Wettkämpfen, Athleten, Rekorden, Meldungen, Cup-Wertung,
Richtzeiten und Statistiken für den ÖBSV, mit LENEX-Datenaustausch.

Fachlicher Kontext: siehe [README.md](README.md), `docs/domain-glossary.md`
und `docs/data-model.md`. Architektur: [docs/architecture.md](docs/architecture.md).

## Wichtigste Kommandos

```bash
composer dev          # Dev-Umgebung (Server + Queue + Vite)
composer test         # Pint-Check + gesamte Testsuite
vendor/bin/pest       # nur Tests
vendor/bin/pest --group=<gruppe>
composer lint         # Pint anwenden
composer lint:check   # Pint nur prüfen
```

## Arbeitsweise

- **Streng phasenweise.** Erst Plan vorstellen → Freigabe abwarten → implementieren → Tests laufen lassen → Sign-off
  abwarten → nächste Phase. Nicht mehrere Phasen auf einmal.
- **Eine Sache nach der anderen**, mit ausdrücklicher Bestätigung vor jedem nächsten Schritt.
- **Tests müssen grün sein**, bevor eine Phase als fertig gilt.
- **Alle PhpStorm-Inspections auflösen**, bevor weitergemacht wird (redundante Null-Checks, unnötige Casts,
  `phpdoc_align`-Umbrüche, Default-Argumente an Aufrufstellen, `->group()` in file-level `uses()` verschoben).

## Nicht verhandelbare Standards

### Code-Stil

- **Laravel Pint** (Preset `laravel`, mit angepassten Regeln in `pint.json`). Vor Abschluss immer `composer lint:check`.
- Services als `final readonly class` mit Constructor-Injection (siehe
  [docs/architecture.md](docs/architecture.md)).
- Keine Default-Argumente an Aufrufstellen.

### Datenbank-Portabilität (kritisch)

- Dev/Prod = **MySQL**, Tests = **In-Memory-SQLite**. Jede Query muss auf beiden laufen.
- **Keine MySQL-only-Funktionen** (kein `YEAR()`, `MONTH()` usw.). Für Datumsfilter `whereDate()` und Standard-SQL
  verwenden.

### Migrationen

- `dropForeign()` muss vor `dropUnique()` im selben Schema-Block stehen (sonst MySQL-Fehler 1553).

### Tests

- **Pest** mit `RefreshDatabase`; Suite läuft auf SQLite in-memory.
- **Keine Factories** – direkt `Model::create()` / `Model::forceCreate()`
  (für guarded Felder).
- Helper-Funktionen mit phasen-Suffix, um Namenskollisionen zu vermeiden (z. B. `makeAdmin_p2()`, `makeAdmin_qtl1()`).
- Test-Gruppen mit beschreibendem Suffix (z. B. `--group=qualifying-time-lists-p1`); `->group()` auf File-Ebene in
  `uses()`.
- Wenn Composer/Packagist in der Umgebung nicht erreichbar ist, mindestens
  `php -l` zur Syntaxprüfung nutzen.
- Erwartungen möglichst mit verketteten `->and()` schreiben.

### Blade / Flux / Alpine

- Views mit `@extends('layouts.app')` + `@section('content')`, **nicht**
  `<x-layouts.app>`.
- Flux-Komponenten immer mit `x-model`, **nie** `:value`.
- Für IMask oder komplexe Alpine-Interaktion natives `<input>` verwenden.
- `<flux:select variant="listbox">` + `<flux:select.option :selected="...">` (Prop-Bindung) für
  Dropdowns — nicht natives `<option>` mit `@selected()` (Flux' Standard-Variante rendert ohne
  `@tailwindcss/forms` keinen Pfeil) und **nicht** `@selected()` direkt in
  `<flux:select.option>` (bricht den Blade-Component-Parser). `:selected` funktioniert, weil
  `UIOption.mount()` `hasAttribute("selected")` synchron liest, unabhängig vom kaputten
  `value=""`-Mechanismus am äußeren `<flux:select>` — siehe
  `docs/specs/admin-ui-rework.md` "Combobox-Fix gefunden".
- Flux-Tabellen-Padding: `[&_td:first-child]:ps-4` (Flux setzt intern
  `first:ps-0`).
- Alpine-Logik in separate `.js`-Dateien auslagern und via `Alpine.data()`
  registrieren (reduziert IDE-Warnungen).
- `@php use Foo\Bar; @endphp` für Imports steht am Dateianfang **vor** `@extends`, nicht
  mitten in `@section('content')` — sonst löst PhpStorm den Import nicht auf ("Missing
  import statement"), siehe `club-entries/edit-relay.blade.php` für das korrekte Muster.

## Bewährte Architektur-Muster (Fallstricke)

- **Session speichert nur IDs**, keine Eloquent-Modelle (Deserialisierung schlägt sonst fehl).
- **Mehrkriterien-Sortierung**: `sortBy()` mit Closure-Arrays ist unzuverlässig – stattdessen zusammengesetzte
  `sprintf()`-Sortierschlüssel.
- **Alpine-Doppelinitialisierung** vermeiden: kein `import Alpine` / `Alpine.start()`
  in `app.js`; Plugins/Data in `document.addEventListener('alpine:init', …)` auf
  `window.Alpine` registrieren.
- **Öffentliche Routen mit `{locale}`-Präfix + optionalem Pfadparameter** (z. B.
  `{locale}/cup/{jahr?}`): den optionalen Parameter **nicht** als eigenes
  Methodenargument (`?string $jahr = null`) deklarieren, sondern per
  `$request->route('jahr')` im Methodenrumpf lesen. Laravels implizite Bindung von
  Nicht-Klassen-Routenparametern läuft positionsbasiert, nicht namensbasiert
  (`RouteDependencyResolverTrait::resolveMethodDependencies`) — bei einem Routenparameter
  mehr als Methodenargumenten (hier: `locale` fehlt im Methodenkopf) bekommt das
  Methodenargument den falschen Wert (`$jahr` erhielt `'de'` statt der Jahreszahl). Tests, die
  nur einen Fallback-Pfad prüfen (z. B. "unbekanntes Jahr → aktuellstes Jahr"), decken das
  **nicht** auf, weil der falsche Wert zufällig denselben Fallback auslöst — siehe
  `Public\CupRankingController`/`Public\AnnualBestController` und die zugehörigen
  Regressionstests in `PublicFrontendPhase7Test.php`.
- **Blade `@json()` zerlegt sein Argument naiv an jedem Komma**
  (`Illuminate\View\Compilers\Concerns\CompilesJson::compileJson()` macht intern
  `explode(',', ...)`) — nie einen Ausdruck mit eigenen Kommas übergeben (z. B.
  `old('key', 'default')` oder ein mehrteiliges Array-Literal direkt in `@json([...])`),
  sondern vorher in eine einzelne Variable schreiben (`@php $x = old('key', 'default');
  @endphp`, dann `@json($x)`). Ein internes Komma verfälscht dabei nur unbemerkt die
  JSON-Encoding-Flags, zwei oder mehr können den kompilierten PHP-Ausdruck abschneiden
  (`ParseError`). Zusätzlich: `x-data`-Attribute, die `@json()` enthalten, **immer einfach
  anführen** (`x-data='...'`), nie doppelt — `@json()`s eingebettete doppelte
  Anführungszeichen brechen sonst das HTML-Attribut. `php artisan view:cache` erkennt beides
  nicht (kompiliert nur, führt nichts aus) — nur ein echter Seitenaufruf deckt es auf.
- **Flux' `flux:description` bekommt intern eine Vendor-Regel**
  (`[&>*:not([data-flux-label])+[data-flux-description]]:mt-3` in Flux' `field.blade.php`)
  mit strukturell höherer Spezifität als eine einzelne eigene Utility-Klasse — ein normales
  `mt-1` auf der Beschreibung wird davon überstimmt, unabhängig von der Position im
  Stylesheet. Für einen wirksamen Abstand die Tailwind-v4-Important-Syntax verwenden:
  `mt-1!`.
- **Auto-Submit bei Änderung eines `flux:select` nicht über `onchange="this.form.submit()"` lösen.**
  `flux:select` ist ein Custom Element (`<ui-select>`); dessen internes "change"-Event feuert mit
  `{bubbles:false}` (`vendor/livewire/flux/dist/flux.min.js`) — auch `@change` direkt auf dem `<ui-select>`
  kam im Live-Test nicht zuverlässig an. Stattdessen `x-model` auf eine Alpine-Zustandsvariable binden und in
  `x-init` per `$watch(...)` den Submit auslösen (übernimmt dabei auch gleich die Vorbelegung). Siehe
  `qualifying-time-lists/qualifications.blade.php`.
- **`<flux:select.option value="">` für eine echte, bedeutungsvolle Option (z. B. "Alle" in einer
  festen Auswahl) kommt beim Absenden nie im Request an** — Flux liest ein leeres `value` nicht
  zuverlässig (verwandt mit dem oben verlinkten Combobox-Bug, aber ein eigener Fall: hier geht es
  um eine Options-, nicht um die Wrapper-Vorbelegung). Für so eine Option einen echten
  Sentinel-Wert verwenden (z. B. `value="ALL"`), nie `""`. Das ist **nicht** dasselbe wie ein
  `clearable`-Select: Dessen "leerer" Zustand läuft über einen eigenen Platzhalter-Mechanismus und
  funktioniert — dabei aber beachten: Laravels `ConvertEmptyStringsToNull`-Middleware macht aus
  einem geleerten `clearable`-Feld beim Request `null`, nicht `""`. Eine Validierung wie
  `in_array($x, ['', 'A', 'B'], true)` erkennt `null` nicht als gültig und fällt fälschlich auf den
  Default zurück — vorher explizit `$x !== null &&` prüfen. Siehe
  `RecordController::index()` (`$relayFilter`/`$course`).

## Weitere Hinweise

- **Barrierefreiheit** nach `docs/accessibility.md` ist Teil der Definition von "fertig".
- Der **öffentliche Bereich** nutzt Tailkit-Snippets, nicht Flux — siehe `docs/specs/public-frontend.md` §3.1.
