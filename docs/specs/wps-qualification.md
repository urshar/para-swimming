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

## 5.1a `meets.wps_approved`

Zwei Spalten an der bestehenden Tabelle `meets`:

| Spalte              | Typ                    | Bedeutung                                               |
|---------------------|------------------------|---------------------------------------------------------|
| `wps_approved`      | boolean, Default false | Wettkampf von World Para Swimming sanktioniert          |
| `wps_approved_note` | string(255), nullable  | Fundstelle der Anerkennung, für die Nachvollziehbarkeit |

Begründung siehe §7.1. Gesetzt wird das Merkmal im Wettkampfformular, nur für Admins.

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

Berücksichtigt werden Ergebnisse mit Wettkampfdatum im Qualifikationszeitraum **der Meisterschaft**, mit gültiger Zeit,
ohne Status `DNS`/`DNF`/`DSQ`/`SICK`/`WDR`, aus Einzelbewerben. `EXH` wird berücksichtigt — die Bewertung, ob ein außer
Konkurrenz erzieltes Ergebnis international anerkannt wird, liegt bei World Para Swimming. Die Kennzeichnung bleibt
sichtbar.

Maßgeblich ist `results.sport_class`, nicht der Athletenstammsatz. Vor dem Abgleich greift die Zuordnung 21 → 14
(`WpsSportClass`).

Je Athlet, Bewerb und Norm zählt die beste Leistung im Zeitraum — getrennt nach realer Langbahnzeit und umgerechneter
Kurzbahnzeit, da beide unterschiedlichen Rang haben (**[Q4]**).

**Eine reale Zeit auf der Bahnlänge der Meisterschaft schlägt eine umgerechnete immer**, auch wenn die umgerechnete
schneller ist. Die Schätzung wird nur herangezogen, wenn gar keine reale Zeit vorliegt. Grund: Der Status trägt eine
Zeit. Gewönne die Schätzung, verschwände die reale Zeit aus der Zeile — angezeigt stünde "rechnerisch erreicht" bei
jemandem, der auf der Zielbahnlänge nachweislich langsamer war, und die Zahl, die das widerlegt, wäre nirgends zu sehen.
Eine reale Zeit ist der stärkere Beleg, auch wenn sie ein Nein ist.

**Strecken unter 50 m bleiben außen vor.** 25-m-Bewerbe werden auf internationalen Meisterschaften nicht ausgetragen;
sie mitzuführen erzeugt Zeilen, zu denen es nie eine Norm geben wird. Konstante `MIN_DISTANCE` im
`QualificationEvaluationService`, wirkt in beiden Ansichten.

**Nur WPS-anerkannte Wettkämpfe liefern Nachweise.** `meets.wps_approved` kennzeichnet Wettkämpfe, die von World Para
Swimming sanktioniert sind. Zeiten aus nicht gekennzeichneten Wettkämpfen erscheinen **nicht** in der Qualifikantenliste
(§7.5), in der Förderansicht dagegen sehr wohl — dort mit dem Vermerk "Wettkampf nicht WPS-anerkannt".

Der Default ist `false`, ausdrücklich auch für den Altbestand: Ein Default `true` behauptete über jeden bestehenden
Wettkampf eine Anerkennung, die niemand geprüft hat. Damit eine leere Liste nicht mit einer korrekt leeren verwechselt
wird, weist die Qualifikantenliste aus, wie viele Ergebnisse mangels Kennzeichnung ausgeschlossen wurden.

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

**Darstellung.** In beiden Ansichten werden Bewerbe ohne Norm **nicht** als Zeile geführt: Die Frage lautet "wie weit
fehlt zur Norm", und diese Entfernung gibt es dort nicht. Sie verschwinden aber nicht stillschweigend, sondern werden am
Fuß des Athletenblocks benannt ("3 weitere Bewerbe ohne ausgeschriebene Norm: …"). Sonst entstünde der Eindruck, der
Athlet sei dort gar nicht angetreten.

Die Trennung geschieht **im Controller, nicht im Service**: Der Status `no_standard` bleibt Teil der Bewertung und
bleibt geprüft; getrennt wird erst für die Darstellung. Athleten, bei denen kein einziger Bewerb eine Norm hat,
erscheinen nicht in der Förderansicht.

## 7.5 Zwei getrennte Ansichten, kein Statusfilter

Die Erfüllungsübersicht beantwortet zwei verschiedene Fragen. Sie werden als **zwei eigenständige Ansichten** umgesetzt,
nicht als eine Liste mit Statusfilter — ein Filter wäre die Möglichkeit, sich umgerechnete Zeiten doch wieder in die
Nachweisliste zu holen (Q-R1).

| Ansicht                            | Frage                                                      | Enthält                                                                                                                                                                                                 |
|------------------------------------|------------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Qualifikanten** (`/qualified`)   | Wer hat sich qualifiziert, und wie weit fehlt den übrigen? | je Athlet **alle** Bewerbe mit ausgeschriebener Norm — erfüllte wie offene. Ausschließlich reale Zeiten auf der Bahnlänge der Meisterschaft aus WPS-anerkannten Wettkämpfen; keine umgerechneten Zeiten |
| **Förderansicht** (`/development`) | Hat der Athlet international eine Chance?                  | alles: umgerechnete Zeiten, Abstand zur Norm auch bei Nichterfüllung, Zielzeit auf der anderen Bahnlänge, Bewerbe ohne Norm                                                                             |

Beide teilen sich `QualificationEvaluationService` — es darf nur **eine** Stelle geben, die entscheidet, ob eine Norm
erfüllt ist. Getrennt sind ausschließlich Zeilenauswahl und Darstellung. Die Unterscheidung selbst liegt in
`QualificationStatus::isProof()`.

Die Auswahl-Rangliste (§8) beantwortet die dritte Frage — *wer fährt?* — und greift erst, wenn aus der
Qualifikantenliste mehr Namen kommen als Startplätze vorhanden sind.

## 7.6 Aufbau der Qualifikantenansicht

Gegliedert nach **Kaderart → Athlet → Bewerb**. Athleten ohne Kaderzugehörigkeit stehen in einem eigenen Abschnitt am
Ende, damit sie nicht stillschweigend verschwinden — auch sie können eine Norm erfüllt haben.

Je Athlet eine Kopfzeile mit Name, Geschlecht, Sportklasse, Verein und der Zählung erfüllter MQS, erfüllter MET und
offener Bewerbe.

Je Bewerb eine Zeile mit der **Bestleistung**: Platz, Zeit, WPS-Punkte, Wettkampf mit Datum, Normstatus und — bei
Nichterfüllung — der Abstand. Bewerbe **ohne** Norm entfallen; sie sagen über die Qualifikation nichts aus. Bewerbe mit
Norm, die nicht erfüllt sind, bleiben stehen: Der Abstand ist die eigentliche Information für die
Nominierungsentscheidung.

Die WPS-Punkte stammen aus `results.wps_points` und werden nicht neu berechnet.

### Leistungsverlauf

Jede Bewerbszeile lässt sich **aufklappen** und zeigt dann alle Ergebnisse dieses Bewerbs im Zeitraum,
**chronologisch**, mit Zeit, Punkten, Wettkampf und der Kennzeichnung, ob das jeweilige Einzelergebnis MQS oder MET
erreicht. Dazu die Veränderung vom ersten zum letzten Ergebnis.

Daran ist ablesbar, ob ein Athlet seine Leistung hält, sich verbessert oder nachlässt — die Bestleistung allein sagt
darüber nichts, weil sie auch zwei Jahre alt sein kann. Chronologisch und **nicht** nach Zeit sortiert: Aus einer nach
Zeit sortierten Liste ist keine Entwicklung ablesbar, und genau darum geht es hier.

Bei weniger als zwei Ergebnissen wird **keine** Tendenz ausgewiesen. Aus einem einzelnen Wert lässt sich keine
Entwicklung ablesen, und eine erfundene Tendenz wäre schlimmer als gar keine.

Erreicht ein Einzelergebnis die MQS, wird **nur** MQS ausgewiesen, nicht zusätzlich MET: Wer die MQS erfüllt, erfüllt
zwangsläufig auch die langsamere MET, und zweimal dieselbe Leistung auszuzeichnen wäre irreführend.

### Stichtag der Kaderzugehörigkeit

Die Zugehörigkeit zu einer Kaderart (`athlete_kader_memberships`) hat einen Gültigkeitszeitraum, es braucht also einen
Stichtag:

- Läuft der Qualifikationszeitraum noch oder liegt er in der Zukunft → **heutiger Tag**. Die Liste stützt eine
  Nominierungsentscheidung, die jetzt getroffen wird, und dafür zählt der Kader, in dem jemand jetzt ist.
- Ist der Zeitraum abgelaufen → **sein Ende**. Die Liste ist dann ein Rückblick und muss reproduzierbar bleiben; mit dem
  heutigen Tag stünde bei einer Auswertung der EM 2026 im Jahr 2028 die Kadereinteilung von 2028.

Der verwendete Stichtag wird in der Ansicht ausgewiesen, damit nachvollziehbar ist, worauf sich die Kaderangabe bezieht.
Gibt es zum Stichtag mehrere gültige Zugehörigkeiten, gewinnt die mit der kleinsten `sort_order` — die höchste
Kaderstufe.

## 7.7 Förderansicht

Je Athlet ein Block mit Kopfzeile (Name, Geschlecht, **Sportklasse**, Verein) und einer Zeile je Bewerb mit Norm:
Leistung mit Bahnlänge, gegebenenfalls die umgerechnete Zeit samt Faktor, MQS, ÖBSV-Norm, Zielzeit auf der anderen
Bahnlänge und der Status mit Abstand.

Angezeigt wird die **S-Klasse**, nicht SB oder SM: Die gelten nur für Brust und Lagen und können abweichen; als
Kennzeichnung des Athleten taugt allein die S-Klasse. Die Regel liegt in
`QualificationAthleteSummary::primarySportClass()` — beide Ansichten nutzen dieselbe.

**Athletenauswahl.** Je Athlet eine Checkbox; die Auswahl bestimmt, wer ins PDF kommt. Ohne Auswahl enthält das PDF alle
gefilterten Athleten — der häufigere Fall soll keinen zusätzlichen Handgriff kosten. Eine Umschaltung "nur Auswahl
anzeigen" wendet sie zusätzlich auf den Bildschirm an; standardmäßig ist sie aus, damit sich die übrigen Athleten
weiterhin vergleichen lassen.

Die Ansicht ist deshalb eine **Livewire-Komponente**: Auf einer gewöhnlichen Seite mit Seiteneinteilung verfiele jedes
Häkchen beim Blättern, weil jeder Seitenwechsel ein neuer Aufruf ist — und genau die Auswahl über mehrere Seiten hinweg
wird gebraucht, wenn dreißig Athleten in der Liste stehen.

**Seitenweise Ausgabe**, zehn Athleten je Seite. Gezählt werden Athleten, nicht Zeilen: Jeder Athlet ist eine eigene
Tabelle, ihn über zwei Seiten zu zerreißen wäre unlesbar. Paginiert wird in PHP und nicht über die Abfrage — die
Bewertung findet ohnehin in PHP statt, weil je Athlet alle Bewerbe gegeneinander aufgelöst werden müssen (bedingte
MET-Auswertung, §7.2); eine Seiteneinteilung auf Datenbankebene würde Athleten mittendrin abschneiden. Die Suche wird an
die Blätter-Links angehängt, sonst fiele sie beim Blättern weg.

### Filter

Erfüllung (alle Bewerbe mit Norm / nur erfüllte / nur nicht erfüllte), Kaderart, Namenssuche. Der Erfüllungsfilter wirkt
auf die **Bewerbszeilen**, nicht auf die Athleten; ein Athlet ohne passende Zeile wird ausgeblendet.

Der Filterstand liegt im Wertobjekt `QualificationOverviewFilter`, das Bildschirm **und** PDF-Ausgabe verwenden. Zweimal
ausprogrammiert liefen die Regeln früher oder später auseinander, und dann zeigte das PDF etwas anderes als der
Bildschirm, von dem aus es erzeugt wurde. Der PDF-Link trägt den Stand als Abfrageparameter mit; unbekannte Werte fallen
auf den Standard zurück, damit ein vertippter Parameter ein vollständiges PDF liefert und kein leeres. Das PDF nennt den
aktiven Filter im Kopf.

---

# 8. Auswahl-Rangliste

Beantwortet die Frage bei mehr Bewerbern als Startplätzen: Rangliste je Bewerb, Geschlecht und Sportklasse nach
**WPS-Punkten** absteigend.

Punkte statt Zeiten, weil sie über Klassen und Bewerbe hinweg vergleichbar sind. Die Berechnung erfolgt über
`WpsPointCalculator` mit der zum Ergebnisdatum gültigen Version; geschätzte Kurzbahnpunkte sind gekennzeichnet.

Angezeigt werden Rang, Athlet, Verein, Zeit, WPS-Punkte und Wettkampf. Eine wählbare Obergrenze ("beste n") blendet die
weiteren Plätze aus, ohne sie zu löschen.

Die Punkte stammen aus `results.wps_points` und werden nicht neu berechnet — damit stimmt die Rangliste zwangsläufig mit
dem überein, was anderswo im System an diesem Ergebnis steht.

**Grundlage sind ausschließlich Nachweise.** Umgerechnete Zeiten, `met_only` und Ergebnisse aus nicht anerkannten
Wettkämpfen gehen nicht ein: Wer nicht qualifiziert ist, steht in keiner Auswahlliste.

**Startplätze werden nicht hinterlegt.** Die Rangliste liefert die Reihenfolge; die Auswahl trifft ein Mensch. Eine im
System gepflegte Quote würde eine Entscheidungsautomatik suggerieren, die es nicht gibt.

**Gleichstand:** Punktgleiche teilen sich den Rang, der darauffolgende Rang springt entsprechend (1, 2, 2, 4) — wie in
der Cup-Wertung. Sie unterschiedlich zu platzieren hieße, eine Reihenfolge zu behaupten, die die Zahlen nicht hergeben.

**Ohne Punktbewertung** — etwa wenn für die Kombination kein Parametersatz vorliegt — steht ein Athlet mit `rank = null`
in einem eigenen Abschnitt unterhalb der Rangliste. Weder weglassen noch mit null Punkten einsortieren: Beides
behauptete etwas, einmal dass es die Leistung nicht gibt, einmal dass sie die schlechteste ist.

## 8.1 Gesamtrangliste der Athleten

Neben der Rangliste je Bewerb steht eine Gesamtsicht über alle Athleten. Gemessen wird die **beste einzelne Punktzahl**
über alle Bewerbe, **nicht die Summe**: Eine Summe belohnte, wer viele Bewerbe schwimmt, und das sagt über
internationale Chancen nichts — ein Athlet mit 850 Punkten in einem Bewerb ist stärker aufgestellt als einer mit fünfmal
"700".

Die Zeile nennt zusätzlich den Bewerb, aus dem die Bestpunktzahl stammt, und die Zahl der insgesamt erfüllten Normen.
Beides gehört zur Einschätzung, ohne die Reihenfolge zu bestimmen.

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

**Aufbau der bekannten Datei** (Beispiel EM 2026, aus dem PDF nach Excel konvertiert):

| Spalte | Inhalt                                                                   |
|--------|--------------------------------------------------------------------------|
| A      | Bewerb — **nur in der ersten Zeile einer Gruppe** gefüllt, darunter leer |
| B      | Sportklasse                                                              |
| C      | MQS Männer                                                               |
| D      | MQS Frauen                                                               |

Zeile 1 Titel, Zeile 2 Kopfzeile, Zeile 3 Unterkopf. Zeiten als Excel-Zeitwerte, nicht als Text — entsprechend über
`PhpSpreadsheet\Shared\Date` zu lesen, sonst erscheinen Serienwerte.

**Verbindliche Regeln:**

- Der Bewerbsname wird mitgeführt, bis ein neuer erscheint (Gruppenkopf).
- Leere Zellen bedeuten "nicht ausgeschrieben" und erzeugen **keine** Zeile — kein Fehler.
- Der Staffelabschnitt am Ende wird übersprungen; Staffelnormen sind in Punkten angegeben und nicht Teil dieses Moduls.
- Der Import füllt **ausschließlich** MQS und MET. ÖBSV-Prozentsätze und -Zeiten bleiben unberührt, damit ein erneuter
  Import eure Festlegungen nicht überschreibt.
- Erkennt der Import das Format nicht, bricht er mit einer verständlichen Meldung ab und verweist auf die manuelle
  Pflege.

## 9.3 Historisierung

Meisterschaften und ihre Normen werden nicht überschrieben. Jede Ausgabe ist ein eigener Datensatz, damit vergangene
Entscheidungen nachvollziehbar bleiben.

---

# 10. Oberfläche

Routen unter `/championships`, Verwaltung hinter `RequireAdmin`, Ansichten in der `auth`-Gruppe. Navigationsgruppe
"Meisterschaften".

| Ansicht       | Inhalt                                                                      |
|---------------|-----------------------------------------------------------------------------|
| Übersicht     | Meisterschaften mit Zeitraum, Anzahl Normen, offenen ÖBSV-Zeilen            |
| Normen        | Tabelle mit Filterung, Massenaktion, Inline-Bearbeitung                     |
| Import        | Formular, Vorschau                                                          |
| Qualifikanten | Kaderart → Athlet → Bewerbe mit Norm, aufklappbarer Leistungsverlauf (§7.6) |
| Förderansicht | je Athlet über alle Bewerbe, mit umgerechneten Zeiten und Suche             |
| Auswahl       | Rangliste nach Punkten                                                      |

Blade- und Flux-Konventionen wie in `wps-points` §14.4: `@extends('layouts.app')` +
`@section('content')`, Flux mit `x-model`, kein `<flux:select.option>` mit `@selected()`.

**Farbgebung der Statuskennzeichnung** (bestehende Bedeutung im Projekt beibehalten):

| Status                 | Farbe                                                                        |
|------------------------|------------------------------------------------------------------------------|
| erfüllt (MQS und ÖBSV) | grün                                                                         |
| erfüllt (nur MQS)      | blau                                                                         |
| rechnerisch erreicht   | **amber** — hier lohnt ein zweiter Blick                                     |
| nicht erreicht         | **rot** — die offenen Bewerbe sind das, worauf der Blick fallen soll         |
| ohne Norm              | zinc — keine verfehlte Leistung, sondern eine Aussage über die Ausschreibung |

---

# 11. PDF

Über den bestehenden `PdfExportService`, Templates unter `resources/views/pdf/`, ohne Tailwind und Flux. Vorlagen:
`qualifying-times`, `cup-overall-ranking`.

Kopfbereich: Meisterschaft, Qualifikationszeitraum, Stand der Auswertung, verwendete WPS-Punkteversion,
Umrechnungsfaktoren.

Drei Vorlagen: `championship-qualified`, `championship-development`, `championship-selection`. Das PDF kennt keine
Filter und keine Seiteneinteilung — es ist die vollständige Fassung, dafür ist es da. Der Leistungsverlauf (§7.6) fehlt
darin: Aufklappen gibt es auf Papier nicht, und alle Ergebnisse auszuschreiben machte aus zwei Seiten zwanzig.

**Verpflichtender Hinweis**, sobald eine umgerechnete Zeit enthalten ist — und nur dann. Ein Hinweis, der immer dasteht,
wird nicht mehr gelesen und fehlt dann dort, wo er zählt. In der Qualifikantenansicht und der Auswahl-Rangliste kommen
keine umgerechneten Zeiten vor, dort entfällt er also regelmäßig; die Prüfung bleibt in der Vorlage stehen, falls sich
die Grundlage je ändert.

```
Hinweis:
Mit „rechnerisch erreicht" gekennzeichnete Leistungen beruhen auf umgerechneten
Kurzbahnzeiten. Sie sind kein Qualifikationsnachweis — international zählt
ausschließlich eine auf der Langbahn geschwommene Zeit innerhalb des
Qualifikationszeitraums.
```

---

# 12. Services

| Service                             | Aufgabe                                                                                                                                       |
|-------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------|
| `ChampionshipStandardService`       | Normen anlegen, Prozentsatz anwenden, Massenaktion, Kopieren                                                                                  |
| `ChampionshipStandardImportService` | Import der WPS-Datei (§9.2)                                                                                                                   |
| `QualificationEvaluationService`    | Erfüllungsübersicht (§7), rein lesend — `qualificationOverview()` für die Qualifikantenansicht, `developmentOverview()` für die Förderansicht |
| `QualificationSelectionService`     | Auswahl-Rangliste je Bewerb und je Athlet (§8), rein lesend                                                                                   |

**Wertobjekte** unter `App\Support`:

| Objekt                        | Inhalt                                                                                                                                          |
|-------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------|
| `QualificationStatus`         | Status, Abstand zur Norm, verwendeter Faktor, geschätzte Langbahnzeit, Begründung bei fehlender Norm; `isProof()` entscheidet über den Nachweis |
| `QualificationRow`            | ein Athlet in einem Bewerb: Norm, Zielzeit, Status, MET-Verwertbarkeit, Leistungsverlauf                                                        |
| `QualificationResultEntry`    | ein Einzelergebnis im Leistungsverlauf                                                                                                          |
| `QualificationAthleteSummary` | ein Athlet mit seinen Bewerbszeilen, Kaderart und Zählung erfüllter Normen                                                                      |
| `QualificationRankingEntry`   | ein Platz in einer Auswahl-Rangliste; `rank = null` heißt "ohne Punktbewertung"                                                                 |
| `QualificationOverviewFilter` | Filterstand der Qualifikantenansicht; von Bildschirm und PDF gemeinsam genutzt                                                                  |

Durchgehend Wertobjekte statt assoziativer Arrays: Bei einem Array ist jeder Zugriff für die statische Analyse ein
`mixed` — Tippfehler in Schlüsseln fallen erst zur Laufzeit auf, und Methodenaufrufe lassen sich nicht auflösen.

Ranglisten und Übersichten werden bei jedem Aufruf berechnet, nicht persistiert — wie im übrigen Projekt.

---

# 13. Tests

Pest mit `RefreshDatabase`, keine Factories, Helper mit Phasensuffix, Gruppen `wps-qual-p1` bis `-p5`
(die Qualifikantenansicht liegt in `wps-qual-p4b`).

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
- Ergebnisse aus nicht WPS-anerkannten Wettkämpfen erscheinen nicht in der Qualifikantenansicht, in der Förderansicht
  aber sehr wohl
- eine reale Zeit schlägt eine umgerechnete, auch wenn die umgerechnete schneller ist
- Bewerbe ohne Norm fehlen in der Qualifikantenansicht, Bewerbe mit unerfüllter Norm stehen darin
- der Leistungsverlauf ist chronologisch, nicht nach Zeit sortiert
- 25-m-Bewerbe erscheinen in keiner der beiden Ansichten
- bei einem einzelnen Ergebnis wird keine Tendenz ausgewiesen
- eine zum Stichtag abgelaufene Kaderzugehörigkeit wird nicht berücksichtigt
- bei laufendem Zeitraum gilt der heutige Tag als Kader-Stichtag, bei abgelaufenem dessen Ende
- Auswahl-Rangliste sortiert nach Punkten, nicht nach Zeit
- in die Auswahl-Rangliste gehen nur Nachweise ein
- Punktgleichheit teilt den Rang, der folgende Rang springt
- die Athletenrangliste nimmt die beste Einzelpunktzahl, nicht die Summe
- Athleten ohne Punktbewertung stehen mit `rank = null` am Ende
- die Obergrenze blendet aus, ohne die zugrunde liegende Menge zu verändern
- der Filterstand der Qualifikantenansicht landet im PDF-Link und wirkt dort gleich
- unbekannte Filterwerte in der Adresse ergeben ein vollständiges PDF, kein leeres
- die Athletenauswahl der Förderansicht überlebt den Seitenwechsel
- ohne Auswahl enthält das Förder-PDF alle gefilterten Athleten
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

`QualificationEvaluationService`, Zielzeiten, Status, Abstand, Förderansicht. `meets.wps_approved` samt Kennzeichnung im
Wettkampfformular.

*DoD:* Für einen Athleten ist je Bewerb erkennbar, ob und wie die Norm erreicht wurde; umgerechnete Zeiten sind
durchgängig als "kein Nachweis" gekennzeichnet. — **abgeschlossen**

## Phase 4b — Qualifikantenansicht

Gliederung nach Kaderart, aufklappbarer Leistungsverlauf, Filter (§7.6). Wertobjekte `QualificationAthleteSummary` und
`QualificationResultEntry`.

*DoD:* Je Athlet sind alle Bewerbe mit Norm sichtbar, erfüllte wie offene, und der Verlauf zeigt, ob die Leistung
gehalten, verbessert oder verschlechtert wurde. — **abgeschlossen**

## Phase 5 — Auswahl-Rangliste und PDF

`QualificationSelectionService` (§8), PDF-Ausgabe beider Ansichten (§11).

*DoD:* Auswahl nach Punkten nachvollziehbar; PDF entspricht dem bestehenden Layout und trägt den Hinweis. —
**abgeschlossen**

## Phase 5b — Filterübernahme und Athletenauswahl im PDF

Qualifikanten-PDF folgt dem Filterstand des Bildschirms (`QualificationOverviewFilter`); die Förderansicht wird eine
Livewire-Komponente mit Athletenauswahl, Kaderart-Filter und Namenssuche.

*DoD:* Ein gefilterter Bildschirm ergibt ein gleich gefiltertes PDF; eine Athletenauswahl überlebt das Blättern und
bestimmt den Inhalt des Förder-PDF.

---

# 15. Risiken

| #    | Risiko                                                                       | Gegenmaßnahme                                                                  |
|------|------------------------------------------------------------------------------|--------------------------------------------------------------------------------|
| Q-R1 | Umgerechnete Zeit wird als Qualifikation missverstanden                      | **[Q4]**, eigener Status, Hinweis in Anzeige und PDF, eigener Testfall         |
| Q-R2 | Dateiformat ändert sich zwischen Veröffentlichungen                          | manuelle Pflege als Regelfall (§9.1), Import bricht verständlich ab            |
| Q-R3 | Erneuter Import überschreibt ÖBSV-Festlegungen                               | Import füllt nur MQS und MET (§9.2)                                            |
| Q-R4 | Fehlende Norm wird als „nicht erfüllt" gelesen                               | eigener Status `no_standard` (§7.2), eigener Abschnitt (§7.4)                  |
| Q-R5 | MET wird ohne MQS als Qualifikation gewertet                                 | bedingte Auswertung (§7.2), eigener Testfall                                   |
| Q-R6 | Prozentsatz auf die Zeit wirkt über die Bewerbe ungleich                     | Punktanzeige neben der Zeit (§5.3)                                             |
| Q-R7 | Offene Zeilen sehen aus wie bewusst gesetzte                                 | `null` ≠ `0` (**[Q3]**), Kennzeichnung offener Zeilen                          |
| Q-R8 | Umrechnung für Nachwuchs zu optimistisch                                     | Übernahme des Hinweises aus `wps-points` §9.6                                  |
| Q-R9 | Nicht gekennzeichnete Wettkämpfe lassen die Qualifikantenliste leer aussehen | Ausschlusshinweis mit Anzahl und betroffenen Wettkämpfen über der Liste (§7.1) |

---

# 16. Offene Punkte

- **Anerkennung von EXH-Ergebnissen** international: Die Regel liegt bei World Para Swimming. Bis zur Klärung werden sie
  einbezogen und gekennzeichnet.
- **Staffelnormen** sind in Punkten angegeben (z. B. "34 Points") und folgen einer eigenen Systematik. Nicht Teil dieses
  Moduls; bei Bedarf eigene Ausbaustufe.
- **Automatischer Bezug der Normdateien** von World Para Swimming ist nicht vorgesehen.
