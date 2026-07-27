# Spec: Cup-Wertung (ÖBSV Cup)

Die Cup-Wertung ermittelt Tages- und Gesamtwertungen des ÖBSV-Cups auf Basis der World-Aquatics-Punkte. Sie ist als
**Berechnungskette aus drei manuell ausgelösten Snapshots** aufgebaut; jede Stufe ersetzt bei erneutem Lauf ihren
bisherigen Stand.

```
Top-Gruppen-Klassifizierung  →  Tageswertung (je Meet)  →  Gesamtwertung (je Cup-Jahr)
```

Punkteformel und Basiswert-Versionen:
[world-aquatics-base-times.md](world-aquatics-base-times.md). Fachbegriffe (Sportklassengruppen PI/VI/II/T21/HI/TOP,
Kader, Wertungen):
[../domain-glossary.md](../domain-glossary.md). Tabellen: [../data-model.md](../data-model.md).

## Beteiligte Bausteine

| Baustein                            | Datei                                              |
|-------------------------------------|----------------------------------------------------|
| Zuordnung (Gruppe/Alter/Top-Gruppe) | `App\Services\GroupResolverService`                |
| Top-Gruppen-Klassifizierung         | `App\Services\TopGroupClassificationService`       |
| Tageswertung                        | `App\Services\DailyRankingService`                 |
| Gesamtwertung                       | `App\Services\OverallRankingService`               |
| Aktualitäts-Prüfung                 | `App\Services\CupStalenessService`                 |
| Stammdaten (Cups)                   | `App\Http\Controllers\CupController`               |
| Tageswertung (HTTP)                 | `App\Http\Controllers\CupDailyRankingController`   |
| Gesamtwertung (HTTP)                | `App\Http\Controllers\CupOverallRankingController` |
| PDF                                 | `App\Services\PdfExportService`                    |

## Cup-Stammdaten — `Cup`

Felder: `name`, `base_time_version_id` (maßgebliche Basiswert-Version der Saison),
`best_of_count` (Anzahl der besten Tageswertungen für die Gesamtwertung),
`is_active`. Ein Meet gehört über `meets.cup_id` optional zu einem Cup.

Konfiguration je Cup:

- `isGroupActive(SportClassGroup)` — welche Sportklassengruppen gewertet werden (`cup_group_settings`).
- `isGenderCombined(SportClassGroup)` — ob M/F in dieser Gruppe gemeinsam gewertet werden.
- `isAgeGroupActive(AgeGroup, SportClassGroup)` — Altersgruppen-Aktivierung **je Sportklassengruppe**
  (`cup_age_group_settings`).

## Zuordnung — `GroupResolverService`

Reine Zuordnungslogik (keine Wertungsberechnung):

- `resolveSportClassGroup(...)` / `resolveBaseSportClassGroup(...)` — ordnet eine Sportklasse ihrer Gruppe zu
  (`SportClassGroupMember`). `loadSportClassMap()`
  lädt die Zuordnung einmalig gegen N+1 bei Massenverarbeitung.
- `isTopGroup(Result, ?map)` — Top-Gruppen-Zugehörigkeit eines Ergebnisses. Die Top-Gruppe ist eine **virtuelle**
  Sportklassengruppe (`code = 'TOP'`,
  `is_virtual = true`).
- `resolveAgeGroup(...)` — Altersgruppe nach Stichtagsregel (siehe unten).

### Altersregel (31.12.-Stichtag)

Maßgeblich ist das Alter, das der Athlet **am 31. Dezember des Wettkampfjahres**
erreicht (nicht das exakte Alter am Wettkampftag). Sind `Cup` **und**
`SportClassGroup` bekannt, werden die **effektiven** Altersgrenzen der aktiven Altersgruppen verwendet
(`effectiveAgeGroupBoundaries`); sonst gelten die statisch in `AgeGroup` konfigurierten Spannen. Ist für die Kombination
keine Altersgruppe aktiv, erfolgt eine gemeinsame Wertung ohne Alterskategorie.

### Dynamische Altersgrenzen — `effectiveAgeGroupBoundaries`

Eine **deaktivierte** Altersgruppe verschwindet komplett aus der Altersskala; die verbleibenden aktiven Gruppen rücken
lückenlos zusammen:

- die **erste** aktive Gruppe beginnt immer bei **0** (unabhängig von ihrer konfigurierten Untergrenze);
- die **letzte** aktive Gruppe ist immer **nach oben offen**;
- **mittlere** Gruppen behalten ihre Untergrenze; ihre Obergrenze ergibt sich aus der Untergrenze der nächsten aktiven
  Gruppe minus 1.

Beispiel: Jugend (0–18) + offen (19+) + Senioren (50+) → effektiv Jugend 0–18, offen 19–49, Senioren 50+. Ist Senioren
deaktiviert, wird "offen" 19+; ist zusätzlich Jugend deaktiviert, deckt "offen" 0+ die ganze Skala ab.

## Stufe 1 — Top-Gruppen-Klassifizierung (`TopGroupClassificationService`)

`calculateForCup(Cup)` bestimmt zu Saisonbeginn, welche Athleten im Cup-Jahr zur Top-Gruppe gehören (Snapshot in
`cup_top_group_classifications`):

- **Nationalkader-Athleten** sind immer in der Top-Gruppe (`reason = KADER`).
- **Alle anderen**: Auf-/Abstieg anhand der **besten Tageswertung der beiden Kalenderjahre vor dem Cup-Jahr** bei einem
  Cup-Meet. Über dem Schwellenwert → `reason = POINTS_HISTORY`, sonst `reason = null` (nicht in der Top-Gruppe).
- **Ausländische Vereine** (Nation ≠ AUT) gelangen über das Punkte-Kriterium **nicht** in die Top-Gruppe.

Die Top-Gruppe ist ein **saisonaler Snapshot**, keine ergebnisbezogene Live-Prüfung. Dieser Lauf **muss vor** der
Tageswertung desselben Cup-Jahres erfolgen.

## Stufe 2 — Tageswertung (`DailyRankingService`)

`calculateForMeet(Meet)` berechnet die Tageswertung eines Cup-Meets neu und ersetzt den bisherigen Stand
(`cup_daily_results`).

- **Wertungskategorie** = Geschlecht + Sportklassengruppe (**keine**
  Altersgruppe).
- Pro Athlet zählt nur das **beste gültige Ergebnis** des Tages.
- Die Punkte werden **nicht** ungeprüft aus `results.points` übernommen, sondern gegen die **Basiswert-Version des
  Cups** neu berechnet (das beim Import gesetzte `results.points` kann auf einer anderen Version beruhen).
  `results.points` selbst wird dabei **nicht** überschrieben.
- Ergebnisse ohne berechenbare Punkte (z. B. fehlender Basiswert) oder ohne Sportklassengruppe fließen nicht ein.
- **Rang**: gleiche Punkte = gleicher Rang, der nächste Rang überspringt entsprechend (`assignRanks`).
  `rankedBracket(meetId, gender, sportClassGroupId)`
  liefert die Rangliste einer Kategorie.

## Stufe 3 — Gesamtwertung (`OverallRankingService`)

`calculateForCup(Cup)` berechnet die Gesamtwertung des Cup-Jahres aus den persistierten Tageswertungen
(`cup_overall_results`).

- **Wertungskategorie** = Geschlecht + Sportklassengruppe + **Altersgruppe**
  (die Altersgruppe kommt **nur hier** dazu, nicht in der Tageswertung).
- Je Athlet und Kategorie werden die besten **`cup.best_of_count`**
  Tageswertungen aufsummiert.
- `counted_meet_ids` (JSON) hält die IDs der gezählten Meets — **nicht** die Tageswertungs-IDs, da diese bei
  Neuberechnung veralten würden. `club_id` ist der Verein des punktbesten gezählten Tages.
- Buckets: Sportklassengruppe × Altersgruppe × Geschlecht; Rang analog zur Tageswertung.
  `rankedBracket(cupId, gender, sportClassGroupId, ageGroupId)`,
  `brackets(cup)`.

## Aktualität — `CupStalenessService`

Prüft **ausschließlich Zeitstempel** und löst nichts automatisch aus. Ändert sich eine vorgelagerte Stufe nach der
letzten Berechnung einer nachgelagerten Stufe, gilt Letztere als veraltet und muss manuell nachgerechnet werden.
Methoden:
`topGroupClassificationStatus(cup)`, `dailyRankingStatus(meet)`,
`overallRankingStatus(cup)`.

## HTTP & PDF

- `CupController` — Cup-Stammdaten (Ressource ohne `show`); `classifyTopGroup(Cup)`
  stößt Stufe 1 an.
- `CupDailyRankingController` — je Meet: `show`, `pdf` (`pdf.cup-daily-ranking`),
  `calculate` (Stufe 2).
- `CupOverallRankingController` — `index` (Cup-Wertungsübersicht), je Cup: `show`,
  `pdf` (`pdf.cup-overall-ranking`, Querformat), `calculate` (Stufe 3).

## Routen

Alle unter `auth`:

| Route                                            | Name                                |
|--------------------------------------------------|-------------------------------------|
| `resource cups` (ohne `show`)                    | `cups.*`                            |
| `POST /cups/{cup}/classify-top-group`            | `cups.classify-top-group`           |
| `GET /cup-wertung`                               | `cups.overall-ranking.index`        |
| `GET /cups/{cup}/overall-ranking`                | `cups.overall-ranking.show`         |
| `GET /cups/{cup}/overall-ranking/pdf`            | `cups.overall-ranking.pdf`          |
| `POST /cups/{cup}/overall-ranking/calculate`     | `cups.overall-ranking.calculate`    |
| `GET /meets/{meet}/cup-daily-ranking`            | `meets.cup-daily-ranking.show`      |
| `GET /meets/{meet}/cup-daily-ranking/pdf`        | `meets.cup-daily-ranking.pdf`       |
| `POST /meets/{meet}/cup-daily-ranking/calculate` | `meets.cup-daily-ranking.calculate` |

## Tests

- `tests/Unit/`: `GroupResolverServiceTest`, `DailyRankingServiceTest`,
  `OverallRankingServiceTest`, `TopGroupClassificationServiceTest`,
  `CupStalenessServiceTest`, `CupAgeGenderSettingsTest`, `CupWertungPhase1ModelTest`.
- `tests/Feature/`: `CupWertungPhase1Test`, `CupSettingsFormTest`,
  `CupDailyRankingControllerTest`, `CupOverallRankingControllerTest`,
  `CupPdfExportTest`, `MeetCupAssignmentTest`.

Die Cup- **Statistik** (Einbindung in den Jahresbericht) ist in
[statistics.md](statistics.md) beschrieben (`CupStatisticsService`).
