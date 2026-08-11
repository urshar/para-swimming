# WPS Rankings & Reports

## Modul

**Name:** WPS Rankings & Reports **Modul-ID:** wps-rankings **Version:** 1.3 — Implementierungsfassung **Status:**
Blocked — Voraussetzung: `wps-points` Phase 4 abgeschlossen

> **Änderungen gegenüber Version 1.0:** Diese Fassung integriert die Ergebnisse der Phase-0-Bestandsanalyse.
> Wesentliche Anpassungen: das Rollenmodell (Trainer/Verein/Öffentlich) wurde auf die tatsächlich vorhandene
> Berechtigungsstruktur zurückgeführt; die Alterslogik wurde gegen die im Cup-Modul bereits etablierte
> Konvention abgeglichen; die PDF- und Filter-Infrastruktur verweist nun auf konkrete Bestandsklassen.

---

# 0. Umsetzungsentscheidungen

### [R1] Abhängigkeit von `wps-points`

Dieses Modul darf **erst begonnen werden, wenn `wps-points` Phase 4 abgeschlossen ist**. Vorher existieren die Felder
`results.wps_points`, `wps_point_version_id`, `wps_point_parameter_id` und `wps_calculation_type` nicht, und es gibt
keine berechneten Daten, gegen die getestet werden könnte.

### [R2] Berechtigungen — bestehendes Modell, kein Rollensystem

Version 1.0 nannte vier Rollen (Administrator, Trainer, Verein, öffentlicher Benutzer). Im Repository existieren nur
`users.is_admin` und `users.club_id` sowie `EntryPolicy` und `RequireAdmin`. Analog zur Entscheidung **[E2]**
in `wps-points` wird **kein** Rollensystem eingeführt.

| Rolle laut Spec 1.0   | Umsetzung                                                                                                                                       |
|-----------------------|-------------------------------------------------------------------------------------------------------------------------------------------------|
| Administrator         | `is_admin` — sieht alle Ranglisten und Reports                                                                                                  |
| Trainer               | **entfällt in Version 1.1.** Es gibt keine Trainer-Athlet-Zuordnung im Datenmodell. Trainerfunktionen werden über den Vereinszugang abgebildet. |
| Verein                | Benutzer mit `club_id` — sieht alle Ranglisten, Vereinsauswertungen jedoch nur für den eigenen Verein                                           |
| öffentlicher Benutzer | **entfällt in Version 1.1.** Die gesamte Anwendung liegt hinter `auth`; eine öffentliche Ansicht würde ein neues Routing-Konzept erfordern.     |

Eine Trainerzuordnung und eine öffentliche Ansicht sind in §17 als Erweiterung vorgemerkt.

### [R3] Keine eigene Berechnung

Das Modul liest ausschließlich gespeicherte `results.wps_points`. Es ruft `WpsPointCalculator` **nicht** auf.

Damit weicht es bewusst vom Muster des `DailyRankingService` ab, der die WA-Punkte für die Cup-Wertung neu rechnet.
Begründung: Bei der Cup-Wertung ist die Basiswert-Version saisonal festgelegt und kann von der am Ergebnis gespeicherten
abweichen. Bei WPS ist die verwendete Version **am Ergebnis selbst** gespeichert (`wps_point_version_id`) und damit
bereits nachvollziehbar. Eine Rangliste, die still mit einer anderen Version rechnet als am Ergebnis vermerkt, wäre
nicht reproduzierbar.

Stattdessen: Ranglisten zeigen die **verwendeten Versionen** an. Enthält eine Rangliste Ergebnisse aus mehreren
WPS-Versionen, wird das im Kopfbereich sichtbar gemacht (§11.2).

### [R4] Keine Persistenz, kein Cache in Version 1.1

Ranglisten werden bei jedem Aufruf aus den Bestandsdaten berechnet. Das entspricht dem im Projekt durchgängigen Muster
(`docs/architecture.md`: "Keine Persistenz in Fassaden. Statistik-/Wertungswerte werden bei jedem Aufruf neu
berechnet").

`wps_ranking_cache` und Caching (Version 1.0 §11.3, §15.4) werden **nicht** umgesetzt. Erst wenn eine gemessene
Antwortzeit das erfordert, wird nachgerüstet — dann mit einer Invalidierungsstrategie analog zum bestehenden
`CupStalenessService`.

### [R5] Statistikmodul bleibt unberührt

`StatisticsService` und seine Teilservices werden **nicht** geändert. Version 1.0 §3.3 sprach von "erweitern"; das ist
nicht nötig — die WPS-Ranglisten stehen fachlich neben der Statistik, nicht darin. Eine spätere Einbindung einzelner
WPS-Kennzahlen in den Jahresbericht ist als Erweiterung vorgemerkt (§17).

---

# 1. Übersicht

Das Modul stellt die Auswertungs- und Darstellungsebene für die von der WPS Points Engine berechneten Punkte bereit:

- Ranglisten
- Vergleiche
- Athletenanalysen
- Vereinsauswertungen
- PDF- und Druckausgaben

Eine eigene Punkteberechnung findet nicht statt **[R3]**.

---

# 2. Ziel

## 2.1 Hauptziele

- internationale Leistungsbewertung österreichischer Para-Schwimmer
- Vergleich über Sportklassen, Bewerbe, Geschlechter und Jahrgänge hinweg
- Jugendanalysen
- Saison- und Veranstaltungsranglisten
- Vereinsauswertungen
- PDF- und Druckausgaben

## 2.2 Nicht-Ziele

Berechnung der WPS-Punkte, Verwaltung der Parameter, Import der Point Scores — alles in `wps-points`.

Zusätzlich ausgeschlossen: Änderungen am Statistikmodul **[R5]**, Caching **[R4]**, Rollensystem **[R2]**,
Trainer-Athlet-Zuordnung, öffentliche Ansicht.

---

# 3. Integration in bestehende Module

## 3.1 WPS Points Engine

Gelesen werden je Ergebnis: `wps_points`, `wps_point_version_id`, `wps_calculation_type`, `wps_calculated_at`.

## 3.2 Results

Grundlage aller Ranglisten sind `results` mit den Relationen `athlete`, `club`, `swimEvent.strokeType`, `meet`.

**Wichtig:** Die Sportklasse wird aus `results.sport_class` gelesen, nicht aus `athlete_sport_classes` — sie kann je
Ergebnis abweichen (LENEX `RESULT.handicap`).

**Nationalität** wird aus `athlete.nation` gelesen, nicht aus der Vereinsnation. Das entspricht der im Statistikmodul
bereits bestätigten Regel (EU-Bürger mit Wohnsitz in Österreich).

## 3.3 Statistics

Unberührt **[R5]**.

## 3.4 Wiederverwendete Bestandsklassen

| Klasse                            | Verwendung                                                   |
|-----------------------------------|--------------------------------------------------------------|
| `App\Support\TimeParser`          | Zeitformatierung (`display()`)                               |
| `App\Support\SportClassSorter`    | natürliche Sortierung der Sportklassen (`S2` vor `S10`)      |
| `App\Support\ReportConfiguration` | Vorbild für die konfigurierbare Spalten-/Abschnittssteuerung |
| `App\Services\PdfExportService`   | gesamte PDF-Ausgabe                                          |
| `App\Models\AgeGroup`             | bestehende Altersgruppendefinition — **prüfen**, siehe §5    |
| `App\Concerns\SearchesAthletes`   | Athletensuche in Filtern                                     |

---

# 4. Ergebnisauswahl — verbindliche Regeln

Diese Regeln gelten für **alle** Ranglisten und werden zentral in `WpsRankingService` umgesetzt.

**Gewertet werden** Ergebnisse mit `wps_points IS NOT NULL`.

**Ausgeschlossen** sind Ergebnisse mit `status` ∈ `DNS`, `DNF`, `DSQ`, `SICK`, `WDR`. Diese haben ohnehin keine
WPS-Punkte, der Filter ist eine zusätzliche Absicherung.

**`EXH` (Exhibition)** wird standardmäßig **ausgeschlossen**, ist aber per Filter zuschaltbar. Das weicht bewusst von
der Statistik-Konvention ab (dort zählt `EXH` als Start), weil eine Rangliste eine Wertung ist und ein außer Konkurrenz
erzieltes Ergebnis dort nicht platziert werden soll.

**Staffeln** (`swim_events.relay_count > 1`) sind ausgeschlossen — es gibt keine WPS-Staffelparameter.

**Beste Leistung je Athlet und Bewerb:** In Saison- und Jugendranglisten zählt je Athlet und Bewerb die höchste
WPS-Punktzahl. Bei Gleichstand entscheidet die schnellere Zeit, danach das frühere Wettkampfdatum.

**LCM/SCM-Trennung:** Offizielle und geschätzte Punkte werden **standardmäßig nicht vermischt** und durchgängig
gekennzeichnet.

**Abweichende Vorbelegung gegenüber Version 1.1:** Da in Österreich ausschließlich Kurzbahn geschwommen wird
(`wps-points` §2.3), zeigt die Standardansicht **SCM**. Eine Standardansicht auf LCM wäre für nationale Auswertungen
nahezu leer. LCM ist per Filter wählbar; eine gemischte Ansicht ist möglich, blendet dann aber den Hinweis nach §11.4
verpflichtend ein.

**Geschätzte Langbahnzeit:** Bei umgerechneten Kurzbahnergebnissen wird `wps_estimated_lcm_time` als eigene Spalte
ausgewiesen. Für die Kaderplanung ist sie oft aussagekräftiger als die Punktzahl, weil sie sich unmittelbar gegen
internationale Melde- und Finalzeiten halten lässt.

---

# 5. Alterslogik

Das Alter wird nicht gespeichert, sondern aus `athlete.birth_date` und dem Ergebnisdatum berechnet.

**Verbindliche Festlegung — Abweichung von Version 1.0:** Version 1.0 §5.3 und §6 forderten das Alter "zum Zeitpunkt des
Ergebnisses". Das Cup-Modul verwendet dagegen das Alter **zum 31. Dezember des Wettkampfjahres** — eine bereits mit dem
ÖBSV abgestimmte Regel.

Zwei unterschiedliche Alterskonventionen in derselben Anwendung sind eine Fehlerquelle. **Entscheidung:** Auch
`wps-rankings` verwendet **Alter zum 31. Dezember des Wettkampfjahres**, in derselben Ausprägung wie im Cup-Modul.

Damit ist die Jugendgrenze eine Jahrgangsgrenze: „U18“ bedeutet, dass der Athlet im Wettkampfjahr höchstens 18 wird.

Athleten **ohne Geburtsdatum** werden aus Altersranglisten ausgeschlossen und als sichtbarer Sammelposten „Ohne
Geburtsdatum“ ausgewiesen — analog zur im Statistikmodul bestätigten Regel, dass fehlende Zuordnungen sichtbar bleiben
und nicht still verschwinden.

**Altersgruppen:** Vor Beginn von Phase 3 ist zu prüfen, ob die bestehende `AgeGroup`-Struktur des Cup-Moduls
wiederverwendbar ist oder ob `wps-rankings` eigene, frei definierbare Gruppen benötigt. Die Cup-Altersgruppen sind an
Sportklassengruppen und Cup-Einstellungen gebunden und daher möglicherweise zu speziell.

---

# 6. Ranglistenarten

## 6.1 Veranstaltungsrangliste

Beste WPS-Leistungen innerhalb einer Veranstaltung, über Sportklassen und Bewerbe hinweg vergleichbar.

Filter: Veranstaltung, Bewerb, Geschlecht, Sportklasse, Altersgruppe.

Kurs und WPS-Version ergeben sich aus der Veranstaltung.

## 6.2 Saisonrangliste

Alle Leistungen eines Jahres. Je Athlet und Bewerb die beste Leistung (§4).

Filter: Jahr, Bewerb, Sportklasse, Geschlecht, Altersgruppe, Kurs, Verein, Nation, Mindestpunktzahl.

**Jahresabgrenzung:** über `meets.start_date` — **kein** `YEAR()`, da die Testsuite auf SQLite läuft. Zu verwenden ist
`whereBetween('start_date', ["$jahr-01-01", "$jahr-12-31 23:59:59"])`. Die Uhrzeit an der oberen Grenze ist zwingend:
eine `date`-Spalte wird je nach Treiber als
`"2026-12-31"` oder als `"2026-12-31 00:00:00"` abgelegt, und ohne Uhrzeit fiele eine Veranstaltung am 31. Dezember im
zweiten Fall still aus der Auswertung. Grenzfälle (1. Januar und 31. Dezember) sind zu testen.

## 6.3 Jugendrangliste

Wie die Saisonrangliste, zusätzlich auf eine Altersobergrenze gefiltert (§5). Standard: U18.

## 6.4 Bewerbsrangliste

Bestenliste je Bewerb über einen wählbaren Zeitraum, wahlweise auf eine Sportklasse eingeschränkt oder
klassenübergreifend nach WPS-Punkten.

## 6.5 Internationale Vergleichsrangliste

**Zurückgestellt.** Setzt importierte internationale Ergebnisse voraus. Der LENEX-Import kann solche Daten aufnehmen, es
besteht aber keine automatisierte Quelle. In Version 1.1 nicht umgesetzt (§17).

---

## 6.6 Förderauswertung (Talentsichtung)

Beantwortet die Frage des Verbandes: *Welche Athletinnen und Athleten haben Potenzial und sollen gefördert werden?* Sie
ist damit die Kaderfrage — im Unterschied zur Selektionsfrage einer konkreten Meisterschaft, die im Modul
`wps-qualification` liegt.

**Datenlage:** Ausgewertet werden Athletinnen und Athleten, die überwiegend national starten, also nahezu ausschließlich
Kurzbahn. Die Punkte beruhen damit fast immer auf umgerechneten Zeiten (`wps-points` §9).

### 6.6.1 Eingaben

| Eingabe            | Beschreibung                                                            |
|--------------------|-------------------------------------------------------------------------|
| Zeitraum           | frei wählbar, auch mehrjährig                                           |
| Referenznorm       | eine Meisterschaft aus `wps-qualification`; Vorbelegung: die jüngste EM |
| Schwelle Jugend    | Prozentsatz der Normpunktzahl, Vorschlag 85 %                           |
| Schwelle Allgemein | Prozentsatz der Normpunktzahl, Vorschlag 95 %                           |
| Bahnlänge          | Vorbelegung SCM (§4)                                                    |

**Warum die EM als Vorbelegung:** Sie ist die realistische erste internationale Meisterschaft. Für einen 16-Jährigen ist
die Paralympics-Norm kein Maßstab, sondern eine Zahl ohne Aussagekraft. Die Referenznorm ist wählbar, muss aber im
Bericht ausgewiesen werden — ein Prozentwert ohne Angabe seiner Bezugsgröße ist wertlos.

**Für mehrjährige Auswertungen wird eine Norm über den gesamten Zeitraum festgehalten**, nicht jährlich nachgezogen.
MQS-Zeiten schwanken zwischen den Ausgaben; ein jährlicher Wechsel ließe einen Athleten schlechter dastehen, obwohl er
sich verbessert hat.

### 6.6.2 Schwelle in Punkten, nicht in Prozent der Zeit

Die Normzeit wird in WPS-Punkte umgerechnet, die Schwelle bezieht sich auf diese Punktzahl:

```
Schwelle = Punkte(Normzeit) × Prozentsatz / 100
```

Punkte sind genau dafür gemacht, über Sportklassen und Bewerbe hinweg vergleichbar zu sein. Ein Zeitprozentsatz bedeutet
bei S3 etwas völlig anderes als bei S14 und bei 50 m etwas anderes als bei 400 m.

### 6.6.3 Altersgruppen

| Gruppe    | Alter               |
|-----------|---------------------|
| Jugend    | 18 Jahre und jünger |
| Allgemein | 19 Jahre und älter  |

**Maßgeblich ist das Alter zum 31. Dezember des Jahres, in dem das ERGEBNIS erzielt wurde** — nicht des Zeitraumendes.
Bei einer mehrjährigen Auswertung kann ein Athlet damit in beiden Gruppen erscheinen:
mit den Ergebnissen aus seinem 18. Lebensjahr in der Jugend, mit den späteren in der allgemeinen Klasse. Das ist gewollt
und bildet die Entwicklung korrekt ab.

Athletinnen und Athleten ohne Geburtsdatum erscheinen als sichtbarer Sammelposten (§5).

### 6.6.4 Ausgabe

**Eine Zeile je Athlet und Bewerb** — ein Athlet kann mehrfach in der Liste stehen. Das ist beabsichtigt:
Die Information, in welchen Bewerben jemand über der Schwelle liegt, ist für die Förderentscheidung wesentlich.

Je Zeile: Athlet, Jahrgang, Altersgruppe, Verein, Sportklasse, Bewerb, beste Zeit im Zeitraum, Bahnlänge, geschätzte
Langbahnzeit, WPS-Punkte, Schwelle, Abstand zur Schwelle in Punkten und in Prozent der Norm, Veranstaltung und Datum der
Leistung.

**Die geschätzte Langbahnzeit steht bewusst neben der Punktzahl.** Zwei Größen, die unabhängig voneinander plausibel
sein müssen — und die Langbahnzeit lässt sich unmittelbar gegen die Normzeit halten.

### 6.6.5 Aussagekraft — verpflichtender Hinweis

Der Umrechnungsfaktor beruht überwiegend auf international startenden Athletinnen und Athleten, also der nationalen
Spitze (`wps-points` §9.6). **Für Nachwuchsathleten fallen die Punkte tendenziell zu hoch aus**, weil schwächere
Schwimmer den Wendenvorteil auf der Langbahn meist weniger ausgleichen können.

Damit trifft die größte Unsicherheit genau die Gruppe, für die dieses Werkzeug in erster Linie gedacht ist. Der Hinweis
erscheint deshalb in der Ansicht **und** im PDF, nicht nur in dieser Spec:

```text
Hinweis:
Die Punkte beruhen auf umgerechneten Kurzbahnzeiten. Der Umrechnungsfaktor ist an
international startenden Athletinnen und Athleten geeicht und fällt für den Nachwuchs
tendenziell zu optimistisch aus. Die Auswertung ist ein Anhaltspunkt für die Förderung,
kein Leistungsnachweis.
```

### 6.6.6 Abgrenzung

Die Förderauswertung ist **keine** Qualifikationsübersicht. Sie sagt nichts darüber aus, ob jemand startberechtigt ist —
dafür ist eine reale Langbahnzeit im Qualifikationszeitraum erforderlich (`wps-qualification` **[Q4]**). Sie greift auf
die Normen dieses Moduls ausschließlich zu, um ihre Schwelle abzuleiten.

---

# 7. Athletenanalyse

## 7.1 Ziel

Leistungsentwicklung sichtbar machen, Fortschritte erkennen, Abstand zur Spitze darstellen.

## 7.2 Athletenprofil

Historische WPS-Auswertung je Athlet: Zeitraum, Bewerb, Zeit, WPS-Punkte, Sportklasse, Kurs, Veranstaltung,
Berechnungstyp.

**Hinweis zur Sportklassen-Historie:** Sportklassen werden im Datenmodell **nicht** historisiert
(`athlete_sport_classes` führt genau eine Klasse je Kategorie). Eine Klassenänderung ist im Stammsatz daher nicht
nachvollziehbar. Da die Auswertung `results.sport_class` verwendet, bleibt die Historie über die Ergebnisse dennoch
korrekt — die Analyse muss aber sichtbar machen, wenn ein Athlet über den Zeitraum in unterschiedlichen Klassen
gestartet ist, weil die Punkte dann nur eingeschränkt vergleichbar sind.

## 7.3 Leistungsentwicklung

Beste Leistung je Saison und Bewerb, Punkte- und Zeitentwicklung, Differenz zur Vorsaison.

Diagramme werden serverseitig als einfache Tabellen plus optionaler Inline-Darstellung umgesetzt; im PDF ausschließlich
tabellarisch (dompdf kann kein JavaScript).

---

# 8. Vergleichsreports

## 8.1 Athletenvergleich

Gegenüberstellung mehrerer Athleten, Leistungen oder Zeitpunkte.

## 8.2 Abstand zur Referenzleistung

Referenzen: höchste Punktzahl der Rangliste, nationale Bestleistung, frei eingetragener Zielwert.

„Weltklasse“ und „internationale Spitze“ setzen internationale Vergleichsdaten voraus und sind mit §6.5 zurückgestellt.

**Kadernorm:** Es existieren bereits `kader_types` und `athlete_kader_memberships` sowie das Richtzeitenmodul
(`qualifying_times`). Vor Phase 4 ist zu klären, ob die Richtzeiten als WPS-Referenz taugen — sie sind über die
World-Aquatics-Formel definiert, nicht über WPS, und damit nicht unmittelbar vergleichbar.

## 8.3 Trainerreport

Beste Leistungen, Entwicklung, stärkste Bewerbe, Verbesserungspotenzial. Zugriff über den Vereinszugang **[R2]**.

---

# 9. Vereinsauswertung

Auswertungen: beste Vereinsleistungen, Durchschnittswerte, Anzahl gewerteter Leistungen, Entwicklung über Jahre.

Die Bewertungsmethode ist konfigurierbar: Summe der besten Leistungen, Durchschnitt der besten Leistungen, Anzahl
Leistungen über einem Schwellenwert.

**Vorbild:** Das Cup-Modul hat mit `ClubRankingConfiguration`, `StartBasedClubRankingService` und
`PerformanceBasedClubRankingService` bereits genau dieses Muster umgesetzt. Die WPS-Vereinswertung ist daran
auszurichten und darf dessen Konfigurationsobjekt als Vorlage nehmen — jedoch **ohne** die bestehenden Services zu
verändern.

**Abgrenzung:** Die Vereinswertung im Modul `obsv-cup-vereinswertung` ist eine offizielle ÖBSV-Wertung. Die
WPS-Vereinsauswertung ist ein Analysewerkzeug ohne offiziellen Charakter. Das muss in der Oberfläche und im PDF klar
unterscheidbar sein.

Ein Benutzer mit `club_id` sieht die Vereinsauswertung nur für den eigenen Verein **[R2]**.

---

# 10. Filter

**Standardfilter:** Jahr, Veranstaltung, Verein, Nation, Athlet, Geschlecht, Jahrgang, Altersgruppe, Sportklasse,
Bewerb, Kurs.

**Das Jahr gilt für alle Ranglistenarten**, auch für die Veranstaltungsrangliste: Dort grenzt es die Auswahlliste der
Veranstaltungen ein, die sonst mit jeder Saison länger und unübersichtlicher wird.

**Vorbelegung des Jahres:** das jüngste Jahr, für das Wettkämpfe vorliegen — **nicht** das laufende Kalenderjahr. Sonst
zeigt die Auswahlliste ihren ersten Eintrag, während intern ein anderes Jahr gefiltert wird, und die Rangliste bleibt
unerklärt leer.

**Kaderfilter:** Athleten lassen sich nach Kaderart ein- oder ausblenden. Drei Modi:

| Modus    | Wirkung                                      |
|----------|----------------------------------------------|
| `all`    | Filter wirkt nicht                           |
| `only`   | nur Athleten der gewählten Kaderarten        |
| `except` | Athleten der gewählten Kaderarten ausblenden |

Neben den definierten Kaderarten steht **"ohne Kaderzuordnung"** als eigener wählbarer Eintrag. Ohne ihn ließe sich
"nur Kaderathleten" nicht ausdrücken, und beim Ausblenden verschwänden Athleten ohne Zuordnung entweder immer oder nie —
beides wäre eine stille Festlegung.

Ein gesetzter Modus **ohne** Auswahl wirkt nicht: Er sähe sonst nach einer Einschränkung aus, die es nicht gibt. Wird
die letzte Kaderart abgewählt, fällt der Modus auf `all` zurück.

**Stichtag der Kaderzugehörigkeit:** das Ende des Auswertungsjahres, sofern es vergangen ist, sonst der heutige Tag —
dieselbe Regel wie im Qualifikationsmodul. Eine Auswertung der Saison 2024 soll auch 2028 dieselbe Kadereinteilung
zeigen. Aufgelöst über den gemeinsamen `AthleteKaderResolver`; bei mehreren gültigen Zugehörigkeiten gewinnt die höchste
Kaderstufe (kleinste `sort_order`).

**Erweiterte Filter:** nur offizielle Punkte / nur geschätzte SCM-Punkte, nur LCM / nur SCM, Mindestpunktzahl,
Exhibition einbeziehen.

Die Filter werden in einem Support-Objekt `App\Support\WpsRankingFilter` gekapselt (Vorbild:
`ReportConfiguration`), damit Livewire-Komponente, Service und PDF dieselbe Definition verwenden und die Filter im
PDF-Kopf ausgegeben werden können.

---

# 11. PDF und Druck

## 11.1 Technologie

Ausschließlich der bestehende `PdfExportService` (dompdf). **Keine** parallele PDF-Implementierung.

**Verbindlich:** PDF-Views liegen unter `resources/views/pdf/` und sind eigenständige, einfache HTML/CSS-Views. Sie
dürfen die Flux-basierten Web-Views **nicht** wiederverwenden — dompdf unterstützt weder Tailwind noch Flux noch Alpine.
Als Vorlage dienen die bestehenden Templates `cup-overall-ranking`, `cup-daily-ranking`,
`qualifying-times` und `qualifications`. Es wird **kein** neues PDF-Layout entworfen.

## 11.2 Kopfbereich

Titel, Zeitraum, verwendete Filter, Punktesystem (`WPS`), WPS-Version (en), Kurs, Erstellungsdatum.

Enthält die Rangliste Ergebnisse aus **mehreren** WPS-Versionen, werden alle aufgeführt **[R3]**.

## 11.3 Spalten

Rang, Name, Verein, Nation, Jahrgang, Altersgruppe, Sportklasse, Bewerb, Zeit, geschätzte Langbahnzeit (bei Umrechnung),
WPS-Punkte, Veranstaltung. Sichtbare Spalten sind konfigurierbar (§10).

Sortierung der Sportklassen über `SportClassSorter`. Mehrkriterien-Sortierung über zusammengesetzte
`sprintf()`-Sortierschlüssel, **nicht** über `sortBy()` mit Closure-Arrays (bekannter Fallstrick im Projekt).

## 11.4 SCM-Hinweis

Bei geschätzten SCM-Punkten verpflichtend, in PDF und Druckansicht:

```text
Hinweis:
Die dargestellten SCM-WPS-Punkte wurden anhand abgeleiteter Parameter berechnet.
Diese Werte sind nicht offiziell von World Para Swimming anerkannt.
```

Der Hinweis erscheint automatisch, sobald mindestens ein Ergebnis mit `wps_calculation_type = estimated`
enthalten ist. Bei Jugend- und Nachwuchsranglisten wird er um den Hinweis aus `wps-points` §9.6 ergänzt:
Der Umrechnungsfaktor beruht überwiegend auf international startenden Athletinnen und Athleten und fällt für den
Nachwuchs tendenziell zu optimistisch aus.

---

# 12. Benutzeroberfläche

Die Ranglisten werden als Livewire-Komponenten umgesetzt. Vorbild: `App\Livewire\StatisticsDashboard`.

Routen unter `/wps/rankings`, innerhalb der `auth`-Gruppe. Views unter `resources/views/wps/rankings/`.

Blade-/Flux-Konventionen wie in `wps-points` §14.4.

Funktionen: Rankingtyp wählen, Zeitraum wählen, Filter setzen, Tabelle anzeigen, PDF erzeugen.

---

# 13. Technische Umsetzung

## 13.1 Services

| Service                     | Aufgabe                                                          |
|-----------------------------|------------------------------------------------------------------|
| `WpsRankingService`         | Fassade: wählt anhand des Rankingtyps den passenden Teilservice  |
| `WpsSeasonRankingService`   | Saison-, Jugend- und Bewerbsranglisten                           |
| `WpsMeetRankingService`     | Veranstaltungsranglisten                                         |
| `WpsAthleteAnalysisService` | Athletenprofil, Leistungsentwicklung, Vergleiche                 |
| `WpsClubRankingService`     | Vereinsauswertung                                                |
| `WpsResultSelectionService` | Ergebnisauswahl nach §4 — verbindlich für alle Ranglistenarten   |
| `AthleteKaderResolver`      | Kaderzugehörigkeit zum Stichtag; geteilt mit `wps-qualification` |

`WpsResultSelectionService` liegt bewusst **neben** der Fassade und nicht darin: Die Regeln aus §4 gelten für alle
Ranglistenarten, und in der Fassade müssten die Teilservices sie über die Fassade zurückrufen — das kehrte das Muster
um.

Alle als `final readonly class` mit Constructor-Injection. Die Fassade enthält **keine** eigene Auswertungslogik —
dasselbe Muster wie `StatisticsService`.

Ein eigener `WpsPdfExportService` wird **nicht** angelegt; die Ausgabe läuft über den bestehenden
`PdfExportService` (Abweichung von Version 1.0 §15.1).

## 13.2 Performance

Ranglisten laden Ergebnisse mit `with()` für `athlete`, `club`, `swimEvent.strokeType`, `meet`, um N+1-Abfragen zu
vermeiden. Die Filterung erfolgt so weit wie möglich in der Datenbank; nur die Auswahl der besten Leistung je Athlet und
Bewerb und die Mehrkriterien-Sortierung laufen in PHP.

Alle Queries müssen auf MySQL und SQLite laufen.

---

# 14. Tests

Pest mit `RefreshDatabase`, keine Factories, Helper mit Phasensuffix, Testgruppen `wps-rankings-p1` … `-p6`.

## 14.1 Unit-Tests

- Sortierung nach WPS-Punkten absteigend, Tie-Break über Zeit, dann Datum
- beste Leistung je Athlet und Bewerb wird korrekt ermittelt
- Altersberechnung zum 31. Dezember des Wettkampfjahres (§5), inklusive Grenzfall Geburtstag am 31.12.
- Athlet ohne Geburtsdatum erscheint als Sammelposten, nicht als stiller Ausfall
- Ausschluss von `DNS`/`DNF`/`DSQ`/`SICK`/`WDR` und Staffeln
- `EXH` standardmäßig ausgeschlossen, per Filter einschließbar
- LCM/SCM werden standardmäßig nicht vermischt
- Ergebnisse ohne `wps_points` erscheinen nicht
- Vereinsauswertung: Summe, Durchschnitt und Schwellenwertmethode liefern korrekte Werte
- Sportklassensortierung: `S2` vor `S10`
- Förderauswertung: Schwelle wird korrekt aus der Normpunktzahl abgeleitet
- Förderauswertung: Altersgruppe richtet sich nach dem Ergebnisjahr, nicht nach dem Zeitraumende
- Förderauswertung: ein Athlet erscheint in einer mehrjährigen Auswertung in beiden Altersgruppen
- Förderauswertung: ein Athlet kann mit mehreren Bewerben in der Liste stehen
- Förderauswertung: Bewerbe ohne Norm erzeugen keine Schwelle und keine Zeile
- Jahresabgrenzung erfasst Veranstaltungen am 1. Januar und am 31. Dezember (§6.2)
- Kaderfilter blendet gewählte Kaderarten aus bzw. zeigt nur diese
- "ohne Kaderzuordnung" ist eine eigene, wählbare Gruppe
- ein Kadermodus ohne Auswahl wirkt nicht
- die Jahresvorbelegung trifft das jüngste Jahr mit Wettkämpfen, nicht das laufende Kalenderjahr
- die Veranstaltungsliste zeigt nur Wettkämpfe des gewählten Jahres

## 14.2 Feature-Tests

- Saisonrangliste: Jahr wählen, Filter setzen, korrekte Athleten in korrekter Reihenfolge
- Jugendrangliste: Altersgrenze wirkt, historische Altersberechnung korrekt
- PDF-Export: vollständige Tabelle, korrekter Kopfbereich, SCM-Hinweis vorhanden, wenn nötig
- mehrere WPS-Versionen in einer Rangliste werden im Kopfbereich alle ausgewiesen

## 14.3 Berechtigungstests

- Administrator erreicht alle Ranglisten
- Benutzer mit `club_id` sieht Vereinsauswertungen nur für den eigenen Verein
- nicht authentifizierte Anfragen werden abgewiesen

## 14.4 Regressionstests

Cup-Wertung, Richtzeiten und Statistik liefern unverändert dieselben Ergebnisse — dieses Modul ist reinlesend.

---

# 15. Implementierungsphasen

**Voraussetzung für Phase 1: `wps-points` Phase 4 ist abgeschlossen und abgenommen [R1].**

## Phase 1 — Grundstruktur

`WpsRankingFilter`, `WpsRankingService`-Fassade, Routing, Basis-Livewire-Komponente, Ergebnisauswahl nach §4.

*DoD:* Gefilterte Ergebnismenge ist korrekt und getestet; noch ohne Darstellung.

## Phase 2 — Saison- und Veranstaltungsranglisten

`WpsSeasonRankingService`, `WpsMeetRankingService`, Tabellenansicht, Filter — einschließlich Kaderfilter (§10).

*DoD:* Ranglisten werden korrekt angezeigt und sortiert. — **Phase 1 und 2 gemeinsam abgeschlossen**

Anmerkung: Die Veranstaltungsrangliste macht **keine** Bestenauswahl je Athlet und Bewerb. §4 nennt diese Regel
ausdrücklich nur für Saison- und Jugendranglisten; innerhalb einer Veranstaltung ist jeder Start ein eigener, und
Vorlauf wie Finale sollen beide sichtbar bleiben.

## Phase 3 — Alterslogik und Jugendranglisten

Altersberechnung nach §5, Altersgruppenentscheidung, U18-Filter.

*Voraussetzung:* Entscheidung, ob `AgeGroup` wiederverwendet wird (§5). *DoD:* Jugendranglisten sind korrekt, Grenzfälle
getestet.

## Phase 4 — Athletenanalyse

`WpsAthleteAnalysisService`, Profil, Leistungsentwicklung, Vergleichsreports, Referenzwerte.

*DoD:* Entwicklung einzelner Athleten ist sichtbar und nachvollziehbar.

## Phase 5 — PDF und Druck

PDF-Templates auf Basis der bestehenden, Export, Druckansicht, SCM-Hinweis.

*DoD:* Reports können erzeugt werden und entsprechen dem bestehenden Layout.

## Phase 6 — Förderauswertung

`WpsTalentReportService`, Eingabemaske, Ausgabe, PDF. Setzt `wps-qualification` Phase 1 voraus (Zugriff auf die Normen).

*DoD:* Für einen Zeitraum und eine Referenznorm liegt eine nach Altersgruppen getrennte Liste vor; geschätzte
Langbahnzeiten und der Hinweis nach §6.6.5 sind enthalten.

## Phase 7 — Vereinsauswertung

`WpsClubRankingService`, konfigurierbare Bewertungsmethoden, Abgrenzung zur offiziellen Cup-Vereinswertung.

*DoD:* Vereinsauswertungen verfügbar, Sichtbarkeit je Verein korrekt eingeschränkt.

---

# 16. Definition of Done

**Funktional:** Ranglisten, Jugendwertung, Athletenentwicklung, Vereinsauswertung und PDF-Export funktionieren;
SCM-Hinweise erscheinen automatisch.

**Technisch:** Services, Tests und Berechtigungen vorhanden; keine doppelte Berechnungslogik; keine Änderung an
`wps-points`, Statistik, Cup oder Richtzeiten; Pint sauber; alle Queries MySQL- und SQLite-tauglich.

**Benutzer:** Administratoren können alle Reports erstellen; Vereinsbenutzer sehen ihre eigenen Auswertungen; jede
Rangliste weist Punktesystem, Version und Berechnungstyp aus.

---

# 17. Zurückgestellt / Erweiterungsmöglichkeiten

- internationale Vergleichsranglisten (§6.5) — setzt eine internationale Datenquelle voraus
- Trainer-Athlet-Zuordnung und eigene Trainerrolle **[R2]**
- öffentliche, nicht authentifizierte Ranglistenansicht **[R2]**
- `wps_ranking_cache` und Caching **[R4]**
- Einbindung von WPS-Kennzahlen in den bestehenden Jahresbericht **[R5]**
- Vergleich WPS / World Aquatics / ÖBSV am selben Ergebnis
- Kaderprognosen, Qualifikationsanalysen, Talentanalyse
