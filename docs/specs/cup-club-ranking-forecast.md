# ÖBSV Cup Vereinswertung — Prognose (Forecast)

**Status:** Freigegeben (Erik, 07.11.2026); Phase 1 in Umsetzung
**Modul:** ÖBSV Cup Vereinswertung (Erweiterung von [obsv-cup-vereinswertung.md](obsv-cup-vereinswertung.md))

---

## 1. Ziel

Liegen für eine noch nicht geschwommene Cup-Runde die Meldungen vor (z. B. per LENEX-Meldedatei importiert,
Tabelle `entries`), soll eine **voraussichtliche Endwertung der Vereine** berechnet werden — für beide
Wertungssysteme der Vereinswertung:

- **Startwertung** (System A, Spec §3),
- **Leistungswertung** (System B, Spec §4–§6).

Die Prognose ist eine reine Punktschätzung auf Basis der Meldungen; sie wird nicht persistiert und ersetzt keine
echte Wertung.

## 2. Fachliche Grundlage

Beide Vereinswertungen sind **platzierungsunabhängig**: Die Startwertung zählt Starts, die Leistungswertung nutzt
absolute Cup-Punkte (`WorldAquaticsPointsService` gegen die Basiswert-Version des Cups). Eine Platzierung je
Wertungsgruppe muss daher nicht prognostiziert werden. Die Sportklassengruppe wirkt nur als Filter: Ein Ergebnis
fließt wie in `DailyRankingService` nur ein, wenn Geschlecht und eine aktive Sportklassengruppe auflösbar sind.

## 3. Prognose-Meet

Prognostiziert wird **jedes Cup-Meet mit Meldungen, aber ohne Ergebnisse** (automatische Erkennung). Ein Meet mit
(auch nur teilweisen) Ergebnissen gilt als echt und fließt unverändert über die bestehende Wertung ein.

## 4. Startwertung — Prognose

- Jede Meldung (`entries`) eines Prognose-Meets zählt als Start.
- Ausgeschlossen: Meldungen mit Status `EXH`, `RJC`, `SICK`, `WDR` sowie Staffeln (`swim_events.relay_count > 1`;
  Staffelmeldungen liegen ohnehin in `relay_entries`).
- Zusammenfassung wie bisher: ein Start je (Athlet, Distanz + Schwimmart, Meet).
- Meldungen ohne Zeit (NT) zählen als Start.
- Verein = meldender Verein (`entries.club_id`).
- Keine pauschale Ausfallquote.

## 5. Leistungswertung — Prognose

- Aus jeder gültigen Meldung wird ein **nicht gespeichertes `Result`** gebaut (`swim_time = entry_time`,
  `sport_class = entries.sport_class`, Athlet, Bewerb, Verein). Punkte über
  `WorldAquaticsPointsService::calculatePoints($result, $meet, $cup->baseTimeVersion)`, Gruppe über
  `GroupResolverService` — keine zweite Punkteberechnung.
- Je Athlet zählt die punktbeste Meldung des Meets; daraus entsteht eine Zeile im Format von `cup_daily_results`
  (`athlete_id`, `club_id`, `meet_id`, `points`).
- **NT-Meldungen** erhalten keine Punkte und werden in der UI als "nicht bewertbar" gezählt.
- **Meldezeit aus anderer Bahnlänge** (`entries.entry_course` ≠ `meets.course`): wird ohne Umrechnung gewertet und
  in der Aufschlüsselung gekennzeichnet.
- **Fehlende Sportklasse** an der Meldung: keine Punkte, ausgewiesen; kein Rückgriff auf die Athletenklasse zur
  Laufzeit (der Meldeimport setzt sie bereits, PR #93).
- Kaderbegrenzung und Ausland-Filter gelten unverändert.

## 6. Zusammenführung

Die Wertungsberechnung beider Services ist von der Datenbeschaffung getrennt (Phase 1). Der Forecast-Service
übergibt ihr die echten Zeilen aller übrigen Cup-Meets plus die prognostizierten Zeilen der Prognose-Meets und
liefert zusätzlich den Vergleich zur aktuellen Wertung (Rang jetzt, Rang Prognose, Differenz).

Staleness: Ein Prognose-Meet ohne Tageswertung löst keinen Veraltet-Hinweis aus; veraltete Tageswertungen der
übrigen Meets werden wie bisher gemeldet.

## 7. Zugriff

Die Prognose ist **nur für Admins** sichtbar (Erik, 07.11.2026) — sie zeigt Punkte aus Meldezeiten fremder
Vereine. Die normale Vereinswertung bleibt bei `module:cup,read`.

## 8. Nicht im Umfang

- Prognose der Athleten-Gesamtwertung (`OverallRankingService`).
- Simulation / Unsicherheitsbereiche, Ausfallquoten.

## 9. Phasen

1. Spec + Umbau ohne Verhaltensänderung: Wertungsberechnung in `StartBasedClubRankingService` und
   `PerformanceBasedClubRankingService` von der Datenbeschaffung trennen (öffentliche API unverändert).
2. `ClubRankingEntryProjectionService`: Start- und Punkte-Zeilen aus Meldungen inkl. Gründen für nicht bewertbare
   Meldungen.
3. `CupClubRankingForecastService`: Zusammenführung, Vergleich zur aktuellen Wertung, Staleness ohne Prognose-Meet.
4. UI: Prognose-Umschalter in `CupClubRanking` (nur Admin, nur wenn ein Prognose-Meet existiert), Hinweis,
   Spalten Rang jetzt/Differenz, Kennzeichnung prognostizierter Meet-Punkte; Barrierefreiheit; Feature-Tests.
5. Optional: PDF-Export der Prognose.

Test-Gruppen `cup-club-ranking-forecast-p1` … `-p4`.
