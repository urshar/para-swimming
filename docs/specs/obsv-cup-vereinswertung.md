# ÖBSV Cup Vereinswertung — Spezifikation

**Status:** Freigegeben zur Implementierung (Entscheidungen aus Phase 0 eingearbeitet)
**Modul:** ÖBSV Cup Vereinswertung
**Projekt:** Para Swimming NatDB
**Technologie:** Laravel 13, PHP 8.3+, Livewire 4, Flux UI 2, Blade, MySQL / SQLite-Tests

---

## 1. Ziel des Moduls

Das Modul ermittelt und visualisiert Vereinswertungen innerhalb des ÖBSV Cups.

Die bestehende Vereinswertung basiert auf der absoluten Anzahl der Starts. Dadurch haben vor allem große Vereine mit vielen aktiven Schwimmern realistische Chancen auf die vorderen Plätze. Vereine mit wenigen Athleten können trotz sehr guter sportlicher Leistungen kaum unter die Top 3 kommen.

Das neue Modul unterstützt daher **zwei Wertungssysteme parallel**:

1. **Historische / klassische Startwertung (System A)**
   - Rangfolge nach der Anzahl der Starts.
   - Bildet die bisherige Wertung ab und muss für vergangene Jahre reproduzierbar bleiben.
2. **Neue leistungsorientierte Vereinswertung (System B)**
   - Berücksichtigt die sportliche Leistung der Athleten.
   - Begrenzt den Vorteil sehr großer Vereine.
   - Ermöglicht auch Vereinen mit wenigen Startern eine realistische Chance auf die Top 3.
   - Die Wertungslogik ist konfigurierbar und langfristig erweiterbar.

---

## 2. Geltungsbereich

Für die Vereinswertung werden ausschließlich Wettkämpfe berücksichtigt, die einem ÖBSV Cup zugeordnet sind. Ein Meet ist Cup-relevant, wenn:

```text
meets.cup_id IS NOT NULL
```

Es fließen keine Ergebnisse aus normalen Wettkämpfen, Landesmeisterschaften, Österreichischen Meisterschaften oder anderen Veranstaltungen automatisch in die Vereinswertung ein.

Die Auswertung erfolgt immer innerhalb eines bestimmten Cups bzw. Cup-Jahres (`cups.year`), z.B. „ÖBSV Cup 2026". Die Wertungen vergangener Jahre müssen jederzeit abrufbar sein.

---

## 3. Wertungssystem A — Klassische Startwertung

### 3.1 Zweck

Die klassische Wertung bildet die bisherige Vereinswertung ab und beantwortet: **Welcher Verein hatte im ÖBSV Cup die meisten Starts?**

### 3.2 Definition eines Starts

```text
1 Athlet + 1 Einzelbewerb + 1 ÖBSV-Cup-Meet = 1 Start
```

- Ein **Einzelbewerb** ist die logische Disziplin, abgebildet über **Distanz + Schwimmart** (`swim_events.distance` + `swim_events.stroke_type_id`). Mehrere Ergebnisse desselben Athleten in derselben Disziplin und demselben Meet — insbesondere **Vorlauf und Finale** (getrennte `swim_events`) sowie verschiedene **Läufe/Heats** — zählen als **genau ein Start**. In der Praxis sind die ÖBSV-Cup-Meets überwiegend Timed Finals (`round = TIM`), sodass diese Zusammenfassung selten greift, aber korrekt bleibt.
- **Staffelstarts werden nicht berücksichtigt** (`swim_events.relay_count > 1`).
- Als Start zählen **angetretene Einzelergebnisse**: reguläre Ergebnisse (`results.status = null`) sowie **DSQ** und **DNF** (der Athlet ist angetreten). **Nicht** gewertet werden **DNS, SICK, WDR** (nicht angetreten) und **EXH** (außer Konkurrenz).
- Maßgeblich ist der **Startverein zum Zeitpunkt des Ergebnisses** (`results.club_id`, siehe §9).

> Diese Start-Definition ist mit dem ÖBSV abgestimmt und deckt sich mit der bestehenden Statistiklogik (`ParticipationStatisticsService`), erweitert um den zusätzlichen Ausschluss von **EXH** und die Zusammenfassung von Runden/Heats zu einem Start je Disziplin. Sie ist im Code an genau einer Stelle gekapselt (`StartBasedClubRankingService`), damit sie zentral anpassbar bleibt.

### 3.3 Rangfolge

1. Anzahl Starts absteigend
2. Anzahl unterschiedlicher Athleten absteigend
3. Anzahl unterschiedlicher Cup-Meets absteigend
4. Vereinsname aufsteigend

Vereine mit identischen Kriterien (Starts, Athleten, Meets) teilen sich einen Rang; der nächste Rang überspringt entsprechend (Sportwertung). Der Vereinsname ist nur ein stabiles Anzeigekriterium und beeinflusst den Rang nicht.

### 3.4 Historische Darstellung

Je Cup darstellbar:

| Rang | Verein   | Starts | Athleten |
|------|----------|--------|----------|
| 1    | Verein A | 142    | 18       |
| 2    | Verein B | 117    | 12       |
| 3    | Verein C | 98     | 9        |

Die historische Startwertung muss auch für bereits vergangene Cup-Jahre abrufbar sein.

---

## 4. Wertungssystem B — Neue leistungsorientierte Vereinswertung

### 4.1 Ziel

Die neue Wertung belohnt nicht ausschließlich die Größe eines Vereins. Ein Verein mit wenigen Athleten soll eine realistische Chance auf einen Spitzenplatz haben, wenn diese Athleten überdurchschnittliche Leistungen erbringen. Gleichzeitig soll ein großer Verein nicht vollständig benachteiligt werden. Daher wird ein **gewichtetes Top-Athleten-Modell** verwendet.

### 4.2 Grundprinzip

Die Berechnung erfolgt in drei Ebenen:

```text
Ergebnis → Athletenwertung → Vereinswertung
```

- **Ebene 1 — Ergebnis:** Ein Athlet erzielt bei einem ÖBSV-Cup-Meet ein Ergebnis.
- **Ebene 2 — Athletenwertung:** Für jeden Athleten werden seine besten Cup-Leistungen ermittelt.
- **Ebene 3 — Vereinswertung:** Für jeden Verein werden die besten Athleten gewichtet berücksichtigt. Die Anzahl einfließender Athleten ist begrenzt und gewichtet — dadurch entsteht ein kontrollierter, aber kein unbegrenzter Vorteil für größere Vereine.

---

## 5. Berechnungsmodell

### 5.1 Schritt 1 — Punkte pro Ergebnis

Für jedes gültige Einzelresultat werden die **bereits vorhandenen ÖBSV-Cup-Punkte** verwendet.

**Datenquelle (Entscheidung Phase 0):** Die Punkte werden aus dem bestehenden Snapshot `cup_daily_results` gelesen. Dieser enthält je Athlet und Cup-Meet bereits das **punktbeste gültige Einzelergebnis**, dessen Punkte über den `WorldAquaticsPointsService` gegen die im Cup konfigurierte Basiswert-Version berechnet wurden, inklusive `club_id` (Startverein), Sportklassengruppe und Geschlecht.

Die Vereinswertung implementiert **keine** eigene, abweichende Punkteberechnung parallel zum bestehenden Cup-System (Spec §19). Sie baut ausschließlich auf der bestehenden Cup-Punktelogik auf.

> **Snapshot-Abhängigkeit:** `cup_daily_results` wird pro Cup-Meet manuell über die Tageswertung berechnet. Fehlt für ein Cup-Meet die Tageswertung, fehlt es in der Leistungswertung. Die UI muss diesen Zustand erkennbar machen (siehe §12 / §13).

> **EXH-Ausschluss:** EXH-Ergebnisse (außer Konkurrenz) zählen in **keiner** Punktewertung — Tageswertung, Gesamtwertung und Vereinswertung. Der Ausschluss erfolgt an der Quelle: `DailyRankingService` überspringt EXH bei der Auswahl des Tagesbesten, sodass `cup_daily_results` je Athlet/Meet bereits das beste **Nicht-EXH**-Ergebnis enthält. Hatte ein Athlet neben einem EXH-Schwimmen ein reguläres Ergebnis, wird dieses gewertet; hatte er nur EXH, erhält er in diesem Meet keine Tageswertungszeile. Die Vereinswertung liest zusätzlich nur `results.status = null` aus dem Snapshot (Absicherung gegen veraltete Snapshots). Für Richtzeiten und Rekorde bleibt EXH gültig (`Result::isValid()` unverändert).

### 5.2 Schritt 2 — Beste Leistung pro Athlet und Meet

Bereits durch `cup_daily_results` gegeben: je Athlet und Cup-Meet zählt nur die beste Leistung. Damit erhält ein Athlet mit sehr vielen Starts keinen unverhältnismäßigen Vorteil.

Beispiel: Athlet A bei Meet 1 mit 50 m Freistil (720), 100 m Freistil (810), 100 m Rücken (760) → gewertet werden **810 Punkte**.

### 5.3 Schritt 3 — Saisonwertung pro Athlet (je Verein)

Für jeden Athleten werden die besten Cup-Meet-Leistungen der Saison aufsummiert. Empfohlene Standardkonfiguration:

```text
counted_meets_per_athlete = 3
```

Beispiel: Meets mit 810 / 790 / 760 / 720 → Athletenwert = 810 + 790 + 760 = **2.360** (das vierte Meet fällt weg).

**Vereinswechsel (Entscheidung Phase 0, §9):** Der Saisonwert wird **je Verein getrennt** gebildet. Ein Athlet, der innerhalb der Saison den Verein wechselt, trägt bei jedem seiner Vereine nur mit den dort (laut `results.club_id`) erzielten Cup-Meets bei. Die „besten N Meets" werden also je (Athlet, Verein) ermittelt, nicht global je Athlet.

Die Anzahl der zu berücksichtigenden Meets ist konfigurierbar (`counted_meets_per_athlete`, siehe §8).

---

## 6. Schritt 4 — Vereinswertung mit gewichteten Top-Athleten

Für jeden Verein werden die (je Verein ermittelten) Athleten-Saisonwerte absteigend sortiert. Es werden maximal die besten `max_counted_athletes_per_club` Athleten gewichtet berücksichtigt.

Standardgewichtung (Top 5):

| Position | Gewicht |
|----------|---------|
| 1        | 100 %   |
| 2        | 80 %    |
| 3        | 60 %    |
| 4        | 40 %    |
| 5        | 20 %    |

```text
Vereinswert = A1×1,00 + A2×0,80 + A3×0,60 + A4×0,40 + A5×0,20
```

Beispiel (2.700 / 2.400 / 2.100 / 1.900 / 1.600):

```text
2.700×1,00 = 2.700
2.400×0,80 = 1.920
2.100×0,60 = 1.260
1.900×0,40 =   760
1.600×0,20 =   320
Gesamt     = 6.960
```

Ein Verein mit nur zwei Athleten kann ebenfalls in die Wertung gelangen (2.700×1,00 + 2.400×0,80 = 4.620). Zusätzliche Athleten außerhalb der Top N bringen keinen weiteren Vorteil.

---

## 7. Warum dieses Modell

- **Kleine Vereine:** Wenige, aber sehr gute Athleten ergeben eine hohe Wertung.
- **Mittlere Vereine:** Mehrere gute Athleten erhöhen die Chance auf Spitzenplätze.
- **Große Vereine:** Viele Athleten bleiben ein Vorteil, aber ein begrenzter — allein durch Masse lässt sich die Wertung nicht dominieren.

---

## 8. Konfigurierbarkeit

Die Wertung ist nicht hardcodiert. Konfigurierbar sind:

```text
include_foreign_clubs           (Standard false — gilt für beide Wertungssysteme)
counted_meets_per_athlete       (Standard 3 — nur Leistungswertung)
max_counted_athletes_per_club   (Standard 5 — nur Leistungswertung)
athlete_weights                 (Standard 1: 1.00 / 2: 0.80 / 3: 0.60 / 4: 0.40 / 5: 0.20)
restricted_kader_type_codes     (Standard WELTKLASSE, INTERNATIONALE_KLASSE, SICHTUNGSPOOL — nur Leistungswertung)
counted_kader_athletes_per_club (Standard 0 — nur Leistungswertung; in der Ansicht überschreibbar)
```

**Kaderathleten (`restricted_kader_type_codes` + `counted_kader_athletes_per_club`):** Athleten, die während des Cup-Jahres in einer eingeschränkten Kaderart aktiv waren (Weltklasse, Internationale Klasse, Sichtungspool), sollen die Leistungswertung nicht dominieren. Statt sie pauschal auszuschließen, zählt je Verein höchstens `counted_kader_athletes_per_club` Kaderathleten mit (die besten nach Saisonwert); ist das Limit erreicht, rücken Nicht-Kaderathleten nach. Standard 0 = kein Kaderathlet zählt. Maßgeblich für die Kadereigenschaft ist die Überschneidung der Kaderzugehörigkeit (`athlete_kader_memberships.valid_from`/`valid_until`) mit dem Kalenderjahr des Cups — der Bezug auf das Cup-Jahr (statt auf den heutigen Stichtag) hält historische Cup-Jahre reproduzierbar. Die betroffenen Kaderarten werden über den (administrierbaren) `kader_types.code` gewählt; ein leeres Array bedeutet keine Kaderbegrenzung. Die Anzahl lässt sich in der Vereinswertungs-Ansicht je Aufruf überschreiben (0 … `max_counted_athletes_per_club`). Die klassische Startwertung ist **nicht** betroffen.

**Ausländische Vereine (`include_foreign_clubs`):** Standardmäßig werden nur österreichische Vereine (`club.nation.code = 'AUT'`) gewertet. Über die Konfiguration lassen sich ausländische Vereine (Gaststarts) zuschalten. Der Wert gilt für **beide** Wertungssysteme. `StartBasedClubRankingService::getRanking()` akzeptiert zusätzlich ein optionales Argument `$includeForeignClubs`, das den Konfigurationswert je Aufruf überschreiben kann (Standard: Konfigurationswert).

**Ablageort (Entscheidung Phase 0):** Die Konfiguration liegt in einer **Config-Datei** (`config/cup_club_ranking.php`) mit den obigen Standardwerten. `counted_meets_per_athlete` ist bewusst **unabhängig** von `cups.best_of_count` (die Gesamtwertung der Athleten ist eine fachlich andere Wertung als die Vereinswertung).

Die Datenstruktur ist so zu halten, dass spätere abweichende Konfigurationen (z.B. Top 3 mit 100/75/50 % oder Top 8 mit 100…30 %) sowie ein späterer Snapshot mit `configuration_snapshot` (§12.2) möglich bleiben.

---

## 9. Vereinszugehörigkeit

Maßgeblich ist der Verein, für den der Athlet **beim jeweiligen Ergebnis** gestartet ist:

```text
results.club_id
```

`results.club_id` ist NOT NULL und wird je Ergebnis gesetzt — historische Vereinswechsel sind damit korrekt abgebildet, ohne `athlete.club_id` (nur aktueller Verein) heranzuziehen. `entries.club_id` und `athlete.club_id` sind nicht maßgeblich.

Für die **aggregierte Saisonleistung** eines Athleten in System B gilt die per-Meet/per-Verein-Zuordnung aus §5.3: Jeder Beitrag bleibt bei dem Verein, für den das jeweilige Cup-Meet geschwommen wurde.

---

## 10. Datenquelle und Integration mit dem Statistik-/Cup-Modul

Wiederverwendet werden:

- `cup_daily_results` (Snapshot) als Datenbasis für System B (§5.1).
- `WorldAquaticsPointsService` als einzige Punktequelle (nicht duplizieren).
- `GroupResolverService` (Staffel-/Gruppen-/Altersauflösung), wo für Detailansichten nötig.
- `results.club_id` als Startverein, `Meet::where('cup_id', …)` als Cup-Kontext.
- `CupStalenessService` für den Staleness-Hinweis, wenn ein Snapshot veraltet ist.

Die **fachliche Wertungslogik** bleibt im Vereinswertungsmodul. Empfohlene Struktur:

```text
CupClubRankingService  (Fassade)
    ├── StartBasedClubRankingService        → getRanking(Cup): Collection<StartClubRankingResult>
    └── PerformanceBasedClubRankingService  → getRanking(Cup, Config): Collection<PerformanceClubRankingResult>
```

Die Fassade `CupClubRankingService` bietet `calculateStartRanking(Cup)` und `calculatePerformanceRanking(Cup, ?Config)` und enthält selbst keine Punktelogik.

---

## 11. Historische Wertungen

Für jedes Cup-Jahr kann zwischen den Wertungssystemen gewechselt werden. Die alte Wertung wird durch die neue nicht überschrieben. Beispiel:

```text
ÖBSV Cup 2026 — Startwertung:      1. Verein A (142)  2. Verein B (117)  3. Verein C (98)
ÖBSV Cup 2026 — Leistungswertung:  1. Verein B (6.960) 2. Verein A (6.540) 3. Verein D (5.980)
```

---

## 12. Persistenz der Ergebnisse

### 12.1 Grundsatz

Die Vereinswertung wird **dynamisch** aus den Bestandsdaten berechnet und nicht als eigenes Ranking persistiert:

- **System A** rechnet vollständig live aus `results`.
- **System B** rechnet live über den bestehenden `cup_daily_results`-Snapshot (§5.1). Es entsteht kein zweiter, konkurrierender Snapshot der Vereinswertung.

Vorteile: Änderungen an Resultaten und Cup-Zuordnungen wirken automatisch; keine veralteten Vereinsrankings; historische Auswertungen entstehen aus den historischen Daten.

### 12.2 Snapshot-Option (später)

Falls eine offizielle Wertung nach Cup-Abschluss unveränderlich archiviert werden muss, kann später ein Snapshot-System ergänzt werden (`cup_id`, `ranking_type`, `configuration_snapshot`, `calculated_at` samt Ranglisten). Nicht Teil der ersten Ausbaustufe; die Architektur darf ein späteres Einfrieren jedoch nicht verhindern.

---

## 13. Benutzeroberfläche

### 13.1 Hauptseite

Erreichbar über das Cup-Modul (Navigationsgruppe „Cup Wertung"):

```text
ÖBSV Cup
    ├── Tageswertung
    ├── Gesamtwertung
    └── Vereinswertung   ← neu
```

Umsetzung: neuer `CupClubRankingController` (`show(Cup)`, `pdf(Cup)`), Views unter `resources/views/cups/`, Einstieg über die bestehende öffentliche Cup-Übersicht sowie einen neuen Navigationseintrag „Vereinswertung".

### 13.2 Filter

```text
Cup / Jahr
Wertungssystem: [Startwertung] [Leistungswertung]   (Standard: Leistungswertung)
Ausländische Vereine: [ausgeschlossen] [einbezogen]  (überschreibt den config-Standard)
Kaderathleten je Verein: [0 … max]                   (nur Leistungswertung; überschreibt den config-Standard)
```

### 13.3 Startwertung — Anzeige

> **Vereinsname:** In beiden Wertungssystemen (Anzeige und PDF) wird der Kurzname des Vereins (`clubs.short_name`) angezeigt, sofern vorhanden, sonst der volle Name (`Club::display_name`).

```text
Rang | Verein | Starts | Anzahl Athleten | Anzahl Cup-Meets
```

### 13.4 Leistungswertung — Anzeige

```text
Rang | Verein | Gesamtpunkte | gewertete Athleten | gewertete Meets
```

Optional aufklappbar je Athlet: die gewerteten Meet-Punkte, deren Summe, das Gewicht der Position und der resultierende Wertungsbeitrag — damit die Wertung transparent nachvollziehbar bleibt.

**Staleness-Hinweis:** Beruht die Leistungswertung auf einem veralteten `cup_daily_results`-Snapshot (Ergebnis- oder Klassifizierungsänderungen seit der letzten Tageswertung), zeigt die Seite einen Hinweis über `CupStalenessService` an und verweist auf die Neuberechnung der Tageswertung.

---

## 14. Ranggleichheit (Tie-Breaker)

**Startwertung:** 1. mehr unterschiedliche Athleten · 2. mehr unterschiedliche Cup-Meets · 3. Vereinsname alphabetisch.

**Leistungswertung:** 1. höhere Summe der ungewichteten Athletenleistungen · 2. höhere beste Einzelleistung · 3. mehr unterschiedliche gewertete Athleten · 4. mehr gewertete Cup-Meets · 5. Vereinsname alphabetisch.

---

## 15. Ausschlussregeln

Nicht berücksichtigt werden:

- Nicht-Cup-Meets (`meets.cup_id IS NULL`),
- Staffelstarts (`swim_events.relay_count > 1`),
- nicht angetretene Ergebnisse: **DNS, SICK, WDR**,
- **EXH** (außer Konkurrenz),
- **ausländische Vereine** (`club.nation.code != 'AUT'`) — standardmäßig; über `include_foreign_clubs` (§8) zuschaltbar,
- **Kaderathleten** (nur Leistungswertung) — Athleten, die während des Cup-Jahres in einer eingeschränkten Kaderart (`restricted_kader_type_codes`, §8) aktiv waren, zählen je Verein nur begrenzt (`counted_kader_athletes_per_club`),
- Ergebnisse ohne gültige Vereinszuordnung (strukturell ausgeschlossen, da `results.club_id` NOT NULL ist).

**DSQ und DNF** werden in der **Startwertung** als Start gewertet (der Athlet ist angetreten). In der **Leistungswertung** erhalten sie über den `WorldAquaticsPointsService` ohnehin keine Punkte und wirken daher nicht mit.

> Hinweis: Das Ergebnisstatus-Enum kennt `DNS` (nicht „NS"). Frühere Spec-Fassungen nannten „NS" — gemeint war stets `DNS`.

---

## 16. Erforderliche Analyse vor Implementierung

Die Repositoryanalyse (Phase 0) ist abgeschlossen. Bestätigt wurde u.a.:

- Cup-Kontext eindeutig über `meets.cup_id` / `cups.year`.
- Startverein historisiert über `results.club_id` (NOT NULL).
- Cup-Punkte vorhanden in `cup_daily_results` (bestes Ergebnis je Athlet/Meet, Punkte gegen Cup-Basiswert-Version).
- Startzählung vorhanden (`ParticipationStatisticsService`), inkl. Staffel-Ausschluss über `swim_events.relay_count`.
- Vor-/Endlauf werden als getrennte `swim_events` (`round`, `prev_event_id`) geführt; Cup-Meets sind überwiegend `round = TIM`.

Getroffene Entscheidungen (Phase 0):

1. **Start-Definition:** regulär + DSQ + DNF zählen; DNS/SICK/WDR/EXH nicht.
2. **Bewerbs-Granularität:** ein Start je (Athlet, Disziplin = Distanz+Schwimmart, Meet); Runden/Heats zusammengefasst.
3. **Datenbasis System B:** `cup_daily_results`-Snapshot lesen + Staleness-Hinweis in der UI.
4. **Vereinswechsel:** Athleten-Saisonwert je Verein getrennt (per-Meet/per-Verein).
5. **Konfiguration:** `config/cup_club_ranking.php`; `counted_meets_per_athlete` unabhängig von `cups.best_of_count`.
6. **Ausländische Vereine:** standardmäßig ausgeschlossen, per `include_foreign_clubs` zuschaltbar (beide Wertungssysteme).
7. **Kaderbegrenzung (nur Leistungswertung):** Kaderathleten (Weltklasse, Internationale Klasse, Sichtungspool; `restricted_kader_type_codes`) werden nicht pauschal ausgeschlossen, sondern zählen je Verein höchstens `counted_kader_athletes_per_club` (Standard 0, in der Ansicht überschreibbar). Kadereigenschaft = Überschneidung der Kaderzugehörigkeit mit dem Kalenderjahr des Cups (reproduzierbar).

---

## 17. Implementierungsphasen

### Phase 0 — Repositoryanalyse
Abgeschlossen (Analysebericht liegt vor).

### Phase 1 — Klassische Startwertung
- `App\Services\StartBasedClubRankingService::getRanking(Cup, ?bool $includeForeignClubs): Collection<StartClubRankingResult>`
- `App\Support\StartClubRankingResult` (VO: rank, clubId, clubName, starts, athletes, meets)
- `config/cup_club_ranking.php` mit `include_foreign_clubs` (weitere Schlüssel für Phase 2 bereits vorhanden)
- Unit-Tests (§18).

### Phase 2 — Neue Leistungswertung
- `App\Services\PerformanceBasedClubRankingService::getRanking(Cup, ?ClubRankingConfiguration): Collection<PerformanceClubRankingResult>`
- `App\Support\PerformanceClubRankingResult` (VO inkl. Athleten-Breakdown), `App\Support\CountedAthleteBreakdown` (VO je gewertetem Athleten: Position, Meet-Punkte, Saisonwert, Gewicht, Wertungsbeitrag), `App\Support\ClubRankingConfiguration` (VO + `fromConfig()`), `config/cup_club_ranking.php`
- `App\Services\CupClubRankingService` (Fassade: `calculateStartRanking`, `calculatePerformanceRanking`)
- Berechnungskette: `cup_daily_results` (nur reguläre Ergebnisse, EXH ausgeschlossen §5.1) → beste N Meets je (Athlet, Verein) → Athleten-Saisonwert → je Verein Top-N gewichten → Vereinsgesamtwert → Reihung + Tie-Break (§14)
- Unit-Tests (§18).

### Phase 3 — UI
- `CupClubRankingController` (`index`, `show`) mit Routen `cups.club-ranking.index` (`/vereinswertung`) und `cups.club-ranking.show` (`/cups/{cup}/club-ranking?system=&foreign=`)
- Navigationseintrag „Vereinswertung" in der Gruppe „Cup Wertung"
- Views `cups/club-ranking-index` und `cups/club-ranking` (Cup/Jahr-Auswahl, Umschalter Start/Leistung, Ausland-Umschalter, Tabellen §13.3/§13.4, aufklappbare Athleten-Details, Staleness-Hinweis)
- `CupStalenessService::clubRankingStatus(Cup)` (Aktualität der Tageswertungen über alle Cup-Meets)
- `ClubRankingConfiguration::withIncludeForeignClubs()` (UI-Übersteuerung des Ausland-Schalters)
- Feature-Tests (`cup-club-ranking-p3`) und Unit-Tests für `clubRankingStatus`
- PDF-Export bleibt Phase 4.

### Phase 4 — PDF / Export
- Route `cups.club-ranking.pdf` (`/cups/{cup}/club-ranking/pdf?system=&foreign=&kader=&detail=0|1`) und `CupClubRankingController::pdf()`; `show()` und `pdf()` teilen sich einen privaten Helfer (`resolveRankingData`), damit Ansicht und PDF dieselbe Wertung/Filter zeigen.
- PDF-View `pdf/cup-club-ranking.blade.php` (dompdf, Portrait) für beide Wertungssysteme; bei der Leistungswertung optional die gewerteten Athleten je Verein (`detail=1`, Kaderathleten gekennzeichnet). Kopf mit aktiven Filtern, Tageswertungs-Stand und Staleness-Hinweis.
- PDF-Buttons in der Ansicht (Startwertung: „PDF"; Leistungswertung: „PDF Übersicht" und „PDF mit Athleten"), die die aktiven Filter mitnehmen.
- Feature-Tests (`cup-club-ranking-p3`): PDF beider Systeme (200, `application/pdf`), Detail-Variante, Anmeldepflicht.

---

## 18. Tests

### Startwertung
- nur Cup-Meets werden berücksichtigt; Nicht-Cup-Meets werden ignoriert
- Staffeln werden ignoriert
- reguläre sowie **DSQ- und DNF**-Ergebnisse zählen als Start; **DNS/SICK/WDR/EXH** werden ignoriert
- Vor-/Endlauf und Heats desselben Bewerbs ergeben genau einen Start
- Starts, Athleten und Meets werden korrekt gezählt
- historische Cup-Jahre funktionieren
- der Startverein des Ergebnisses (`results.club_id`) ist maßgeblich, nicht der aktuelle Verein des Athleten
- ausländische Vereine werden standardmäßig ausgeschlossen und über Konfiguration/Argument zuschaltbar
- Gleichstände werden korrekt aufgelöst (Rangvergabe)

### Leistungswertung
- bestes Ergebnis pro Athlet und Meet
- nur die konfigurierten besten Meets je Athlet werden berücksichtigt
- nur die konfigurierten Top-Athleten je Verein werden berücksichtigt
- Gewichtung wird korrekt angewendet
- kleiner Verein mit wenigen, starken Athleten kann vorne stehen
- großer Verein erhält keinen unbegrenzten Vorteil durch zusätzliche Athleten
- Vereinswechsel werden je Verein korrekt berücksichtigt
- Nicht-Cup-Meets, Staffeln und Ergebnisse ohne Cup-Punkte werden ignoriert
- Gleichstände werden korrekt aufgelöst

---

## 19. Akzeptanzkriterien

Das Modul ist fachlich fertig, wenn:

- nur ÖBSV-Cup-Meets berücksichtigt werden,
- die historische Startwertung korrekt und für vergangene Jahre reproduzierbar angezeigt wird,
- eine leistungsorientierte Vereinswertung vorhanden ist, bei der kleine Vereine realistisch unter die Top 3 kommen können und große Vereine nicht allein durch Athletenzahl dominieren,
- die Wertung transparent nachvollziehbar ist,
- Anzahl gewerteter Meets, Anzahl gewerteter Athleten und Gewichtung konfigurierbar sind,
- Vereinswechsel korrekt berücksichtigt werden,
- die Berechnung durch automatisierte Tests abgesichert ist,
- die bestehende Cup- und Statistiklogik nicht dupliziert oder widersprüchlich implementiert wird,
- die bestehende Projektarchitektur eingehalten wird.

---

## 20. Punktequelle — festgelegt

Die Leistungswertung basiert auf den **bestehenden Cup-Punkten** aus `cup_daily_results` (über `WorldAquaticsPointsService` gegen die Cup-Basiswert-Version berechnet). Es werden keine mehreren, widersprüchlichen Punktesysteme parallel verwendet. Eine separate ÖBSV-1000-Punkte-Basis ist nicht erforderlich, da die vorhandenen Cup-Punkte genau diese standardisierte Leistung bereits abbilden.

---

## Zusammenfassung der Standardwertung (System B)

```text
Nur ÖBSV-Cup-Meets
  → bestes Ergebnis je Athlet und Meet   (cup_daily_results)
  → beste 3 Meets je Athlet und Verein
  → Athleten-Saisonwert
  → beste 5 Athleten je Verein
  → Gewichtung 100/80/60/40/20 %
  → Vereinsranking
```

Parallel bleibt die historische **Startwertung** (System A) erhalten.
