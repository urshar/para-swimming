# Konventionen

Verbindliche Regeln für Code, Tests und UI in diesem Projekt. Diese Konventionen sind Teil der Definition von "fertig" –
eine Phase gilt erst als abgeschlossen, wenn sie eingehalten sind, alle PhpStorm-Inspections aufgelöst sind und die
Tests grün sind.

## Code-Stil (PHP)

- **Laravel Pint** ist maßgeblich. Preset `laravel` mit angepassten Regeln in
  `pint.json`:
    - `ordered_imports` alphabetisch, Reihenfolge `class → function → const`.
    - `ordered_class_elements` in fester Reihenfolge (Traits, Konstanten, Properties, Konstruktor, Magic, dann public →
      protected → private Methoden).
- Vor Abschluss: `composer lint:check` (prüft, ändert nichts) bzw.
  `composer lint` (formatiert).
- **Services** als `final readonly class` mit Constructor-Injection.
- **Keine Default-Argumente an Aufrufstellen** – nur in der Signatur.
- **PhpStorm-Inspections vollständig auflösen**: keine redundanten Null-Checks, keine unnötigen Casts, korrekte
  `phpdoc_align`-Formatierung, `->group()` auf File-Ebene statt an Aufrufstellen.

## Datenbank-Portabilität (kritisch)

Dev/Prod laufen auf **MySQL**, die Tests auf **In-Memory-SQLite**. Jede Abfrage muss auf beiden funktionieren.

- **Keine MySQL-only-Funktionen** (kein `YEAR()`, `MONTH()`, `DATE_FORMAT()` …).
- Datumsvergleiche mit **`whereDate()`** und Standard-SQL statt DB-spezifischer Funktionen.
- Im Zweifel gegen die Testsuite (SQLite) prüfen, bevor eine Query als fertig gilt.

## Migrationen

- Beim Ändern von Constraints im selben Schema-Block muss **`dropForeign()` vor
  `dropUnique()`** stehen, sonst MySQL-Fehler 1553.

## Tests

- **Pest 4** mit `RefreshDatabase`. Suites: `tests/Feature`, `tests/Unit`.
- **Keine Factories.** Testdaten direkt über `Model::create()` bzw.
  `Model::forceCreate()` (für guarded Felder) anlegen.
- **Helper-Funktionen mit phasen-Suffix**, um Kollisionen im globalen Namespace zu vermeiden: `makeAdmin_p2()`,
  `makeAdmin_qtl1()`, gemeinsame Helfer in Dateien wie `tests/helpers_p5.php`.
- **Test-Gruppen mit beschreibendem Suffix**, z. B.
  `vendor/bin/pest --group=qualifying-time-lists-p1`. `->group()` auf File-Ebene in `uses()` deklarieren, nicht an
  einzelnen Tests.
- **Verkettete Erwartungen** mit `->and()`, wo möglich.
- Wenn in der Umgebung kein Composer/Netzwerk verfügbar ist: zumindest `php -l`
  zur Syntaxprüfung der geänderten Dateien.
- Vor dem Sign-off: `composer test` (führt zusätzlich `composer lint:check` aus).

## Blade

- Views mit **`@extends('layouts.app')` + `@section('content')`**, nicht mit
  `<x-layouts.app>`.
- Views modulweise unter `resources/views/<modul>` ablegen.
- **`@php use Foo\Bar; @endphp`** für Imports steht am **Dateianfang, vor `@extends`** —
  nicht mitten in `@section('content')`. Funktioniert dort zwar zur Laufzeit (Blades
  `@section`/`@endsection` erzeugt keinen echten PHP-Block-Scope), aber PhpStorm löst den
  Import an dieser Stelle nicht auf ("Missing import statement"). Siehe
  `club-entries/edit-relay.blade.php` für das korrekte Muster.
- **`@json()` teilt sein Argument naiv an jedem Komma**
  (`Illuminate\View\Compilers\Concerns\CompilesJson::compileJson()` macht intern
  `explode(',', ...)`). Nie einen Ausdruck mit eigenen Kommas übergeben (`old('key',
  'default')`, ein mehrteiliges Array-Literal) — erst in eine einzelne Variable schreiben
  (`@php $x = old('key', 'default'); @endphp`, dann `@json($x)`). Ein internes Komma
  verfälscht sonst nur unbemerkt die JSON-Encoding-Flags, zwei oder mehr können den
  kompilierten PHP-Ausdruck abschneiden (`ParseError`). `php artisan view:cache` erkennt
  das nicht (kompiliert nur, führt nichts aus) — nur ein echter Seitenaufruf deckt es auf.
- **`x-data`-Attribute, die `@json()` enthalten, immer einfach anführen** (`x-data='...'`),
  nie doppelt — `@json()`s eingebettete doppelte Anführungszeichen brechen sonst das
  HTML-Attribut.

## Flux UI

- Flux-Formularkomponenten immer mit **`x-model`**, **nie** `:value`.
- Für **IMask** oder komplexe Alpine-Interaktion natives `<input>` statt der Flux-Komponente verwenden.
- **`<flux:select variant="listbox">` + `<flux:select.option :selected="...">`** (Prop-Bindung) für alle
  Dropdowns — die Standard-Variante rendert ohne `@tailwindcss/forms` keinen Pfeil. **Nicht** natives
  `<option>` mit `@selected()`, und **nicht** `@selected()` direkt in `<flux:select.option>` (bricht den
  Blade-Component-Parser). `:selected` funktioniert zuverlässig, weil `UIOption.mount()`
  `hasAttribute("selected")` synchron liest — unabhängig vom kaputten `value=""`-Mechanismus am äußeren
  `<flux:select>` (siehe `docs/specs/admin-ui-rework.md` "Combobox-Fix gefunden"). `<optgroup>` wird zu
  `<flux:select.group label="...">`.
- Flux-Tabellen-Padding korrigieren mit dem Arbitrary-Selector **`[&_td:first-child]:ps-4`** (Flux setzt intern
  `first:ps-0`).
- **`flux:description` bekommt intern eine Vendor-Regel**
  (`[&>*:not([data-flux-label])+[data-flux-description]]:mt-3` in Flux' `field.blade.php`)
  mit strukturell höherer Spezifität als eine einzelne eigene Utility-Klasse — ein normales
  `mt-1` auf der Beschreibung wird davon überstimmt, unabhängig von der Position im
  Stylesheet. Für einen wirksamen Abstand die Tailwind-v4-Important-Syntax verwenden:
  **`mt-1!`**.
- **Auto-Submit bei Änderung eines `flux:select` NICHT über natives `onchange="this.form.submit()"`
  lösen.** `flux:select` ist ein Custom Element (`<ui-select>`); dessen internes "change"-Event feuert mit
  `{bubbles:false}` (`vendor/livewire/flux/dist/flux.min.js`) — auch ein `@change` direkt auf dem
  `<ui-select>` kommt im Test nicht zuverlässig an. Stattdessen `x-model` (ohnehin vorgeschrieben) auf eine
  Alpine-Zustandsvariable binden und in `x-init` per `$watch(...)` den Submit auslösen. `x-model` übernimmt
  dabei auch die Vorbelegung — `:selected()` auf den Optionen wird dann nicht mehr gebraucht. Siehe
  `qualifying-time-lists/qualifications.blade.php` für das durchgespielte Muster.
- **`<flux:select.option value="">` für eine echte, bedeutungsvolle Option (z. B. "Alle" in einer
  festen Auswahl) kommt beim Absenden nie im Request an** — ein leeres `value` wird nicht
  zuverlässig gelesen. Für so eine Option immer einen echten Sentinel-Wert verwenden (z. B.
  `value="ALL"`), nie `""`. Das ist **nicht** dasselbe wie ein `clearable`-Select — dessen leerer
  Zustand läuft über einen eigenen, funktionierenden Platzhalter-Mechanismus. Dabei aber beachten:
  Laravels `ConvertEmptyStringsToNull`-Middleware macht aus einem geleerten `clearable`-Feld beim
  Request `null`, nicht `""` — eine Validierung wie `in_array($x, ['', 'A', 'B'], true)` erkennt
  `null` nicht als gültigen "kein Filter"-Zustand und fällt fälschlich auf den Default zurück;
  vorher explizit `$x !== null &&` prüfen. Siehe `RecordController::index()`
  (`$relayFilter`/`$course`).

## Alpine.js

- Alpine-Logik in **separate `.js`-Dateien** auslagern und über `Alpine.data()`
  registrieren (weniger IDE-Warnungen, testbarer).
- **Doppelinitialisierung vermeiden**: **kein** `import Alpine` /
  `Alpine.start()` in `app.js`. Plugins und Data-Komponenten innerhalb von
  `document.addEventListener('alpine:init', …)` auf `window.Alpine` registrieren.

## Weitere bewährte Muster

- **Session speichert nur IDs**, keine Eloquent-Modelle (Deserialisierung schlägt sonst fehl).
- **Mehrkriterien-Sortierung** über zusammengesetzte **`sprintf()`-Sortierschlüssel**, nicht über `sortBy()` mit
  Closure-Arrays (unzuverlässig).
- **Athletennationalität** über `athlete.nation` (nicht die Vereinsnation), um EU-Bürger mit Wohnsitz in Österreich
  korrekt zu behandeln. Weitere fachliche Regeln je Modul in `docs/specs/`.
