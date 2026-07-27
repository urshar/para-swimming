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

## Flux UI

- Flux-Formularkomponenten immer mit **`x-model`**, **nie** `:value`.
- Für **IMask** oder komplexe Alpine-Interaktion natives `<input>` statt der Flux-Komponente verwenden.
- **Kein `<flux:select.option>` mit `@selected()`** – das bricht den Blade-Component-Parser. Stattdessen natives
  `<option>`.
- Flux-Tabellen-Padding korrigieren mit dem Arbitrary-Selector **`[&_td:first-child]:ps-4`** (Flux setzt intern
  `first:ps-0`).

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
