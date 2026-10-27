# Spec: LENEX 2.0 (sem2004) importieren

**Status:** Erledigt. Phasen 1–4 umgesetzt und abgenommen (`feature/lenex-2-import`, PR #70, 25.10.2026); die IDM
ist auf dev als Wettkampf 26 neu eingelesen (22 Ergebnisse, keine doppelten), Rekord-Check ohne Befund (Erik). Weitere
LENEX-2.0-Dateien mit neuen Abweichungen werden als eigene Phase nachgezogen.

**Anlass:** `IDM_Berlin_2018.lxf` (32. Internationale Deutsche Meisterschaften, Berlin 2018, Wettkampf 25 auf dev,
mit Nationenfilter AUT eingelesen) — und es kommen weitere LENEX-2.0-Dateien (Erik).

## Referenzen

- **Offizielle Dokumentation:**
  Das [swimrankings-Wiki zu Lenex](https://wiki.swimrankings.net/index.php/swimrankings:Lenex)
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

| Thema                     | LENEX 2.0 / sem2004                                                                                                                                                                               | Folge im heutigen Import                                                                                                                                            |
|---------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Version                   | `LENEX version="2.0"`, `CONSTRUCTOR name="… 'sem2004'"`, Kodierung ISO-8859-1                                                                                                                     | wird gelesen                                                                                                                                                        |
| Klasse der Wertungsgruppe | kein `handicap` an AGEGROUP; Klasse nur im Namen: "Startklasse S4", "Startklasse SB4", "Startklasse SM4"; dazu "Startklasse AB", "Offene Wertung", "Jugend A–D", "Jugendwertung", "Masters I–III" | `agegroupHandicap()` erkennt nur Namen, die mit "S…" beginnen → Gruppe ohne Klasse = alle Klassen → **jeder Schwimmer erscheint in jeder Startklasse**              |
| Platz                     | kein `RANKINGS`; stattdessen **ein RESULT je Wertungsgruppe** mit `agegroupid`, gleicher Zeit und eigenem `place`/`points` (bis zu 8 RESULTs je Start; 7.743 RESULTs für ~3.800 Starts)           | alle RESULTs eines Starts landen auf demselben Ergebnis, **der zuletzt gelesene Platz gewinnt** (meist "Offene Wertung", z. B. Ernhofer 200 m Freistil: 42 statt 7) |
| Ergebnis-ID               | keine `resultid`                                                                                                                                                                                  | Abgleich nur über Athlet + Bewerb (Regel 3 in `findExistingResult`)                                                                                                 |
| Lauf/Bahn                 | kein `HEATS`, kein `heatid`, kein `lane`, keine Reaktionszeit                                                                                                                                     | bleiben leer                                                                                                                                                        |
| Meldungen                 | kein `ENTRY`                                                                                                                                                                                      | —                                                                                                                                                                   |
| Vereine                   | `name`, `shortname`, `nation`, `region`; **kein `code`**; Österreich als "NPC Austria" (Nationalteam)                                                                                             | Auflösung über Name; Ergebnisse unter einem Verband gehen seit `feature/result-form-fixes` an den Heimverein                                                        |
| Athleten                  | `license` nur bei 148 von 544; `HANDICAP free/breast/medley` + `exception`                                                                                                                        | Zuordnung meist über Name + Geburtsdatum + Geschlecht + Nation                                                                                                      |
| Runden                    | `round` PRE/FIN/TIM, `preveventid`                                                                                                                                                                | wird gelesen                                                                                                                                                        |
| Staffeln                  | `RELAY` mit `RESULT agegroupid` (nur "Offene Wertung") und `RELAYPOSITION athleteid`                                                                                                              | vermutlich in Ordnung, prüfen                                                                                                                                       |
| Status                    | DNS, DSQ, WDR                                                                                                                                                                                     | wird gelesen                                                                                                                                                        |

Auf dev zusätzlich aufgefallen: Bei Bewerb 1 hat genau eine Gruppe ("Startklasse S4") die Klasse `4`, alle anderen
keine. Erik: von Hand bearbeitet; die Daten von Wettkampf 25 werden ohnehin gelöscht und neu eingespielt.

## Befund: zweite Datei (MSECM 6.2019, Wiener Lagen Cup 2019, ohne Para-Bezug)

`17112019_wien_lenex2_results.lef` (von Erik, 25.10.2026): ebenfalls `LENEX version="2.0"`, ISO-8859-1, aber Erzeuger
`MSECM(R) Export Module`. Gleiche Grundstruktur wie sem2004: **kein `RANKINGS`, ein RESULT je Wertungsgruppe** mit
`agegroupid` und eigenem `place` (z. B. "Jugend II+I" Platz 3, "Allg. Klasse" Platz 20), keine `resultid`, kein
Lauf/Bahn. Unterschiede zu sem2004: Vereine mit `code` und `type="CLUB"`, `reactiontime` an RESULT und
RELAYPOSITION, kein `HANDICAP`, `AGEDATE`, leere `ENTRIES`. Gruppen: "Jugend II+I", "Junioren u. Jugend III",
"Jugend IV+III", "Allg. Klasse" (`agemin="13" agemax="-1"`), bei Staffeln "Offene Klasse". Es gibt also keine
Gruppe "Offene Wertung" — die offene Gruppe heißt je Programm anders.

## Antworten von Erik (25.10.2026)

1. **Welche Dateien kommen?** Nicht absehbar: LENEX 2.0 kann von jedem Veranstalter kommen, dessen Schwimmsoftware
   LENEX 3.0 nicht kann (z. B. WEK), auch von Veranstaltungen ohne Para-Bezug. Erik stellt Beispieldateien von
   Nicht-Para-Veranstaltungen bereit. **Folge:** Die Sonderregeln hängen an der **Struktur der Datei** (kein
   `RANKINGS`, mehrere RESULTs je Start mit `agegroupid`, Klasse nur im Gruppennamen), nicht am Erzeuger "sem2004".
   Version und Erzeuger werden nur für die Rückmeldung gelesen.
2. **Importumfang:** wie bisher wählbar (Nationenfilter, Athletenauswahl); bei internationalen Ergebnissen in der Regel
   nur die Österreicher. Kein neuer Auswahlmechanismus.
3. **Wertungsgruppen:** Startklassen und "Offene Wertung" übernehmen; Altersgruppen ("Jugend A–D", "Jugendwertung",
   "Masters I–III") nicht.
4. **Platz:** Platz in der Startklasse des Athleten (zur Lage des Bewerbs: S/SB/SM), sonst "Offene Wertung", sonst der
   erste in der Datei.
5. **"Startklasse AB":** deutsche Besonderheit, ignorieren (Gruppe wird nicht übernommen, ihr Platz zählt nicht).

Eine eigene LENEX-2.0-Dokumentation gibt es nicht; Grundlage sind die 3.0-Dokumentation und die echten Dateien.

## Plan (phasenweise, eigener Branch von `main`)

### Phase 1 — Testdatei und Erkennung (umgesetzt und abgenommen)

Umgesetzt: `tests/Fixtures/lenex/sem2004-results.lef` und `msecm-results.lef` (anonymisierte Auszüge, ISO-8859-1),
`LenexParserService::fileFormat()` / `fileFormatFromFile()` (Version, Erzeuger, Kurzname, `places_in_results`), Feld
`format` im Ergebnis von `import()`; Tests in `tests/Feature/LenexLegacyFormatTest.php` (`--group=lenex-2-p1`).

- Aus `IDM_Berlin_2018.lxf` eine kleine, anonymisierte Testdatei ableiten (2–3 Bewerbe, Einzel und Staffel, mehrere
  RESULTs je Start, eine Startklasse SB/SM) und unter `tests/Fixtures` ablegen.
- `LenexParserService` merkt sich Version und Erzeuger (`LENEX version`, `CONSTRUCTOR name`) für die
  Import-Rückmeldung ("LENEX 2.0 (sem2004)"). Die Sonderregeln der Phasen 2 und 3 greifen dagegen nach der Struktur
  der Datei (Antwort 1), damit auch andere 2.0-Programme (z. B. WEK) abgedeckt sind.
- Tests: Datei wird erkannt, Typ `results`.

### Phase 2 — Klassen aus Gruppennamen (umgesetzt und abgenommen)

Umgesetzt: Präfix "Startklasse"/"Klasse" in `agegroupHandicap()`; `openAgegroup()` (offene Gruppe nach der mit Erik
abgestimmten Regel: ohne Klasse, `agemax="-1"`, niedrigstes `agemin`, bei Gleichstand die erste; nach einer Klasse
benannte Gruppen ohne erkannte Klasse wie "Startklasse AB" zählen nicht); `scoringAgegroups()` — bei
`places_in_results` nur Klassengruppen und die offene Gruppe als Wertungsgruppe, Splash-Dateien unverändert. Tests in
`tests/Feature/LenexLegacyClassesTest.php` (`--group=lenex-2-p2`). Beim erneuten Import entfernt der Import
Wertungsgruppen eines früheren Imports, deren agegroupid jetzt entfällt ("Jugend A", "Startklasse AB" auf
Wettkampf 25); manuell angelegte Gruppen bleiben. Nebenwirkung: Bewerbe nur mit offener Gruppe
(sem2004-Finals, Staffeln) erscheinen auf der Klärungsseite als mögliche Rahmenbewerbe — dort einfach nicht
ankreuzen.

- `agegroupHandicap()` erkennt die Klasse auch hinter einem Präfix: "Startklasse S4", "Startklasse SB4",
  "Startklasse SM4", ggf. "Klasse S4". Bereiche ("S1-S10") wie bisher. "Startklasse AB", "Offene Wertung",
  "Jugendwertung" usw. bleiben ohne Klasse.
- Wirkt auch auf Rahmenbewerb-Erkennung (`unclassifiedEvents()`) und `extractSportClasses()` — Regressionstests mit
  den bestehenden Splash-Namen ("S4", "SB2", "S1 - 10").
- Altersgruppen ("Jugend …", "Jugendwertung", "Masters …") und "Startklasse AB" werden nicht als Wertungsgruppe
  übernommen (Antworten 3 und 5).

### Phase 3 — mehrere RESULTs je Start (umgesetzt und abgenommen)

Umgesetzt: `buildLegacyPlaceIndex()` / `legacyPlacedResult()` (Platz nach Antwort 4, unter der Ersatz-ID im
`rankingIndex`), `legacyResultId()` (`a{athleteid}-e{eventid}`, Staffel `r{code bzw. Namens-Hash}-{number}-e{eventid}`),
`startResults()` (je Bewerb nur das erste RESULT — für Einzel, Staffeln und `foreign_starts`). DSQ (`place="-1"`)
ergibt keinen Platz. Tests in `tests/Feature/LenexLegacyResultsTest.php` (`--group=lenex-2-p3`). Ergebnisse eines
früheren Imports ohne ID werden beim erneuten Import über Athlet + Bewerb gefunden und bekommen die Ersatz-ID.

Entschieden (Erik, 25.10.2026, siehe Phase 4): Mit "Plätze aus der Datei" (`keep_file_places`) zeigt die
Sammelansicht den gewählten Platz (Startklasse) in allen Gruppen des Ergebnisses, also auch in der "Offenen Wertung" —
so lassen. Je Gruppe eigene Plätze bräuchten eine eigene Speicherung (Platz je Ergebnis und Wertungsgruppe).

Für Phase 4 gefunden: `LenexResolverService::resolveClub()` bildet den Cache-Schlüssel aus `clubid`, sonst
`"code:".$code` — bei Vereinen ohne `clubid` und ohne `code` (alle sem2004-Vereine) ist er für alle gleich
(`"code:"`), sodass der erste aufgelöste Verein für alle weiteren gilt und die Klärungsseite alle unter einem
Schlüssel führt.

- Zusätzlich (Befund Erik auf Wettkampf 25): Ohne `resultid` zeigt die Ergebnisliste die Herkunft "manuell"
  (`lenex_result_id` leer). Vorschlag: eine stabile Ersatz-ID aus athleteid und eventid (z. B. `a365-e1`), damit
  Herkunft "LENEX" stimmt und ein erneuter Import über Regel 1 von `findExistingResult` passt.

- Vor dem Import je Athlet und Bewerb alle RESULTs sammeln (Schlüssel `athleteid` + `eventid`; bei Staffeln
  Staffel + `eventid`). Zeit, Status und Splits sind gleich, also **ein** Ergebnis anlegen.
- Platz und Punkte nach Antwort 4 wählen (Klassengruppe über `agegroupid` → Wertungsgruppe → Klassen).
- Die Gruppen-Plätze bei "Plätze aus der Datei" (`keep_file_places`) je Wertungsgruppe nutzbar machen, falls die
  Sammelansicht mehrere Gruppen eines Ergebnisses zeigt — sonst gilt ein Platz für alle Gruppen. Entscheidung, ob
  nötig, nach Phase 2 anhand der Sammelansicht.
- Tests: ein Ergebnis je Start, richtiger Platz, erneuter Import der Datei legt nichts doppelt an (ohne `resultid`,
  ohne Lauf/Bahn).

### Phase 4 — Staffeln, Vereine, Rückmeldung (umgesetzt und abgenommen)

Umgesetzt: Cache-Schlüssel `name:{nation_id}:{name}` für Vereine ohne `clubid`/`code`
(`LenexResolverService::resolveClub()`); mit Nationenfilter kommt ein unbekannter Verein der Nation mit gewerteten
Staffeln zur Klärung (`hasScoredRelays()`) — "NPC Austria" → ÖBSV, Staffel beim Verband, Einzel beim Heimverein;
Rückmeldung mit Format und `results_merged`; Abschnitt "LENEX 2.0" in
[lenex-import-export.md](lenex-import-export.md). Staffel-RESULTs geprüft (Positionen über `athleteid`, Platz aus der
offenen Gruppe). Tests in `tests/Feature/LenexLegacyClubsTest.php` (`--group=lenex-2-p4`).

Geprüft auf dev (Wettkampf 26, IDM neu eingelesen): 22 Ergebnisse, keine doppelten (Athlet + Bewerb). Ein Ergebnis
erscheint in der Sammelansicht in der "Offenen Wertung" **und** in seiner Startklasse, beide Male mit dem Platz der
Startklasse — Entscheidung Erik (25.10.2026): so lassen, keine Änderung an `scoringAgegroups()` oder an der
Sammelansicht.

- Staffel-RESULTs der Datei durch den Import prüfen (RELAYPOSITION über `athleteid`, Gruppen nur "Offene Wertung").
- "NPC Austria" bzw. andere Nationalteams: Zuordnung zum ÖBSV (Typ Verband) über die Klärungsseite, damit die
  Heimverein-Regel aus `feature/result-form-fixes` greift.
- Import-Rückmeldung: Format und gegebenenfalls zusammengefasste Doppel-RESULTs nennen.
- Spec `lenex-import-export.md` um einen Abschnitt "LENEX 2.0 / sem2004-Eigenheiten" ergänzen.

### Danach (Erik)

- Wettkampf 25 löschen und neu einlesen (Erik: Daten dürfen gelöscht werden), prüfen, dann Rekord-Check.
- Die weiteren LENEX-2.0-Dateien einlesen; Abweichungen als eigene Phase nachziehen.
