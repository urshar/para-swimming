# STATISTICS_SPEC.md

# Para Swimming NatDB

## Statistik- und Jahresberichtsmodul

---

## 1. Status und Ziel

Diese Spezifikation beschreibt die Implementierung eines Statistik- und Jahresberichtsmoduls für das bestehende
Laravel-Projekt **Para Swimming NatDB**.

Das Modul soll Statistiken aus den bereits vorhandenen Daten des Projekts berechnen und daraus einen konfigurierbaren
Jahresbericht erzeugen.

Der Bericht orientiert sich fachlich am bestehenden Bericht:

> ÖBSV Schwimmen – Bericht zur Sportkonferenz – ÖBSV Cup 2024 – ÖBM – ÖJM

Der Referenzbericht enthält unter anderem:

* Teilnehmerstatistiken
* Startstatistiken
* Vereinsauswertungen
* Sportler mit den meisten Teilnahmen
* Auswertung ausländischer Teilnehmer
* Behinderungsgruppen
* Rekordstatistiken
* ÖBM-Statistiken
* ÖJM-Statistiken
* ÖBSV-Cup-Gesamtwertungen

Der Bericht 2024 dient als Referenz für die Plausibilisierung der Ergebnisse.

---

# 2. Absolute Implementierungsregeln

## 2.1 Bestehende Projektlogik verwenden

Vor der Implementierung muss das gesamte Repository analysiert werden.

Die Implementierung muss die bereits vorhandenen:

* Models
* Eloquent Relationships
* Services
* Actions
* Controllers
* Livewire-Komponenten
* Filament-/Flux-Komponenten
* Policies
* Berechtigungen
* Result-Logik
* Cup-Wertungslogik
* Rekordlogik
* Sportklassenlogik
* Altersgruppenlogik
* PDF-Exportlogik

berücksichtigen.

Es darf keine parallele Fachlogik erstellt werden, wenn diese bereits im Projekt existiert.

---

## 2.2 Keine redundante Statistikdatenbank

Statistikwerte dürfen grundsätzlich nicht dauerhaft als aggregierte Werte gespeichert werden.

Nicht zulässig:

```text
statistics.participants = 186
statistics.starts = 1464
```

Stattdessen müssen die Werte aus den bestehenden Daten berechnet werden.

Wenn ein Ergebnis geändert oder gelöscht wird, müssen die Statistiken automatisch aktualisiert werden.

---

## 2.3 Keine doppelte Geschäftslogik

Insbesondere dürfen keine neuen parallelen Berechnungen erstellt werden für:

* ÖBSV-Cup-Wertung
* Sportklassen
* Altersgruppen
* Rekorde
* World Aquatics Points
* Richtzeiten
* Kaderzugehörigkeit

Wenn bestehende Services vorhanden sind, müssen diese verwendet oder erweitert werden.

---

# 3. Pflichtprozess für die Implementierung

Die Implementierung erfolgt in Phasen.

## PHASE 0 – Repositoryanalyse

In dieser Phase darf noch keine fachliche Implementierung erfolgen.

Analysiere:

```text
app/Models
app/Services
app/Actions
app/Http
app/Livewire
app/Policies
database/migrations
resources/views
resources/views/pdf
routes
tests
```

Besonders analysieren:

```text
Athlete
Club
Meet
SwimEvent
Entry
Result
SwimRecord
SportClass
AgeGroup
Cup
CupDailyResult
CupOverallResult
```

Zusätzlich alle bestehenden Services und Komponenten für:

* Rekorde
* ÖBSV Cup
* Sportklassen
* Altersgruppen
* PDF-Export

### Ergebnis von Phase 0

Erstelle eine technische Analyse mit:

1. relevanten Models
2. relevanten Tabellen
3. relevanten Beziehungen
4. relevanten Services
5. vorhandener Geschäftslogik
6. vorgeschlagenen Integrationspunkten
7. notwendigen neuen Klassen
8. notwendigen Migrationen

Für jede geplante neue Datei muss begründet werden, warum sie erforderlich ist.

### STOP

Nach Abschluss der Repositoryanalyse stoppen.

Nicht mit der Implementierung beginnen, bevor die Analyse abgeschlossen ist.

---

# 4. PHASE 1 – Statistikmodell und Konfiguration

Implementiere zunächst nur die technische Grundlage.

Die Statistik benötigt mindestens:

```text
year
date_from
date_to
meet_ids
sections
```

Beispiel:

```php
[
    'year' => 2024,
    'date_from' => '2024-01-01',
    'date_to' => '2024-12-31',
    'meet_ids' => [1, 2, 3],
    'sections' => [
        'overview' => true,
        'participants' => true,
        'clubs' => true,
        'athletes' => true,
        'nations' => true,
        'sport_classes' => true,
        'records' => true,
        'cup' => true,
    ],
]
```

Die AI muss prüfen, ob diese Konfiguration als:

* DTO
* Value Object
* Array
* Model

umgesetzt werden sollte.

Die Entscheidung muss zur bestehenden Architektur passen.

### STOP

Nach Implementierung und Tests der Konfiguration stoppen.

---

# 5. PHASE 2 – Basisstatistiken

Implementiere:

* Anzahl Veranstaltungen
* Anzahl Teilnehmer
* Anzahl Vereine
* Anzahl Starts

## Teilnehmerdefinition

Ein Athlet zählt pro ausgewählter Veranstaltung höchstens einmal.

Beispiel:

```text
Athlet A:
5 Starts bei Meet 1
3 Starts bei Meet 2
```

Ergebnis:

```text
Teilnahmen: 2
Teilnehmer: 1
Starts: 8
```

## Startdefinition

Ein Start ist ein einzelner relevanter Ergebnisdatensatz gemäß der bestehenden Geschäftslogik.

Die AI muss prüfen, wie folgende Statuswerte im bestehenden Projekt behandelt werden:

```text
DNS
DNF
DSQ
```

Die Entscheidung darf nicht geraten werden.

Sie muss aus der bestehenden Anwendung und den fachlichen Regeln abgeleitet werden.

### Tests

Mindestens:

```text
Athlet mit 5 Starts zählt als 1 Teilnehmer.
Athlet mit 5 Starts zählt als 5 Starts.
Athlet bei 3 Veranstaltungen zählt als 3 Teilnahmen.
```

### STOP

---

# 6. PHASE 3 – Veranstaltungsstatistik

Erzeuge pro Veranstaltung:

```text
Teilnehmer
Starts
```

Beispiel:

| Veranstaltung | Teilnehmer | Starts |
|---------------|-----------:|-------:|
| LM Salzburg   |         45 |    234 |
| ÖSTM          |         96 |    421 |
| ÖJBM          |         21 |     70 |

Ein Athlet darf innerhalb einer Veranstaltung nur einmal als Teilnehmer gezählt werden.

### STOP

---

# 7. PHASE 4 – Vereinsstatistik

Implementiere getrennte Auswertungen für:

## Teilnehmer pro Verein

```text
COUNT(DISTINCT athlete_id)
```

## Starts pro Verein

```text
COUNT(relevant results)
```

Beispiel:

| Rang | Verein   | Teilnehmer | Starts |
|------|----------|-----------:|-------:|
| 1    | Verein A |         24 |    188 |
| 2    | Verein B |         19 |    142 |

Die bestehenden Club- und Athlete-Beziehungen müssen verwendet werden.

### STOP

---

# 8. PHASE 5 – Sportlerstatistik

Implementiere:

* Sportler mit den meisten Veranstaltungs-Teilnahmen
* Anzahl der Starts pro Sportler
* Anzahl der Veranstaltungen pro Sportler

Ein Sportler wird pro Veranstaltung maximal einmal gezählt.

Beispiel:

```text
Athlet A
Meet 1: 5 Starts
Meet 2: 3 Starts
Meet 3: 2 Starts
```

Ergebnis:

```text
Teilnahmen: 3
Starts: 10
```

Zusätzlich muss die Anzahl der Sportler mit mindestens X Teilnahmen berechnet werden.

Standard:

```text
X = 2
```

Der Wert muss konfigurierbar sein.

### STOP

---

# 9. PHASE 6 – Nationenstatistik

Die bestehende Nationenlogik des Projekts muss verwendet werden.

Auswertung:

```text
Nation
Teilnehmer
Starts
```

Beispiel:

| Nation | Teilnehmer | Starts |
|--------|-----------:|-------:|
| CZE    |         12 |     44 |
| SUI    |          8 |     31 |
| SVK    |          5 |     19 |

Es darf keine eigene Nationenlogik erstellt werden, wenn bereits eine bestehende Datenstruktur vorhanden ist.

### STOP

---

# 10. PHASE 7 – Sportklassen und Behinderungsgruppen

Die bestehenden Sportklassen und Klassifizierungsstrukturen müssen verwendet werden.

Keine hardcodierten Listen in der Statistiklogik.

Die Statistik muss neue Sportklassen oder Gruppen automatisch berücksichtigen, sofern sie in der bestehenden
Datenstruktur angelegt werden.

Auswertungen müssen mindestens ermöglichen:

```text
Sportklasse
Behinderungsgruppe
Teilnehmer
Starts
```

Beispiele:

```text
S1
S2
S3
...
S10

S11
S12
S13

S14
S15
S21
```

Die bestehende Zuordnung von Sportklassen zu Behinderungsgruppen ist zu verwenden.

### STOP

---

# 11. PHASE 8 – Altersgruppen und Geschlecht

Die bestehenden AgeGroup-Strukturen und die vorhandene Altersberechnung müssen verwendet werden.

Keine parallele Altersberechnung implementieren.

Die Statistik muss mindestens nach:

```text
Jugend
Open
```

auswerten können.

Die vorhandene ÖBSV-Cup-Regel:

```text
Jugend: 18 Jahre und jünger
Open: 19 Jahre und älter
```

muss berücksichtigt werden, sofern diese Regel im bestehenden Cup-Modul verwendet wird.

Geschlecht muss nach der bestehenden Projektlogik ausgewertet werden.

### STOP

---

# 12. PHASE 9 – Rekordstatistik

Die bestehende Rekordlogik muss verwendet werden.

Insbesondere prüfen und integrieren:

```text
SwimRecord
RecordCheckerService
RecordImportService
```

Auswertungen:

* Anzahl aller Rekorde
* österreichische Rekorde
* österreichische Jugendrekorde
* Staffelrekorde
* Rekorde pro Athlet

Der Statistikzeitraum muss berücksichtigt werden.

### STOP

---

# 13. PHASE 10 – ÖBSV-Cup-Integration

Die bestehende ÖBSV-Cup-Logik darf nicht dupliziert werden.

Die vorhandenen Services und Models müssen verwendet werden.

Insbesondere prüfen:

```text
Cup
CupDailyResult
CupOverallResult
DailyRankingService
OverallRankingService
```

Die Statistik muss die bestehende Gesamtwertung darstellen können.

Auswertungen nach:

```text
Sportklasse
Altersgruppe
Geschlecht
```

Beispiel:

```text
S01–S10 Jugend Damen
S01–S10 Jugend Herren
S01–S10 Damen
S01–S10 Herren

S11–S13 Jugend Damen
S11–S13 Jugend Herren
S11–S13 Damen
S11–S13 Herren

S14 Jugend Damen
S14 Jugend Herren
S14 Damen
S14 Herren

S15 Jugend Herren

S21 Jugend Damen
S21 Jugend Herren
S21 Damen
S21 Herren
```

Die Gruppierung muss möglichst dynamisch aus der bestehenden Cup-Konfiguration abgeleitet werden.

### STOP

---

# 14. PHASE 11 – Statistik-Service

Erst jetzt sollen die einzelnen Statistiken in einer zentralen Fassade zusammengeführt werden.

Beispiel:

```php
$statistics = $statisticsService->generate(
    configuration
);
```

Mögliche Rückgabe:

```php
[
    'overview' => [],
    'participants' => [],
    'clubs' => [],
    'athletes' => [],
    'nations' => [],
    'sport_classes' => [],
    'records' => [],
    'cup' => [],
]
```

Die konkrete Struktur muss zur bestehenden Architektur passen.

### STOP

---

# 15. PHASE 12 – Dashboard

Implementiere ein Statistik-Dashboard.

Pflichtfunktionen:

```text
Jahr auswählen
Veranstaltungen auswählen
Statistik anzeigen
```

Kennzahlen:

```text
Teilnehmer
Vereine
Veranstaltungen
Starts
Rekorde
```

Zusätzliche Tabellen:

```text
Teilnehmer pro Veranstaltung
Starts pro Veranstaltung
Top-Vereine
Top-Sportler
Nationen
Rekorde
```

Die Oberfläche muss das bestehende UI-System des Projekts verwenden.

Keine neue UI-Bibliothek einführen.

### STOP

---

# 16. PHASE 13 – Jahresbericht

Implementiere einen modularen Jahresbericht.

Mögliche Abschnitte:

```text
1. Allgemeiner Überblick
2. Teilnehmer und Starts
3. Vereinsstatistik
4. Sportlerstatistik
5. Ausländische Teilnehmer
6. Behinderungsgruppen
7. Sportklassen
8. Rekorde
9. ÖBSV Cup
10. ÖBM
11. ÖJM
```

Jeder Abschnitt muss separat aktivierbar sein.

Der Bericht muss aus den Statistik-Services gespeist werden.

Die Report-Logik darf keine eigenen Berechnungen durchführen.

### STOP

---

# 17. PHASE 14 – PDF-Export

Die bestehende PDF-Infrastruktur muss verwendet werden.

Insbesondere prüfen:

```text
PdfExportService
resources/views/pdf
```

Der Jahresbericht muss als PDF erzeugbar sein.

Das PDF muss enthalten:

* Titel
* Statistikjahr
* Zeitraum
* ausgewertete Veranstaltungen
* Tabellen
* Ranglisten
* Seitenzahlen
* Kopf- und Fußzeile

### STOP

---

# 18. PHASE 15 – Exporte

Falls die bestehende Anwendung bereits Exportinfrastruktur besitzt, soll diese verwendet werden.

Unterstützte Exporte:

```text
PDF
CSV
Excel
```

Mindestens der komplette Jahresbericht muss als PDF exportierbar sein.

Einzelne Statistikbereiche sollen nach Möglichkeit ebenfalls exportierbar sein.

---

# 19. PHASE 16 – Referenzvalidierung

Die Ergebnisse sollen anhand des Berichts 2024 plausibilisiert werden.

Zu prüfen:

```text
186 Sportler
25 österreichische Vereine
1.464 Starts
97 Sportler mit mindestens zwei Teilnahmen
85 neue Rekorde
```

Abweichungen müssen erklärt werden.

Mögliche Ursachen:

* geänderte Daten
* korrigierte Ergebnisse
* unterschiedliche Definition von Start
* unterschiedliche Auswahl von Veranstaltungen
* geänderte Geschäftsregeln

Es darf nicht einfach eine Zahl hardcodiert werden, um die Referenzwerte zu erreichen.

---

# 20. Tests

Alle Statistikberechnungen benötigen automatisierte Tests.

Pflichtfälle:

```text
Teilnehmer werden eindeutig gezählt.
Starts werden korrekt gezählt.
Teilnahmen werden pro Veranstaltung gezählt.
Vereine werden korrekt gruppiert.
Sportler werden korrekt gerankt.
Nationen werden korrekt gruppiert.
Sportklassen werden korrekt gruppiert.
Altersgruppen werden korrekt berechnet.
Rekorde werden korrekt gefiltert.
Cup-Wertungen verwenden die bestehende Logik.
Meet-Filter funktionieren.
Zeitraumfilter funktionieren.
```

Tests müssen mit Pest entsprechend der bestehenden Teststruktur des Projekts erstellt werden.

---

# 21. Performance

Die AI muss die Datenmenge des Projekts berücksichtigen.

Vor der Implementierung großer Aggregationsabfragen prüfen:

* vorhandene Indizes
* Eager Loading
* N+1-Probleme
* Query-Anzahl
* Datenmenge

Keine unnötige Optimierung implementieren.

Caching darf nur eingesetzt werden, wenn es tatsächlich notwendig ist.

Wenn Caching verwendet wird, muss die Invalidierung definiert werden.

---

# 22. Berechtigungen

Die bestehende Berechtigungsstruktur muss verwendet werden.

Falls noch keine passenden Berechtigungen existieren, sollen mindestens folgende Berechtigungen geprüft werden:

```text
statistics.view
statistics.configure
statistics.report.generate
statistics.export
```

Keine neue Berechtigungsarchitektur einführen.

---

# 23. Neue Datenbanktabellen

Neue Tabellen sind grundsätzlich zu vermeiden.

Eine Tabelle für gespeicherte Berichtskonfigurationen darf nur erstellt werden, wenn der bestehende Anwendungsfall dies
tatsächlich erfordert.

Die eigentlichen Statistikwerte dürfen nicht redundant gespeichert werden.

---

# 24. Definition of Done

Das Modul ist fertig, wenn:

* die Repositoryanalyse abgeschlossen ist
* bestehende Geschäftslogik wiederverwendet wird
* Statistikjahre ausgewählt werden können
* Veranstaltungen ausgewählt werden können
* Teilnehmer korrekt gezählt werden
* Starts korrekt gezählt werden
* Vereinsstatistiken funktionieren
* Sportlerstatistiken funktionieren
* Nationenstatistiken funktionieren
* Sportklassenstatistiken funktionieren
* Behinderungsgruppenstatistiken funktionieren
* Altersgruppenstatistiken funktionieren
* Rekordstatistiken funktionieren
* die bestehende ÖBSV-Cup-Wertung integriert ist
* das Dashboard funktioniert
* der Jahresbericht modular erstellt werden kann
* PDF-Export funktioniert
* automatisierte Tests vorhanden sind
* die Referenzwerte 2024 plausibilisiert wurden
* keine redundante Statistikdatenbank entsteht
* keine doppelte Cup-, Rekord- oder Sportklassenlogik implementiert wurde

---

# 25. Wichtigste Regel

Die AI darf nicht direkt mit dem Programmieren beginnen.

Der korrekte Ablauf ist:

```text
1. Repository analysieren
2. Bestehende Datenstrukturen dokumentieren
3. Bestehende Services identifizieren
4. Integrationsplan erstellen
5. Analyse vorlegen
6. Auf Freigabe warten
7. Implementieren
8. Tests erstellen
9. Referenzdaten validieren
10. PDF-Bericht testen
```

Wenn während der Implementierung festgestellt wird, dass eine fachliche Entscheidung fehlt, darf die AI keine eigene
fachliche Regel erfinden.

Sie muss:

```text
Problem dokumentieren
mögliche Optionen nennen
Auswirkung erklären
auf Entscheidung warten
```

---

# Ende der Spezifikation
