# WPS Qualification — Meisterschaftsnormen

## Modul

**Name:** WPS Qualification **Modul-ID:** wps-qualification **Version:** 1.0 — Implementierungsfassung **Status:** Ready
for Implementation **Voraussetzung:** `wps-points` abgeschlossen (Phasen 1–6)

---

# 0. Umsetzungsentscheidungen

### [Q1] Eigenes Modul, keine Erweiterung der Richtzeiten

Die bestehenden `qualifying_times` bilden die **ÖBSV-Richtzeiten** für ÖSTM und ÖM ab. Sie teilen mit den
Meisterschaftsnormen zwar die Merkmale (Stil, Strecke, Geschlecht, Sportklasse, Zeit), haben aber fachlich nichts
miteinander zu tun: andere Herausgeber, anderer Zweck, andere Lebensdauer.

**Entscheidung:** eigenes Modul mit eigenen Tabellen. Die Richtzeiten bleiben unangetastet.

### [Q2] Zwei Normebenen — WPS und ÖBSV

Eine Norm hat zwei Ebenen, die **beide** gespeichert werden:

- die **WPS-Norm** (MQS, optional MET) — gibt vor, wer international startberechtigt ist
- die **ÖBSV-Norm** — kann strenger sein, weil die Zahl der Startplätze begrenzt ist

Beide Ebenen sind erforderlich. Wird nur die schärfere gespeichert, lässt sich später nicht mehr nachvollziehen, ob
jemand an der internationalen oder an der nationalen Hürde gescheitert ist — genau die Frage, die ein Athlet stellen
wird.

### [Q3] Die ÖBSV-Norm wird je Zeile festgelegt, nicht global

Der Prozentsatz gehört zur einzelnen Norm (Bewerb × Geschlecht × Sportklasse), nicht zur Meisterschaft. Berechnung:

```
Zeit_ÖBSV = MQS × (1 − Prozentsatz / 100)
```

Der Prozentsatz **verschärft** die Norm — die Zeit wird schneller.

Gespeichert wird **beides**: Der Prozentsatz und die daraus errechnete Zeit. Die Zeit ist überschreibbar, ohne den
Prozentsatz zu verbiegen (§5.3).

**`null` und `0` bedeuten Verschiedenes:**

| Wert                  | Bedeutung                            |
|-----------------------|--------------------------------------|
| `obsv_percent = null` | noch nicht festgelegt — offene Zeile |
| `obsv_percent = 0`    | bewusst die MQS übernommen           |

Ohne diese Unterscheidung sähe eine unbearbeitete Liste aus wie eine fertige. Die Oberfläche weist offene Zeilen aus.

### [Q4] Umgerechnete Zeiten qualifizieren niemanden

In Österreich wird ausschließlich Kurzbahn geschwommen, die Normen sind Langbahnzeiten. Das Modul rechnet deshalb in
beide Richtungen (§6). **Eine umgerechnete Zeit ist jedoch keine erfüllte Norm.**

International zählt ausschließlich eine tatsächlich auf der Langbahn geschwommene Zeit innerhalb des
Qualifikationszeitraums. Das Modul unterscheidet daher durchgängig zwischen:

- **erfüllt** — reale Langbahnzeit im Zeitraum, unterhalb der Norm
- **rechnerisch erreicht** — Kurzbahnzeit, deren geschätztes Langbahn-Äquivalent unterhalb der Norm liegt; **kein**
  Qualifikationsnachweis

Diese Unterscheidung ist in jeder Ansicht und jedem PDF sichtbar zu machen. Sie ist der Kern des Moduls: Es ist ein
**Planungswerkzeug**, kein Nachweis.

### [Q5] Norm und Auswahl sind zwei Fragen

Die Norm beantwortet: *Wer darf?* Die Auswahl-Rangliste (§8): *Wer fährt?*

Bei mehr Bewerbern als Startplätzen ist eine Rangliste nach WPS-Punkten das ehrlichere Werkzeug, als den Prozentsatz so
lange zu justieren, bis die gewünschte Anzahl übrig bleibt. Beide Ansichten existieren nebeneinander.

---

# 1. Übersicht

Das Modul verwaltet die Qualifikationsnormen internationaler Meisterschaften (EM, WM, Paralympics) und stellt dar,
welche Athletinnen und Athleten sie erfüllen.

Es umfasst:

- Meisterschaften mit Qualifikationszeitraum
- Normen je Bewerb, Geschlecht und Sportklasse: MQS, MET, ÖBSV-Norm
- Import aus der WPS-Datei sowie vollständige manuelle Pflege
- Kurzbahn-Zielzeiten für den Trainingsalltag
- Erfüllungsübersicht je Athlet
- Auswahl-Rangliste nach WPS-Punkten
- PDF-Ausgabe

**Nicht Teil des Moduls:** die Förderauswertung über einen Zeitraum (Mindestpunkte, getrennt nach Jugend und Allgemein).
Sie ist eine Ranglistenfrage ohne Bezug zu einer einzelnen Veranstaltung und gehört nach `wps-rankings`. Sie greift
lediglich auf die Normen dieses Moduls zu, um ihre Schwelle abzuleiten.

---

# 2. Ziel

## 2.1 Hauptziele

- Normen nachvollziehbar und historisch verfügbar halten
- erkennen, wer die Norm erfüllt hat und wer wie weit entfernt ist
- Kurzbahn-Zielzeiten bereitstellen, da national ausschließlich Kurzbahn geschwommen wird
- die Auswahl bei begrenzten Startplätzen nachvollziehbar begründen

## 2.2 Fachlicher Hintergrund

Die MQS-Zeiten kommen von World Para Swimming, jeweils für EM, WM und Paralympics, mit einem Qualifikationszeitraum
(Beispiel EM 2026: 1. Januar 2025 bis 6. Juli 2026).

**MET (Minimum Entry Time)** ist eine zweite, weichere Norm. Ihre Bedeutung ist bedingt: Wer in einem Bewerb die MQS
erreicht hat, darf in einem **weiteren** Bewerb starten, in dem er nur die MET erfüllt. Ohne mindestens eine MQS ist
eine MET wertlos (§7.2).

**Die Normlisten sind lückenhaft** — nicht jeder Bewerb ist für jede Sportklasse ausgeschrieben. Fehlt eine Norm, ist
das kein Datenfehler, sondern bedeutet, dass es den Bewerb für diese Klasse nicht gibt. Ergebnisse in solchen Bewerben
werden mit Begründung ausgewiesen, nicht stillschweigend übergangen.

## 2.3 Nicht-Ziele

Meldung und Nominierung, Reiseplanung, Kaderverwaltung, Änderungen an den ÖBSV-Richtzeiten oder an
`wps-points`.

---

# 3. Architekturprinzip

Wie im übrigen Projekt (`docs/architecture.md`):

```
Route → Middleware (auth, RequireAdmin) → Controller (dünn)
      → Service (app/Services) → Model → DB
```

Services als `final readonly class` mit Constructor-Injection. Berechnung und Persistenz getrennt.

**Wiederverwendet werden:** `WpsScmConversionService` (Umrechnungsfaktoren), `WpsPointCalculator`
(Punkte), `App\Support\WpsSportClass` (Klassenzuordnung 21 → 14), `App\Support\TimeParser`,
`App\Support\SportClassSorter`, `App\Services\PdfExportService`.

---

# 4. Berechtigungen

Analog zu `wps-points` **[E2]** — kein neues Rollensystem:

| Aktion                                                      | Absicherung    |
|-------------------------------------------------------------|----------------|
| Meisterschaften und Normen anlegen, importieren, bearbeiten | `RequireAdmin` |
| Erfüllungsübersicht und Auswahl-Rangliste ansehen           | `auth`         |
| PDF erzeugen                                                | `auth`         |

---

# 5. Datenmodell

## 5.1 `championships`

| Feld                | Typ                      | Beschreibung                                            |
|---------------------|--------------------------|---------------------------------------------------------|
| id                  | bigint                   | PK                                                      |
| name                | string(150)              | z. B. „World Para Swimming European Championships 2026" |
| short_name          | string(50), nullable     | z. B. „EM 2026"                                         |
| type                | string(20)               | `EC` / `WC` / `PARALYMPICS` / `OTHER`                   |
| year                | smallint unsigned        |                                                         |
| course              | string(3), default `LCM` | Bahnlänge der Normen                                    |
| qualification_start | date                     | Beginn des Qualifikationszeitraums                      |
| qualification_end   | date                     | Ende                                                    |
| source              | string(255), nullable    | Herkunft der Normdatei                                  |
| notes               | text, nullable           |                                                         |
| is_active           | boolean, default true    |                                                         |
| timestamps          |                          |                                                         |

Index auf `(year, type)`.

`course` ist vorhanden, obwohl die Normen bislang stets Langbahn sind — eine Kurzbahn-Meisterschaft ist denkbar, und
ohne das Feld wäre die Umrechnung dann still falsch.

## 5.2 `championship_standards`

| Feld              | Typ                                 | Beschreibung                            |
|-------------------|-------------------------------------|-----------------------------------------|
| id                | bigint                              | PK                                      |
| championship_id   | FK, cascadeOnDelete                 |                                         |
| stroke_type_id    | FK → stroke_types, restrictOnDelete |                                         |
| distance          | smallint unsigned                   |                                         |
| gender            | string(1)                           | `M` / `F`                               |
| sport_class       | string(15)                          | Format wie `results.sport_class`        |
| mqs_centiseconds  | integer, nullable                   | WPS-Norm                                |
| met_centiseconds  | integer, nullable                   | WPS-Zweitnorm, optional                 |
| obsv_percent      | decimal(5,2), nullable              | Verschärfung in Prozent; `null` = offen |
| obsv_centiseconds | integer, nullable                   | daraus errechnet, überschreibbar        |
| obsv_is_manual    | boolean, default false              | Zeit von Hand gesetzt statt errechnet   |
| notes             | text, nullable                      |                                         |
| timestamps        |                                     |                                         |

Unique: `(championship_id, stroke_type_id, distance, gender, sport_class)` → Indexname
`championship_standards_unique_combo`.

**Zeiten in Hundertstelsekunden**, wie überall im Projekt (`results.swim_time`).

`mqs_centiseconds` ist nullable, damit eine Zeile auch dann existieren kann, wenn nur eine MET veröffentlicht wurde.

## 5.3 Zusammenspiel von Prozentsatz und Zeit

Beim Setzen eines Prozentsatzes wird `obsv_centiseconds` errechnet und `obsv_is_manual` auf `false`
gesetzt. Beim Setzen einer Zeit von Hand wird `obsv_is_manual` auf `true` gesetzt; der Prozentsatz bleibt zur
Information erhalten, wird aber nicht mehr angewandt.

Eine Massenaktion ("auf alle offenen Zeilen x % anwenden") füllt ausschließlich Zeilen mit
`obsv_percent = null`. Von Hand gesetzte Werte bleiben unangetastet — dasselbe Muster wie bei den Umrechnungsfaktoren in
`wps-points`.

**Anzeigehilfe:** Neben der errechneten Zeit wird die zugehörige **WPS-Punktzahl** angezeigt. Ein fester Prozentsatz auf
die Zeit wirkt ungleich — zwei Prozent sind bei 50 m Freistil rund eine halbe Sekunde, bei 400 m Freistil fast acht. In
Punkten ist unmittelbar sichtbar, ob die Normen über die Bewerbe hinweg gleich streng sind.

---

# 6. Umrechnung zwischen den Bahnlängen

Beide Richtungen verwenden die Faktoren aus `wps_scm_conversion_factors` (`wps-points` §9) über
`WpsScmConversionService`.

**Zielzeit (Norm → Kurzbahn), für den Trainingsalltag:**

```
Zielzeit_SCM = Norm_LCM ÷ Faktor
```

**Bewertung (Ergebnis → Langbahn), für die Erfüllungsübersicht:**

```
geschätzt_LCM = Zeit_SCM × Faktor
```

Fehlt ein Faktor, wird **nicht** umgerechnet und die Zeile mit Begründung ausgewiesen. Ein fehlender Faktor darf nicht
als 1 behandelt werden.

Ist die Bahnlänge des Ergebnisses gleich der Bahnlänge der Meisterschaft, findet keine Umrechnung statt und die
Bewertung ist verbindlich (**[Q4]**).

---

# 7. Erfüllungsübersicht

## 7.1 Ergebnisauswahl

Berücksichtigt werden Ergebnisse mit Wettkampfdatum im Qualifikationszeitraum, mit gültiger Zeit, ohne Status `DNS`/
`DNF`/`DSQ`/`SICK`/`WDR`, aus Einzelbewerben. `EXH` wird berücksichtigt — die Bewertung, ob ein außer Konkurrenz
erzieltes Ergebnis international anerkannt wird, liegt bei World Para Swimming. Die Kennzeichnung bleibt sichtbar.

Maßgeblich ist `results.sport_class`, nicht der Athletenstammsatz. Vor dem Abgleich greift die Zuordnung 21 → 14
(`WpsSportClass`).

Je Athlet, Bewerb und Norm zählt die beste Leistung im Zeitraum — getrennt nach realer Langbahnzeit und umgerechneter
Kurzbahnzeit, da beide unterschiedlichen Rang haben (**[Q4]**).

## 7.2 Status je Athlet und Bewerb

| Status          | Bedeutung                                                       |
|-----------------|-----------------------------------------------------------------|
| `mqs_met`       | reale Langbahnzeit unterhalb der MQS                            |
| `obsv_met`      | zusätzlich unterhalb der ÖBSV-Norm                              |
| `met_only`      | reale Langbahnzeit unterhalb der MET, aber nicht der MQS        |
| `estimated_mqs` | umgerechnete Kurzbahnzeit unterhalb der MQS — **kein Nachweis** |
| `not_met`       | Norm nicht erreicht                                             |
| `no_standard`   | für Bewerb und Klasse existiert keine Norm                      |

**MET-Auswertung ist bedingt:** `met_only` ist nur dann von Belang, wenn derselbe Athlet in einem **anderen** Bewerb den
Status `mqs_met` hat. Die Übersicht wertet das je Athlet über alle Bewerbe hinweg aus und weist es als solches aus ("MET
verwertbar" gegenüber "MET ohne MQS — wirkungslos").

## 7.3 Darstellung

Je Athlet und Bewerb:

```
100 m Freistil S7 männlich
MQS (WPS)              1:13.19
ÖBSV-Norm              1:11.72     (MQS − 2 %)
Zielzeit Kurzbahn      1:09.32     (Faktor 1,0345 — World Aquatics)

Max Mustermann         1:10.85 SCM  →  1:13.30 LCM geschätzt
                       rechnerisch erreicht · kein Qualifikationsnachweis
                       Abstand zur MQS: +0,11 s
```

Bei realer Langbahnzeit entfällt der Hinweis und der Status lautet "erfüllt".

**Der Abstand zur Norm wird immer mit ausgewiesen**, auch bei Nichterfüllung — er ist die eigentliche Information für
die Förderentscheidung.

## 7.4 Fehlende Normen

Bewerbe ohne Norm für die betreffende Klasse erscheinen als eigener Abschnitt "ohne Norm ausgeschrieben" und
verschwinden nicht aus der Liste. Grund: Ein Trainer soll erkennen, dass dieser Bewerb international nicht zur Verfügung
steht — nicht, dass er vergessen wurde.

---

# 8. Auswahl-Rangliste

Beantwortet die Frage bei mehr Bewerbern als Startplätzen: Rangliste je Bewerb, Geschlecht und Sportklasse nach
**WPS-Punkten** absteigend.

Punkte statt Zeiten, weil sie über Klassen und Bewerbe hinweg vergleichbar sind. Die Berechnung erfolgt über
`WpsPointCalculator` mit der zum Ergebnisdatum gültigen Version; geschätzte Kurzbahnpunkte sind gekennzeichnet.

Angezeigt werden Rang, Athlet, Verein, Zeit, Bahnlänge, geschätzte Langbahnzeit, WPS-Punkte, Normstatus (§7.2). Eine
wählbare Obergrenze ("beste n") blendet die weiteren Plätze aus, ohne sie zu löschen.

---

# 9. Import und Pflege

## 9.1 Manuelle Pflege ist der Regelfall

Die Darstellung der WPS-Dateien **ändert sich von Veröffentlichung zu Veröffentlichung**. Ein Import, der ein festes
Format voraussetzt, wäre nicht verlässlich. Die vollständige Pflege über die Oberfläche ist deshalb der garantierte Weg,
der Import eine Erleichterung.

Erforderlich: Zeilen anlegen, bearbeiten, löschen; Filterung nach Bewerb, Geschlecht und Klasse; Massenaktion für den
Prozentsatz (§5.3); Kopieren aller Normen einer Meisterschaft als Ausgangspunkt für die nächste.

## 9.2 Import

Dreistufig nach dem Muster von `WpsParameterImportService`: Formular → Vorschau (parst und validiert, schreibt nichts) →
Import. Der Vorschauschritt ist verbindlich.

**Aufbau der bekannten Datei** (geprüft an *Para Swimming European Open Championships*, Madeira 2024, aus dem PDF nach
Excel konvertiert):

| Zeile | Inhalt                                                   |
|-------|----------------------------------------------------------|
| 1     | Titel, enthält den Qualifikationszeitraum im Klartext    |
| 2     | Kopfzeile: A Events, B Class, C Men, E Women             |
| 3     | Unterkopf: C MQS, D MET, E MQS, F MET                    |
| ab 4  | Daten                                                    |

| Spalte | Inhalt                                                |
|--------|-------------------------------------------------------|
| A      | Bewerb — nur in der ersten Zeile einer Gruppe gefüllt |
| B      | Sportklasse                                           |
| C      | MQS Männer                                            |
| D      | MET Männer                                            |
| E      | MQS Frauen                                            |
| F      | MET Frauen                                            |

**Korrektur gegenüber Fassung 1.0 dieser Spec.** Die frühere Beschreibung nannte vier Spalten (A–D) und Zeiten als
Excel-Zeitwerte. Beides trifft auf die geprüfte Datei nicht zu:

- Es sind **sechs Spalten**; MET ist je Geschlecht vorhanden.
- Die Zeiten stehen als **Text** ("01:00.94"), nicht als Excel-Zeitwerte. Gelesen wird deshalb primär über
  `TimeParser::parse()`; ein numerischer Wert wird als Excel-Serienwert behandelt, falls eine künftige Datei es anders
  hält.

**Verbindliche Regeln:**

- Der Bewerbsname wird mitgeführt, bis ein neuer erscheint (Gruppenkopf). Er steht in **verbundenen Zellen**
  (`A4:A13` …); PhpSpreadsheet liefert den Wert am Anker, die übrigen Zellen leer.
- Es gibt auch **verbundene Zeitzellen** (`C8:C9`, `E27:F29` …) — Überbleibsel der PDF-Konvertierung, die jeweils leere
  Nachbarn überspannen. Verbundene Werte dürfen **nicht** nach unten aufgelöst werden: In Zeile 9 bekäme die Klasse S8
  sonst die Zeit von S7 zugewiesen, eine falsche Norm, die niemandem auffällt. Gelesen wird nur der Ankerwert.
- Zwischen den Bewerbsgruppen stehen **Leerzeilen**. Eine leere Zeile beendet den Datenbereich also **nicht** (anders
  als beim Import der Punkteparameter). Schluss ist erst beim Staffelabschnitt.
- Leere Zellen bedeuten "nicht ausgeschrieben" und erzeugen **keine** Zeile — kein Fehler.
- Der Staffelabschnitt am Ende (Zeile mit `Relays*`, darunter Klassen wie `34 Points`) wird übersprungen und in der
  Vorschau als Hinweis ausgewiesen; Staffelnormen sind in Punkten angegeben und nicht Teil dieses Moduls.
- Der Import füllt **ausschließlich** MQS und MET. ÖBSV-Prozentsätze und -Zeiten bleiben unberührt, damit ein erneuter
  Import eure Festlegungen nicht überschreibt. Dafür sorgt die Feld-Whitelist in
  `ChampionshipStandardService::upsertStandard()`.
- Normen, die in der Meisterschaft stehen und in der Datei fehlen, werden **nicht gelöscht**, sondern in der Vorschau
  ausgewiesen. Löschen wäre bei einem Formatfehler in der Datei ein stiller Datenverlust.
- Der Qualifikationszeitraum aus der Titelzeile wird als **Vorschlag** angezeigt, aber nicht übernommen: Die
  Formulierung ist nicht garantiert stabil, und ein still falsch gesetzter Zeitraum nähme später Ergebnisse aus der
  Wertung, ohne dass jemand die Ursache sieht.
- Erkennt der Import das Format nicht, bricht er mit einer verständlichen Meldung ab und verweist auf die manuelle
  Pflege.

## 9.3 Historisierung

Meisterschaften und ihre Normen werden nicht überschrieben. Jede Ausgabe ist ein eigener Datensatz, damit vergangene
Entscheidungen nachvollziehbar bleiben.

---

# 10. Oberfläche

Routen unter `/championships`, Verwaltung hinter `RequireAdmin`, Ansichten in der `auth`-Gruppe. Navigationsgruppe
"Meisterschaften".

| Ansicht   | Inhalt                                                           |
|-----------|------------------------------------------------------------------|
| Übersicht | Meisterschaften mit Zeitraum, Anzahl Normen, offenen ÖBSV-Zeilen |
| Normen    | Tabelle mit Filterung, Massenaktion, Inline-Bearbeitung          |
| Import    | Formular, Vorschau                                               |
| Erfüllung | je Athlet oder je Bewerb, mit Statusfilter                       |
| Auswahl   | Rangliste nach Punkten                                           |

Blade- und Flux-Konventionen wie in `wps-points` §14.4: `@extends('layouts.app')` +
`@section('content')`, Flux mit `x-model`, kein `<flux:select.option>` mit `@selected()`.

**Farbgebung der Statuskennzeichnung** (bestehende Bedeutung im Projekt beibehalten):

| Status                 | Farbe                                    |
|------------------------|------------------------------------------|
| erfüllt (MQS und ÖBSV) | grün                                     |
| erfüllt (nur MQS)      | blau                                     |
| rechnerisch erreicht   | **amber** — hier lohnt ein zweiter Blick |
| nicht erreicht         | zinc                                     |
| ohne Norm              | zinc, kursiv                             |

---

# 11. PDF

Über den bestehenden `PdfExportService`, Templates unter `resources/views/pdf/`, ohne Tailwind und Flux. Vorlagen:
`qualifying-times`, `cup-overall-ranking`.

Kopfbereich: Meisterschaft, Qualifikationszeitraum, Stand der Auswertung, verwendete WPS-Punkteversion,
Umrechnungsfaktoren.

**Verpflichtender Hinweis**, sobald eine umgerechnete Zeit enthalten ist:

```
Hinweis:
Mit „rechnerisch erreicht" gekennzeichnete Leistungen beruhen auf umgerechneten
Kurzbahnzeiten. Sie sind kein Qualifikationsnachweis — international zählt
ausschließlich eine auf der Langbahn geschwommene Zeit innerhalb des
Qualifikationszeitraums.
```

---

# 12. Services

| Service                             | Aufgabe                                                      |
|-------------------------------------|--------------------------------------------------------------|
| `ChampionshipStandardService`       | Normen anlegen, Prozentsatz anwenden, Massenaktion, Kopieren |
| `ChampionshipStandardImportService` | Import der WPS-Datei (§9.2)                                  |
| `QualificationEvaluationService`    | Erfüllungsübersicht (§7), rein lesend                        |
| `QualificationSelectionService`     | Auswahl-Rangliste (§8)                                       |

Wert-Objekt `App\Support\QualificationStatus` mit Status, Abstand zur Norm, verwendetem Faktor, geschätzter Langbahnzeit
und Begründung bei fehlender Norm.

Ranglisten und Übersichten werden bei jedem Aufruf berechnet, nicht persistiert — wie im übrigen Projekt.

---

# 13. Tests

Pest mit `RefreshDatabase`, keine Factories, Helper mit Phasensuffix, Gruppen `wps-qual-p1` bis `-p5`.

**Fachlich zwingend abzudecken:**

- reale Langbahnzeit unterhalb der MQS → `mqs_met`
- umgerechnete Kurzbahnzeit unterhalb der MQS → `estimated_mqs`, **nie** `mqs_met`
- Ergebnisse außerhalb des Qualifikationszeitraums zählen nicht
- MET ohne MQS in einem anderen Bewerb ist wirkungslos
- MET mit MQS in einem anderen Bewerb ist verwertbar
- fehlende Norm ergibt `no_standard`, nicht `not_met`
- `obsv_percent = 0` verhält sich anders als `null`
- Massenaktion überschreibt von Hand gesetzte Werte nicht
- fehlender Umrechnungsfaktor → keine Umrechnung, Begründung
- Zuordnung 21 → 14 greift beim Abgleich
- Import lässt ÖBSV-Werte unberührt
- Import erzeugt für leere Zellen keine Zeilen
- Auswahl-Rangliste sortiert nach Punkten, geschätzte gekennzeichnet
- PDF enthält den Hinweis, sobald eine umgerechnete Zeit vorkommt

---

# 14. Implementierungsphasen

Jede Phase endet mit grüner Testsuite, `composer lint:check` ohne Befund und aufgelösten PhpStorm-Inspections. Kein
Phasenbeginn ohne ausdrückliche Freigabe.

## Phase 1 — Datenmodell

Migrationen `championships` und `championship_standards`, Models, Relationen,
`ChampionshipStandardService` für Anlegen und Prozentsatzanwendung.

*DoD:* Normen lassen sich speichern und lesen; `null` und `0` beim Prozentsatz unterscheidbar.

## Phase 2 — Verwaltung und Oberfläche

Meisterschaftsverwaltung, Normtabelle mit Filterung, Inline-Bearbeitung, Massenaktion, Kopierfunktion, Punktanzeige
neben der errechneten Zeit.

*DoD:* Eine vollständige Normliste ist ohne Import pflegbar.

## Phase 3 — Import

`ChampionshipStandardImportService`, Vorschau, Validierung.

*DoD:* Die EM-2026-Datei lässt sich importieren; ÖBSV-Werte bleiben unberührt.

## Phase 4 — Erfüllungsübersicht

`QualificationEvaluationService`, Zielzeiten, Status, Abstand, Ansichten.

*DoD:* Für einen Athleten ist je Bewerb erkennbar, ob und wie die Norm erreicht wurde; umgerechnete Zeiten sind
durchgängig als "kein Nachweis" gekennzeichnet.

## Phase 5 — Auswahl-Rangliste und PDF

`QualificationSelectionService`, PDF-Ausgabe beider Ansichten.

*DoD:* Auswahl nach Punkten nachvollziehbar; PDF entspricht dem bestehenden Layout und trägt den Hinweis.

---

# 15. Risiken

| #    | Risiko                                                   | Gegenmaßnahme                                                          |
|------|----------------------------------------------------------|------------------------------------------------------------------------|
| Q-R1 | Umgerechnete Zeit wird als Qualifikation missverstanden  | **[Q4]**, eigener Status, Hinweis in Anzeige und PDF, eigener Testfall |
| Q-R2 | Dateiformat ändert sich zwischen Veröffentlichungen      | manuelle Pflege als Regelfall (§9.1), Import bricht verständlich ab    |
| Q-R3 | Erneuter Import überschreibt ÖBSV-Festlegungen           | Import füllt nur MQS und MET (§9.2)                                    |
| Q-R4 | Fehlende Norm wird als „nicht erfüllt" gelesen           | eigener Status `no_standard` (§7.2), eigener Abschnitt (§7.4)          |
| Q-R5 | MET wird ohne MQS als Qualifikation gewertet             | bedingte Auswertung (§7.2), eigener Testfall                           |
| Q-R6 | Prozentsatz auf die Zeit wirkt über die Bewerbe ungleich | Punktanzeige neben der Zeit (§5.3)                                     |
| Q-R7 | Offene Zeilen sehen aus wie bewusst gesetzte             | `null` ≠ `0` (**[Q3]**), Kennzeichnung offener Zeilen                  |
| Q-R8 | Umrechnung für Nachwuchs zu optimistisch                 | Übernahme des Hinweises aus `wps-points` §9.6                          |

---

# 16. Offene Punkte

- **Anerkennung von EXH-Ergebnissen** international: Die Regel liegt bei World Para Swimming. Bis zur Klärung werden sie
  einbezogen und gekennzeichnet.
- **Staffelnormen** sind in Punkten angegeben (z. B. "34 Points") und folgen einer eigenen Systematik. Nicht Teil dieses
  Moduls; bei Bedarf eigene Ausbaustufe.
- **Automatischer Bezug der Normdateien** von World Para Swimming ist nicht vorgesehen.
