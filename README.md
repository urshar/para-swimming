# Para Swimming NatDB

Wettkampf- und Athletenverwaltung für das österreichische Para-Schwimmen (ÖBSV). Die Anwendung verwaltet Athleten,
Vereine, Wettkämpfe, Meldungen, Ergebnisse, Rekorde, Cup-Wertung, Richtzeiten (Qualifikation) und Statistiken und
tauscht Daten über das LENEX-Format mit Wettkampf-Software (z. B. Splash Meet Manager, Swimify) aus.

## Tech-Stack

| Bereich   | Technologie                                                           |
|-----------|-----------------------------------------------------------------------|
| Sprache   | PHP 8.3+                                                              |
| Framework | Laravel 13                                                            |
| UI        | Livewire 4 + Flux UI 2, Blade                                         |
| Frontend  | Alpine.js 3, Tailwind CSS 4, Vite 8, IMask (Zeiteingaben), flag-icons |
| Auth      | Laravel Fortify                                                       |
| Datenbank | MySQL (Dev/Prod), SQLite in-memory (Tests)                            |
| Tests     | Pest 4                                                                |
| Code-Stil | Laravel Pint (angepasster Preset)                                     |
| Export    | dompdf (PDF), PhpSpreadsheet (Excel)                                  |
| Node      | 22 (siehe `.nvmrc`)                                                   |

## Voraussetzungen

- PHP **8.3** oder neuer mit den Extensions `dom`, `libxml`, `simplexml`, `zip`
- Composer
- Node **22** und npm
- MySQL (für Dev/Prod)

## Setup

```bash
# 1) Repository klonen und in das Verzeichnis wechseln
git clone https://github.com/urshar/para-swimming.git
cd para-swimming

# 2) Automatisiertes Setup (install, .env, key, migrate, npm build)
composer setup
```

Das mitgelieferte `.env.example` verwendet standardmäßig **SQLite**
(`DB_CONNECTION=sqlite`). Für dieses Projekt wird in Dev/Prod **MySQL**
eingesetzt – nach dem Setup in `.env` entsprechend anpassen:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=para_swimming
DB_USERNAME=root
DB_PASSWORD=
```

Danach die Migrationen (erneut) ausführen:

```bash
php artisan migrate
```

## Entwicklung starten

```bash
composer dev
```

Startet parallel den PHP-Dev-Server, den Queue-Listener und Vite (Hot Reload).

## Tests

Die Testsuite läuft auf **In-Memory-SQLite** (`phpunit.xml`), nicht auf MySQL. Alle Datenbankabfragen müssen daher
portabel zwischen MySQL und SQLite sein (siehe [docs/conventions.md](docs/conventions.md)).

```bash
composer test          # config:clear + Pint-Check + gesamte Testsuite
vendor/bin/pest        # nur die Tests
vendor/bin/pest --group=<gruppe>   # gezielt eine Test-Gruppe
```

## Code-Stil / Linting

```bash
composer lint          # Pint anwenden (formatiert)
composer lint:check    # Pint nur prüfen (CI, ändert nichts)
```

## Projektstruktur (Kurzüberblick)

```
app/
  Http/Controllers/   Dünne Controller, delegieren an Services
  Livewire/           Interaktive Komponenten (Statistik-Dashboard, Admin)
  Models/             Eloquent-Modelle (Domäne)
  Services/           Geschäftslogik (Import/Export, Berechnung, Wertung)
  Support/            Zustandslose Helfer (TimeParser, SportClassSorter …)
  Concerns/           Wiederverwendbare Traits
  Policies/           Autorisierung (EntryPolicy)
  Http/Middleware/    RequireAdmin
database/migrations/  Schema (58 Migrationen)
resources/views/      Blade-Views je Modul
routes/               web.php, settings.php, console.php
tests/                Feature/ und Unit/ (Pest)
docs/                 Projekt-Doku und Modul-Specs
```

Eine ausführliche Beschreibung der Schichten und Muster steht in
[docs/architecture.md](docs/architecture.md).

## Module (fachlicher Überblick)

- **Wettkämpfe & Meldungen** – Meets, Events, Einzel- und Staffelmeldungen je Verein
- **Records** – Rekordverwaltung mit Prüf-/Genehmigungsworkflow, LENEX-Im-/Export
- **Cup-Wertung** – ÖBSV-Cup-Punkte (Tages-, Gesamt- und Jugendwertung)
- **Richtzeiten (Qualifikation)** – World-Aquatics-Punkteformel, Qualifikations-Snapshots
- **World-Aquatics-Basiszeiten** – Excel-Import und Punkteberechnung
- **LENEX Import/Export** – `.lxf`-Austausch mit Wettkampf-Software
- **Statistik & Jahresbericht** – Auswertungen und konfigurierbarer Jahresbericht

## Weiterführende Doku

- [CLAUDE.md](CLAUDE.md) – Anweisungen und Standards für KI-gestützte Coding-Sessions
- [docs/architecture.md](docs/architecture.md) – Architektur und Schichten
- [docs/conventions.md](docs/conventions.md) – Code-, Test- und UI-Konventionen
- [docs/domain-glossary.md](docs/domain-glossary.md) – Fachbegriffe des Para-Schwimmens
- [docs/data-model.md](docs/data-model.md) – Datenmodell und Beziehungen
- `docs/specs/` – Modul-Spezifikationen *(folgen ab Phase 3)*
