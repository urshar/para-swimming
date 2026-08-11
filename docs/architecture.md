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
| `Livewire`         | Interaktive Komponenten: `StatisticsDashboard`, `Admin/UserManager`, `Admin/BaseTimeTable`, `Admin/ChampionshipStandardTable`, `Admin/ChampionshipQualificationTable`, `Admin/ChampionshipDevelopmentTable`, `Actions/Logout`. |
| `Models`           | Eloquent-Modelle (die Domäne, ~45 Modelle).                                                                                                         |
| `Services`         | Geschäftslogik: Import/Export, Berechnungen, Wertung, Statistik (~26 Services).                                                                     |
| `Support`          | Zustandslose Helfer und Wertobjekte: `TimeParser`, `SportClassSorter`, `SportClassValidator`, `AthleteAge`, `ReportConfiguration`.                  |
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
- **Richtzeiten (national)** – `QualifyingTimeService`,
  `QualifyingTimeCalculationService`, `QualificationDeterminationService`
- **Internationale Qualifikation** – `ChampionshipStandardService` (Normpflege),
  `ChampionshipStandardImportService` (Excel), `QualificationEvaluationService`
  (Erfüllung, rein lesend), `QualificationSelectionService` (Auswahl-Rangliste)
- **World-Aquatics-Basiszeiten** – `BaseTimeImportService`,
  `BaseTimeExportService`, `BaseTimeCalculationService`,
  `WorldAquaticsPointsService`
- **WPS-Punkte** – `WpsPointCalculator` (reinlesend), `WpsPointCalculationService`
  (persistierend), `WpsPointVersionResolver`, `WpsParameterImportService`,
  `WpsScmConversionService`, `WpsScmFactorCalibrationService`
- **LENEX** – `LenexParserService`, `LenexResolverService`, `LenexExportService`
- **Statistik** – `StatisticsService` (Fassade), `ParticipationStatisticsService`,
  `StatisticsExportService`
- **PDF** – `PdfExportService`

## WPS-Punkte im Besonderen

Das Modul berechnet World-Para-Swimming-Punkte über eine Gompertz-Funktion
`q = a · e^(-e^(b - c/p))`. Drei Eigenheiten, die beim Anfassen des Moduls wichtig sind:

- **`results.points` gehört World Aquatics.** WPS-Punkte liegen in eigenen Spalten (`wps_points` und weitere). Ein
  Überschreiben von `points` bräche Cup-Wertung, Richtzeiten und Statistik.
- **Rechnen und Speichern sind getrennt.** `WpsPointCalculator` liefert ein
  `App\Support\WpsPointResult` und schreibt nichts; nur `WpsPointCalculationService`
  persistiert. Ranglisten können damit mit einer bestimmten Version rechnen, ohne gespeicherte Werte zu verändern.
- **Kurzbahn wird umgerechnet, nicht abgeleitet.** Für SCM existieren keine offiziellen Parameter. Die Zeit wird über
  einen Faktor auf ein Langbahn-Äquivalent gebracht (`wps_estimated_lcm_time`), darauf die offizielle Tabelle angewandt.
  Da in Österreich ausschließlich Kurzbahn geschwommen wird, ist das der Regelfall, nicht die Ausnahme. Hintergrund und
  verworfene Alternativen in `docs/specs/wps-points-engine.md` §9.

Bei einer Massenberechnung werden Parametersätze und Umrechnungsfaktoren **einmal** geladen und in PHP nachgeschlagen
(`once()`), nicht je Ergebnis abgefragt. Der Testfall dazu liegt in
`tests/Feature/WpsPointsPhase6Test.php`.

## Internationale Qualifikation im Besonderen

Das Modul beantwortet drei verschiedene Fragen, und die Trennung zwischen ihnen ist sein Kern:

| Frage | Ansicht | Grundlage |
|---|---|---|
| Wer hat sich qualifiziert, und wie weit fehlt den übrigen? | Qualifikanten | **nur Nachweise**: reale Zeiten auf der Bahnlänge der Meisterschaft aus WPS-anerkannten Wettkämpfen |
| Hat der Athlet international eine Chance? | Förderansicht | alles, einschließlich umgerechneter Kurzbahnzeiten — gekennzeichnet |
| Wer fährt? | Auswahl-Rangliste | Nachweise, sortiert nach WPS-Punkten |

Vier Punkte, die beim Anfassen des Moduls wichtig sind:

- **`QualificationStatus::isProof()` ist die einzige Stelle, die über einen Nachweis entscheidet.** Alle drei Ansichten
  teilen sich `QualificationEvaluationService`; getrennt sind ausschließlich Zeilenauswahl und Darstellung. Bewusst
  **kein** Statusfilter über einer gemeinsamen Liste — der wäre die Möglichkeit, sich umgerechnete Zeiten doch wieder in
  die Nachweisliste zu holen.
- **Eine reale Zeit schlägt eine umgerechnete immer**, auch wenn die umgerechnete schneller ist. Der Status trägt eine
  Zeit; gewönne die Schätzung, verschwände die reale aus der Zeile, und angezeigt stünde "rechnerisch erreicht" bei
  jemandem, der auf der Zielbahnlänge nachweislich langsamer war.
- **Nichts wird persistiert.** Übersichten und Ranglisten werden bei jedem Aufruf neu berechnet — wie im Statistikmodul.
- **Durchgehend Wertobjekte statt assoziativer Arrays** (`QualificationStatus`, `QualificationRow`,
  `QualificationResultEntry`, `QualificationAthleteSummary`, `QualificationRankingEntry`,
  `QualificationOverviewFilter`). Bei einem Array ist jeder Zugriff für die statische Analyse ein `mixed`: Tippfehler in
  Schlüsseln fallen erst zur Laufzeit auf, und Methodenaufrufe lassen sich nicht auflösen.

Der Filterstand der Qualifikantenansicht liegt in `QualificationOverviewFilter` und wird von Bildschirm **und**
PDF-Ausgabe verwendet; der PDF-Link trägt ihn als Abfrageparameter mit. Zweimal ausprogrammiert liefen die Regeln
auseinander, und das PDF zeigte etwas anderes als der Bildschirm, von dem aus es erzeugt wurde.

Hintergrund und verworfene Alternativen in `docs/specs/wps-qualification.md`.

## Import-/Export-Pipelines

- **LENEX (`.lxf`)** – Ein `.lxf` ist ein ZIP-Archiv mit einer `.lef`-XML-Datei. Import: ZIP entpacken → XML parsen
  (`LenexParserService`) → mehrstufige Auflösung von Vereinen/Athleten (`LenexResolverService`). Export: umgekehrt,
  Ergebnis wieder als `.lxf`-ZIP. Details je Modul in `docs/specs/`.
- **Excel** – World-Aquatics-Basiszeiten via PhpSpreadsheet (`BaseTimeImportService`).
- **Excel** – WPS Point Scores via PhpSpreadsheet (`WpsParameterImportService`). Dreistufig:
  Formular → Vorschau (parst und validiert, schreibt nichts) → Import in einer Transaktion. Der Vorschauschritt ist
  verbindlich.
- **Excel** – WPS-Qualifikationsnormen via PhpSpreadsheet (`ChampionshipStandardImportService`), ebenfalls dreistufig.
  Der Import füllt **ausschließlich** MQS und MET; ÖBSV-Prozentsätze und -Zeiten bleiben unberührt, damit ein erneuter
  Import die eigenen Festlegungen nicht überschreibt. Die Dateien stammen aus PDF-Konvertierungen und tragen deren
  Eigenheiten (Zeiten als Text, verbundene Zellen, Leerzeilen zwischen den Bewerbsgruppen) — Einzelheiten in
  `docs/specs/wps-qualification.md` §9.2.
- **PDF** – Berichte und Wertungslisten via dompdf (`PdfExportService`, Views unter `resources/views/pdf`).

## Views

Blade-Views liegen modulweise unter `resources/views/<modul>` (z. B.
`records`, `club-entries`, `cups`, `qualifying-time-lists`, `championships`, `statistics`). Layouts unter `resources/views/layouts`,
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
