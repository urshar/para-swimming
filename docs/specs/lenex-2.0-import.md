# Spec: LENEX 2.0 (sem2004) importieren

**Status:** Plan, noch nicht umgesetzt (25.10.2026). Offener Punkt in [../open-points.md](../open-points.md).

**Anlass:** `IDM_Berlin_2018.lxf` (32. Internationale Deutsche Meisterschaften, Berlin 2018, Wettkampf 25 auf dev,
mit Nationenfilter AUT eingelesen) — und es kommen weitere LENEX-2.0-Dateien (Erik).

## Referenzen

- **Offizielle Dokumentation:** Das [swimrankings-Wiki zu Lenex](https://wiki.swimrankings.net/index.php/swimrankings:Lenex)
  verlinkt nur noch die
  [Lenex 3.0 Technical Documentation (PDF)](https://wiki.swimrankings.net/images/6/62/Lenex_3.0_Technical_Documentation.pdf)
  — eine eigene 2.0-Dokumentation ist dort nicht mehr verfügbar (Stand 25.10.2026). Version 2.0 entstand 2004 mit den
  Europäischen Ranglisten; 3.0 ist laut Wiki "fast vollständig abwärtskompatibel" und hat vor allem die **Darstellung
  der Ergebnislisten** geändert. Das passt zum Befund unten: In 2.0 steht der Platz am RESULT (ein RESULT je
  Wertungsgruppe mit `agegroupid`), in 3.0 in `AGEGROUP > RANKINGS`.
- **Beispieldateien (3.0):** swimrankings.net stellt Beispieldateien für Meldungen, Startliste, Ergebnisse und Rekorde
  bereit (Links im Wiki).
- **Zum Klären in Phase 1:** Hat Erik bzw. der ÖBSV noch eine 2.0-Dokumentation (z. B. aus einer alten
  Programmversion)? Sonst gelten die 3.0-Dokumentation und die echten Dateien als Grundlage, Abweichungen werden hier
  festgehalten.

## Entscheidung: kein eigener Importer

LENEX 2.0 und 3.0 sind im Kern gleich aufgebaut (MEET → SESSIONS → EVENTS → AGEGROUPS, CLUBS → ATHLETES → RESULTS,
RELAYS). Der bestehende `LenexParserService` hat die IDM-Datei auch ohne Fehler eingelesen; falsch sind nur einzelne
Auslegungen. Deshalb **den bestehenden Import erweitern** (wie schon für die Splash-Eigenheiten, siehe
[lenex-import-export.md](lenex-import-export.md) "Splash-Meet-Manager-Eigenheiten"), statt einen zweiten Importer zu
bauen. Ein eigener Importer würde Auflösung, Klärungsseite, Nationenfilter, Athletenauswahl, Gastvereine, Abgleich mit
vorhandenen Ergebnissen und Rekordlogik doppeln.

## Befund: Eigenheiten der Datei (sem2004 3.7.622, Titanium Media)

| Thema | LENEX 2.0 / sem2004 | Folge im heutigen Import |
|---|---|---|
| Version | `LENEX version="2.0"`, `CONSTRUCTOR name="… 'sem2004'"`, Kodierung ISO-8859-1 | wird gelesen |
| Klasse der Wertungsgruppe | kein `handicap` an AGEGROUP; Klasse nur im Namen: "Startklasse S4", "Startklasse SB4", "Startklasse SM4"; dazu "Startklasse AB", "Offene Wertung", "Jugend A–D", "Jugendwertung", "Masters I–III" | `agegroupHandicap()` erkennt nur Namen, die mit "S…" beginnen → Gruppe ohne Klasse = alle Klassen → **jeder Schwimmer erscheint in jeder Startklasse** |
| Platz | kein `RANKINGS`; stattdessen **ein RESULT je Wertungsgruppe** mit `agegroupid`, gleicher Zeit und eigenem `place`/`points` (bis zu 8 RESULTs je Start; 7.743 RESULTs für ~3.800 Starts) | alle RESULTs eines Starts landen auf demselben Ergebnis, **der zuletzt gelesene Platz gewinnt** (meist "Offene Wertung", z. B. Ernhofer 200 m Freistil: 42 statt 7) |
| Ergebnis-ID | keine `resultid` | Abgleich nur über Athlet + Bewerb (Regel 3 in `findExistingResult`) |
| Lauf/Bahn | kein `HEATS`, kein `heatid`, kein `lane`, keine Reaktionszeit | bleiben leer |
| Meldungen | kein `ENTRY` | — |
| Vereine | `name`, `shortname`, `nation`, `region`; **kein `code`**; Österreich als "NPC Austria" (Nationalteam) | Auflösung über Name; Ergebnisse unter einem Verband gehen seit `feature/result-form-fixes` an den Heimverein |
| Athleten | `license` nur bei 148 von 544; `HANDICAP free/breast/medley` + `exception` | Zuordnung meist über Name + Geburtsdatum + Geschlecht + Nation |
| Runden | `round` PRE/FIN/TIM, `preveventid` | wird gelesen |
| Staffeln | `RELAY` mit `RESULT agegroupid` (nur "Offene Wertung") und `RELAYPOSITION athleteid` | vermutlich in Ordnung, prüfen |
| Status | DNS, DSQ, WDR | wird gelesen |

Auf dev zusätzlich aufgefallen: Bei Bewerb 1 hat genau eine Gruppe ("Startklasse S4") die Klasse `4`, alle anderen
keine; im Code ist dafür kein Weg zu finden (Datei hat kein `handicap`). Vor Phase 2 klären, ob die Gruppe von Hand
bearbeitet wurde.

## Offene Fragen an Erik (vor Phase 1)

1. **Welche Dateien kommen?** Liste bzw. die Dateien selbst (Veranstaltungen, Jahre, Programm). Alle aus sem2004 oder
   auch andere LENEX-2.0-Programme? Davon hängt ab, wie eng die Erkennung sein darf.
2. **Importumfang:** wie bei Wettkampf 25 nur Österreich (Nationenfilter) bzw. ausgewählte Athleten, oder die ganze
   Datei (Gäste als `foreign_starts` für die Statistik)?
3. **Welche Wertungsgruppen übernehmen?** Vorschlag: Startklassen und "Offene Wertung" übernehmen; Altersgruppen
   ("Jugend", "Masters", "Jugendwertung") nur übernehmen, wenn sie gebraucht werden — sie sind deutsche
   Jahrgangswertungen ohne Bedeutung für den ÖBSV.
4. **Welcher Platz zählt?** Vorschlag: Platz in der Startklasse des Athleten (zur Lage des Bewerbs: S/SB/SM), sonst
   "Offene Wertung", sonst der erste in der Datei.
5. **"Startklasse AB"**: Bedeutung (z. B. Ausnahme-/Gaststarter)? Vorschlag: wie "Offene Wertung" ohne Klasse.

## Plan (phasenweise, eigener Branch von `main`)

### Phase 1 — Testdatei und Erkennung

- Aus `IDM_Berlin_2018.lxf` eine kleine, anonymisierte Testdatei ableiten (2–3 Bewerbe, Einzel und Staffel, mehrere
  RESULTs je Start, eine Startklasse SB/SM) und unter `tests/Fixtures` ablegen.
- `LenexParserService` merkt sich Version und Erzeuger (`LENEX version`, `CONSTRUCTOR name`), damit Sonderregeln gezielt
  greifen und die Import-Rückmeldung "LENEX 2.0 (sem2004)" nennen kann.
- Tests: Datei wird erkannt, Typ `results`.

### Phase 2 — Klassen aus Gruppennamen

- `agegroupHandicap()` erkennt die Klasse auch hinter einem Präfix: "Startklasse S4", "Startklasse SB4",
  "Startklasse SM4", ggf. "Klasse S4". Bereiche ("S1-S10") wie bisher. "Startklasse AB", "Offene Wertung",
  "Jugendwertung" usw. bleiben ohne Klasse.
- Wirkt auch auf Rahmenbewerb-Erkennung (`unclassifiedEvents()`) und `extractSportClasses()` — Regressionstests mit
  den bestehenden Splash-Namen ("S4", "SB2", "S1 - 10").
- Je nach Antwort auf Frage 3: Altersgruppen beim Import auslassen.

### Phase 3 — mehrere RESULTs je Start

- Vor dem Import je Athlet und Bewerb alle RESULTs sammeln (Schlüssel `athleteid` + `eventid`; bei Staffeln
  Staffel + `eventid`). Zeit, Status und Splits sind gleich, also **ein** Ergebnis anlegen.
- Platz und Punkte nach der Regel aus Frage 4 wählen (Klassengruppe über `agegroupid` → Wertungsgruppe → Klassen).
- Die Gruppen-Plätze bei "Plätze aus der Datei" (`keep_file_places`) je Wertungsgruppe nutzbar machen, falls die
  Sammelansicht mehrere Gruppen eines Ergebnisses zeigt — sonst gilt ein Platz für alle Gruppen. Entscheidung, ob
  nötig, nach Phase 2 anhand der Sammelansicht.
- Tests: ein Ergebnis je Start, richtiger Platz, erneuter Import der Datei legt nichts doppelt an (ohne `resultid`,
  ohne Lauf/Bahn).

### Phase 4 — Staffeln, Vereine, Rückmeldung

- Staffel-RESULTs der Datei durch den Import prüfen (RELAYPOSITION über `athleteid`, Gruppen nur "Offene Wertung").
- "NPC Austria" bzw. andere Nationalteams: Zuordnung zum ÖBSV (Typ Verband) über die Klärungsseite, damit die
  Heimverein-Regel aus `feature/result-form-fixes` greift.
- Import-Rückmeldung: Format und gegebenenfalls zusammengefasste Doppel-RESULTs nennen.
- Spec `lenex-import-export.md` um einen Abschnitt "LENEX 2.0 / sem2004-Eigenheiten" ergänzen.

### Danach (Erik)

- Wettkampf 25 neu einlesen (Gruppen werden über `lenex_agegroup_id` aktualisiert, Plätze überschrieben), prüfen,
  dann Rekord-Check.
- Die weiteren LENEX-2.0-Dateien einlesen; Abweichungen als eigene Phase nachziehen.
