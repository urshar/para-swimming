# Spec: ÖBSV Cup — Top-Gruppe nach der Zwei-Jahres-Regel, Hand-Einstufung, Historie

Erweitert die Top-Gruppen-Klassifizierung (Stufe 1 in [cup-scoring.md](cup-scoring.md)). Grundlage: offener Punkt
"ÖBSV Cup: Top-Gruppe setzen, Regel über zwei Jahre, Historie" in [../open-points.md](../open-points.md).

**Stand:** in Umsetzung (Branch `feature/cup-top-group`), Phasen 1–3 umgesetzt.

## Entscheidungen (Erik, 31.10.2026)

| # | Frage                                       | Entscheidung                                                                                                         |
|---|---------------------------------------------|----------------------------------------------------------------------------------------------------------------------|
| 1 | Jahreswert "über der Grenze"                | **Beste Tageswertung** des Cup-Jahres über der Grenze (strikt größer, wie bisher).                                   |
| 2 | Cup-Jahr ohne Start                         | Zählt nicht, der Status bleibt; "zwei Jahre hintereinander" bezieht sich auf die Jahre mit Start.                   |
| 3 | Ein Jahr drüber, ein Jahr drunter           | Der Vorjahresstatus bleibt. Neue Athleten starten außerhalb der Top-Gruppe.                                          |
| 4 | Vorjahr nicht eingestuft                    | Fehlende Einstufungen früherer Cups werden vorab vom ältesten an nachgerechnet; bestehende bleiben unverändert.      |
| 5 | Welche Punktgrenze                          | Die Grenze des **einzustufenden** Cups gilt für beide Vorjahre; sie bleibt je Cup einstellbar.                       |
| 6 | Hand-Einstufung im Folgejahr                | Wirkt als Vorjahresstatus weiter.                                                                                    |
| 7 | Kaderende                                   | Das Kaderjahr zählt als Top-Status; Abstieg erst nach zwei Jahren unter der Grenze.                                  |
| 8 | Sichtbarkeit der Historie (nur Verwaltung)  | Einstufungsliste je Cup und Abschnitt auf der Athleten-Detailseite.                                                  |

**Kader-Stichtag:** Ein Athlet gilt im Cup-Jahr als Kader, wenn seine Zugehörigkeit sich mit dem Kalenderjahr
überschneidet (`AthleteKaderMembership::activeDuring`). So zählen auch eine Berufung während der Saison und die
Einstufung vergangener Jahre beim Nachrechnen richtig (bisher: aktiv am Tag der Berechnung).

## Regel

Einstufung für Cup Y, je Athlet, in dieser Reihenfolge:

1. **Hand-Einstufung** (`manual_is_top_group`) gilt, falls gesetzt.
2. **Nationalkader** im Cup-Jahr → Top-Gruppe (`reason = KADER`).
3. **Zwei-Jahres-Regel** gegen den Vorjahresstatus, d. h. die wirksame Einstufung im vorigen Cup (nächstkleineres
   Cup-Jahr; fehlt eine Zeile, gilt "nicht Top"):
    - kein Start im vorigen Cup → Status bleibt (`CARRIED_OVER`);
    - Start im vorigen Cup und im letzten Cup davor mit Start: beide über der Grenze → Top (`TWO_YEARS_ABOVE`),
      beide darunter → nicht Top (`TWO_YEARS_BELOW`), sonst Status bleibt (`CARRIED_OVER`);
    - nur ein Cup mit Start → Status bleibt (`CARRIED_OVER`).

Ein "Start" ist eine Tageswertung (`cup_daily_results`) des Athleten im Cup. Meets ohne berechnete Tageswertung zählen
deshalb nicht.

Eine Zeile bekommen Kader-Athleten, Athleten mit Hand-Einstufung, Athleten, die im vorigen Cup in der Top-Gruppe
waren, und alle mit Start im vorigen Cup. Alle anderen sind nicht in der Top-Gruppe.

## Datenmodell — `cup_top_group_classifications`

| Spalte                                          | Bedeutung                                                                          |
|-------------------------------------------------|------------------------------------------------------------------------------------|
| `is_top_group`                                  | wirksame Einstufung (Hand-Einstufung vor Regel); liest die Tageswertung            |
| `calculated_is_top_group`                       | Ergebnis von Kader bzw. Regel                                                      |
| `reason`                                        | `App\Enums\TopGroupReason`; `POINTS_HISTORY` nur in Einstufungen der alten Regel   |
| `previous_is_top_group`                         | wirksame Einstufung im vorigen Cup; `null` = es gibt keinen früheren Cup           |
| `last_start_year` / `last_start_points`         | letzter früherer Cup mit Start und dessen beste Tageswertung                       |
| `prior_start_year` / `prior_start_points`       | der Cup mit Start davor                                                            |
| `manual_is_top_group`, `manual_note`            | Hand-Einstufung (`null` = keine) und Begründung                                    |
| `manual_set_by`, `manual_set_at`                | wer und wann                                                                       |

`reference_points` (bester Wert über zwei Jahre) entfällt.

## Aktualität

`CupStalenessService::topGroupClassificationStatus()` meldet die Einstufung zusätzlich als veraltet, wenn danach die
Einstufung oder die Tageswertung eines früheren Cups aktualisiert wurde. Eine Neuberechnung von Cup Y rechnet spätere
Cups nicht mit; sie erscheinen dann als veraltet.

## Phasen

1. **Datenmodell und Regel** — Migration, `TopGroupReason`, `TopGroupClassificationService` (Kette, Hand-Einstufung
   bleibt erhalten), Aktualität, Tests (`--group=cup-top-group-p1`). ✔
2. **Einstufungsliste je Cup** — `CupTopGroupController`, Route `cups.top-group.*` (Liste `module:cup,read`, Hand-Einstufung `module:cup,write`), Filter client-seitig (`top-group-filter.js`), Hand-Einstufung setzen/zurücknehmen mit Pflicht-Begründung; Hinweis, wenn Meets des vorigen Cups keine Tageswertung haben. Tests `--group=cup-top-group-p2`. ✔
3. **Historie auf der Athleten-Detailseite** — Abschnitt "ÖBSV Cup — Top-Gruppe" in `athletes/show` (nur mit `module:cup,read`): je Cup-Jahr Einstufung, Grund, Starts mit Punkten, Hand-Einstufung mit Begründung; Link zur Einstufungsliste. Tests `--group=cup-top-group-p3`. ✔
4. **Dokumentation** — `cup-scoring.md`, `data-model.md`, `domain-glossary.md`, offener Punkt.
