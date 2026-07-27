# Spec: World-Aquatics-Basiszeiten & Punkte

Dieses Modul verwaltet die **World-Aquatics-Basiszeiten** (versioniert) und berechnet daraus die WA-Punkte eines
Ergebnisses nach

```
P = 1000 × (B / T)³
```

mit `B` = Basiszeit (`base_times.value_centiseconds`) und `T` = Schwimmzeit (`results.swim_time`) — beide in
Hundertstelsekunden. Die Basiszeiten sind die Grundlage für die Cup-Wertung und die Richtzeiten.

Tabellen (`base_time_versions`, `_categories`, `_disciplines`, `_sport_classes`,
`base_times`, `_derivation_rules`): [../data-model.md](../data-model.md).

## Beteiligte Bausteine

| Baustein                         | Datei                                                  |
|----------------------------------|--------------------------------------------------------|
| Punkteberechnung                 | `App\Services\WorldAquaticsPointsService`              |
| Basiswert-Berechnung (Ableitung) | `App\Services\BaseTimeCalculationService`              |
| Excel-Import                     | `App\Services\BaseTimeImportService`                   |
| Excel-Export                     | `App\Services\BaseTimeExportService`                   |
| Versionen (CRUD)                 | `App\Http\Controllers\BaseTimeVersionController`       |
| Kategorien (Ansicht)             | `App\Http\Controllers\BaseTimeCategoryController`      |
| Import / Export (HTTP)           | `BaseTimeImportController`, `BaseTimeExportController` |
| Punkte-Neuberechnung (HTTP)      | `App\Http\Controllers\WorldAquaticsPointsController`   |
| Inline-Bearbeitung               | `App\Livewire\Admin\BaseTimeTable`                     |

## Werttypen — `BaseTime::value_type`

| Typ              | Bedeutung                                             |
|------------------|-------------------------------------------------------|
| `MANUAL`         | offizieller Basiswert (Weltrekord), von Hand pflegbar |
| `CALCULATED`     | aus MANUAL-Werten über Ableitungsregeln berechnet     |
| `NOT_APPLICABLE` | Bewerb existiert für diese Sportklasse nicht (Wert 0) |

## Versionen

`BaseTimeVersion` (`label`, `valid_from`, `valid_until`) versioniert den gesamten Basiswert-Satz. Der Scope
`validOn(date)` liefert die zum Datum gültige Version (`valid_from ≤ date` **und** (`valid_until` null oder `≥ date`)).
Diese Zuordnung steuert automatisch, welche Basiswerte für ein Meet herangezogen werden. `BaseTimeVersionController`
bietet die übliche Ressourcen-CRUD.

## Excel-Import — `BaseTimeImportService`

Importiert die World-Aquatics-Basiswert-Excel-Datei. Je Arbeitsblatt eine Kategorie (LC/SC × Men/Women sowie LC/SC
Mixed). Zellinterpretation:

| Zelle               | Ergebnis                                                                                                                        |
|---------------------|---------------------------------------------------------------------------------------------------------------------------------|
| Literal, Wert `0`   | `NOT_APPLICABLE` (Bewerb für diese Klasse nicht vorhanden)                                                                      |
| Literal, Wert `> 0` | `MANUAL` (offizieller Weltrekord, editierbar)                                                                                   |
| Formel              | `CALCULATED` — der von Excel gecachte Wert wird übernommen; die Formel-Referenz (Bewerbs-Paar + Ratio) wird automatisch erkannt |

Ablauf: `parse(filePath)` liest die Datei und liefert eine **Vorschau** ohne DB-Änderung;
`import(filePath, versionData)` legt eine neue Version an und speichert die Werte;
`importIntoExistingVersion(filePath, version)` importiert in eine bestehende Version (in einer Transaktion).
Sportklassen-Codes, die in der Excel-Datei abweichend heißen, werden über eine Mapping-Tabelle normalisiert.

Der HTTP-Ablauf (`BaseTimeImportController`): `showForm()` → `preview(Request)`
→ `run()`.

## Basiswert-Berechnung — `BaseTimeCalculationService`

Berechnet die `CALCULATED`-Werte einer Version aus den `MANUAL`-Werten und den Ableitungsregeln
(`base_time_derivation_rules`). Es werden **ausschließlich**
`CALCULATED`-Zeilen aktualisiert; `MANUAL` und `NOT_APPLICABLE` bleiben unangetastet.

Ablauf je Kategorie:

1. Start-Matrix (Bewerb × Sportklasse) aus allen `MANUAL`-Werten der Kategorie.
2. Ableitungsregeln iterativ anwenden, solange sie sich auf Werte stützen können (auch auf bereits in dieser Iteration
   berechnete). Eine Regel füllt beide Richtungen: längerer Bewerb `= kürzerer × (1 + ratio)`, kürzerer
   `= längerer / (1 + ratio)`.
3. Der Ratio ergibt sich aus dem durchschnittlichen Wachstum zwischen den referenzierten Bewerben über die relevanten
   Sportklassen (`averageGrowthRatio`).

Kategorien mit **Cross-Kategorie-Ratio-Referenz** (z. B. LC Mixed → LC Men)
werden per **topologischer Sortierung** erst nach ihrer Referenzkategorie verarbeitet (`topologicalOrder`,
`dependentCategoryIds`,
`reverseDependencyEdges`).

Einstiegspunkte: `recalculateVersion(version)` (ganze Version) und
`recalculateCategory(version, category)` (eine Kategorie nach einer MANUAL-Änderung, inkl. abhängiger Kategorien).

## Punkteberechnung — `WorldAquaticsPointsService`

`recalculateForMeet(Meet, ?BaseTimeVersion)` berechnet und **speichert** die Punkte aller Ergebnisse eines Meets neu;
der Rückgabewert meldet
`updated`/`skipped` samt Gründen. Eine explizit übergebene Version übersteuert die automatische Zuordnung nach
Wettkampfdatum. Der berechnete Wert überschreibt
`results.points` auch dann, wenn beim LENEX-Import bereits ein (womöglich veralteter) Wert gesetzt war.

Zuordnung eines Ergebnisses zum Basiswert:

1. **Version**: übergeben oder automatisch über `resolveAutomaticVersion` (=
   `validOn(meet.start_date)`).
2. **Geschlecht**: bei Staffeln das Bewerbsgeschlecht (Mixed `X` erlaubt), bei Einzelbewerben das Geschlecht des
   Athleten (`M`/`F`, sonst übersprungen).
3. **Kategorie**: `course` + `gender`.
4. **Bewerb**: `stroke_type_id` + `distance` + `relay_count`.
5. **Sportklasse**: normalisiert auf `S<n>` (Stroke-Präfix `SB`/`SM` entfällt, da die Basiswert-Tabelle keinen Präfix
   führt).

Wird eine Kombination nicht aufgelöst oder ist der Basiswert `NOT_APPLICABLE`
bzw. `≤ 0`, wird das Ergebnis mit einem sprechenden Grund übersprungen. Ergänzend liefern `calculatePoints(...)` (ohne
Speichern) und
`findOutdatedResults(...)` (rein lesend) Werte für Vorschau und Konsistenz-Checks.

Die Neuberechnung wird per HTTP über `WorldAquaticsPointsController::recalculate`
(`POST /meets/{meet}/recalculate-points`) angestoßen.

## Ansicht & Inline-Bearbeitung

`BaseTimeCategoryController` zeigt die Kategorien einer Version (`index`) und die Wertematrix einer Kategorie (`show`).
Die Matrix wird über die Livewire-Komponente
`Admin\BaseTimeTable` bearbeitet: `MANUAL`-Werte lassen sich inline pflegen, und
`recalculate()` stößt die Neuberechnung der `CALCULATED`-Werte über
`BaseTimeCalculationService` an.

## Excel-Export — `BaseTimeExportService`

`export(BaseTimeVersion)` schreibt die Version als `.xlsx` und gibt den Dateipfad zurück; `downloadFilename(version)`
liefert den Download-Namen (z. B. `OeBSV-Base-Times_2021-2026.xlsx`). Auslieferung über
`BaseTimeExportController::export`.

## Routen

Alle unter `auth`. Prefix `base-times`:

| Route                                                                    | Name                               |
|--------------------------------------------------------------------------|------------------------------------|
| `GET /base-times/import` · `POST …/import/preview` · `POST …/import/run` | `base-times.import[.preview/.run]` |
| `resource versions` (ohne `show`)                                        | `base-times.versions.*`            |
| `GET /base-times/{version}/categories`                                   | `base-times.categories.index`      |
| `GET /base-times/{version}/categories/{category}`                        | `base-times.categories.show`       |
| `GET /base-times/{version}/export`                                       | `base-times.export`                |

Zusätzlich (außerhalb des Prefix): `POST /meets/{meet}/recalculate-points`
→ `meets.recalculate-points`.

## Tests

- `tests/Feature/WorldAquaticsPointsServiceTest.php` — Punkteformel und Zuordnung.
- `tests/Feature/BaseTimeCalculationServiceTest.php` — Ableitung der
  `CALCULATED`-Werte.
- `tests/Feature/BaseTimeImportServiceTest.php` — Excel-Import und Zell-Typen.
- `tests/Feature/BaseTimeExportServiceTest.php` — Excel-Export.
- `tests/Feature/BaseTimeCrudTest.php` — Versionen/Kategorien-CRUD.
