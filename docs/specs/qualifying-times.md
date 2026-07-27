# Spec: Richtzeiten & Qualifikation

Das Modul verwaltet **Richtzeiten** (Sollzeiten für die Qualifikation zu ÖSTM & ÖM), berechnet sie automatisch aus
Zielpunkten und Basiszeiten und ermittelt anschließend, welche Schwimmer die Richtzeiten im Qualifikationszeitraum
erreicht haben. Ergebnis der Ermittlung ist ein **Snapshot** (`qualifications`).

Punkteformel und Basiszeiten: [world-aquatics-base-times.md](world-aquatics-base-times.md). Tabellen
(`qualifying_time_lists`, `qualifying_target_points`,
`qualifying_times`, `qualifying_excluded_disciplines`, `qualifications`):
[../data-model.md](../data-model.md).

## Beteiligte Bausteine

| Baustein                           | Datei                                                         |
|------------------------------------|---------------------------------------------------------------|
| CRUD (Listen, Zielpunkte, Zeilen)  | `App\Services\QualifyingTimeService`                          |
| Richtzeiten-Berechnung             | `App\Services\QualifyingTimeCalculationService`               |
| Qualifikations-Ermittlung          | `App\Services\QualificationDeterminationService`              |
| natürliche Sportklassen-Sortierung | `App\Support\SportClassSorter`                                |
| HTTP (Listen, PDF, Berechnung)     | `App\Http\Controllers\QualifyingTimeListController`           |
| Ausschluss-Disziplinen             | `App\Http\Controllers\QualifyingExcludedDisciplineController` |
| PDF                                | `App\Services\PdfExportService`                               |

## Datenmodell (Kurzform)

- **`QualifyingTimeList`** — eine Richtzeitenliste (`year`, `is_active`, Qualifikationszeitraum, optional einem
  Ziel-Meet zugeordnet).
- **`QualifyingTargetPoint`** — Zielpunktzahl `P` je Sportklasse innerhalb einer Liste.
- **`QualifyingTime`** — konkrete Richtzeit je `(Liste, stroke_type, gender,
  sport_class)` in `value_centiseconds`, mit `source` = `MANUAL` (Default) oder
  `CALCULATED`.
- **`QualifyingExcludedDiscipline`** — Basiswert-Bewerbe, die bei ÖSTM & ÖM nicht ausgetragen werden und daher keine
  Richtzeit bekommen.
- **`Qualification`** — Snapshot einer erfüllten Qualifikation.

## Richtzeiten-Berechnung — `QualifyingTimeCalculationService`

`calculateForList(QualifyingTimeList $list, bool $overwriteManual = false)`
berechnet die Richtzeiten als **inverse Anwendung der World-Aquatics-Formel**:

```
P = 1000 × (B/T)³   ⟺   T = B / (P/1000)^(1/3)
```

- `B` = Basiszeit aus den Basiswert-Tabellen (siehe
  [world-aquatics-base-times.md](world-aquatics-base-times.md)).
- `P` = Zielpunkte der Sportklasse (`QualifyingTargetPoint`).

Iteriert über die Kombinationen Kurs/Geschlecht/Bewerb/Sportklasse. Bewerbe **ohne Basiswert** werden übersprungen; als
**Ausschluss-Disziplin** markierte Bewerbe (`QualifyingExcludedDiscipline`) ebenfalls. **Manuell gesetzte**
Richtzeiten (`source = MANUAL`) bleiben bei einer Neuberechnung erhalten — außer `overwriteManual = true`. Berechnete
Zeilen werden mit
`source = CALCULATED` geschrieben.

## Qualifikations-Ermittlung — `QualificationDeterminationService`

`calculateForList(QualifyingTimeList $list)` ermittelt alle Schwimmer, die im Qualifikationszeitraum eine Richtzeit
erreicht haben.

**Qualifikationszeitraum** wird direkt an der Liste gepflegt, **nicht** aus dem Ziel-Meet abgeleitet: Das Ziel-Meet des
Folgejahres existiert zum Zeitpunkt der Ermittlung oft noch nicht; der Zeitraum beginnt mit der bereits stattgefundenen
Vorjahres-ÖSTM & ÖM. Das Periodenende kann vorläufig sein (`period_end_is_provisional`).

Ablauf:

1. Kandidaten sind gültige Ergebnisse im Zeitraum mit Event, Athlet, gesetzter
   `sport_class` und Geschlecht `M`/`F`.
2. Zuordnung über den Schlüssel `stroke_type_id | distance | gender |
   sport_class` zur passenden Richtzeit.
3. **Erreicht**, wenn `result.swim_time ≤ qualifying_time.value_centiseconds` — verglichen wird die **Zeit**, nicht die
   Punktzahl.
4. Je `(Athlet, Richtzeit)` wird das **schnellste** Ergebnis behalten.

**Snapshot-Prinzip:** In einer Transaktion werden alle `Qualification`-Zeilen der Liste gelöscht und neu aufgebaut.
Denormalisiert gespeichert werden
`sport_class`, `club_id`, `points`, `swim_time_centiseconds` und
`qualified_at` (= `result.meet.start_date`), damit spätere Korrekturen an Athleten/Vereinen bestehende
Qualifikationslisten nicht rückwirkend verändern. Das Ziel-Meet (`meet_id`) wird — falls zugeordnet — rein informativ
mitgespeichert und ist für die Berechnung nicht erforderlich.

## Sortierung & Gruppierung — `SportClassSorter`

Liefert einen Sortierschlüssel `sprintf('%s-%05d', Präfix, Nummer)` (z. B.
`S-00009`), damit Sportklassen **numerisch** statt alphabetisch sortieren — reine String-Sortierung würde `S10`
fälschlich vor `S2`/`S9` einordnen. Wird für die gruppierte Anzeige von Richtzeiten und Qualifikationen verwendet.

## HTTP & PDF

`QualifyingTimeListController` (Ressource + Zusatzaktionen):

| Methode                                                               | Zweck                                          |
|-----------------------------------------------------------------------|------------------------------------------------|
| `index` / `create` / `store` / `show` / `edit` / `update` / `destroy` | Listen-CRUD                                    |
| `storeTargetPoint` / `destroyTargetPoint`                             | Zielpunkte je Sportklasse                      |
| `storeTime` / `destroyTime`                                           | Richtzeiten-Zeilen (manuell)                   |
| `calculate`                                                           | Richtzeiten (neu) berechnen                    |
| `qualifications`                                                      | Qualifikationen ansehen                        |
| `calculateQualifications`                                             | Qualifikationen (neu) ermitteln                |
| `pdfTimes`                                                            | Richtzeiten als PDF (`pdf.qualifying-times`)   |
| `pdfQualifications`                                                   | Qualifikationen als PDF (`pdf.qualifications`) |

`QualifyingExcludedDisciplineController`: `index`, `store(BaseTimeDiscipline)`,
`destroy(BaseTimeDiscipline)` — pflegt die Menge der bei ÖSTM & ÖM nicht ausgetragenen Bewerbe.

## Routen

Alle unter `auth`:

| Route                                                                | Name                                              |
|----------------------------------------------------------------------|---------------------------------------------------|
| `resource qualifying-time-lists`                                     | `qualifying-time-lists.*`                         |
| `GET …/{list}/qualifications`                                        | `qualifying-time-lists.qualifications`            |
| `GET …/{list}/pdf`                                                   | `qualifying-time-lists.pdf`                       |
| `GET …/{list}/qualifications/pdf`                                    | `qualifying-time-lists.qualifications.pdf`        |
| `POST …/{list}/target-points` · `DELETE …/{list}/target-points/{tp}` | `…target-points.store` · `…target-points.destroy` |
| `POST …/{list}/times` · `DELETE …/{list}/times/{time}`               | `…times.store` · `…times.destroy`                 |
| `POST …/{list}/calculate`                                            | `qualifying-time-lists.calculate`                 |
| `POST …/{list}/qualifications/calculate`                             | `qualifying-time-lists.qualifications.calculate`  |
| `GET/POST/DELETE qualifying-excluded-disciplines[/{discipline}]`     | `qualifying-excluded-disciplines.*`               |

## Tests

- `QualifyingTimeListPhase1Test`, `…Phase2Test`, `…Phase3Test`, `…Phase7Test`
  — Listen-CRUD, Zielpunkte, Berechnung, PDF.
- `QualificationPhase4Test`, `QualificationPhase5And6Test` — Ermittlung und Snapshot.
- `QualifyingTimeGroupingTest`, `QualificationGroupingTest` — gruppierte Anzeige (Sortierung).
- `QualifyingExcludedDisciplineTest` — Ausschluss-Disziplinen.
- `MeetQualifyingTimeListAssignmentTest` — Zuordnung Liste ↔ Meet.
