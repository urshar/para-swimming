# Architektur

Para Swimming NatDB ist eine klassische Laravel-Anwendung mit einer bewusst ausgeprägten **Service-Schicht**. Controller
bleiben dünn, die fachliche Logik liegt in Services, die Modelle bilden die Domäne ab.

## Schichten im Überblick

```
HTTP-Request
   │
   ▼
Route (routes/web.php)  ──►  Middleware (auth, RequireAdmin)
   │
   ▼
Controller / Livewire-Komponente      ← dünn: validiert, delegiert, rendert
   │
   ▼
Service (app/Services)                 ← Geschäftslogik, Berechnung, Im-/Export
   │
   ▼
Model (app/Models, Eloquent)           ← Persistenz und Domänen-Beziehungen
   │
   ▼
Datenbank (MySQL / SQLite-Tests)
```

Reine Darstellungslogik und wiederholte Formatierung wandern in **Support-Klassen** (`app/Support`) oder Blade-Views;
querschnittliche Wiederverwendung in **Traits** (`app/Concerns`).

## Verzeichnisstruktur (`app/`)

| Verzeichnis        | Rolle                                                                                                                                               |
|--------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------|
| `Http/Controllers` | Dünne Controller; ein Controller je Ressource (RESTful) plus dedizierte Import-/Export-Controller. Admin-Controller unter `Http/Controllers/Admin`. |
| `Livewire`         | Interaktive Komponenten: `StatisticsDashboard`, `Admin/UserManager`, `Admin/BaseTimeTable`, `Actions/Logout`.                                       |
| `Models`           | Eloquent-Modelle (die Domäne, ~45 Modelle).                                                                                                         |
| `Services`         | Geschäftslogik: Import/Export, Berechnungen, Wertung, Statistik (~26 Services).                                                                     |
| `Support`          | Zustandslose Helfer: `TimeParser`, `SportClassSorter`, `ReportConfiguration`.                                                                       |
| `Concerns`         | Traits: `PasswordValidationRules`, `ProfileValidationRules`, `SearchesAthletes`.                                                                    |
| `Policies`         | Autorisierung: `EntryPolicy` (wer darf Meldungen verwalten).                                                                                        |
| `Http/Middleware`  | `RequireAdmin`.                                                                                                                                     |
| `Providers`        | `AppServiceProvider`, `FortifyServiceProvider`.                                                                                                     |
| `Console/Commands` | Artisan-Commands, z. B. `StatisticsReferenceCheckCommand`.                                                                                          |

## Service-Muster

Services sind in der Regel `final readonly class` mit Constructor-Injection ihrer Abhängigkeiten. Größere Module
verwenden ein **Fassaden-Muster**: ein Service bündelt mehrere Einzelservices und ordnet deren Ergebnisse zusammen,
enthält aber selbst keine eigene Auswertungslogik.

Beispiel `StatisticsService` (Fassade des Statistikmoduls):

```php
final readonly class StatisticsService
{
    public function __construct(
        private ParticipationStatisticsService $participation,
        private RecordStatisticsService $records,
        private CupStatisticsService $cup,
        // …
    ) {}
}
```

Merkmale, die sich durchziehen:

- **Keine Persistenz in Fassaden.** Statistik-/Wertungswerte werden bei jedem Aufruf neu aus den Bestandsdaten
  berechnet, nicht gespeichert.
- **Ausführliche PHPDoc-Blöcke** an Services, die fachliche Entscheidungen und Sonderfälle erklären.
- **Konfiguration über Support-Objekte** (z. B. `ReportConfiguration`), die Reihenfolge und aktive Abschnitte kapseln,
  sodass View und PDF ohne eigene Sortierung darüber iterieren.

## Module und ihre Services

- **Meldungen** – `ClubEntryService`, `RelayClassValidator`
- **Records** – `RecordCheckerService`, `RecordImportService`,
  `RecordLenexExportService`, `RecordStatisticsService`
- **Cup-Wertung** – `GroupResolverService`, `DailyRankingService`,
  `OverallRankingService`, `TopGroupClassificationService`,
  `CupStalenessService`, `CupStatisticsService`
- **Richtzeiten / Qualifikation** – `QualifyingTimeService`,
  `QualifyingTimeCalculationService`, `QualificationDeterminationService`
- **World-Aquatics-Basiszeiten** – `BaseTimeImportService`,
  `BaseTimeExportService`, `BaseTimeCalculationService`,
  `WorldAquaticsPointsService`
- **LENEX** – `LenexParserService`, `LenexResolverService`, `LenexExportService`
- **Statistik** – `StatisticsService` (Fassade), `ParticipationStatisticsService`,
  `StatisticsExportService`
- **PDF** – `PdfExportService`

## Import-/Export-Pipelines

- **LENEX (`.lxf`)** – Ein `.lxf` ist ein ZIP-Archiv mit einer `.lef`-XML-Datei. Import: ZIP entpacken → XML parsen
  (`LenexParserService`) → mehrstufige Auflösung von Vereinen/Athleten (`LenexResolverService`). Export: umgekehrt,
  Ergebnis wieder als `.lxf`-ZIP. Details je Modul in `docs/specs/`.
- **Excel** – World-Aquatics-Basiszeiten via PhpSpreadsheet (`BaseTimeImportService`).
- **PDF** – Berichte und Wertungslisten via dompdf (`PdfExportService`, Views unter `resources/views/pdf`).

## Views

Blade-Views liegen modulweise unter `resources/views/<modul>` (z. B.
`records`, `club-entries`, `cups`, `qualifying-time-lists`, `statistics`). Layouts unter `resources/views/layouts`,
gemeinsame Bausteine unter
`components` und `partials`, PDF-Templates unter `pdf`.

Konventionen zu Blade/Flux/Alpine: siehe [conventions.md](conventions.md).

## Routing und Autorisierung

- `routes/web.php` – Hauptrouten. `/` und `/dashboard` leiten auf `/meets` um. Authentifizierte Routen liegen in einer
  `auth`-Gruppe; der Admin-Bereich zusätzlich hinter der `RequireAdmin`-Middleware (`/admin`-Prefix).
- `routes/settings.php` – Einstellungen/Profil (Fortify).
- `routes/console.php` – Artisan/Scheduler.
- **Autorisierung** über die `EntryPolicy` (z. B. ob ein Verein für einen Meet noch melden darf – abhängig vom
  Meldeschluss) und die `RequireAdmin`-Middleware.

## Authentifizierung

Über **Laravel Fortify** (`FortifyServiceProvider`, `app/Actions/Fortify`). Nutzer haben `is_admin` und eine `club_id`;
ein großer Teil der Logik hängt an
`auth()->user()->club_id` (Vereinsbindung von Meldungen).

## Tests

Zwei Suites (`tests/Feature`, `tests/Unit`) auf **In-Memory-SQLite**. Unit-Tests prüfen einzelne Services isoliert (z.
B. `GroupResolverServiceTest`,
`SportClassSorterTest`), Feature-Tests ganze Abläufe inkl. HTTP/Livewire (z. B. `StatisticsDashboardTest`,
`RelayEntryFeatureTest`). Wegen SQLite in den Tests ist **DB-Portabilität** Pflicht
(siehe [conventions.md](conventions.md)).
