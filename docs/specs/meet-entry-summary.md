# Spec: Melde-Kennzahlen und Medaillenplanung

> **Status: geplant, noch nicht umgesetzt** (mit Erik abgestimmt am 07.11.2026). Diese Spec beschreibt den
> vereinbarten Zielstand. Sobald eine Phase umgesetzt ist, wird der Abschnitt auf den implementierten Stand gebracht.

Sobald es zu einer Veranstaltung Meldungen gibt, zeigt "Alle Meldungen" einen Block "Kennzahlen": wie viele Jugendliche
gemeldet sind, wie viele Sportler in der Wertung der Österreichischen B-Meisterschaft stehen und wie viele Medaillen je
Wertung gebraucht werden. Grundlage sind die [Wertungsgruppen](scoring-groups.md).

Es ist eine **Planzahl aus den Meldungen**, keine Vergabe nach Ergebnissen. Alles wird bei jedem Aufruf neu berechnet und
nicht gespeichert; gespeichert werden nur die Einstellungen.

## Entscheidungen (mit Erik geklärt)

| Frage                         | Entscheidung                                                                                                                       |
|-------------------------------|------------------------------------------------------------------------------------------------------------------------------------|
| Welche Gruppen zählen?        | Einstellung **"Medaillenwertung" ja/nein je Wertungsgruppe** (`awards_medals`). Beim ÖBSV Cup z. B. nein: Dort zählt die Tageswertung |
| Genau 2 Teilnehmer            | Gold + Anerkennung: Wie bei 1 und 3 Teilnehmern bekommt der Letzte die Anerkennungsmedaille                                        |
| Staffeln                      | Eigene Zeile, je Team so viele Medaillen wie Schwimmer (`relay_count`)                                                             |
| Medaillenregel an/aus         | Je Veranstaltung, Standard an, Schalter direkt im Block "Kennzahlen"                                                               |
| Cup-Tageswertung              | Nicht enthalten: Sie hängt von den Ergebnissen ab und lässt sich aus Meldungen nicht verlässlich vorab berechnen                   |

## Medaillenregel

Gezählt wird **je Wertungsgruppe und Bewerb**.

| Teilnehmer   | Regel an                  | Regel aus            |
|--------------|---------------------------|----------------------|
| mindestens 4 | Gold, Silber, Bronze      | Gold, Silber, Bronze |
| 3            | Gold, Silber, Anerkennung | Gold, Silber, Bronze |
| 2            | Gold, Anerkennung         | Gold, Silber         |
| 1            | Anerkennung               | Gold                 |

## Zählregeln

- **Teilnehmer** einer Gruppe sind Meldungen, die zur Gruppe passen. Nicht als Teilnehmer zählen Meldungen mit Status
  `EXH` (AK), `WDR`, `SICK` oder `RJC`.
- **Zuordnung** wie bei Ergebnissen über `ScoringGroup::matches()`: Geschlecht, Klassennummer, Jahrgangsalter,
  Landesverband des meldenden Vereins, Level zum Wettkampfdatum. Die Merkmale kommen aus derselben Logik wie in
  `ScoringGroupService`, damit Meldungen und Ergebnisse gleich zugeordnet werden.
- **Sportklasse:** `entries.sport_class`; ist sie leer, die Klasse des Athleten passend zur Lage (S/SB/SM, wie
  `SwimEvent::classCategory()`). Auf dev ist `entries.sport_class` bei allen Meldungen leer. Die Spalte selbst wird
  nicht befüllt.
- **Mehrfachwertung:** Wer in ÖJM und ÖBM startet, zählt in beiden Wertungen.
- **Jugendliche:** Jede Person einmal, Einzel- und Staffelmeldungen, Alter 0-18 nach der Stichtagsregel
  (`AthleteAge::atEndOf()`, 31. Dezember des Wettkampfjahres). Hat eine Person nur nicht zählende Meldungen, zählt sie
  nicht. Personen ohne Geburtsdatum werden als Hinweis ausgewiesen.
- **B-Meisterschaft:** Nur wenn die Veranstaltung mindestens eine Gruppe mit Titel `OBM` hat. Jede Person einmal.
- **Staffeln:** Nur auswertbar mit Wertungsgruppen und eingetragenen Schwimmern, sonst erscheint ein Hinweis statt einer
  Zahl.

## Wertungen in der Aufstellung

`title` fasst die Gruppen zu Wertungen zusammen und bestimmt, ob es eine B-Meisterschaft gibt; `awards_medals` entscheidet
allein, ob eine Gruppe in der Medaillenaufstellung zählt. Gruppen ohne Titel, aber mit Medaillenwertung erscheinen unter
ihrem Gruppennamen.

Neue Titel an `ScoringGroup::TITLES`: `OJM` (Österr. Jugendmeisterschaft) und `OBM` (Österr. B-Meisterschaft), neben
`OSTM` und `OM`. Die Erkennung aus dem Namen ("ÖSTM…", "ÖM…", "ÖJM…", "ÖBM…") liegt an einer Stelle und wird von
LENEX-Import, Formular und Migration genutzt.

Vorbelegung von `awards_medals`:

| Fall                           | Medaillenwertung                                                       |
|--------------------------------|------------------------------------------------------------------------|
| Bestehende Gruppen (Migration) | Ja, wenn ein Titel gesetzt ist oder aus dem Namen erkannt wird, sonst nein |
| LENEX-Import, neue Gruppe      | Ebenso                                                                 |
| LENEX-Import, bestehende Gruppe | Bleibt unverändert, von Hand gesetzte Werte werden nicht überschrieben |
| Neue Gruppe im Formular        | Ja, lässt sich direkt abschalten                                       |

`awards_medals` wirkt nur auf die Aufstellung. Plätze, Punkte und Cup-Wertung bleiben unberührt. LENEX hat dafür kein
Attribut, die Einstellung wird nicht exportiert.

## Datenmodell

- `meets.medal_reduction_rule` (boolean, Standard `true`): Medaillenregel der Veranstaltung an/aus.
- `scoring_groups.awards_medals` (boolean): Medaillenwertung der Gruppe.
- `scoring_groups.title`: zusätzlich `OJM` und `OBM` (Spalte `string(10)`, keine Strukturänderung). Die Migration trägt
  fehlende Titel aus den Gruppennamen nach.

## Bausteine

| Teil            | Ort                                                                                  |
|-----------------|--------------------------------------------------------------------------------------|
| Medaillenregel  | reine Funktion in `App\Support` (ohne Datenbankzugriff)                              |
| Auswertung      | `App\Services\MeetEntrySummaryService` (`final readonly`), Wertobjekte statt Arrays  |
| Anzeige         | Block "Kennzahlen" auf `meets/entries-overview`, `MeetEntriesOverviewController`     |
| Regel-Schalter  | eigene Route mit `module:meets,write`, Schalter nur mit Schreibrecht sichtbar        |
| Sammelaktion    | Seite "Wertungsgruppen einschränken", getrennt von den Einschränkungen               |

## Anzeige

Der Block erscheint nur, wenn es Meldungen gibt:

- Jugendliche und, falls vorhanden, Sportler in der ÖBM-Wertung.
- Medaillentabelle je Wertung mit Gold, Silber, Bronze und Anerkennung, darunter die Staffeln.
- Aufklappbare Details nach Gruppe und Bewerb mit Teilnehmerzahl und Medaillen.
- Hinweise: Bewerbe mit Meldungen, aber ohne Wertungsgruppen; Meldungen, die in keine Gruppe passen; Gruppen ohne
  Medaillenwertung; nicht auswertbare Staffeln.

Die Sammelaktion setzt die Medaillenwertung über den Namensanfang (z. B. "ÖBSV Cup" auf nein), mit Vorschau der
betroffenen Gruppen, und überschreibt keine Einschränkungen.

## Phasen

Zuerst kommt [scoring-groups.md](scoring-groups.md) "Reihenfolge und einzelne Gruppe kopieren" (Branch
`feature/scoring-group-order-copy`, eigener PR).
Danach folgen diese zwei Phasen:

1. **Datenmodell und Berechnung** (Branch `feature/meet-entry-summary`, Testgruppe `meet-entry-summary-p1`): Migration,
   Titel und Namenserkennung, Medaillenregel, Service. Tests: alle Teilnehmerzahlen mit Regel an und aus, Ausschlüsse
   nach Status, Gruppen ohne Medaillenwertung, Ersatzklasse, Jahrgangsregel.
2. **Oberfläche** (Testgruppe `meet-entry-summary-p2`): Block, Regel-Schalter, Schalter im Bewerbsformular, Sammelaktion,
   Hinweise. Tests inkl. `AccessControlTest`, Barrierefreiheit, Dark Mode, Live-Test an Meet #213.

Nicht enthalten: Export als PDF oder Excel, Medaillen nach tatsächlichen Starts, Landesmeisterschaften als eigener Titel,
Medaillen der Cup-Tageswertung.

## Testfall

"Österreichische Jugend und B - Meisterschaften" (dev Meet #213, 14.11.2026). Probelauf vom 07.11.2026, nur lesend und
außerhalb der Anwendung gerechnet:

| Kennzahl                           | Wert |
|------------------------------------|------|
| Athleten gesamt                    | 68   |
| Jugendliche (0-18)                 | 15   |
| Sportler in der ÖBM-Wertung        | 22   |

| Wertung | Regel an: G / S / B / Anerkennung | Regel aus: G / S / B |
|---------|-----------------------------------|----------------------|
| ÖJM     | 13 / 4 / 0 / 21                   | 21 / 13 / 4          |
| ÖBM     | 10 / 7 / 7 / 6                    | 13 / 10 / 7          |

Diese Zahlen sind vorläufig. Die Bewerbe #5, #7 und #11 (73 Meldungen) haben noch keine Wertungsgruppen und fehlen darin,
die Staffelmeldungen haben noch keine Schwimmer und Klassen, und ihre Bewerbe (#8, #15) haben keine Gruppen.
