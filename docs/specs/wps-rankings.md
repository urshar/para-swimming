# WPS Rankings & Reports

## Modul

**Name:** WPS Rankings & Reports  
**Modul-ID:** wps-rankings  
**Version:** 1.0  
**Status:** Specification

---

# 1. Übersicht

Das Modul WPS Rankings & Reports stellt die Auswertungs- und Darstellungsebene für die innerhalb der WPS Points Engine
berechneten Punkte bereit.

Während die WPS Points Engine ausschließlich für die Berechnung und Speicherung der Punkte verantwortlich ist, übernimmt
dieses Modul:

- Ranglisten
- Vergleiche
- Analysen
- Berichte
- PDF-Ausgaben
- Druckansichten

Das Modul verwendet ausschließlich bereits berechnete Ergebnisse.

Eine eigene Punkteberechnung findet nicht statt.

---

# 2. Ziel

## 2.1 Hauptziele

Das Modul soll ermöglichen:

- internationale Leistungsbewertung von Para-Schwimmern
- Vergleich verschiedener Sportklassen
- Jugendanalysen bis einschließlich 18 Jahre
- Saisonvergleiche
- Veranstaltungsranglisten
- Vereinsauswertungen
- Traineranalysen
- PDF- und Druckausgaben

---

## 2.2 Nicht-Ziele

Folgende Funktionen gehören nicht zu diesem Modul:

- Berechnung der WPS Punkte
- Verwaltung der WPS Parameter
- Import von WPS Point Scores

Diese Funktionen befinden sich im Modul:

`wps-points`

---

# 3. Integration in bestehende Module

## 3.1 WPS Points Engine

Das Ranking-Modul verwendet die von der WPS Points Engine bereitgestellten Daten.

Benötigte Informationen:

- WPS Punkte
- WPS Version
- Berechnungstyp
- LCM / SCM Kennzeichnung
- Veranstaltung
- Athlet
- Sportklasse

---

## 3.2 Results Modul

Die Grundlage aller Ranglisten sind die gespeicherten Ergebnisse.

Benötigte Daten:

- Athlet
- Verein
- Nation
- Geschlecht
- Jahrgang
- Sportklasse
- Bewerb
- Zeit
- WPS Punkte

---

## 3.3 Statistics Modul

Das vorhandene Statistikmodul soll erweitert und genutzt werden.

Keine parallele Statistikberechnung im WPS Ranking Modul.

---

# 4. Fachliche Grundlagen

## 4.1 WPS Ranking Prinzip

WPS Punkte ermöglichen den Vergleich unterschiedlicher:

- Sportklassen
- Bewerbe
- Geschlechter
- Jahrgänge

Ein Ranking basiert auf:

```
Athlet

+

Ergebnis

+

WPS Punkte

+

Filter
```

---

# 5. Ranglistenarten

Das Modul unterstützt verschiedene Ranglisten.

## 5.1 Veranstaltungsrangliste

Darstellung der besten Leistungen innerhalb einer Veranstaltung.

Filter:

- Veranstaltung
- Bewerb
- Geschlecht
- Sportklasse
- Bahnlänge

Beispiel:

```
ÖBSV Meisterschaft 2026

Top WPS Leistungen

1. Athlet A     945 Punkte
2. Athlet B     912 Punkte
3. Athlet C     889 Punkte
```

---

## 5.2 Saisonrangliste

Vergleich aller Leistungen innerhalb eines Jahres.

Filter:

- Jahr
- Bewerb
- Sportklasse
- Geschlecht
- Altersgruppe

Regel:

Für jeden Athleten kann die beste Leistung pro Bewerb ausgewählt werden.


---

## 5.3 Jugendrangliste

Spezielle Rangliste für Nachwuchsathleten.

Definition:

```
Jugend:

Alter <= 18 Jahre
```

Berechnung:

Alter zum Zeitpunkt des Ergebnisses.

Ziele:

- Nachwuchsvergleich
- internationale Einordnung
- Talententwicklung

---

## 5.4 Internationale Vergleichsrangliste

Darstellung der Position eines Athleten im internationalen Vergleich.

Mögliche Quellen:

- nationale Ergebnisse
- importierte internationale Ergebnisse
- zukünftige WPS Rankings

---

# 6. Alterslogik

Das Alter wird nicht dauerhaft gespeichert.

Es wird aus:

- Geburtsdatum
- Datum des Ergebnisses

berechnet.

Beispiel:

```
Geburtsdatum:

01.06.2010


Ergebnis:

15.05.2026


Alter:

15 Jahre
```

Dadurch bleiben historische Auswertungen korrekt.

# 7. Athletenanalyse

## 7.1 Ziel

Das Modul soll nicht nur Ranglisten anzeigen, sondern auch die Entwicklung einzelner Athleten analysieren können.

Ziel:

- Leistungsentwicklung sichtbar machen
- Fortschritte erkennen
- Abstand zur internationalen Spitze darstellen
- Trainer bei Entscheidungen unterstützen

---

# 7.2 Athletenprofil WPS Analyse

Für einen Athleten soll eine historische WPS-Auswertung verfügbar sein.

Darstellung:

- Zeitraum
- Bewerb
- Zeit
- WPS Punkte
- Sportklasse
- Bahnlänge
- Veranstaltung

Beispiel:

```
Athlet:

Max Mustermann


100m Freistil


2024

720 Punkte


2025

785 Punkte


2026

842 Punkte
```

---

# 7.3 Leistungsentwicklung

Das System soll die Entwicklung der WPS-Punkte über die Zeit darstellen können.

Mögliche Auswertungen:

- Punkteentwicklung
- Zeitentwicklung
- beste Leistung pro Saison
- persönliche Verbesserung

Beispiel:

```
2024:

720 Punkte


2025:

+65 Punkte


2026:

+57 Punkte
```

---

# 8. Vergleichsreports

## 8.1 Ziel

Trainer und Betreuer sollen erkennen können, wie eine Leistung international einzuordnen ist.


---

# 8.2 Athletenvergleich

Vergleich zwischen:

- mehreren Athleten
- mehreren Leistungen
- unterschiedlichen Zeitpunkten

Beispiel:

| Athlet   | Bewerb        | Zeit     | WPS Punkte |
|----------|---------------|----------|------------|
| Athlet A | 100m Freistil | 01:05.20 | 842        |
| Athlet B | 100m Freistil | 01:04.90 | 850        |

---

# 8.3 Abstand zur Referenzleistung

Ein Report soll den Abstand zu Referenzwerten anzeigen.

Mögliche Referenzen:

- Weltklasse
- internationale Spitze
- nationale Spitze
- Kadernorm

Beispiel:

| Bewerb        | Leistung   | Referenz   | Abstand |
|---------------|------------|------------|---------|
| 100m Freistil | 842 Punkte | 950 Punkte | -108    |

---

# 8.4 Trainerreport

Ein Trainerreport kann folgende Informationen enthalten:

- beste Leistungen
- Entwicklung
- stärkste Bewerbe
- Verbesserungspotenzial
- internationale Position

---

# 9. Vereinsauswertung

## 9.1 Ziel

Vereine sollen über WPS Leistungen ausgewertet werden können.

Mögliche Auswertungen:

- beste Vereinsleistungen
- Durchschnittswerte
- Anzahl gewerteter Leistungen
- Entwicklung über Jahre

---

# 9.2 Vereinsranking

Beispiele:

```
Vereinswertung WPS Punkte Saison 2026


1. Verein A

Durchschnitt:
812 Punkte


2. Verein B

Durchschnitt:
785 Punkte
```

Die Bewertungsmethode muss konfigurierbar sein.

Mögliche Varianten:

- Summe der besten Leistungen
- Durchschnitt der besten Leistungen
- Anzahl Leistungen über Schwellenwert

---

# 10. Filtermöglichkeiten

Alle Ranglisten und Reports unterstützen Filter.

## Standardfilter:

- Jahr
- Saison
- Veranstaltung
- Verein
- Nation
- Athlet
- Geschlecht
- Jahrgang
- Altersgruppe
- Sportklasse
- Bewerb
- Bahnlänge

---

# 10.1 Erweiterte Filter

Zusätzlich:

- offizielle WPS Punkte
- geschätzte SCM Punkte
- nur LCM
- nur SCM
- Mindestpunktzahl

Beispiel:

```
Zeige alle U18 Athleten

mit mehr als 800 WPS Punkten

über 100m Freistil

LCM
```

---

# 11. Datenmodell

## 11.1 Grundprinzip

Das Ranking-Modul speichert keine eigenen Ergebnisse.

Es verwendet bestehende Ergebnisdaten.

Datenquelle:

- Results
- Athletes
- Events
- WPS Points Engine

---

# 11.2 Ranking Abfragen

Die Ranglisten werden dynamisch aus den Ergebnisdaten erzeugt.

Vorteile:

- keine doppelten Daten
- immer aktuelle Ergebnisse
- weniger Synchronisationsprobleme

---

# 11.3 Optionale Speicherung

Für große Datenmengen kann später eine Aggregationstabelle eingeführt werden.

Beispiel:

```
wps_ranking_cache
```

Diese enthält:

- Rankingtyp
- Filter
- Saison
- Ergebniszeitpunkt

Nur verwenden, wenn Performance notwendig wird.

# 12. PDF- und Druckausgabe

## 12.1 Ziel

Alle Ranglisten und Reports sollen sowohl digital als auch als offizielles Dokument ausgegeben werden können.

Unterstützte Ausgaben:

- Browseransicht
- PDF
- Druckansicht

---

# 12.2 PDF Standard

Alle PDF-Ausgaben verwenden ein einheitliches Layout.

Kopfbereich:

- Logo (optional)
- Titel der Rangliste
- Zeitraum
- verwendete Filter
- Punktesystem
- WPS-Version
- Bahnlänge
- Erstellungsdatum

---

# 12.3 Tabelleninhalt

Standardspalten:

| Spalte        | Beschreibung |
|---------------|--------------|
| Rang          | Platzierung  |
| Name          | Athlet       |
| Verein        | Verein       |
| Nation        | Nation       |
| Jahrgang      | Geburtsjahr  |
| Altersgruppe  | z.B. U18     |
| Sportklasse   | S-Klasse     |
| Bewerb        | Strecke      |
| Zeit          | Leistung     |
| WPS Punkte    | Bewertung    |
| Veranstaltung | Quelle       |

Die sichtbaren Spalten müssen konfigurierbar sein.


---

# 12.4 Hinweis bei SCM

Bei geschätzten SCM-WPS-Punkten muss automatisch ein Hinweis eingefügt werden.

Text:

```
Hinweis:

Die dargestellten SCM-WPS-Punkte wurden anhand abgeleiteter Parameter berechnet.

Diese Werte sind nicht offiziell von World Para Swimming anerkannt.
```

Dieser Hinweis erscheint:

- im PDF
- in der Druckansicht
- optional in der Browseransicht

---

# 12.5 PDF Technologie

Die vorhandene PDF-Infrastruktur des Projekts soll verwendet werden.

Vorgabe:

- Nutzung der bestehenden PDF-Lösung
- keine parallele PDF-Implementierung

---

# 13. Benutzeroberfläche

## 13.1 Übersicht

Die Ranglisten werden über Livewire-Komponenten dargestellt.

Ziele:

- schnelle Filterung
- dynamische Aktualisierung
- Wiederverwendung bestehender UI-Komponenten

---

# 13.2 WPS Ranking Übersicht

Funktionen:

- Auswahl Rankingtyp
- Auswahl Zeitraum
- Filter setzen
- Tabelle anzeigen
- PDF erzeugen

Beispiel:

```
Ranking:

[X] Saisonrangliste


Jahr:

2026


Altersgruppe:

U18


Bewerb:

100m Freistil


[Anzeigen]

[PDF Export]
```

---

# 13.3 Athletenanalyse Oberfläche

Darstellung:

- Stammdaten
- aktuelle Leistungen
- Entwicklung
- Diagramme
- Vergleichswerte

Beispiel:

```
Athlet:

Max Mustermann


Beste WPS Leistung:

856 Punkte


Entwicklung:

2024 720
2025 785
2026 856
```

---

# 13.4 Vergleichsreport Oberfläche

Der Benutzer kann auswählen:

- Athlet
- Zeitraum
- Bewerb
- Referenz

Ausgabe:

- Tabelle
- Entwicklung
- Abstand

---

# 14. Berechtigungen

## Administrator

Darf:

- alle Ranglisten sehen
- Reports erstellen
- Einstellungen verwalten

---

## Trainer

Darf:

- eigene Athleten analysieren
- Reports erstellen
- PDF exportieren

---

## Verein

Darf:

- eigene Vereinsauswertungen sehen

---

## Öffentlicher Benutzer

Optional:

Darf:

- veröffentlichte Ranglisten sehen

---

# 15. Technische Umsetzung

## 15.1 Services

Empfohlene Services:

```
WpsRankingService

WpsAthleteAnalysisService

WpsReportService

WpsPdfExportService
```

---

# 15.2 WpsRankingService

Aufgaben:

- Ergebnisse filtern
- Ranking erstellen
- Sortierung durchführen

Beispiel:

```php
WpsRankingService::getRanking(
    filters
);
```

---

# 15.3 WpsReportService

Aufgaben:

- Daten für Reports vorbereiten
- Vergleichswerte berechnen
- Exportdaten erzeugen

---

# 15.4 Caching

Bei großen Datenmengen kann Caching eingesetzt werden.

Mögliche Cache-Daten:

- Saisonranglisten
- Vereinsranglisten
- internationale Vergleiche

Cache muss invalidiert werden bei:

- neuen Ergebnissen
- Änderungen an WPS-Punkten
- Änderungen an Filtern

# 16. Tests

## 16.1 Unit Tests

Die Ranking-Logik muss unabhängig getestet werden.

Zu testen:

- korrekte Sortierung nach WPS Punkten
- Filter funktionieren
- Altersberechnung funktioniert
- beste Leistung pro Athlet wird korrekt ermittelt
- Vereinsauswertungen liefern korrekte Ergebnisse

---

# 16.2 Feature Tests

Folgende Benutzerabläufe müssen getestet werden:

## Saisonrangliste

Test:

- Jahr auswählen
- Filter setzen
- Ranking anzeigen

Erwartung:

- korrekte Athleten
- korrekte Reihenfolge
- korrekte Punkte

---

## Jugendrangliste

Test:

- Altersgrenze U18 prüfen

Erwartung:

- nur Athleten mit Alter <= 18 Jahre
- historische Altersberechnung korrekt

---

## PDF Export

Test:

- Rangliste erzeugen
- PDF erstellen

Erwartung:

- vollständige Tabelle
- korrekte Überschrift
- SCM Hinweis vorhanden

---

# 16.3 Berechtigungstests

Prüfen:

- Administrator kann alle Funktionen verwenden
- Trainer sieht erlaubte Athleten
- Verein sieht nur eigene Daten
- öffentliche Ansicht zeigt nur freigegebene Inhalte

---

# 17. Implementierungsphasen

## Phase 0 - Analyse bestehender Module

Aufgaben:

- bestehende Results-Struktur analysieren
- Statistics Modul prüfen
- vorhandene PDF-Lösung prüfen
- Benutzerrechte analysieren

Definition of Done:

- Integrationspunkte dokumentiert
- benötigte Erweiterungen definiert

---

# Phase 1 - Grundstruktur

Aufgaben:

- Modulstruktur erstellen
- Services erstellen
- Berechtigungen vorbereiten

Ergebnis:

Grundlage für Ranglisten vorhanden.


---

# Phase 2 - Saison- und Veranstaltungsranglisten

Aufgaben:

- Ranking Service erstellen
- Filter implementieren
- Tabellenansicht erstellen

Definition of Done:

- Ranglisten können angezeigt werden

---

# Phase 3 - Jugendranglisten

Aufgaben:

- Alterslogik implementieren
- U18 Filter erstellen
- Jugendreports erstellen

Definition of Done:

- internationale Jugendvergleiche möglich

---

# Phase 4 - Athletenanalyse

Aufgaben:

- Leistungsentwicklung
- Vergleichsreports
- Traineransichten

Definition of Done:

- Entwicklung einzelner Athleten sichtbar

---

# Phase 5 - PDF und Druck

Aufgaben:

- PDF Layout erstellen
- Export implementieren
- Druckansicht erstellen

Definition of Done:

- offizielle Reports können erzeugt werden

---

# Phase 6 - Vereins- und erweiterte Rankings

Aufgaben:

- Vereinsauswertung
- zusätzliche Rankingmethoden
- internationale Vergleiche

Definition of Done:

- Vereins- und Verbandsauswertungen verfügbar

---

# 18. Definition of Done

Das Modul gilt als fertiggestellt, wenn:

## Funktional

- Ranglisten können erstellt werden
- Jugendwertung funktioniert
- Athletenentwicklung ist sichtbar
- PDF Export funktioniert
- SCM Hinweise werden angezeigt

---

## Technisch

- Services vorhanden
- Tests vorhanden
- Berechtigungen umgesetzt
- keine doppelte Berechnungslogik vorhanden

---

## Benutzer

- Trainer können Athleten analysieren
- Administratoren können Reports erstellen
- Benutzer können veröffentlichte Ranglisten nutzen

---

# 19. Erweiterungsmöglichkeiten

Zukünftige Erweiterungen:

- automatische internationale Rankingimporte
- Vergleich mit WPS Weltrangliste
- Qualifikationsanalysen
- Kaderprognosen
- automatische Talentanalyse
- KI-basierte Leistungsprognosen
