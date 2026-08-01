# WPS Points Engine

## Modul

**Name:** WPS Points Engine **Modul-ID:** wps-points **Version:** 1.1 — Implementierungsfassung **Status:** Ready for
Implementation

> **Änderungen gegenüber Version 1.0:** Diese Fassung integriert die Ergebnisse der Phase-0-Bestandsanalyse
> (`phase-0-analyse-wps-points-engine.md`). Alle in Version 1.0 offenen Architekturfragen sind entschieden und
> in den jeweiligen Abschnitten als **[E1]**– **[E5]** markiert. Die Datenbankstruktur (§8) wurde an die
> tatsächlichen Gegebenheiten des Repositories angepasst und weicht bewusst von Version 1.0 ab.
>
> **Ergänzung nach Sichtung der offiziellen Datei** (`2026_01_30__World_Para_Swimming_Points_Calculator.xlsx`):
> Das Importformat ist in §11.4 vollständig festgelegt, die Berechnungsformel gegen die Datei verifiziert.
> Zwei inhaltliche Korrekturen gegenüber Version 1.0: die Punkte werden **abgerundet** (nicht kaufmännisch
> gerundet, §5.3), und der Parameter `a` beträgt **1200** — WPS-Punkte können über 1000 liegen (§5.2).

---

# 0. Umsetzungsentscheidungen

Die folgenden Entscheidungen sind verbindlich und gelten für alle Phasen.

### [E1] Dimensionsmodell — eigenständige WPS-Merkmale, keine Fremdschlüssel auf `base_time_*`

Version 1.0 sah `event_id` und `sport_class_id` in `wps_point_parameters` vor. Im Repository existiert **keine**
neutrale Bewerbs- oder Sportklassen-Stammtabelle. Die einzigen vorhandenen Dimensionstabellen (`base_time_disciplines`,
`base_time_sport_classes`) gehören fachlich zum World-Aquatics-Modul und führen Sportklassen **ohne** `S`/`SB`/`SM`
-Differenzierung.

**Entscheidung:** Die WPS-Parameter referenzieren **keine** `base_time_*`-Tabellen. Stattdessen werden die fachlichen
Merkmale direkt im Parametersatz geführt:

- `stroke_type_id` → FK auf die bestehende, neutrale Tabelle `stroke_types`
- `distance` (Integer)
- `relay_count` (Integer, Default 1)
- `sport_class` (String, z. B. `S9`, `SB8`, `SM10`) — **identisches Format wie `results.sport_class`**

Damit ist die Engine ohne Berührung des WA-Moduls, der Cup-Wertung und der Richtzeiten lauffähig. Eine spätere
Konsolidierung auf gemeinsame Stammtabellen (`disciplines`, `sport_classes`) bleibt möglich, ist aber **nicht**
Teil dieses Moduls.

`stroke_types` ist die einzige Dimension, die wiederverwendet wird — sie ist bereits neutral und wird auch von
`swim_events` und `base_time_disciplines` referenziert.

### [E2] Berechtigungen — bestehendes Modell, kein neues Rollensystem

Version 1.0 nannte drei Rollen. Im Repository existiert **kein** Rollen-/Permission-System, sondern nur
`users.is_admin` (bool), `users.club_id` sowie `EntryPolicy` und die `RequireAdmin`-Middleware.

**Entscheidung:** Es wird **kein** Rollensystem eingeführt. Die Rollen der Spec werden abgebildet als:

| Rolle laut Spec 1.0                                                 | Umsetzung                                                                |
|---------------------------------------------------------------------|--------------------------------------------------------------------------|
| Administrator (Versionen, Parameter, SCM verwalten)                 | `RequireAdmin`-Middleware                                                |
| Wettkampfadministrator (WPS je Meet aktivieren, Berechnung starten) | `EntryPolicy::manageEntries` — exakt wie beim bestehenden WA-Recalculate |
| Benutzer (Punkte sehen)                                             | jeder authentifizierte Benutzer (`auth`-Gruppe)                          |

### [E3] Generische `PointsEngine`-Fassade — später

Version 1.0 §3 skizzierte `PointsEngine::calculate(pointSystem:, result:)`.

**Entscheidung:** Die Fassade wird **nicht** in diesem Modul umgesetzt. Ein vorzeitiger Umbau würde
`WorldAquaticsPointsService` betreffen, von dem `DailyRankingService` (Cup-Wertung) und
`QualifyingTimeCalculationService` (Richtzeiten) abhängen. Die Tabelle `point_systems` wird jedoch **jetzt schon**
angelegt, damit die Registry vorhanden ist und die spätere Fassade ohne Migration nachgerüstet werden kann (siehe §21).

### [E4] `results.points` bleibt unangetastet

`results.points` enthält heute die World-Aquatics-Punkte und wird gelesen von `DailyRankingService`,
`QualifyingTimeService`, `StatisticsService` und `LenexExportService`. Beim LENEX-Import wird `RESULT.points`
in dieses Feld übernommen, beim Recalculate durch `WorldAquaticsPointsService` überschrieben.

**Entscheidung:** WPS-Punkte werden in **eigenen Spalten** gespeichert. `results.points` wird von diesem Modul weder
gelesen noch geschrieben. Eine Umbenennung nach `wa_points` ist **nicht** Teil dieses Moduls.

### [E5] LENEX-Export — unverändert

LENEX kennt genau **ein** Punktefeld pro Ergebnis (`RESULT.points`), gefüllt nach dem am Wettkampf eingestellten
Punktesystem. Das optionale `POINTTABLE`-Objekt deklariert, welches System gemeint ist, ist aber nicht verpflichtend und
wird von vielen Meetmanagern nicht gesetzt.

**Entscheidung:** `LenexExportService` bleibt unverändert und exportiert weiterhin `results.points`
(World Aquatics). WPS-Punkte werden **nicht** nach LENEX exportiert. Begründung: Ein Export von WPS-Punkten im selben
Feld würde beim Reimport in ein anderes System als WA-Punkte fehlinterpretiert.

Optionale Erweiterung (nicht Teil der Definition of Done, siehe §21): Setzen eines `POINTTABLE`-Elements, das das
tatsächlich verwendete System benennt.

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

Die Darstellung von Ranglisten, Statistiken und Reports erfolgt im separaten Modul `wps-rankings`.

---

# 2. Ziel

## 2.1 Hauptziele

- offizielle WPS-Punkte nach den aktuellen World-Para-Swimming-Vorgaben berechnen
- historische WPS-Point-Score-Versionen verwalten
- vergangene Ergebnisse jederzeit reproduzierbar neu berechnen
- LCM-Berechnung unterstützen
- SCM-Berechnung über eine definierte, versionierte Ableitung ermöglichen
- offizielle und geschätzte Berechnungen eindeutig unterscheiden
- in die bestehende Wettkampf- und Ergebnisstruktur integrieren

## 2.2 Nicht-Ziele

Nicht Teil dieses Moduls: Ranglisten, Bestenlisten, Jugendwertungen, Vereinsauswertungen, Trainerreports, PDF-Ausgaben.
Diese liegen in `wps-rankings`.

Zusätzlich ausgeschlossen (Ergebnis Phase 0): Umbau von `WorldAquaticsPointsService`, Änderung an der Cup-Wertung,
Änderung an den Richtzeiten, Änderung an `results.points`, Einführung eines Rollensystems, Umbau des LENEX-Exports.

---

# 3. Architekturprinzip

Die Engine folgt dem im Repository etablierten Muster (siehe `docs/architecture.md`):

```
Route → Middleware (auth, RequireAdmin) → Controller/Livewire (dünn)
      → Service (app/Services) → Model (Eloquent) → DB
```

Services werden als `final readonly class` mit Constructor-Injection implementiert.

Die Berechnungsmethode **rechnet und speichert nicht in einem Aufruf**. Vorbild ist
`WorldAquaticsPointsService`, der zwischen `calculatePoints()` (reinlesend) und `recalculateForMeet()`
(persistierend) trennt. Dieses Muster ist zwingend, weil `wps-rankings` und spätere Wertungen die Punkte mit einer
bestimmten Version neu berechnen können müssen, **ohne** gespeicherte Werte zu verändern — genau wie es
`DailyRankingService` heute für die Cup-Wertung tut.

Die generische `PointsEngine`-Fassade wird gemäß **[E3]** zurückgestellt.

---

# 4. Integration in bestehende Module

## 4.1 Results

Die WPS-Punkte werden am Ergebnis gespeichert, in **eigenen Spalten** gemäß **[E4]**:

```text
results
  + wps_points               int, nullable
  + wps_point_version_id     FK → wps_point_versions, nullable
  + wps_point_parameter_id   FK → wps_point_parameters, nullable
  + wps_calculation_type     string(10), nullable: 'official' | 'estimated'
  + wps_calculated_at        timestamp, nullable
```

`wps_calculated_at` ist eine Ergänzung gegenüber Version 1.0 und dient der Erkennung veralteter Berechnungen (analog zum
bestehenden `CupStalenessService`).

Alle neuen Felder sind in `Result::$fillable` zu ergänzen. Zusätzlich zwei Relationen:
`wpsPointVersion()`, `wpsPointParameter()`.

Beispiel LCM:

```text
wps_points: 856   wps_point_version_id: 3   wps_point_parameter_id: 12345   wps_calculation_type: official
```

Beispiel SCM:

```text
wps_points: 842   wps_point_version_id: 3   wps_point_parameter_id: 67890   wps_calculation_type: estimated
```

## 4.2 Veranstaltungs-Modul

Je Veranstaltung wird festgelegt, welche Punktesysteme verwendet werden. Umsetzung über eine Pivot-Tabelle
`meet_point_system` (§8.5), **nicht** über zusätzliche Spalten auf `meets`.

```text
Veranstaltung: ÖBSV Landesmeisterschaft 2026

Punkteberechnung:
  [x] World Aquatics Punkte
  [x] WPS Punkte    → WPS-Version: 2026
```

Ist WPS aktiviert, können die Ergebnisse des Meets berechnet werden; die verwendete Version wird je Ergebnis
gespeichert.

Bei einem Meet mit `course` ≠ `LCM` erscheint beim Aktivieren:

```text
Warnung:
Die Berechnung der WPS-Punkte erfolgt auf Basis abgeleiteter SCM-Parameter.
Diese Werte sind nicht offiziell von World Para Swimming veröffentlicht.
```

**Hinweis zu `course`:** `meets.course` kennt elf Werte (`LCM`, `SCM`, `SCY`, `SCM16/20/33`, `SCY20/27/33/36`,
`OPEN`). Die WPS-Engine unterstützt ausschließlich `LCM` (official) und `SCM` (estimated). Alle anderen Kursarten führen
zum Übersprungen-Status mit Begründung, **nicht** zu einem Fehler.

## 4.3 Statistics

Das Statistikmodul bleibt die zentrale Stelle für statistische Auswertungen. Die WPS Points Engine liefert
ausschließlich berechnete Werte und implementiert **keine** eigene Statistiklogik. Am bestehenden
`StatisticsService` und seinen Teilservices wird in diesem Modul **nichts** geändert.

## 4.4 LENEX

Keine Änderung, siehe **[E5]**.

---

# 5. WPS Berechnungsgrundlage

## 5.1 Allgemein

Die WPS-Punkteberechnung basiert auf einer Gompertz-Funktion, nicht auf einer Basiszeitformel. Die Parameter werden aus
den veröffentlichten WPS-Point-Score-Tabellen übernommen.

## 5.2 Berechnungsformel

```
q = a * e^(-e^(b - c/p))
```

| Parameter | Bedeutung                     |
|-----------|-------------------------------|
| q         | berechnete WPS-Punkte         |
| p         | erzielte Zeit in **Sekunden** |
| a         | WPS Parameter A — Asymptote   |
| b         | WPS Parameter B               |
| c         | WPS Parameter C               |

Die Formel ist gegen die offizielle Datei **verifiziert** (§11.4).

**Wichtig — Einheit:** `results.swim_time` ist in **Hundertstelsekunden** gespeichert. Vor der Berechnung ist zwingend
`p = swim_time / 100` zu bilden. Ein Verwechseln der Einheit ergibt keinen Fehler, sondern still falsche Punkte — dies
ist explizit zu testen.

**Wichtig — Wertebereich:** `a` ist die Asymptote der Funktion und beträgt in der Version 2026 durchgängig **1200**,
nicht 1000. WPS-Punkte können also **über 1000 liegen**; 1000 Punkte sind kein Maximum, sondern ein Referenzniveau. Jede
Anzeige, Validierung oder Spaltenbreite, die implizit von einer Obergrenze 1000 ausgeht, ist falsch. `a` wird trotz
Konstanz je Parametersatz gespeichert, da spätere Versionen davon abweichen können.

## 5.3 Numerische Schutzmaßnahmen

Verbindlich zu implementieren:

- `p <= 0` → keine Berechnung, Begründung `keine gültige Schwimmzeit`
- der Exponent `b - c/p` wird vor dem Inneren `exp()` auf ein sicheres Intervall geklemmt, um Überlauf zu vermeiden; bei
  sehr großem Exponenten strebt `q` gegen 0, bei sehr kleinem gegen `a`
- **Ergebnis wird abgerundet, nicht kaufmännisch gerundet:** `(int) floor($q)`. Die offizielle WPS-Rechenvorschrift
  lautet ausdrücklich *"final points for certain time are rounded down"*; die Datei verwendet `FLOOR(...;1)`. `round()`
  würde bei rund der Hälfte aller Ergebnisse einen Punkt zu viel liefern. Untergrenze `>= 0`
- ist das Ergebnis nicht endlich (`is_finite()` false), gilt die Berechnung als fehlgeschlagen

## 5.4 Berechnungsprinzip

Es dürfen **keine** WPS-Werte im Programmcode hinterlegt werden. Alle Parameter werden datenbankgestützt geladen.

---

# 6. Historisierung und Versionierung

- WPS-Versionen werden **niemals** überschrieben
- jede veröffentlichte Version wird separat gespeichert
- Ergebnisse speichern die verwendete Version **und** den konkret verwendeten Parametersatz
- Änderungen an aktuellen Parametern beeinflussen keine historischen Ergebnisse

**Gelöschte Versionen:** Die Fremdschlüssel auf `results` werden mit `nullOnDelete()` angelegt. Ein Löschen einer
Version macht historische Punkte damit nicht ungültig, entfernt aber die Nachvollziehbarkeit — deshalb wird das Löschen
einer Version im UI nur zugelassen, wenn keine Ergebnisse darauf verweisen. Andernfalls ist die Version zu archivieren
(`status = archived`).

---

# 7. Datenmodell — Grundprinzip

Eine Berechnung basiert auf: Punktesystem, WPS-Version, Parametersatz, Bewerb (Stroke + Distanz + Relaycount),
Sportklasse, Geschlecht, Bahnlänge, erzielter Zeit.

## 7.1 Zuordnung Sportklasse — kritisch

`results.sport_class` stammt aus dem LENEX-Attribut `RESULT.handicap` und kann vom Athletenstammsatz abweichen. Es ist
deshalb **immer** `results.sport_class` maßgeblich, nicht `athlete_sport_classes`.

Die WPS-Tabellen sind nach `S` / `SB` / `SM` getrennt. Die im WA-Modul vorhandene Normalisierung
`normalizeSportClassCode()` (`SB9` → `S9`) darf **auf keinen Fall** übernommen werden — sie würde systematisch den
falschen Parametersatz liefern.

Erwartete Kategorie aus dem Bewerb:

| Stroke (LENEX)  | erwartete Kategorie |
|-----------------|---------------------|
| FREE, BACK, FLY | `S`                 |
| BREAST          | `SB`                |
| MEDLEY          | `SM`                |

Ablauf: aus dem Stroke die erwartete Kategorie bestimmen, `results.sport_class` parsen (Präfix + Nummer) und prüfen, ob
die Kategorie übereinstimmt. Bei Abweichung wird die Berechnung mit Begründung übersprungen — es wird **nicht**
stillschweigend umgeschrieben.

Nicht-numerische Klassen (`GER.AB`, `GER.GB`) sowie die Staffelklassen (`S14`, `S20`, `S21`, `S34`, `S49`) haben keine
WPS-Parameter und führen zum Übersprungen-Status.

## 7.2 Zuordnung Geschlecht

Analog zu `WorldAquaticsPointsService`: Bei Einzelbewerben zählt das Geschlecht des **Athleten**
(`athletes.gender`), bei Staffeln (`relay_count > 1`) das Geschlecht des **Bewerbs** (`swim_events.gender`). Grund:
manche Meets listen Einzelbewerbe organisatorisch als „Mixed“.

**Staffeln sind in Version 1.1 ausgeschlossen** — WPS veröffentlicht keine Staffel-Parameter. Ergebnisse mit
`relay_count > 1` werden mit Begründung übersprungen. Die Spalte `relay_count` ist in
`wps_point_parameters` trotzdem vorgesehen, damit spätere Staffel-Parameter ohne Migration importierbar sind.

## 7.3 Ergebnisse ohne Berechnung

Die Berechnung wird übersprungen (nicht abgebrochen) bei: `status` ∈ `DNS`, `DNF`, `DSQ`, `SICK`, `WDR`; fehlender oder
nicht positiver `swim_time`; fehlender Sportklasse; nicht unterstütztem Kurs; fehlendem Parametersatz. `EXH`
(Exhibition) **wird** berechnet, da eine gültige Zeit vorliegt — die Bewertung, ob ein EXH-Ergebnis in eine Rangliste
eingeht, liegt in `wps-rankings`.

---

# 8. Datenbankstruktur

> Diese Struktur ersetzt §8 der Version 1.0. Alle Migrationen müssen MySQL- **und** SQLite-tauglich sein
> (keine `YEAR()`/`MONTH()`; Datumsfilter über `whereDate()`). In Down-Migrationen steht `dropForeign()` vor
> `dropUnique()` (MySQL-Fehler 1553).

## 8.1 `point_systems`

| Feld        | Typ                   | Beschreibung                  |
|-------------|-----------------------|-------------------------------|
| id          | bigint                | PK                            |
| name        | string(100)           | Anzeigename                   |
| code        | string(20), unique    | z. B. `WA`, `WPS`, `OBSV1000` |
| description | text, nullable        |                               |
| active      | boolean, default true |                               |
| timestamps  |                       |                               |

Seed: `WA` (World Aquatics Punkte), `WPS` (World Para Swimming Points). `OBSV1000` optional.

Die Tabelle ist Registry gemäß **[E3]** — sie steuert in Version 1.1 nur die Meet-Zuordnung, noch keine Dispatch-Logik.

## 8.2 `wps_point_versions`

| Feld        | Typ                          | Beschreibung                         |
|-------------|------------------------------|--------------------------------------|
| id          | bigint                       | PK                                   |
| label       | string(100)                  | Anzeigename, z. B. `WPS 2026`        |
| year        | smallint unsigned            | Jahr der Veröffentlichung            |
| version     | string(20), nullable         | Versionsbezeichnung des Herausgebers |
| source      | string(255), nullable        | Herkunft                             |
| official    | boolean, default true        | offizielle WPS-Veröffentlichung      |
| status      | string(20), default `active` | `active` / `archived`                |
| valid_from  | date, nullable               |                                      |
| valid_until | date, nullable               | `null` = bis auf Weiteres            |
| timestamps  |                              |                                      |

`valid_from`/`valid_until` sind eine Ergänzung gegenüber Version 1.0 und übernehmen bewusst das im Projekt bewährte
Muster von `base_time_versions` (`validOn()`), damit die automatische Zuordnung nach Wettkampfdatum identisch
funktioniert.

Unique: `(year, version)`.

## 8.3 `wps_point_parameters`

| Feld                 | Typ                                      | Beschreibung                                           |
|----------------------|------------------------------------------|--------------------------------------------------------|
| id                   | bigint                                   | PK                                                     |
| wps_point_version_id | FK → wps_point_versions, cascadeOnDelete |                                                        |
| course               | string(3)                                | `LCM` / `SCM`                                          |
| gender               | string(1)                                | `M` / `F`                                              |
| stroke_type_id       | FK → stroke_types, restrictOnDelete      | **[E1]**                                               |
| distance             | smallint unsigned                        |                                                        |
| relay_count          | tinyint unsigned, default 1              |                                                        |
| sport_class          | string(15)                               | `S9`, `SB8`, `SM10` — Format wie `results.sport_class` |
| parameter_a          | decimal(14,6)                            |                                                        |
| parameter_b          | decimal(14,6)                            |                                                        |
| parameter_c          | decimal(14,6)                            |                                                        |
| official             | boolean, default true                    | `false` = abgeleitet (SCM)                             |
| source               | string(255), nullable                    |                                                        |
| notes                | text, nullable                           |                                                        |
| timestamps           |                                          |                                                        |

Unique (benannt, wegen MySQL-Indexnamenlänge):
`(wps_point_version_id, course, gender, stroke_type_id, distance, relay_count, sport_class)`
→ Indexname `wps_params_unique_combo`.

Zusätzlicher Suchindex in Auflösungsreihenfolge:
`(wps_point_version_id, course, gender, sport_class)` → `wps_params_lookup`.

**Präzision:** `decimal(14,6)` ist bewusst gewählt. Sollte die offizielle Quelle mehr Nachkommastellen führen, ist die
Präzision **vor** dem ersten Produktivimport anzuheben — eine nachträgliche Änderung würde alle bestehenden Berechnungen
unmerklich verschieben.

## 8.4 `wps_scm_derivations`

Dokumentiert, wie eine SCM-Parametergruppe abgeleitet wurde (Spec 1.0 §9.3).

| Feld                 | Typ                                | Beschreibung                                                        |
|----------------------|------------------------------------|---------------------------------------------------------------------|
| id                   | bigint                             | PK                                                                  |
| wps_point_version_id | FK, cascadeOnDelete                |                                                                     |
| conversion_method    | string(50)                         | z. B. `performance_ratio`, `distance_adjustment`, `federation_data` |
| source               | string(255), nullable              | Datenquelle                                                         |
| confidence_level     | string(20), nullable               | `high` / `medium` / `low`                                           |
| sample_size          | integer, nullable                  | Anzahl der Vergleichspaare                                          |
| approved_by          | FK → users, nullable, nullOnDelete |                                                                     |
| approved_at          | timestamp, nullable                |                                                                     |
| notes                | text, nullable                     |                                                                     |
| timestamps           |                                    |                                                                     |

## 8.5 `meet_point_system`

| Feld                 | Typ                                 | Beschreibung                                 |
|----------------------|-------------------------------------|----------------------------------------------|
| id                   | bigint                              | PK                                           |
| meet_id              | FK → meets, cascadeOnDelete         |                                              |
| point_system_id      | FK → point_systems, cascadeOnDelete |                                              |
| wps_point_version_id | FK, nullable, nullOnDelete          | Override der automatischen Versionszuordnung |
| timestamps           |                                     |                                              |

Unique: `(meet_id, point_system_id)`.

`meets` hat `softDeletes`. Der Cascade greift erst beim endgültigen Löschen — beim Soft-Delete bleibt die Zuordnung
bestehen, was gewollt ist.

## 8.6 Erweiterung `results`

Siehe §4.1. Eigene Migration, ausschließlich additiv.

---

# 9. SCM-Unterstützung

## 9.1 Hintergrund

World Para Swimming veröffentlicht derzeit keine offiziellen SCM-Point-Score-Parameter. Da SCM-Ergebnisse für nationale
Auswertungen und Jugendanalysen benötigt werden, wird eine abgeleitete Berechnung ermöglicht.

## 9.2 Grundprinzip

SCM ist keine offizielle WPS-Berechnung. Die Anwendung unterscheidet eindeutig zwischen `official` und
`estimated`. Der Berechnungstyp ergibt sich **ausschließlich** aus dem Feld `official` des verwendeten Parametersatzes,
nicht aus dem Kurs — damit bleibt korrekt gekennzeichnet, falls WPS später offizielle SCM-Parameter veröffentlicht.

## 9.3 Verwaltung der Ableitung

Über `wps_scm_derivations` (§8.4). Auch die Ableitung ist versioniert; feste Werte im Code sind unzulässig.

---

# 10. Berechnungsservice

## 10.1 Grundprinzip

Die gesamte Berechnung liegt in Services. Keine Berechnungslogik in Livewire-Komponenten, Controllern oder Models.

Vorgesehene Services:

| Service                      | Aufgabe                                                              |
|------------------------------|----------------------------------------------------------------------|
| `WpsPointCalculator`         | Parameterauflösung, Validierung, Gompertz, Rundung — **rein lesend** |
| `WpsPointVersionResolver`    | Versionsbestimmung nach Priorität (§10.3 Schritt 2)                  |
| `WpsPointCalculationService` | Persistenz: einzelnes Ergebnis, Meet, Saison                         |
| `WpsParameterImportService`  | Import der Point-Score-Dateien (Phase 3)                             |
| `WpsScmDerivationService`    | SCM-Ableitung (Phase 5)                                              |

Rückgabetyp der Berechnung: ein Support-Objekt `App\Support\WpsPointResult` mit
`points`, `parameter`, `version`, `calculationType`, `skipReason` — analog zum vorhandenen Muster
`App\Support\PerformanceClubRankingResult`. Damit entfällt die im WA-Service verwendete
`array{0: ?int, 1: string}`-Rückgabe.

## 10.2 Signatur

```php
$result = $calculator->calculate(Result $result, WpsPointVersion $version): WpsPointResult;
```

## 10.3 Berechnungsablauf

**Schritt 1 — Ergebnis laden.** Benötigt: `swim_time`, `swim_event` (Stroke, Distanz, Relaycount, Gender),
`sport_class`, `athlete.gender`, `meet.course`.

**Schritt 2 — Version bestimmen** (`WpsPointVersionResolver`), Priorität:

1. explizit übergebene Version (manuelle Auswahl durch Administrator)
2. am Meet hinterlegte Version (`meet_point_system.wps_point_version_id`)
3. Version, deren Gültigkeitszeitraum `meets.start_date` umfasst (`validOn()`)
4. keine → Berechnung wird übersprungen

Gegenüber Version 1.0 wurde die Reihenfolge angepasst: Die manuelle Auswahl steht **vorn**, weil sie sonst durch die
automatische Zuordnung nie erreichbar wäre — dasselbe Verhalten hat der bestehende
`WorldAquaticsPointsController` mit seinem `version_id`-Override.

**Schritt 3 — Parametersatz suchen.** Kriterien: Version, `course`, `gender`, `stroke_type_id`, `distance`,
`relay_count`, `sport_class` (§7.1, §7.2).

**Schritt 4 — Gültigkeit prüfen.** §7.3 und §5.3.

**Schritt 5 — Punkte berechnen.** §5.2 mit `p = swim_time / 100`.

**Schritt 6 — Speichern** (nur in `WpsPointCalculationService`): `wps_points`, `wps_point_version_id`,
`wps_point_parameter_id`, `wps_calculation_type`, `wps_calculated_at`.

## 10.4 Übersprungene Ergebnisse

Wie beim WA-Recalculate liefert die Massenberechnung eine Zusammenfassung:

```php
array{updated: int, skipped: int, skipped_reasons: array<string,int>, skipped_results: array<int,string>}
```

Begründungen sind in deutscher Sprache und fachlich formuliert (z. B. "kein WPS-Parametersatz für LCM/M/100FR/S9 in
Version WPS 2026"). Sie erscheinen aggregiert in der Flash-Meldung.

---

# 11. Import der WPS Point Scores

## 11.1 Ziel

Neue WPS-Veröffentlichungen müssen **ohne Programmänderung** importierbar sein.

Quellen: Excel (`phpoffice/phpspreadsheet`, bereits im Projekt), CSV, manuelle Eingabe. Eine API ist nicht vorgesehen.

## 11.2 Importablauf

Dreistufig, exakt nach dem bewährten Muster von `BaseTimeImportService`
(`showForm` → `preview` → `run`):

1. Datei hochladen
2. **Preview**: Datei parsen, validieren, Vorschau mit Trefferzahl und allen Fehlern anzeigen — **ohne** zu schreiben
3. Neue Version anlegen und Parameter in einer Transaktion importieren
4. Version aktivieren

Der Preview-Schritt ist verbindlich. Ein Import darf nie ohne vorherige Anzeige der Validierungsergebnisse laufen.

## 11.3 Validierung

**Pflichtfelder:** Bahnlänge, Geschlecht, Stroke, Distanz, Sportklasse, Parameter A, B, C.

**Ablehnung bei:** fehlenden Parametern, nicht-numerischen Parameterwerten, doppelten Merkmalskombinationen innerhalb
der Datei, unbekanntem Stroke, unbekanntem Sportklassenformat, nicht unterstütztem Kurs.

Alle Fehler werden **gesammelt** und mit Zeilennummer gemeldet; der Import bricht nicht beim ersten Fehler ab.

## 11.4 Dateiformat — festgelegt

Referenzdatei: **`2026_01_30__World_Para_Swimming_Points_Calculator.xlsx`**, Version 1 vom 30.01.2026, Titel *„World
Para Swimming Point Scores for Senior Long Course Events 2026"*.

### 11.4.1 Aufbau der Datei

Drei Arbeitsblätter:

| Blatt             | Inhalt                                                     | Für den Import                 |
|-------------------|------------------------------------------------------------|--------------------------------|
| `Calculator`      | Zeit↔Punkte-Rechner mit Formeln, dient der Verifikation    | **ignorieren**                 |
| `Parameters`      | die Parametertabelle                                       | **einzige Importquelle**       |
| `version control` | `Version`, `Date`, `Comments` (Zeile 2: `1`, `30/01/2026`) | für Versionsmetadaten auslesen |

### 11.4.2 Blatt `Parameters`

Bereits im **Langformat** — eine Zeile je Merkmalskombination. Kopfzeile in Zeile 1, Daten ab Zeile 2.

| Spalte | Kopf     | Inhalt                                 | Import                                        |
|--------|----------|----------------------------------------|-----------------------------------------------|
| A      | `Gender` | `Men` / `Women`                        | → `gender` `M` / `F`                          |
| B      | `Event`  | Klartext, z. B. `100 m Freestyle`      | → `stroke_type_id` + `distance` (§11.4.4)     |
| C      | `Class`  | `S1`…`S14`, `SB1`…`SB14`, `SM1`…`SM14` | → `sport_class`, unverändert übernehmen       |
| D      | `a`      | Parameter A                            | → `parameter_a`                               |
| E      | `b`      | Parameter B                            | → `parameter_b`                               |
| F      | `c`      | Parameter C                            | → `parameter_c`                               |
| G      | `p_ref`  | **Formelspalte**, Zeit für 1000 Punkte | **ignorieren** — abgeleitet, kein Eingabewert |

**384 Datenzeilen** (2 Geschlechter × 192 Kombinationen), keine Lücken, keine Zeilen mit `a = 0`.

Wertebereiche der Version 2026: `a` konstant `1200`; `b` zwischen ca. `3,98` und `6,53` mit **6**
Nachkommastellen; `c` zwischen ca. `39` und `1550` mit **3** Nachkommastellen. `decimal(14,6)` (§8.3) ist damit
ausreichend und verlustfrei.

### 11.4.3 Nicht enthalten

- **Nur LCM.** Die Datei enthält ausschließlich Langbahn-Parameter. Beim Import wird `course = LCM` und
  `official = true` gesetzt. SCM-Parameter existieren nicht und werden über Phase 5 abgeleitet.
- **Keine Staffeln.** Bestätigt §7.2.
- **Keine Sportklasse `SB10`** — existiert im Regelwerk nicht.
- **Keine Klassen `S15`+, `GER.AB`, `GER.GB`** — bestätigt §7.1.

### 11.4.4 Zuordnung `Event` → Bewerb

18 Bewerbe. Das Feld ist Klartext und wird über eine im Importservice hinterlegte Zuordnungstabelle aufgelöst. Der
Distanzteil wird aus dem führenden Zahlenwert gelesen, der Stilteil aus dem Rest:

| Textbestandteil     | `stroke_types.lenex_code` |
|---------------------|---------------------------|
| `Freestyle`         | `FREE`                    |
| `Backstroke`        | `BACK`                    |
| `Breaststroke`      | `BREAST`                  |
| `Butterfly`         | `FLY`                     |
| `Individual Medley` | `MEDLEY`                  |

`relay_count` ist durchgängig `1`.

Vollständige Bewerbsliste: 50/100/200/400/800/1500 m Freestyle, 50/100/200 m Backstroke, 50/100/200 m Breaststroke,
50/100/200 m Butterfly, 150/200/400 m Individual Medley.

**Nicht jede Klasse existiert in jedem Bewerb.** Die Matrix ist dünn besetzt, z. B.:

- `150 m Individual Medley` — nur `SM1`–`SM4`
- `200 m Butterfly` — nur `S8`–`S14`
- `400 m Individual Medley` — nur `SM8`–`SM14`
- `400/800/1500 m Freestyle` und `200 m Backstroke` — nur `S6`–`S14`
- `50 m Breaststroke` — `SB1`–`SB9`, `SB11`–`SB13` (kein `SB14`)

Eine fehlende Kombination ist **kein Fehler**, sondern bedeutet, dass der Bewerb für diese Klasse nicht ausgeschrieben
ist. Die Validierung darf sie nicht bemängeln; die Berechnung überspringt sie mit Begründung (§7.3).

### 11.4.5 Validierung beim Import

Zusätzlich zu §11.3:

- Kopfzeile muss exakt `Gender`, `Event`, `Class`, `a`, `b`, `c` in den Spalten A–F führen — sonst Abbruch mit Hinweis
  auf ein unerwartetes Dateiformat
- `Gender` ∈ {`Men`, `Women`}
- `Event` muss über §11.4.4 auflösbar sein; unbekannte Bezeichnung → Fehler mit Zeilennummer
- `Class` muss dem Muster `S|SB|SM` + Zahl entsprechen
- `a`, `b`, `c` numerisch und `a > 0`
- Doppelte Kombination innerhalb der Datei → Fehler
- Spalte G wird nicht gelesen; enthält sie Werte, wird das nicht bemängelt

### 11.4.6 Versionsmetadaten

Aus `version control` Zeile 2: `year = 2026` (aus dem Datum), `version = "1"`, `source =
"World Para Swimming Point Scores for Senior Long Course Events 2026"`, `official = true`,
`valid_from = 2026-01-01`. `valid_until` bleibt `null`.

Die Werte werden im Preview-Schritt vorbelegt und sind vom Administrator korrigierbar — insbesondere
`valid_from`, da das Veröffentlichungsdatum nicht zwingend dem Gültigkeitsbeginn entspricht.

### 11.4.7 Referenz-Testvektoren

Aus dem Blatt `Calculator` entnommen und gegen die Formel geprüft. Diese Fälle sind in Phase 2 als Unit-Tests zu
hinterlegen:

| Gender | Event          | Class | a    | b        | c       | Zeit (s) | exakt     | erwartete Punkte |
|--------|----------------|-------|------|----------|---------|----------|-----------|------------------|
| Men    | 50 m Freestyle | S1    | 1200 | 6.190278 | 515.385 | 65.00    | 1006.5984 | **1006**         |
| Men    | 50 m Freestyle | S2    | 1200 | 6.190278 | 433.181 | 57.00    | 939.9101  | **939**          |
| Men    | 50 m Freestyle | S3    | 1200 | 6.190278 | 333.674 | 43.13    | 969.7316  | **969**          |
| Men    | 50 m Freestyle | S4    | 1200 | 6.190278 | 268.021 | 37.08    | 842.0847  | **842**          |

Der Fall S2 (`939.91` → `939`) ist der entscheidende Nachweis für das Abrunden nach §5.3 — mit `round()`
ergäbe sich `940`.

---

# 12. Hintergrundprozesse

## 12.1 Massenberechnung

`app/Jobs/CalculateWpsPointsJob` — das **erste** Job im Projekt. `app/Jobs` existiert noch nicht und wird angelegt.
Queue ist bereits konfiguriert (`QUEUE_CONNECTION=database`, `jobs`-Tabelle vorhanden).

Aufgaben: Ergebnisse eines Meets laden, Parameter auflösen, Punkte berechnen, Ergebnisse aktualisieren.

**Betriebshinweis:** Der Queue-Worker muss produktiv laufen, sonst bleiben Berechnungen liegen. In der Entwicklung
startet `composer dev` den Worker mit.

**Schwellenwert:** Meets bis 500 Ergebnisse werden synchron berechnet (wie beim WA-Recalculate, damit die Rückmeldung
sofort erscheint), darüber wird der Job dispatcht. Der Schwellenwert liegt in `config/`.

## 12.2 Neuberechnung

Möglich für: einzelnes Ergebnis, einzelnes Meet, komplette Saison (Jahr). Wahlweise mit der automatisch ermittelten oder
einer explizit gewählten Version.

---

# 13. Berechtigungen

Gemäß **[E2]**:

| Aktion                                          | Absicherung                  |
|-------------------------------------------------|------------------------------|
| WPS-Versionen importieren, anlegen, archivieren | `RequireAdmin`               |
| Parameter anzeigen, bearbeiten                  | `RequireAdmin`               |
| SCM-Ableitungen verwalten und freigeben         | `RequireAdmin`               |
| WPS für ein Meet aktivieren                     | `EntryPolicy::manageEntries` |
| Berechnung für ein Meet starten                 | `EntryPolicy::manageEntries` |
| berechnete Punkte sehen                         | `auth`                       |

---

# 14. Benutzeroberfläche

## 14.1 WPS-Verwaltung (Admin)

Routen unter `/wps`, hinter `RequireAdmin`. Views unter `resources/views/wps/`.

Funktionen: Versionen listen, neue Version importieren, Parameter einer Version anzeigen und durchsuchen, Version
aktivieren/archivieren, SCM-Ableitungen verwalten.

Die Parametertabelle wird als Livewire-Komponente `App\Livewire\Admin\WpsParameterTable` umgesetzt, direkt nach dem
Vorbild von `App\Livewire\Admin\BaseTimeTable` (Filterung nach Kurs, Geschlecht, Sportklasse, Bewerb; serverseitige
Paginierung).

## 14.2 Veranstaltungs-Einstellungen

Im Meet-Formular ein Abschnitt „Punkteberechnung“ mit Checkboxen je `point_system` und — bei aktiviertem WPS — einer
Versionsauswahl. Bei `course` ≠ `LCM` wird der SCM-Hinweis eingeblendet.

## 14.3 Ergebnisanzeige

In `resources/views/results/show.blade.php` ein WPS-Block:

```text
Zeit: 01:05.20     WPS Punkte: 856     Berechnung: Official     Version: WPS 2026
```

Bei SCM:

```text
WPS Punkte: 842    Berechnung: Estimated SCM
Hinweis: Nicht offizielle WPS-Wertung
```

Ist kein Wert vorhanden, wird der Block ausgeblendet — kein „—“, keine Fehlermeldung.

## 14.4 Blade-/Flux-Konventionen

Verbindlich (siehe `docs/conventions.md`): `@extends('layouts.app')` + `@section('content')`, **nicht**
`<x-layouts.app>`. Flux-Komponenten immer mit `x-model`, nie `:value`. Kein `<flux:select.option>` mit
`@selected()` — natives `<option>`. Flux-Tabellenpadding über `[&_td:first-child]:ps-4`.

---

# 15. Tests

Pest mit `RefreshDatabase`, In-Memory-SQLite. Keine Factories — `Model::create()` / `Model::forceCreate()`. Helper mit
Phasensuffix (`makeAdmin_wps1()` usw.). `->group()` auf File-Ebene in `uses()`. Testgruppen: `wps-points-p1` …
`wps-points-p6`.

## 15.1 Unit-Tests (Berechnung)

- korrekter Parametersatz wird geladen (Version, Kurs, Geschlecht, Bewerb, Klasse)
- Gompertz liefert bekannte Referenzwerte
- **Einheitenumrechnung**: Hundertstel → Sekunden (§5.2)
- **Kategorie-Zuordnung**: `BREAST` verlangt `SB`, `MEDLEY` verlangt `SM`, `FREE/BACK/FLY` verlangen `S`; ein `SB8`
  -Ergebnis in einem Freistilbewerb wird übersprungen (§7.1)
- Rundung auf ganze Punkte, Untergrenze 0
- fehlende Parameter, fehlende Zeit, `swim_time = 0`, nicht unterstützter Kurs → Übersprungen mit Begründung
- Statusfälle: `DNS`/`DNF`/`DSQ`/`SICK`/`WDR` übersprungen, `EXH` berechnet
- Staffeln (`relay_count > 1`) übersprungen
- numerische Extremwerte erzeugen keinen Overflow und kein `NaN`

**Gleitkomma:** Erwartungen mit Toleranz formulieren, nicht auf exakte Gleichheit — MySQL und SQLite runden
`decimal` unterschiedlich.

## 15.2 Feature-Tests

- Punktesystem kann einem Meet zugeordnet und wieder entfernt werden
- Berechnung für ein Meet setzt Punkte, Version, Parameter-ID und Berechnungstyp
- SCM-Meet erzeugt `wps_calculation_type = estimated`
- SCM-Warnung erscheint im Meet-Formular
- Berechtigungen: Nicht-Admin erreicht `/wps` nicht; Benutzer ohne `manageEntries` kann die Berechnung nicht starten
- Import lehnt fehlerhafte Dateien ab und schreibt dabei nichts

## 15.3 Historien- und Regressionstests

- eine neue Version verändert bestehende Ergebnisse nicht
- eine Neuberechnung mit einer historischen Version reproduziert die ursprünglichen Punkte
- **Regression (verpflichtend):** `results.points` bleibt durch jede WPS-Operation unverändert; die Cup-Wertung
  (`DailyRankingService`) und die Richtzeiten liefern vor und nach einer WPS-Berechnung identische Ergebnisse

---

# 16. Implementierungsphasen

Jede Phase endet mit grüner Testsuite, `composer lint:check` ohne Befund und aufgelösten PhpStorm-Inspections. Der
Beginn jeder Phase erfordert eine ausdrückliche Freigabe.

## Phase 1 — Datenmodell

Migrationen `point_systems`, `wps_point_versions`, `wps_point_parameters`, `wps_scm_derivations`,
`meet_point_system`, Erweiterung `results`. Models `PointSystem`, `WpsPointVersion`, `WpsPointParameter`,
`WpsScmDerivation`. Relationen auf `Result` und `Meet`. Seeder für `point_systems`.

*DoD:* Migrationen laufen auf MySQL und SQLite; Daten können gespeichert und gelesen werden; bestehende Testsuite bleibt
grün.

## Phase 2 — Berechnungsengine

`WpsPointCalculator`, `WpsPointVersionResolver`, `WpsPointCalculationService`, `App\Support\WpsPointResult`.

*DoD:* Referenztestfälle liefern korrekte Punkte; alle Unit-Tests aus §15.1 grün.

## Phase 3 — Import

`WpsParameterImportService`, Controller, Preview-Ansicht, Validierung, Versionsverwaltung.

*Quelle:* `2026_01_30__World_Para_Swimming_Points_Calculator.xlsx`, Format festgelegt in §11.4. *DoD:* Eine reale
WPS-Version lässt sich vollständig importieren.

## Phase 4 — Veranstaltungsintegration

Pivot-Verwaltung im Meet-Formular, Auslösen der Berechnung, `CalculateWpsPointsJob`, SCM-Warnungen, Ergebnisanzeige.

*DoD:* Ein Wettkampf kann WPS-Punkte berechnen; Punkte sind im Ergebnis sichtbar und nachvollziehbar.

## Phase 5 — SCM-Unterstützung

`WpsScmDerivationService`, Verwaltung der Ableitungen, Kennzeichnung.

*Voraussetzung:* Prüfung auf Produktivdaten, wie viele Athleten mit LCM- **und** SCM-Ergebnis in identischer Klasse und
identischem Bewerb existieren. Reicht die Datenbasis nicht, greift die Alternativmethode. *DoD:* SCM-Berechnung
funktioniert; Ergebnisse sind eindeutig als `estimated` markiert.

## Phase 6 — Admin-UI, Optimierung, Tests

`WpsParameterTable`, Versionsverwaltung, Performance-Prüfung bei Massenberechnung, Testabdeckung, Dokumentation
(`docs/architecture.md` und `docs/data-model.md` ergänzen).

*DoD:* produktiver Einsatz möglich.

---

# 17. Definition of Done

**Funktional:** WPS-Punkte werden korrekt berechnet; historische Versionen funktionieren; SCM ist eindeutig
gekennzeichnet; Veranstaltungen können WPS aktivieren.

**Technisch:** Migrationen, Models, Services, Job und Tests vorhanden; Pint sauber; keine PhpStorm-Inspections offen;
alle Queries MySQL- und SQLite-tauglich.

**Regression:** `results.points`, Cup-Wertung, Richtzeiten, Statistik und LENEX-Export verhalten sich unverändert.

**Benutzer:** Administrator kann WPS-Daten verwalten; Wettkampfadministrator kann Berechnungen aktivieren und starten;
Benutzer können Punkte inklusive Version und Berechnungstyp nachvollziehen.

---

# 18. Risiken

| #   | Risiko                                                                           | Gegenmaßnahme                                          |
|-----|----------------------------------------------------------------------------------|--------------------------------------------------------|
| R1  | `results.points` ist WA-belegt; Überschreiben bricht Cup, Richtzeiten, Statistik | eigene Spalten **[E4]**, Regressionstests §15.3        |
| R2  | Sportklassen-Granularität: WA reduziert `SB9`→`S9`, WPS nicht                    | §7.1, eigener Testfall                                 |
| R3  | Einheiten: `swim_time` in Hundertstel, Formel erwartet Sekunden                  | §5.2, eigener Testfall                                 |
| R4  | Parameterpräzision zu gering → Punkte weichen von offiziellen Tabellen ab        | `decimal(14,6)`, Präzision vor Erstimport verifizieren |
| R5  | Gompertz-Overflow / `NaN` bei Extremwerten                                       | §5.3                                                   |
| R6  | SCM-Datenbasis reicht für die Ableitung nicht                                    | Prüfung vor Phase 5, Alternativmethode                 |
| R7  | Queue-Worker läuft produktiv nicht → Berechnungen bleiben liegen                 | Schwellenwert §12.1, Betriebshinweis                   |
| R8  | ~~Importformat unbekannt~~                                                       | **erledigt** — Format festgelegt in §11.4              |
| R9  | Abrunden statt Runden übersehen → jeder zweite Wert um 1 zu hoch                 | §5.3, Testvektor S2 in §11.4.7                         |
| R10 | Annahme „max. 1000 Punkte" in Anzeige oder Validierung                           | §5.2, `a = 1200`                                       |

---

# 19. Nicht umgesetzt / bewusst zurückgestellt

- generische `PointsEngine`-Fassade **[E3]**
- Umbenennung `results.points` → `wa_points`
- gemeinsame Stammtabellen `disciplines` / `sport_classes` **[E1]**
- Rollensystem **[E2]**
- WPS-Punkte im LENEX-Export **[E5]**
- Staffelparameter (§7.2)

---

# 20. Erweiterungsmöglichkeiten

- `POINTTABLE`-Element im LENEX-Export, das das verwendete Punktesystem deklariert
- Konsolidierung der Dimensionstabellen (`base_time_*` → neutrale Stammtabellen), anschließend die
  `PointsEngine`-Fassade
- automatische WPS-Dateiimporte
- Vergleich WPS / World Aquatics / ÖBSV am selben Ergebnis
- Kaderanalysen, Leistungsentwicklung, Talentidentifikation (überwiegend in `wps-rankings`)
