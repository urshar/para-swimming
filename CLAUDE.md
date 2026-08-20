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
- **Kein** `<flux:select.option>` mit `@selected()` – bricht den Blade-Parser; stattdessen natives `<option>`.
- Flux-Tabellen-Padding: `[&_td:first-child]:ps-4` (Flux setzt intern
  `first:ps-0`).
- Alpine-Logik in separate `.js`-Dateien auslagern und via `Alpine.data()`
  registrieren (reduziert IDE-Warnungen).

## Bewährte Architektur-Muster (Fallstricke)

- **Session speichert nur IDs**, keine Eloquent-Modelle (Deserialisierung schlägt sonst fehl).
- **Mehrkriterien-Sortierung**: `sortBy()` mit Closure-Arrays ist unzuverlässig – stattdessen zusammengesetzte
  `sprintf()`-Sortierschlüssel.
- **Alpine-Doppelinitialisierung** vermeiden: kein `import Alpine` / `Alpine.start()`
  in `app.js`; Plugins/Data in `document.addEventListener('alpine:init', …)` auf
  `window.Alpine` registrieren.

## Lieferung von Änderungen

Alle geänderten/neuen Dateien als **ein einziges ZIP** liefern, nicht einzeln.

- **Barrierefreiheit** nach `docs/accessibility.md` ist Teil der Definition von "fertig".
- Der **öffentliche Bereich** nutzt Tailkit-Snippets, nicht Flux — siehe `docs/specs/public-frontend.md` §3.1.
