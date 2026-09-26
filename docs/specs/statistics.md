# Spec: Statistik & Jahresbericht

Das Statistikmodul wertet Teilnahmen, Vereine, Athleten, Nationen, Sportklassen, Rekorde und die Cup-Gesamtwertung eines
Zeitraums aus und stellt daraus einen konfigurierbaren Jahresbericht zusammen (Ansicht, PDF, Excel, CSV).

Zentrales Prinzip: **keine redundante Statistikdatenbank.** Alle Werte werden bei jedem Aufruf live aus den
Bestandsdaten berechnet; es wird nichts persistiert. Die Fassade enthält keine eigene Auswertungslogik, sondern ruft nur
die bestehenden Services auf.

Fachbegriffe: [../domain-glossary.md](../domain-glossary.md). Tabellen: [../data-model.md](../data-model.md).

## Beteiligte Bausteine

| Baustein                     | Datei                                                  |
|------------------------------|--------------------------------------------------------|
| Konfiguration (Value Object) | `App\Support\ReportConfiguration`                      |
| Fassade                      | `App\Services\StatisticsService`                       |
| Teilnahmen                   | `App\Services\ParticipationStatisticsService`          |
| Rekorde                      | `App\Services\RecordStatisticsService`                 |
| Cup                          | `App\Services\CupStatisticsService`                    |
| Mehrjahres-Trends            | `App\Services\MultiYearStatisticsService`              |
| Export (Excel/CSV)           | `App\Services\StatisticsExportService`                 |
| Dashboard                    | `App\Livewire\StatisticsDashboard`                     |
| Jahresvergleich (Livewire)   | `App\Livewire\YearComparison`                          |
| Chart-Definitionen           | `App\Support\YearComparisonCharts`                     |
| SVG-Mehrlinien-Chart         | `App\Support\TrendChart` + `x-trend-chart`             |
| HTTP                         | `App\Http\Controllers\StatisticsController`            |
| HTTP (Jahresvergleich-PDF)   | `App\Http\Controllers\YearComparisonController`        |
| Referenzabgleich             | `App\Console\Commands\StatisticsReferenceCheckCommand` |

## Konfiguration — `ReportConfiguration`

Unveränderliches Value Object (kein Model/keine Tabelle). Es beschreibt nur, **was** ausgewertet wird:

| Feld                          | Bedeutung                                                      |
|-------------------------------|----------------------------------------------------------------|
| `year`                        | Berichtsjahr                                                   |
| `dateFrom` / `dateTo`         | Auswertungszeitraum (`CarbonImmutable`)                        |
| `meetIds`                     | ausgewählte Veranstaltungen; **leer = alle Meets im Zeitraum** |
| `sections`                    | Abschnitt ⇒ aktiv? (nur Schlüssel aus `SECTION_KEYS`)          |
| `minParticipations`           | Schwelle X für "mind. X Teilnahmen", Default **2**             |
| `oebmMeetIds` / `oejmMeetIds` | Meets, die als ÖBM bzw. ÖJM ausgewertet werden                 |

Kanonische Abschnittsschlüssel (`SECTION_KEYS`, einzige Quelle der Wahrheit, zugleich Ausgabereihenfolge):

```
overview, multi_year, meets, status_by_meet, participants, clubs,
athletes, nations, sport_classes, records, cup, oebm, oejm
```

Verhalten und Fabrikmethoden:

- `fromArray(array)` — baut die Konfiguration aus einem (Request-)Array. Jahr und Zeitraum ergänzen sich: fehlt der
  Zeitraum, wird er aus dem Jahr abgeleitet (1.1.–31.12.); fehlt das Jahr, kommt es aus `date_from`; **fehlt beides →
  Exception** (keine stille Annahme).
- `forYear(year, meetIds, sections)` — Bequem-Konstruktor für ein Kalenderjahr.
- `restrictedToMeets(meetIds)` — Kopie, eingeschränkt auf bestimmte Meets (für die Meisterschaftsabschnitte);
  Zeitraum/Jahr/Schwelle bleiben.
- `enabledSections()` — aktive Abschnitte in `SECTION_KEYS`-Reihenfolge.
- `hasSection()`, `isMeetFiltered()`, `toArray()`.
- **Defaults:** alle bekannten Abschnitte sind standardmäßig aktiv; explizite Werte überschreiben. **Unbekannte
  Abschnittsschlüssel werfen eine Exception**
  (fängt Tippfehler früh ab), ebenso `dateFrom > dateTo` und
  `minParticipations < 1`.

## Fassade — `StatisticsService::generate(config)`

Iteriert über die aktiven Abschnitte und ordnet jeden genau einem Service zu (`match` ohne default-Zweig — ein neuer,
nicht zugeordneter Schlüssel schlägt bewusst mit `UnhandledMatchError` fehl statt still leer zu liefern):

| Abschnitt        | Inhalt / Quelle                                                                                  |
|------------------|--------------------------------------------------------------------------------------------------|
| `overview`       | Basiskennzahlen + `min_participations` + Anzahl Sportler mit ≥ X Teilnahmen + `status_breakdown` |
| `multi_year`     | 5-Jahres-Vergleich (Starts/Teilnehmer, Damen/Herren, Staffeln) — `MultiYearStatisticsService`    |
| `meets`          | je Veranstaltung: Teilnehmer und Starts                                                          |
| `status_by_meet` | je Veranstaltung: Aufschlüsselung Regulär/EXH/DSQ/DNS/DNF/SICK/WDR + Gesamt                      |
| `participants`   | `by_age_group`, `by_gender`, `by_age_group_and_gender`                                           |
| `clubs`         | je Verein: Teilnehmer und Starts                                                                 |
| `athletes`      | je Sportler: Teilnahmen und Starts                                                               |
| `nations`       | je Nation: Teilnehmer und Starts                                                                 |
| `sport_classes` | `by_sport_class`, `by_disability_group`                                                          |
| `records`       | `overview`, `by_athlete`, `by_record_type`                                                       |
| `cup`           | ÖBSV-Cup-Gesamtwertung                                                                           |
| `oebm` / `oejm` | Meisterschaftsauswertung (siehe unten)                                                           |

`generate()` liefert nur die **aktivierten** Abschnitte, immer in
`SECTION_KEYS`-Reihenfolge — Ansicht und PDF können ohne eigene Sortierung darüber iterieren.

## Auswertungsumfang & Kerndefinitionen

Alle Teilnahme-Kennzahlen setzen auf einer gemeinsamen Basis-Query auf (`scopedQuery`):

- Nur **Einzelbewerbe** (`swim_event.relay_count ≤ 1`). **Staffeln sind derzeit ausgeklammert** (der Staffelcup ist noch
  nicht definiert).
- Eingeschränkt auf `meetIds`, sonst alle Meets, deren `start_date` im Zeitraum liegt. Der Datumsvergleich läuft über **
  `whereDate()`** (portabel MySQL/SQLite; verhindert, dass der letzte Zeitraumtag durch Zeitkomponenten herausfällt).
- **Bewusst ohne Status-Filter**, damit sowohl Startzählung als auch Status-Aufschlüsselung darauf aufsetzen.

**Teilnehmer** = Anzahl distinkter Athleten. **Start** = Athlet ist tatsächlich angetreten:

- **Kein Start** (ausgeschlossen): `DNS`, `SICK`, `WDR`.
- **Start** (eingeschlossen): reguläres Ergebnis (`status = null`) sowie `EXH`,
  `DSQ`, `DNF`.

`statusBreakdown()` weist ergänzend jeden Status aus (reguläre Ergebnisse unter dem Schlüssel `regular`), damit der
Bericht auch die Nicht-Start-Fälle zeigen kann.

## Teilnahmen — `ParticipationStatisticsService`

Methoden: `overview`, `statusBreakdown`, `byMeet`, `statusByMeet`, `byClub`, `byAthlete`,
`countAthletesWithMinParticipations`, `byNation`, `bySportClass`,
`byDisabilityGroup`, `byAgeGroup`, `byAgeGroupAndGender`, `byGender`,
`relayStartsByEventGender`.

Regeln, die man kennen sollte:

- **Athleten-Nationalität** stammt aus `athlete.nation`, **nicht** aus der Vereinsnation — in Österreich lebende
  EU-Bürger starten für österreichische Vereine. `byNation` reiht nach Starts (absteigend) und enthält alle beteiligten
  Nationen inkl. AUT. `byClub` trägt den Nationscode je Verein mit, sodass österreichische Vereine
  (`nation.code = 'AUT'`) hervorgehoben werden können.
- **Sportklassen ohne Zuordnung** werden nicht verworfen, sondern als sichtbarer Sammeleintrag **"Ohne Zuordnung"**
  ausgewiesen (`by_disability_group`).
- **Athleten ohne Geburtsdatum** erscheinen als Sammeleintrag **"Ohne Geburtsdatum"** (`by_age_group`).
- `byAthlete` liefert eine 1-basierte Rangliste; `participations` = distinkte Meets, `starts` = Anzahl gewerteter
  Starts.
- `statusByMeet` (Abschnitt `status_by_meet`) schlüsselt je Veranstaltung die Ergebnisstatus auf: `regular`
  (gewertetes Ergebnis, `status = null`) plus `EXH`, `DSQ`, `DNS`, `DNF`, `SICK`, `WDR` und `total`. Nur
  Einzelbewerbe; nach `start_date` sortiert.

## Rekorde — `RecordStatisticsService`

Methoden: `overview`, `byRecordType`, `byAthlete`.

- Abgegrenzt wird über **`set_date`** (Aufstelldatum), **nicht** über `is_current`. Ein im Berichtsjahr geschwommener,
  inzwischen überholter Rekord zählt also mit.
- Datumsvergleich über `whereDate()` auf `set_date` im Zeitraum.
- Ausgeschlossen: `record_status` in `INVALID`, `TARGETTIME`.
- Rekorde **ohne `set_date`** lassen sich keinem Zeitraum zuordnen und fallen heraus.
- `overview` weist u. a. Staffelrekorde separat aus (`relay_count > 1`).

## Cup — `CupStatisticsService`

Methoden: `cupForYear`, `overallRankingForConfiguration`, `overallRanking(Cup)`. Der `cup`-Abschnitt zeigt die
**Gesamtwertung** des zum Berichtsjahr gehörenden Cups; die eigentliche Wertungslogik liegt im Cup-Modul
(`docs/specs/cup-scoring.md`, folgt in einer späteren Phase).

## Mehrjahres-Trends & Jahresvergleich

`MultiYearStatisticsService` liefert die jahresübergreifenden Reihen (Default-Spanne **5 Jahre**, konfigurierbar
2–15). Es rechnet nicht selbst, sondern ruft je Jahr `ParticipationStatisticsService` und
`RecordStatisticsService` auf:

- `series(anchorYear, span)` — Starts/Teilnehmer gesamt, nach Geschlecht (Damen/Herren) und Staffelstarts nach
  Typ (H/D/Mixed) je Jahr. Speist den Berichtsabschnitt `multi_year`.
- `recordsPerYear`, `meetsPerYear`, `statusTrend` — Rekorde, Veranstaltungen und Status-Aufschlüsselung je Jahr.
- `yearRange(anchorYear, span)` — volle Kalenderjahre, am Berichtsjahr verankert (unabhängig von einer
  Meet-Auswahl).

> **Staffeldaten:** Die Staffelreihen sind derzeit ein Platzhalter — es sind keine Staffelergebnisse importiert
> (vgl. offener Punkt „Staffel-Ergebnisse importieren"). Die Zählung selbst
> (`ParticipationStatisticsService::relayStartsByEventGender`) ist implementiert und getestet.

**Jahresvergleich-Seite** (`statistics.comparison`, Livewire `YearComparison`): eigener Menüpunkt mit
konfigurierbarem Jahr und Spanne. Auf dem Bildschirm interaktive `flux:chart`s, im PDF dieselben Reihen als
statisches SVG (`TrendChart` → `x-trend-chart`, da dompdf kein JS ausführt). `YearComparisonCharts` baut die
Chart-Definitionen (Farb-Slots, Serien) für beide Ausgabewege. Im Status-Diagramm sind die **regulären
Ergebnisse standardmäßig ausgeblendet** (Schalter `show_regular`), damit die Entwicklung der Sonderstatus
(EXH/DSQ/DNS/…) nicht von der großen Regulär-Reihe überdeckt wird.

## Meisterschaften (ÖBM / ÖJM)

Kein Datenfeld kennzeichnet ein Meet als Meisterschaft — maßgeblich ist die Auswahl in `oebmMeetIds` / `oejmMeetIds`.
Der Abschnitt wertet **denselben Zeitraum**, eingeschränkt auf diese Meets (`restrictedToMeets`), mit denselben Services
aus und liefert `overview`, `meets` und `athletes`. Ohne ausgewählte Meets bleibt der Abschnitt leer.

## Dashboard — `StatisticsDashboard` (Livewire)

Interaktive Zusammenstellung des Berichts. Öffentliche Fläche: `mount()`, Computed Properties `availableYears()`,
`availableMeets()`, `statistics()`, sowie `updatedYear()`, `resetMeetSelection()`, `formatDate()`, `render()`. Bei
Jahreswechsel wird die Meet-Auswahl zurückgesetzt; `statistics()` ruft die Fassade mit der aktuellen Konfiguration auf.

## HTTP & Export

Routen (unter `auth` + `RequireAdmin` — nur Admins):

| Route                            | Name                        | Zweck                         |
|----------------------------------|-----------------------------|-------------------------------|
| `GET /statistics`                | `statistics.index`          | Einstiegsseite (Dashboard)    |
| `GET /statistics/comparison`     | `statistics.comparison`     | Jahresvergleich (Trend-Seite) |
| `GET /statistics/comparison/pdf` | `statistics.comparison.pdf` | Jahresvergleich als PDF       |
| `GET /statistics/report`         | `statistics.report`         | Bericht als Ansicht           |
| `GET /statistics/report/pdf`     | `statistics.report.pdf`     | PDF (dompdf-Stream)           |
| `GET /statistics/report/xlsx`    | `statistics.report.xlsx`    | Excel-Download                |
| `GET /statistics/report/csv`     | `statistics.report.csv`     | CSV-Download                  |

`StatisticsController` baut aus dem Request eine `ReportConfiguration` (fehlende Abschnittsflags werden auf aktiv
gesetzt) und übergibt sie der Fassade. Der Request-Parameter `charts` (bool) steuert, ob Ansicht und PDF die
Trend-Grafiken einblenden (`showCharts`); ohne ihn bleibt der Bericht rein tabellarisch. Die Datei-Exporte
umfassen immer **alle** aktivierten Abschnitte (kein Einzelabschnitt-Export mehr). `StatisticsExportService`
erzeugt `xlsx()` / `csv()` sowie den `downloadFilename()`; das PDF entsteht aus der Report-View via dompdf.

**dompdf-Fallstrick (Ausrichtung):** In den PDF-Wrappern (`pdf/statistics-report`, `pdf/year-comparison`) läuft
das Links/Rechts-Layout der laufenden Fußzeile bewusst über eine **Zwei-Zellen-Tabelle mit `text-align`**, nicht
über `float`. Ein `float` innerhalb eines `position: fixed`-Elements verschiebt in dompdf umgebrochene
Tabellenzellen (lange Veranstaltungsnamen) an anderer Stelle im Dokument nach rechts — siehe Fallstrick-Liste in
`CLAUDE.md`.

## Referenzabgleich (Artisan)

`php artisan statistics:reference-check` vergleicht die berechnete Statistik eines Jahres mit den Werten eines
vorliegenden (gedruckten) Berichts — zur Verifikation, dass die Live-Berechnung mit den historischen Zahlen
übereinstimmt.

## Berechtigungen

Das Statistikmodul ist **ausschließlich für Administratoren** zugänglich: Die
Statistik-Routen liegen in einer `RequireAdmin`-Gruppe (zusätzlich zu `auth`),
und der Navigationseintrag ist mit `@if(auth()->user()?->is_admin)` gegated.
Das Projekt kennt keine eigene Rechte-/Rollen-Architektur, sondern nur das
`is_admin`-Flag mit der `RequireAdmin`-Middleware. Es werden ausschließlich
lesende Auswertungen erzeugt; es wird nichts geschrieben.

Die Referenzvalidierung des Moduls und die dahinterstehenden fachlichen
Entscheidungen sind in [statistics-validation.md](statistics-validation.md)
dokumentiert.

## Tests

`tests/Unit/`: `ReportConfigurationTest`, `StatisticsServiceTest`,
`ParticipationStatisticsServiceTest`, `RecordStatisticsServiceTest`,
`CupStatisticsServiceTest`, `MultiYearStatisticsServiceTest`, `TrendChartTest`.
`tests/Feature/`: `StatisticsDashboardTest`, `StatisticsReportTest`,
`StatisticsExportTest`, `StatisticsPdfExportTest`, `StatisticsReferenceCheckTest`,
`YearComparisonTest`.
