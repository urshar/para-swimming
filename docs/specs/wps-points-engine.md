# WPS Points Engine

## Modul

**Name:** WPS Points Engine  
**Modul-ID:** wps-points  
**Version:** 1.0  
**Status:** Specification

---

# 1. Übersicht

Die WPS Points Engine stellt die zentrale Berechnungslogik für World Para Swimming Punkte (WPS Points) innerhalb der
Para Swimming Plattform bereit.

Das Modul ist verantwortlich für:

- Verwaltung der WPS-Berechnungsdaten
- Berechnung von WPS-Punkten
- Versionierung historischer Berechnungsgrundlagen
- Speicherung der verwendeten Berechnungsparameter
- Integration in Ergebnisse und Veranstaltungen

Die Darstellung von Ranglisten, Statistiken und Reports erfolgt nicht innerhalb dieses Moduls, sondern im separaten
Modul:

`wps-rankings`

---

# 2. Ziel

## 2.1 Hauptziele

Die WPS Points Engine soll:

- offizielle WPS-Punkte entsprechend der aktuellen World Para Swimming Vorgaben berechnen können
- historische WPS Point Score Versionen verwalten
- vergangene Ergebnisse jederzeit reproduzierbar berechnen können
- die Berechnung für LCM (Long Course Meter) unterstützen
- eine SCM-Berechnung (Short Course Meter) über eine definierte Ableitung ermöglichen
- offizielle und geschätzte Berechnungen unterscheiden können
- in die bestehende Wettkampf- und Ergebnisstruktur integriert werden

## 2.2 Nicht-Ziele

Folgende Funktionen gehören nicht zur WPS Points Engine:

- Ranglisten
- Bestenlisten
- Jugendwertungen
- Vereinsauswertungen
- Trainerreports
- PDF-Ausgaben

Diese Funktionen werden im Modul:

`wps-rankings`

umgesetzt.

---

# 3. Architekturprinzip

Die WPS Points Engine wird nicht als isolierte Sonderlösung entwickelt.

Sie ist Bestandteil einer allgemeinen Punktearchitektur.

Ziel ist, dass verschiedene Punktesysteme über eine gemeinsame Schnittstelle verarbeitet werden können.

Beispiele:

- ÖBSV 1000 Punkte
- WPS Punkte
- World Aquatics Punkte
- zukünftige Punktesysteme

Beispiel einer gemeinsamen Berechnung:

```php
PointsEngine::calculate(
    pointSystem: 'WPS',
    result: $result
);
```

Das konkrete Berechnungsverfahren wird abhängig vom Punktesystem geladen.

---

# 4. Integration in bestehende Module

## 4.1 Results Modul

Die WPS-Punkte werden direkt beim Ergebnis gespeichert.

Ein Ergebnis muss nachvollziehen können:

- welches Punktesystem verwendet wurde
- welche WPS-Version verwendet wurde
- welche Parameter verwendet wurden
- ob die Berechnung offiziell oder geschätzt war

Erweiterung der Result-Struktur:

```text
results

+
wps_points
wps_point_version_id
wps_parameter_id
wps_calculation_type
```

Beispiel:

```text
wps_points:

856


wps_point_version_id:

2026


wps_parameter_id:

12345


wps_calculation_type:

official
```

Beispiel für SCM:

```text
wps_points:

842


wps_point_version_id:

2026


wps_parameter_id:

67890


wps_calculation_type:

estimated
```

---

## 4.2 Veranstaltungs-Modul

Bei einer Veranstaltung wird definiert, welche Punktesysteme verwendet werden.

Beispiel:

```text
Veranstaltung:

ÖBSV Landesmeisterschaft 2026


Punkteberechnung:

[x] ÖBSV 1000 Punkte

[x] WPS Punkte

[ ] World Aquatics Punkte
```

Wenn WPS aktiviert ist:

- Ergebnisse werden automatisch berechnet
- die verwendete WPS-Version wird gespeichert
- die Punkte stehen für Auswertungen zur Verfügung

Bei SCM:

```text
Warnung:

Die Berechnung der WPS-Punkte erfolgt auf Basis abgeleiteter SCM-Parameter.

Diese Werte sind nicht offiziell von World Para Swimming veröffentlicht.
```

---

## 4.3 Statistics Modul

Das Statistikmodul bleibt die zentrale Stelle für allgemeine statistische Auswertungen.

Die WPS Points Engine liefert ausschließlich berechnete Werte.

Es wird keine eigene Statistiklogik innerhalb der WPS Points Engine implementiert.

---

# 5. WPS Berechnungsgrundlage

## 5.1 Allgemein

Die World Para Swimming Punkteberechnung basiert auf einer Gompertz-Funktion.

Die Berechnung erfolgt nicht über eine einfache Basiszeitformel.

Die benötigten Parameter werden aus den veröffentlichten WPS Point Score Tabellen übernommen.

---

## 5.2 Berechnungsformel

Die Berechnung erfolgt nach:

```
q = a * e^(-e^(b-c/p))
```

Dabei:

| Parameter | Bedeutung                 |
|-----------|---------------------------|
| q         | berechnete WPS Punkte     |
| p         | erzielte Zeit in Sekunden |
| a         | WPS Parameter A           |
| b         | WPS Parameter B           |
| c         | WPS Parameter C           |

Die Parameter werden abhängig von folgenden Kriterien gespeichert:

- WPS-Version
- Bahnlänge
- Geschlecht
- Bewerb
- Sportklasse

---

## 5.3 Berechnungsprinzip

Die Anwendung darf keine WPS-Werte im Programmcode hinterlegen.

Alle Werte müssen datenbankgestützt geladen werden.

Dadurch können:

- neue WPS-Versionen importiert werden
- historische Werte erhalten bleiben
- Berechnungen reproduziert werden

# 6. Historisierung und Versionierung

## 6.1 Grundprinzip

Die WPS veröffentlicht regelmäßig neue Point Score Tabellen.

Die Anwendung muss sicherstellen, dass historische Ergebnisse weiterhin korrekt nachvollzogen werden können.

Daher gilt:

- WPS-Versionen dürfen niemals überschrieben werden
- jede veröffentlichte Version wird separat gespeichert
- Ergebnisse speichern die verwendete Version
- Änderungen an aktuellen Parametern beeinflussen keine historischen Ergebnisse

Beispiel:

```
Ergebnis:

100m Freistil

Athlet:
Max Mustermann

Zeit:
01:05.20

WPS Punkte:
742


Berechnung:

System:
WPS

Version:
2026

Bahnlänge:
LCM

Berechnung:
official
```

---

# 7. Datenmodell

## 7.1 Allgemeines Prinzip

Die WPS Points Engine verwendet ein versioniertes Datenmodell.

Alle Informationen, die für eine Berechnung benötigt werden, müssen gespeichert werden.

Eine Berechnung basiert auf:

- Punktesystem
- WPS-Version
- Parametern
- Bewerb
- Sportklasse
- Geschlecht
- Bahnlänge
- erzielter Zeit

---

# 8. Datenbankstruktur

## 8.1 Tabelle: point_systems

Diese Tabelle verwaltet alle unterstützten Punktesysteme.

Beispiele:

- ÖBSV 1000 Punkte
- WPS Punkte
- World Aquatics Punkte

Struktur:

| Feld        | Beschreibung           |
|-------------|------------------------|
| id          | Primärschlüssel        |
| name        | Name des Punktesystems |
| code        | eindeutiger Code       |
| description | Beschreibung           |
| active      | aktiv/inaktiv          |
| created_at  | Erstellung             |
| updated_at  | Änderung               |

Beispiel:

```
name:
World Para Swimming Points

code:
WPS
```

---

# 8.2 Tabelle: wps_point_versions

Diese Tabelle verwaltet veröffentlichte WPS-Versionen.

Struktur:

| Feld       | Beschreibung       |
|------------|--------------------|
| id         | Primärschlüssel    |
| year       | Jahr der Version   |
| version    | Versionsnummer     |
| source     | Herkunft           |
| official   | offizielle Version |
| status     | aktiv/archiviert   |
| created_at | Erstellung         |
| updated_at | Änderung           |

Beispiel:

```
year:

2026


source:

World Para Swimming Point Scores


official:

true
```

---

# 8.3 Tabelle: wps_point_parameters

Diese Tabelle enthält die WPS Gompertz-Parameter.

Struktur:

| Feld                 | Beschreibung           |
|----------------------|------------------------|
| id                   | Primärschlüssel        |
| wps_point_version_id | Version                |
| course               | LCM / SCM              |
| gender               | Geschlecht             |
| event_id             | Bewerb                 |
| sport_class_id       | Sportklasse            |
| parameter_a          | Gompertz Parameter A   |
| parameter_b          | Gompertz Parameter B   |
| parameter_c          | Gompertz Parameter C   |
| official             | offiziell / abgeleitet |
| source               | Herkunft               |
| notes                | Bemerkungen            |
| created_at           | Erstellung             |
| updated_at           | Änderung               |

Beispiel:

```
Version:

2026


Course:

LCM


Gender:

Male


Event:

100 Freestyle


Sport Class:

S10


A:

WPS Wert


B:

WPS Wert


C:

WPS Wert
```

---

# 9. SCM Unterstützung

## 9.1 Hintergrund

World Para Swimming veröffentlicht aktuell keine offiziellen SCM Point Score Parameter.

Da SCM-Ergebnisse jedoch für nationale Auswertungen und Jugendanalysen benötigt werden, wird eine abgeleitete Berechnung
ermöglicht.


---

# 9.2 Grundprinzip

SCM wird nicht als eigene offizielle WPS-Berechnung behandelt.

Die Anwendung muss eindeutig unterscheiden zwischen:

```
official
```

und

```
estimated
```

Beispiel:

```
Course:

SCM


Calculation Type:

estimated


Source:

Derived from LCM parameters
```

---

# 9.3 Verwaltung der SCM Ableitung

Die Ableitung wird ebenfalls versioniert gespeichert.

Zusätzliche Felder:

| Feld              | Beschreibung       |
|-------------------|--------------------|
| conversion_method | verwendete Methode |
| source            | Datenquelle        |
| confidence_level  | Qualitätsbewertung |
| approved_by       | Freigabe           |
| notes             | Beschreibung       |

---

# 10. Berechnungsversion speichern

Jede Berechnung muss nachvollziehbar bleiben.

Ein Ergebnis speichert:

- WPS-Version
- verwendete Parameter
- Berechnungstyp
- Ergebniszeit

Dadurch kann später nachvollzogen werden:

```
Wie wurden diese WPS-Punkte berechnet?
```

# 11. WPS Berechnungsservice

## 11.1 Grundprinzip

Die komplette WPS-Berechnung erfolgt über einen zentralen Service.

Die Berechnungslogik darf nicht direkt in:

- Livewire-Komponenten
- Controllern
- Models

implementiert werden.

Beispiel:

```php
WpsPointCalculator::calculate(
    Result $result,
    WpsPointVersion $version
);
```

Der Service ist verantwortlich für:

- Ermittlung der korrekten Parameter
- Validierung der Daten
- Durchführung der Gompertz-Berechnung
- Rundung der Punkte
- Speicherung der Berechnungsinformationen

---

# 11.2 Berechnungsablauf

Die Berechnung erfolgt in folgenden Schritten:

## Schritt 1

Ergebnis laden.

Benötigte Informationen:

- erzielte Zeit
- Bewerb
- Sportklasse
- Geschlecht
- Bahnlänge

---

## Schritt 2

Passende WPS-Version bestimmen.

Priorität:

1. bei Veranstaltung hinterlegte Version
2. aktuelle aktive Version
3. manuelle Auswahl durch Administrator

---

## Schritt 3

Passenden Parametersatz suchen.

Kriterien:

- WPS-Version
- Bahnlänge
- Geschlecht
- Bewerb
- Sportklasse

---

## Schritt 4

Gültigkeit prüfen.

Die Berechnung wird nur durchgeführt, wenn:

- Parameter vorhanden sind
- Zeit vorhanden ist
- Sportklasse vorhanden ist
- Bewerb vorhanden ist

---

## Schritt 5

WPS-Punkte berechnen.

Verwendung:

- Parameter A
- Parameter B
- Parameter C
- erzielte Zeit in Sekunden

---

## Schritt 6

Ergebnis speichern.

Gespeichert werden:

- WPS Punkte
- WPS Version
- Parameter-ID
- Berechnungstyp

---

# 12. SCM Berechnung

## 12.1 Ziel

Die SCM-Berechnung soll eine internationale Vergleichbarkeit ermöglichen, ohne den Status einer offiziellen WPS-Wertung
vorzugeben.


---

# 12.2 Grundprinzip der Ableitung

Die SCM-Parameter werden aus vorhandenen LCM-Daten abgeleitet.

Die Ableitung muss:

- nachvollziehbar
- versioniert
- austauschbar

sein.

Die Anwendung darf keine festen Werte im Code verwenden.


---

# 12.3 SCM Ableitungsstrategie

Die bevorzugte Methode:

Ableitung aus realen Leistungsdaten.

Grundlage:

- Athleten mit LCM- und SCM-Ergebnissen
- gleiche Sportklasse
- gleicher Bewerb
- gleiches Geschlecht

Aus diesen Daten werden Faktoren ermittelt.

Beispiel:

```
Sportklasse:
S10 Männer


Bewerb:
100m Freistil


LCM Parameter:

A
B
C


SCM Faktor:

ermittelt aus Vergleichsdaten
```

---

# 12.4 Alternative Methoden

Falls nicht genügend Vergleichsdaten vorhanden sind:

- mathematische Streckenanpassung
- nationale Referenzdaten
- veröffentlichte Verbandsdaten

Jede Methode muss dokumentiert werden.


---

# 12.5 Kennzeichnung

SCM Ergebnisse müssen immer eindeutig markiert werden.

Beispiel:

```
WPS Punkte:

845


Berechnung:

estimated


Hinweis:

SCM WPS Punkte basieren auf abgeleiteten Parametern und sind nicht offiziell von World Para Swimming anerkannt.
```

---

# 13. Import der WPS Point Scores

## 13.1 Ziel

Neue WPS-Veröffentlichungen müssen ohne Programmänderung importiert werden können.

Unterstützte Importquellen:

- CSV
- Excel
- manuelle Eingabe
- spätere API

---

# 13.2 Importablauf

1. Datei hochladen

2. Daten prüfen

3. neue Version erstellen

4. Parameter importieren

5. Validierung durchführen

6. Version aktivieren

---

# 13.3 Import Validierung

Folgende Prüfungen sind notwendig:

## Pflichtfelder

- Version
- Bahnlänge
- Geschlecht
- Bewerb
- Sportklasse
- Parameter A
- Parameter B
- Parameter C

---

## Fehlerprüfung

Ablehnen bei:

- fehlenden Parametern
- ungültigen Werten
- doppelten Kombinationen
- unbekannten Sportklassen
- unbekannten Bewerben

---

# 14. User Stories

## US-WPS-001

### WPS-Punkte bei Ergebnis berechnen

Als Wettkampfadministrator möchte ich, dass WPS-Punkte automatisch berechnet werden, damit Ergebnisse international
vergleichbar sind.

Akzeptanzkriterien:

- WPS kann pro Veranstaltung aktiviert werden
- Ergebnis wird automatisch berechnet
- verwendete Version wird gespeichert
- Parameter werden gespeichert

---

## US-WPS-002

### Historische Berechnung erhalten

Als Administrator möchte ich historische WPS-Berechnungen erhalten, damit alte Ergebnisse unverändert nachvollziehbar
bleiben.

Akzeptanzkriterien:

- alte Versionen bleiben verfügbar
- Ergebnisse verweisen auf verwendete Version
- neue Versionen überschreiben keine alten Daten

---

## US-WPS-003

### SCM Warnung anzeigen

Als Benutzer möchte ich erkennen können, wenn SCM-WPS-Punkte nicht offiziell sind.

Akzeptanzkriterien:

- Warnung beim Aktivieren von SCM
- Hinweis in Reports
- Kennzeichnung im Ergebnis

---

# 15. Berechtigungen

Folgende Rollen werden benötigt:

## Administrator

Darf:

- WPS-Versionen importieren
- Parameter ändern
- SCM-Ableitungen verwalten

## Wettkampfadministrator

Darf:

- WPS für Veranstaltungen aktivieren
- Berechnungen starten

## Benutzer

Darf:

- berechnete Punkte sehen

# 16. Benutzeroberfläche

## 16.1 WPS Verwaltung

Administratoren benötigen eine Oberfläche zur Verwaltung der WPS-Daten.

Funktionen:

- WPS-Versionen anzeigen
- neue Version importieren
- Parameter anzeigen
- Parameter suchen
- Version aktivieren/deaktivieren
- SCM-Ableitungen verwalten

---

# 16.2 Veranstaltungs-Einstellungen

Bei einer Veranstaltung wird die Punkteberechnung konfiguriert.

Einstellungen:

```
Punktesysteme:

[x] ÖBSV 1000 Punkte

[x] WPS Punkte

[ ] World Aquatics Punkte
```

Zusätzlich:

```
WPS Version:

2026
```

Bei Auswahl SCM:

```
Hinweis:

Die verwendete SCM-Berechnung basiert auf abgeleiteten Parametern.
Die Werte sind nicht offiziell von World Para Swimming anerkannt.
```

---

# 16.3 Ergebnisanzeige

Bei einem Ergebnis werden WPS-Informationen angezeigt.

Beispiel:

```
Zeit:

01:05.20


WPS Punkte:

856


Berechnung:

Official


Version:

2026
```

Bei SCM:

```
WPS Punkte:

842


Berechnung:

Estimated SCM


Hinweis:

Nicht offizielle WPS-Wertung
```

---

# 17. Hintergrundprozesse

## 17.1 Massenberechnung

Bei großen Veranstaltungen soll die WPS-Berechnung über Jobs ausgeführt werden können.

Beispiel:

```php
CalculateWpsPointsJob
```

Aufgaben:

- Ergebnisse laden
- WPS Parameter suchen
- Punkte berechnen
- Ergebnis aktualisieren

---

# 17.2 Neuberechnung

Administratoren können Berechnungen neu starten.

Möglichkeiten:

- einzelnes Ergebnis
- einzelne Veranstaltung
- komplette Saison

Bei einer Neuberechnung:

- aktuelle Version verwenden
- oder historische Version auswählen

---

# 18. Tests

## 18.1 Unit Tests

Die Berechnungslogik muss isoliert getestet werden.

Tests:

- korrekte Parameter werden geladen
- Formel liefert erwartete Werte
- Rundung funktioniert
- fehlende Parameter erzeugen Fehler

---

## 18.2 Feature Tests

Zu testen:

- WPS kann Veranstaltung hinzugefügt werden
- Ergebnisse erhalten Punkte
- Version wird gespeichert
- SCM Warnung erscheint

---

## 18.3 Historientests

Sicherstellen:

- alte Ergebnisse bleiben unverändert
- neue Versionen beeinflussen alte Ergebnisse nicht

---

# 19. Implementierungsphasen

## Phase 0 - Analyse bestehender Architektur

Aufgaben:

- vorhandene Punktesysteme analysieren
- Results-Struktur prüfen
- Veranstaltungsstruktur prüfen
- vorhandene Statistics-Module prüfen

Definition of Done:

- Integrationspunkte dokumentiert
- notwendige Erweiterungen definiert

---

# Phase 1 - Datenmodell

Aufgaben:

- Tabellen erstellen
- Models erstellen
- Beziehungen definieren

Umsetzung:

- point_systems
- wps_point_versions
- wps_point_parameters

Definition of Done:

- Migrationen erfolgreich
- Daten können gespeichert werden

---

# Phase 2 - WPS Berechnungsengine

Aufgaben:

- Gompertz-Berechnung implementieren
- Parameterauflösung implementieren
- Ergebnis-Speicherung

Definition of Done:

- bekannte Testfälle liefern korrekte Punkte

---

# Phase 3 - Import

Aufgaben:

- Importfunktion erstellen
- Validierung erstellen
- Versionsverwaltung implementieren

Definition of Done:

- neue WPS-Version kann importiert werden

---

# Phase 4 - Veranstaltungsintegration

Aufgaben:

- Aktivierung pro Veranstaltung
- automatische Berechnung
- SCM Warnungen

Definition of Done:

- Wettkampf kann WPS Punkte berechnen

---

# Phase 5 - SCM Unterstützung

Aufgaben:

- Ableitungsmethode implementieren
- Parameterverwaltung erweitern
- Kennzeichnung implementieren

Definition of Done:

- SCM Berechnung funktioniert
- Ergebnisse sind eindeutig als geschätzt markiert

---

# Phase 6 - Optimierung und Tests

Aufgaben:

- Performance prüfen
- Tests erweitern
- Dokumentation aktualisieren

Definition of Done:

- produktiver Einsatz möglich

---

# 20. Definition of Done

Das Modul gilt als fertiggestellt, wenn:

## Funktional

- WPS-Punkte werden korrekt berechnet
- historische Versionen funktionieren
- SCM ist eindeutig gekennzeichnet
- Veranstaltungen können WPS aktivieren

## Technisch

- Migrationen vorhanden
- Models vorhanden
- Services vorhanden
- Tests vorhanden
- Code dokumentiert

## Benutzer

- Administrator kann WPS-Daten verwalten
- Wettkampfadministrator kann Berechnungen aktivieren
- Benutzer können Punkte nachvollziehen

---

# 21. Erweiterungsmöglichkeiten

Mögliche zukünftige Erweiterungen:

- automatische WPS-Dateiimporte
- internationale Ranglisten
- Kaderanalysen
- Leistungsentwicklung
- Vergleich WPS / ÖBSV / World Aquatics
- automatische Talentidentifikation
