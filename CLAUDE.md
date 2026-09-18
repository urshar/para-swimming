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
- **Ein mehrzeiliger `@php ... @endphp`-Block direkt innerhalb eines `@if(...)` im Root-Template
  einer Livewire-Komponente kann den Compile-Vorgang mit `"syntax error, unexpected token
  'endif', expecting end of file"` zum Absturz bringen** — obwohl `Blade::compileString()` auf
  dieselbe Datei isoliert aufgerufen fehlerfrei durchläuft. Ursache: Livewires morph-bewusster
  Precompiler (`Livewire\Mechanisms\ExtendBlade\SupportMorphAwareBladeCompilation`) scannt den
  rohen Template-Text nach balancierten `@if`/`@endif`-Paaren, um Morph-Marker einzufügen, und
  verzählt sich dabei an einem verschachtelten `@php`-Block. Betraf ausschließlich das
  Zusammenspiel aus **Livewire-Komponenten-Root** + **`@php` als direktes Kind eines `@if`** +
  vermutlich der Menge an zusätzlicher Verschachtelung im `@if`-Rumpf — ein einzelnes,
  eigenständiges `@if`/`@endif` bricht es nicht. Fix: `@php(...)`-Einzeiler **vor** das `@if`
  ziehen (Werte vorab berechnen, auch wenn sie nur im `@if`-Zweig gebraucht werden), statt sie
  darin zu verschachteln. Trat beim Einbau von `flux:chart` in
  `livewire/wps-athlete-analysis.blade.php` auf (Phase 13); half zusätzlich, die tief
  verschachtelte `flux:chart.*`-Baumstruktur selbst in ein eigenes `@include`
  (`partials/wps-athlete-chart.blade.php`) auszulagern statt sie inline im Root-Template zu
  belassen — dasselbe Muster gilt vermutlich für jede vergleichbar tief verschachtelte
  Component-Struktur direkt im Root eines Livewire-Views.
- **`flux:chart.line` bringt (anders als `flux:chart.point`, `flux:chart.axis.line` usw.) keine
  eigene Dark-Mode-Klasse mit** (`vendor/livewire/flux-pro/.../chart/line.blade.php`: nur
  `text-zinc-800`, kein `dark:text-*`) — im Dunkelmodus dunkelgrau auf dunklem Grund, praktisch
  unsichtbar, ohne dass ein Fehler oder eine sichtbare Lücke auf den ersten Blick auffällt (die
  Punkte/Achsen bleiben sichtbar, nur die Linie fehlt). Beim Einsatz von `flux:chart.line` immer
  `class="text-zinc-800 dark:text-zinc-100"` (o. ä.) selbst ergänzen und mit `getComputedStyle(...).stroke`
  nachmessen statt nur visuell zu prüfen.
- **PhpStorms Inspection "Method expression is not of Function type" auf einem mehrzeiligen
  `@php ... @endphp`-Block ist nicht automatisch der oben dokumentierte Livewire-Precompiler-Bug** —
  der tritt nur bei **Livewire-Komponenten-Root** + `@php` als **direktes Kind eines `@if`** auf. Ein
  `@php`-Block außerhalb eines `@if` (z. B. in einer normalen Controller-View wie
  `athletes/show.blade.php`) ist zur Laufzeit unauffällig, selbst wenn PhpStorm ihn anmeckert. Ein
  Umbau auf einzeilige `@php(...)`-Direktiven ist hier **kein sicherer Fix, sondern kann neue,
  echte Bugs einführen**: mit einer Ternary (`? :`) im Ausdruck kam es zu genau demselben
  `"unexpected token 'endif'"`-Compile-Fehler wie beim Livewire-Fall (obwohl kein `@if` beteiligt
  war), und mit einem Komma im Ausdruck (`old('feld', '')`) wurde die zweite Zeile beim Rendern
  komplett verschluckt (`Undefined variable`) — beides per Testsuite verifiziert, nicht nur
  vermutet. Bei so einer Inspection ohne zugehörigen `@if`-Kontext: **nicht umbauen**, sondern als
  PhpStorm-Fehlalarm stehen lassen (ggf. mit `// @noinspection` direkt in PhpStorm, nicht im Code).
- **PhpStorms Inspection "Potentially polymorphic call" kann auf einen echten Bug hinweisen, nicht nur auf
  Typ-Mehrdeutigkeit** — anders als die beiden Fälle oben. In `classifiers/show.blade.php` bezog sich
  `$cl->status` auf ein Feld, das auf `AthleteClassification` gar nicht existiert (die Spalte heißt
  `classification_status`; `status` gab es nie). PhpStorm konnte den Zugriff deshalb keiner konkreten Klasse
  zuordnen und durchsuchte das ganze Projekt nach irgendeiner Klasse mit `status` — daher "polymorph". Der
  Status-Badge war dadurch seit jeher leer (`@if($cl->status)` immer `false`), ohne Fehlermeldung, weil
  Eloquents `__get()` für unbekannte Attribute still `null` liefert statt zu werfen. Ebenso betroffen:
  `$cl->sport_class_result` (Singular, existiert nicht) statt des echten Accessors
  `sport_class_results_display`. Fix: die bereits vorhandenen, korrekten Model-Accessor verwenden
  (`classification_status`, `status_color`, `status_label`, `sport_class_results_display` — siehe
  `AthleteClassification::getStatusColorAttribute()` etc.), wie es `athletes/show.blade.php` an der
  entsprechenden Stelle bereits richtig macht. **Lehre:** Bei dieser Inspection zuerst prüfen, ob die
  Eigenschaft auf dem tatsächlichen Model überhaupt existiert (Model-Datei/Migration nachschauen), bevor man
  sie wie die anderen zwei PhpStorm-Fallstricke oben als Fehlalarm abtut.
- **PhpStorms Inspection "Method 'X' not found in \Closure" auf dem Ergebnis von
  `Collection::get($key)` (ohne zweites `$default`-Argument) ist ein Fehlalarm** — kein echter Bug wie im
  Klassifizierer-Fall oben. Ursache liegt im generischen Docblock von Laravels `Collection::get()`
  (`vendor/laravel/framework/.../Collection.php`): `@param TGetDefault|(\Closure(): TGetDefault) $default`,
  `@return TValue|TGetDefault`. Wird `$default` weggelassen (fällt auf `null` zurück), kann PhpStorms
  Generics-Resolver `TGetDefault` nicht auflösen und kollabiert die Union sichtbar auf `\Closure` statt auf
  `null` — der Rückgabewert wird dann fälschlich komplett als `\Closure` angezeigt, obwohl er zur Laufzeit
  `TValue|null` ist (`value(null)` in `Collection::get()` liefert schlicht `null` zurück, da `value()` einen
  Nicht-Closure-Wert unverändert durchreicht). Betraf
  `DisabilityGroupGrouper::byNumberThenStroke()`: `$unassigned = $byNumber->get('');` gefolgt von
  `$unassigned->isNotEmpty()`/`->sortBy(...)` hinter einem `if ($unassigned && ...)`-Guard — durch den
  bestehenden Regressionstest ("zeigt Sportklassen mit unerwartetem Format unter „Sonstige Sportklassen"",
  `tests/Feature/QualifyingTimeGroupingTest.php`) bereits als korrekt verifiziert. Kein Code-Fix nötig;
  einfach als PhpStorm-Fehlalarm stehen lassen.

## Weitere Hinweise

- **Barrierefreiheit** nach `docs/accessibility.md` ist Teil der Definition von "fertig".
- Der **öffentliche Bereich** nutzt Tailkit-Snippets, nicht Flux — siehe `docs/specs/public-frontend.md` §3.1.
