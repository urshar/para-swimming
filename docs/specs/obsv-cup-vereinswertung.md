# ÖBSV Cup Vereinswertung -- Spezifikation

**Status:** Entwurf zur Implementierung\
**Modul:** ÖBSV Cup Vereinswertung\
**Projekt:** Para Swimming NatDB\
**Technologie:** Laravel 13, PHP 8.3+, Livewire 4, Flux UI 2, Blade, MySQL / SQLite-Tests

------------------------------------------------------------------------

## 1. Ziel des Moduls

Das Modul ermittelt und visualisiert Vereinswertungen innerhalb des ÖBSV Cups.

Die bestehende Vereinswertung basiert auf der absoluten Anzahl der Starts. Dadurch haben vor allem große Vereine mit
vielen aktiven Schwimmern realistische Chancen auf die vorderen Plätze. Vereine mit wenigen Athleten können trotz sehr
guter sportlicher Leistungen kaum unter die Top 3 kommen.

Das neue Modul soll daher zwei Wertungssysteme parallel unterstützen:

1. **Historische / klassische Startwertung**
    - Rangfolge nach der Anzahl der Starts.
    - Dient der Darstellung der bisherigen Wertungen.
    - Muss für vergangene Jahre weiterhin reproduzierbar sein.
2. **Neue leistungsorientierte Vereinswertung**
    - Berücksichtigt die sportliche Leistung der Athleten.
    - Begrenzt den Vorteil sehr großer Vereine.
    - Ermöglicht auch Vereinen mit wenigen Startern eine realistische Chance auf die Top 3.
    - Die Wertungslogik muss langfristig erweiterbar und konfigurierbar sein.

------------------------------------------------------------------------

## 2. Geltungsbereich

Für die Vereinswertung werden ausschließlich Wettkämpfe berücksichtigt, die einem ÖBSV Cup zugeordnet sind.

Ein Meet ist Cup-relevant, wenn:

``` text
meets.cup_id IS NOT NULL
```

Es dürfen keine Ergebnisse aus normalen Wettkämpfen, Landesmeisterschaften, Österreichischen Meisterschaften oder
anderen Veranstaltungen automatisch in die ÖBSV-Cup-Vereinswertung einfließen.

Die Auswertung erfolgt immer innerhalb eines bestimmten Cups bzw. Cup-Jahres.

Beispiel:

``` text
ÖBSV Cup 2026
ÖBSV Cup 2027
ÖBSV Cup 2028
```

Die Wertungen vergangener Jahre müssen jederzeit abrufbar sein.

------------------------------------------------------------------------

# 3. Wertungssystem A -- Klassische Startwertung

## 3.1 Zweck

Die klassische Wertung bildet die bisherige Vereinswertung ab.

Sie beantwortet:

> Welcher Verein hatte im ÖBSV Cup die meisten Starts?

## 3.2 Definition eines Starts

Ein Start ist eine gültige Einzelmeldung bzw. ein gültiges Einzelergebnis eines Athleten in einem ÖBSV-Cup-Meet.

Staffelstarts werden nicht berücksichtigt.

Die Wertung soll sich bevorzugt an den tatsächlich vorhandenen Resultaten orientieren.

Grundsätzlich zählen:

``` text
1 Athlet
+ 1 Einzelbewerb
+ 1 ÖBSV-Cup-Meet
= 1 Start
```

Ein mehrfaches Ergebnis desselben Athleten im selben Bewerb darf nicht mehrfach gezählt werden, wenn es sich nur um
verschiedene Läufe / Heats desselben Bewerbs handelt.

Die genaue technische Umsetzung muss die bestehende Datenstruktur und die bestehende Statistiklogik berücksichtigen.

## 3.3 Rangfolge

Sortierung:

1. Anzahl Starts absteigend
2. Anzahl unterschiedlicher Athleten absteigend
3. Vereinsname aufsteigend

## 3.4 Historische Darstellung

Für jeden Cup müssen folgende Informationen dargestellt werden können:

Rang Verein Starts Athleten
  ------ ---------- -------- ----------
1 Verein A 142 18 2 Verein B 117 12 3 Verein C 98 9

Die historische Startwertung muss auch für bereits vergangene Cup-Jahre abrufbar sein.

------------------------------------------------------------------------

# 4. Wertungssystem B -- Neue leistungsorientierte Vereinswertung

## 4.1 Ziel

Die neue Wertung soll nicht ausschließlich die Größe eines Vereins belohnen.

Ein Verein mit wenigen Athleten soll eine realistische Chance auf einen Spitzenplatz haben, wenn diese Athleten
überdurchschnittliche Leistungen erbringen.

Gleichzeitig soll ein großer Verein nicht vollständig benachteiligt werden.

Daher wird ein **gewichtetes Top-Athleten-Modell** empfohlen.

------------------------------------------------------------------------

## 4.2 Grundprinzip

Die Vereinswertung wird nicht direkt über alle Starts berechnet.

Stattdessen erfolgt die Berechnung in drei Ebenen:

``` text
Ergebnis
    ↓
Athletenwertung
    ↓
Vereinswertung
```

### Ebene 1 -- Ergebnis

Ein Athlet erzielt bei einem ÖBSV-Cup-Meet ein Ergebnis.

### Ebene 2 -- Athletenwertung

Für jeden Athleten werden seine besten Cup-Leistungen ermittelt.

### Ebene 3 -- Vereinswertung

Für jeden Verein werden die besten Athleten berücksichtigt.

Die Anzahl der Athleten, die in die Vereinswertung einfließen, wird begrenzt und gewichtet.

Dadurch entsteht ein kontrollierter Vorteil für größere Vereine, aber kein unbegrenzter Vorteil.

------------------------------------------------------------------------

# 5. Empfohlenes Berechnungsmodell

## 5.1 Schritt 1 -- Punkte pro Ergebnis

Für jedes gültige Einzelresultat werden die bereits vorhandenen ÖBSV-Cup-Punkte verwendet.

Die Wertung soll möglichst auf bestehender Cup-Logik aufbauen.

Falls für ein Resultat bereits Cup-Punkte vorhanden sind:

``` text
Cup-Punkte verwenden
```

Alternativ kann die Wertung auf Basis der bestehenden ÖBSV-1000-Punkte-Tabelle erfolgen.

Die Vereinswertung darf keine eigene, abweichende Punkteberechnung parallel zum bestehenden Cup-System implementieren,
wenn die vorhandene Cup-Punkteberechnung fachlich identisch verwendet werden kann.

------------------------------------------------------------------------

## 5.2 Schritt 2 -- Beste Leistung pro Athlet und Meet

Innerhalb eines ÖBSV-Cup-Meets wird pro Athlet nur die beste Leistung für die Vereinswertung berücksichtigt.

Beispiel:

``` text
Athlet A – Meet 1
50 m Freistil: 720 Punkte
100 m Freistil: 810 Punkte
100 m Rücken: 760 Punkte
```

Für die Vereinswertung des Meets:

``` text
Bestes Ergebnis = 810 Punkte
```

Damit verhindert die Wertung, dass ein Athlet mit sehr vielen Starts automatisch einen unverhältnismäßig großen Vorteil
erhält.

------------------------------------------------------------------------

## 5.3 Schritt 3 -- Saisonwertung pro Athlet

Für jeden Athleten werden die besten Cup-Meet-Leistungen der Saison berücksichtigt.

Empfohlene Standardkonfiguration:

``` text
Maximal 3 beste Cup-Meets pro Athlet
```

Beispiel:

Meet Punkte
  ------------ --------
Cup Meet 1 810 Cup Meet 2 790 Cup Meet 3 760 Cup Meet 4 720

Athletenwert:

``` text
810 + 790 + 760 = 2.360 Punkte
```

Das vierte Meet wird nicht berücksichtigt.

Die Anzahl der zu berücksichtigenden Meets muss konfigurierbar sein.

Beispiel:

``` text
counted_meets_per_athlete = 3
```

------------------------------------------------------------------------

# 6. Schritt 4 -- Vereinswertung mit gewichteten Top-Athleten

Für jeden Verein werden die Athleten nach ihrer Saisonleistung sortiert.

Beispiel:

Athlet Saisonleistung
  ---------- ----------------
Athlet A 2.700 Athlet B 2.400 Athlet C 2.100 Athlet D 1.900 Athlet E 1.600 Athlet F 1.500 Athlet G 1.200

Es werden maximal die besten fünf Athleten berücksichtigt.

Empfohlene Gewichtung:

Position Gewicht
  ---------- ---------
1 100 % 2 80 % 3 60 % 4 40 % 5 20 %

Berechnung:

``` text
Vereinswert =
Athlet 1 × 1,00
+ Athlet 2 × 0,80
+ Athlet 3 × 0,60
+ Athlet 4 × 0,40
+ Athlet 5 × 0,20
```

Beispiel:

``` text
2.700 × 1,00 = 2.700
2.400 × 0,80 = 1.920
2.100 × 0,60 = 1.260
1.900 × 0,40 =   760
1.600 × 0,20 =   320

Gesamt = 6.960 Punkte
```

Ein Verein mit nur zwei Athleten kann somit ebenfalls in die Wertung gelangen:

``` text
2.700 × 1,00
+ 2.400 × 0,80
= 4.620 Punkte
```

Der Verein erhält keinen automatischen Vorteil durch zusätzliche Athleten außerhalb der Top 5.

------------------------------------------------------------------------

# 7. Warum dieses Modell empfohlen wird

Das Modell verbindet drei Ziele:

### Kleine Vereine

Ein Verein mit wenigen Athleten kann mit sehr guten Leistungen eine hohe Wertung erreichen.

### Mittlere Vereine

Mehrere gute Athleten erhöhen die Chance auf eine Spitzenplatzierung.

### Große Vereine

Viele Athleten sind weiterhin ein Vorteil, aber der Vorteil ist begrenzt.

Ein Verein kann nicht allein durch eine sehr große Anzahl an Athleten die Wertung dominieren.

------------------------------------------------------------------------

# 8. Konfigurierbarkeit

Die neue Wertung darf nicht hardcodiert werden.

Folgende Werte sollen konfigurierbar sein:

``` text
counted_meets_per_athlete
max_counted_athletes_per_club
athlete_weights
```

Standard:

``` text
counted_meets_per_athlete = 3
max_counted_athletes_per_club = 5

weights:
1 = 1.00
2 = 0.80
3 = 0.60
4 = 0.40
5 = 0.20
```

Beispiel einer zukünftigen Konfiguration:

``` text
3 Meets
Top 3 Athleten
Gewichtung:
100 %
75 %
50 %
```

Oder:

``` text
5 Meets
Top 8 Athleten
Gewichtung:
100 %
90 %
80 %
70 %
60 %
50 %
40 %
30 %
```

Die Datenstruktur muss daher eine spätere Erweiterung ermöglichen.

------------------------------------------------------------------------

# 9. Vereinszugehörigkeit

Die Vereinswertung muss den Verein verwenden, für den der Athlet beim jeweiligen Ergebnis gestartet ist.

Maßgeblich ist daher grundsätzlich:

``` text
result.club_id
```

Nicht ausschließlich:

``` text
athlete.club_id
```

Das ist wichtig, weil ein Athlet über die Zeit den Verein wechseln kann.

Für jedes Ergebnis muss der damals zugehörige Startverein berücksichtigt werden.

Falls die bestehende Datenstruktur historische Vereinszugehörigkeiten oder `result.club_id` bereits korrekt abbildet,
soll diese vorhandene Logik verwendet werden.

------------------------------------------------------------------------

# 10. Datenquelle und Integration mit dem Statistikmodul

Die bestehende Statistiklogik soll geprüft und möglichst wiederverwendet werden.

Insbesondere ist zu untersuchen:

``` text
StatisticsService
ParticipationStatisticsService
CupStatisticsService
```

Die neue Vereinswertung darf jedoch nicht einfach eine unklare Kopie von Statistiklogik enthalten.

Empfohlen wird:

``` text
CupClubRankingService
```

mit getrennten Berechnungsmethoden:

``` text
calculateStartRanking()
calculatePerformanceRanking()
```

Eine mögliche Fassade:

``` text
CupClubRankingService
    ├── StartBasedClubRankingService
    └── PerformanceBasedClubRankingService
```

Falls die bestehende `CupStatisticsService` bereits passende, wiederverwendbare Query- oder Aggregationslogik enthält,
soll diese genutzt werden.

Die fachliche Wertungslogik soll jedoch im Vereinswertungsmodul bleiben.

------------------------------------------------------------------------

# 11. Historische Wertungen

Das System muss historische Ergebnisse anzeigen können.

Beispiele:

``` text
ÖBSV Cup 2026
ÖBSV Cup 2027
ÖBSV Cup 2028
```

Für jedes Jahr soll der Benutzer zwischen den Wertungssystemen wechseln können:

``` text
[Startwertung]
[Leistungswertung]
```

Beispiel:

``` text
ÖBSV Cup 2026

Startwertung
1. Verein A – 142 Starts
2. Verein B – 117 Starts
3. Verein C – 98 Starts

Leistungswertung
1. Verein B – 6.960 Punkte
2. Verein A – 6.540 Punkte
3. Verein D – 5.980 Punkte
```

Die alte Wertung darf durch die neue Wertung nicht überschrieben werden.

------------------------------------------------------------------------

# 12. Persistenz der Ergebnisse

## 12.1 Grundsatz

Die bestehende Architektur berechnet Statistik- und Wertungswerte grundsätzlich aus den Bestandsdaten.

Das soll auch für die Vereinswertung bevorzugt gelten.

Die Wertung soll daher zunächst dynamisch berechnet werden.

Vorteile:

- Änderungen an Resultaten werden automatisch berücksichtigt.
- Korrekturen an Cup-Zuordnungen werden berücksichtigt.
- Keine veralteten gespeicherten Rankings.
- Historische Auswertungen können aus den historischen Daten erzeugt werden.

------------------------------------------------------------------------

## 12.2 Snapshot-Option

Falls eine offizielle Wertung nach Abschluss eines Cups unveränderlich archiviert werden muss, soll später ein
Snapshot-System ergänzt werden können.

Ein Snapshot könnte enthalten:

``` text
cup_id
ranking_type
configuration_snapshot
calculated_at
```

sowie die damals ermittelten Vereinsranglisten.

Dies ist nicht zwingend Bestandteil der ersten Implementierungsphase.

Die Architektur soll jedoch nicht verhindern, dass offizielle Wertungen später eingefroren werden können.

------------------------------------------------------------------------

# 13. Benutzeroberfläche

## 13.1 Hauptseite

Die Vereinswertung soll über das Cup-Modul erreichbar sein.

Beispiel:

``` text
ÖBSV Cup
    ├── Tageswertung
    ├── Gesamtwertung
    └── Vereinswertung
```

## 13.2 Filter

Die Seite muss mindestens folgende Filter anbieten:

``` text
Cup / Jahr
Wertungssystem
```

Wertungssystem:

``` text
Startwertung
Leistungswertung
```

------------------------------------------------------------------------

## 13.3 Startwertung

Anzuzeigen:

``` text
Rang
Verein
Starts
Anzahl Athleten
Anzahl Cup-Meets
```

------------------------------------------------------------------------

## 13.4 Leistungswertung

Anzuzeigen:

``` text
Rang
Verein
Gesamtpunkte
gewertete Athleten
gewertete Meets
```

Optional aufklappbar:

``` text
Athlet 1
    Meet 1: 810 Punkte
    Meet 2: 790 Punkte
    Meet 3: 760 Punkte
    Summe: 2.360
    Gewicht: 100 %
    Wertungsbeitrag: 2.360

Athlet 2
    ...
```

Damit die Wertung nachvollziehbar und transparent bleibt.

------------------------------------------------------------------------

# 14. Ranggleichheit

Bei gleicher Punktzahl gelten folgende Tie-Breaker:

## Leistungswertung

1. Höhere Summe der ungegewichteten Athletenleistungen
2. Höhere beste Einzelleistung
3. Mehr unterschiedliche gewertete Athleten
4. Mehr gewertete Cup-Meets
5. Vereinsname alphabetisch

## Startwertung

1. Mehr unterschiedliche Athleten
2. Mehr unterschiedliche Cup-Meets
3. Vereinsname alphabetisch

------------------------------------------------------------------------

# 15. Ausschlussregeln

Nicht berücksichtigen:

- Nicht-Cup-Meets
- Staffelstarts
- ungültige Ergebnisse
- disqualifizierte Ergebnisse
- DNS / DNF / NS / DSQ
- Ergebnisse ohne gültige Vereinszuordnung

Die konkreten gültigen Result status müssen an die bestehende Result-Status-Implementierung angepasst werden.

------------------------------------------------------------------------

# 16. Erforderliche Analyse vor Implementierung

Vor der Implementierung muss die Coding-AI das Repository analysieren.

Insbesondere:

### Bestehende Modelle

- `Cup`
- `Meet`
- `Club`
- `Athlete`
- `Entry`
- `Result`
- `CupDailyResult`
- `CupOverallResult`

### Bestehende Services

- `StatisticsService`
- `ParticipationStatisticsService`
- `CupStatisticsService`
- `DailyRankingService`
- `OverallRankingService`

### Bestehende Cup-Zuordnung

Prüfen:

``` text
meets.cup_id
```

### Vereinszuordnung

Prüfen:

``` text
results.club_id
entries.club_id
athletes.club_id
athlete_club_histories
```

### Bestehende Punktelogik

Prüfen, ob für die Vereinswertung bereits eine geeignete Punktequelle vorhanden ist.

------------------------------------------------------------------------

# 17. Empfohlene Implementierungsphasen

## Phase 0 -- Repositoryanalyse

Keine Implementierung.

Ermitteln:

- vorhandene Modelle
- Beziehungen
- Statistikabfragen
- Cup-Logik
- gültige Result status
- bestehende UI-Struktur
- bestehende PDF- und Exportlogik
- bestehende Berechnung der Cup-Punkte

Ergebnis:

``` text
Analysebericht
```

------------------------------------------------------------------------

## Phase 1 -- Klassische Startwertung

Implementieren:

``` text
StartBasedClubRankingService
```

Funktion:

``` text
getRanking(Cup $cup)
```

Ausgabe:

``` text
ClubRankingResult
```

------------------------------------------------------------------------

## Phase 2 -- Neue Leistungswertung

Implementieren:

``` text
PerformanceBasedClubRankingService
```

Berechnung:

``` text
Resultate
    ↓
Bestes Ergebnis je Athlet / Meet
    ↓
Beste N Cup-Meets je Athlet
    ↓
Athleten-Saisonwert
    ↓
Sortierung je Verein
    ↓
Gewichtung der Top N Athleten
    ↓
Vereinsgesamtwert
    ↓
Ranking
```

------------------------------------------------------------------------

## Phase 3 -- UI

Implementieren:

- Cup-Auswahl
- Wertungsauswahl
- Ranking-Tabelle
- Detailansicht
- historische Jahre

------------------------------------------------------------------------

## Phase 4 -- PDF / Export

Optional bzw. nach bestehendem Projektstandard:

- PDF-Ausgabe
- Excel-Export

Beide Wertungssysteme sollen exportierbar sein.

------------------------------------------------------------------------

# 18. Tests

Es sind Unit- und Feature-Tests zu erstellen.

## Startwertung

Testfälle:

- nur Cup-Meets werden berücksichtigt
- Nicht-Cup-Meets werden ignoriert
- Staffeln werden ignoriert
- DSQ-Ergebnisse werden ignoriert
- Starts werden korrekt gezählt
- historische Cups funktionieren
- Gleichstände werden korrekt aufgelöst

## Leistungswertung

Testfälle:

- bestes Ergebnis pro Athlet und Meet
- nur die konfigurierten besten Meets werden berücksichtigt
- nur die konfigurierten Top-Athleten werden berücksichtigt
- Gewichtung wird korrekt angewendet
- kleiner Verein mit wenigen Athleten kann korrekt gewertet werden
- großer Verein erhält keinen unbegrenzten Vorteil durch zusätzliche Athleten
- Vereinswechsel werden korrekt berücksichtigt
- Nicht-Cup-Meets werden ignoriert
- Staffeln werden ignoriert
- ungültige Resultate werden ignoriert
- Gleichstände werden korrekt aufgelöst

------------------------------------------------------------------------

# 19. Akzeptanzkriterien

Das Modul ist fachlich fertig, wenn:

- nur ÖBSV-Cup-Meets berücksichtigt werden
- die historische Startwertung weiterhin korrekt angezeigt werden kann
- vergangene Cup-Jahre abrufbar sind
- eine leistungsorientierte Vereinswertung vorhanden ist
- Vereine mit wenigen Athleten realistisch unter die Top 3 kommen können
- große Vereine nicht ausschließlich durch die Anzahl ihrer Athleten dominieren
- die Wertung transparent nachvollziehbar ist
- die Anzahl der gewerteten Meets konfigurierbar ist
- die Anzahl der gewerteten Athleten konfigurierbar ist
- die Gewichtung konfigurierbar ist
- Vereinswechsel korrekt berücksichtigt werden
- die Berechnung durch automatisierte Tests abgesichert ist
- die bestehende Cup- und Statistiklogik nicht dupliziert oder widersprüchlich implementiert wird
- die bestehende Architektur des Projekts eingehalten wird

------------------------------------------------------------------------

# 20. Offene fachliche Entscheidung für die finale Version

Vor der produktiven Implementierung sollte festgelegt werden, ob die neue Leistungswertung ausschließlich auf der
bestehenden Cup-Punktewertung basiert oder direkt auf den ÖBSV-1000-Punkten.

Empfehlung:

``` text
Bestehende Cup-Punkte verwenden,
wenn diese bereits die gewünschte sportliche Leistung abbilden.
```

Andernfalls:

``` text
ÖBSV-1000-Punkte als standardisierte Leistungsbasis verwenden.
```

Wichtig ist, dass die Vereinswertung nicht gleichzeitig mehrere widersprüchliche Punktesysteme verwendet.

------------------------------------------------------------------------

## Zusammenfassung der empfohlenen Standardwertung

``` text
Nur ÖBSV-Cup-Meets
        ↓
Bestes Ergebnis je Athlet und Meet
        ↓
Beste 3 Meets je Athlet
        ↓
Athleten-Saisonwert
        ↓
Beste 5 Athleten je Verein
        ↓
Gewichtung:
100 % / 80 % / 60 % / 40 % / 20 %
        ↓
Vereinsranking
```

Parallel bleibt die historische:

``` text
Startwertung
```

erhalten.
