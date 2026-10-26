# Spec: Rekorde aus Zwischenzeiten

Der Rekord-Check (`RecordCheckerService`) prüft bisher nur die Endzeit eines Ergebnisses. Diese Spec erweitert ihn um
Zwischenzeiten (`result_splits`, bei Staffeln `relay_result_splits`), die nach den Regeln des ÖBSV selbst einen Rekord
aufstellen können. Grundlage: offener Punkt "Rekorde aus Zwischenzeiten" in [../open-points.md](../open-points.md),
Rekordlogik in [records.md](records.md).

**Stand:** umgesetzt (Branch `feature/split-records`, Phasen 1–6, 25.–26.10.2026); die Zusammenfassung steht in
[records.md](records.md) unter "Chronologische Rekordkette" und "Rekorde aus Zwischenzeiten".

## Entscheidungen (Erik, 25.10.2026)

| # | Frage                                   | Entscheidung                                                                                                                                                                                                                                   |
|---|-----------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 1 | Zwischenzeiten als Rekord?              | Ja, für **alle** automatisch geprüften Typen: `AUT`, `AUT.JR`, `AUT.<LV>`, `AUT.<LV>.JR`.                                                                                                                                                      |
| 2 | Welche Strecken?                        | Alle **Standardstrecken** im selben Stil. **Lagen- und Rücken-Zwischenzeiten zählen nie.**                                                                                                                                                     |
| 3 | Status?                                 | Immer **PENDING** mit eigenem Grund — der Verband bestätigt; erst die Bestätigung löst den alten Rekord ab.                                                                                                                                    |
| 4 | Erste Teilstrecke einer Staffel?        | Ja, als Einzelrekord des Startschwimmers — **nur Freistilstaffeln**, nur die **volle** erste Teilstrecke. Eigene Phase.                                                                                                                        |
| 5 | Zeitnahme und Ergebnisstatus?           | Zeitnahme **AUTOMATIC** (Bewerb, sonst Wettkampf). Ergebnisstatus leer oder `EXH` (wie bei der Endzeit); DSQ/DNF/DNS nie.                                                                                                                      |
| 6 | Chronologische Einordnung?              | **Generell** (End- und Zwischenzeiten): Maßstab ist der Rekord, der am Wettkampftag galt; ein älterer Rekord kommt an seinem Datum in die Kette. Schließt auch den offenen Punkt "Rekord-Check ohne Datumsprüfung bei nachträglicher Prüfung". |
| 7 | Spätere, langsamere Rekorde?            | Aus der Kette nehmen und auf **INVALID** setzen — nichts wird gelöscht.                                                                                                                                                                        |
| 8 | Nachprüfung der importierten Ergebnisse | **Artisan-Befehl** mit `--dry-run`, alle Wettkämpfe nach Datum, älteste zuerst. Erik führt ihn aus.                                                                                                                                            |

### Rekordfähige Zwischenzeit-Strecken

| Stil          | Strecken (m)                 |
|---------------|------------------------------|
| Freistil      | 50, 100, 200, 400, 800, 1500 |
| Brust         | 50, 100, 200                 |
| Schmetterling | 50, 100, 200                 |
| Rücken        | — (nie)                      |
| Lagen         | — (nie)                      |

Eine Zwischenzeit zählt nur, wenn ihre Distanz in dieser Liste steht und **kürzer** als die Renndistanz ist (die volle
Distanz ist die Endzeit, die schon geprüft wird). Beispiele: 50 m im 100-m-Freistil-Rennen, 800 m im 1500-m-Rennen,
100 m im 200-m-Brust-Rennen. Nicht: 150 m im 200-m-Rennen, 50 m im 100-m-Rücken-Rennen, 100 m im 200-m-Lagen-Rennen.

## Regeln im Detail

### Chronologische Rekordkette (generell)

Gilt für jeden Rekord, den der Rekord-Check anlegt oder der Verband bestätigt — aus End- wie aus Zwischenzeit. Eine
Kategorie ist wie bisher `record_type + stroke_type_id + sport_class + gender + course + distance + relay_count`; die
Kette sind ihre anerkannten Rekorde (`APPROVED`, `APPROVED.HISTORY`), nach `set_date` geordnet.

- **Maßstab** ist nicht mehr der aktuelle Rekord, sondern der Rekord, der **am Wettkampftag galt**: der jüngste
  anerkannte Rekord der Kategorie mit `set_date <= Wettkampfdatum` (bei gleichem Datum der schnellste). Ist die Zeit
  schneller (oder gibt es keinen), ist sie ein Rekord.
- **Einfügen an der richtigen Stelle** (gemeinsamer Baustein, z. B. `RecordChainService`), sofort bei `APPROVED`, bei
  `PENDING` erst mit der Bestätigung (`SwimRecord::approve()` nutzt denselben Baustein):
    1. Der neue Rekord löst den Maßstab ab (`supersedes_id`).
    2. Spätere Rekorde der Kette (`set_date >` Wettkampfdatum), die **gleich schnell oder langsamer** sind, wären damals
       kein Rekord gewesen: Sie kommen aus der Kette, bekommen `record_status = INVALID`, `is_current = false` und einen
       Kommentar ("ungültig: älterer Rekord <Zeit> vom <Datum>"); das Rekord-Flag an ihrem Ergebnis wird zurückgesetzt.
    3. Gibt es einen späteren, **schnelleren** Rekord, löst dieser den neuen ab: der neue wird `APPROVED.HISTORY`, der
       spätere verweist per `supersedes_id` auf ihn. Sonst ist der neue der aktuelle Rekord.
- Ein ausstehender Rekord (`PENDING`), dessen Maßstab bei der Bestätigung inzwischen schneller ist, wird wie bisher
  `APPROVED.HISTORY` — nur jetzt gemessen an seinem Datum, nicht am aktuellen Rekord.
- Der Rekord-Check meldet die auf INVALID gesetzten Rekorde mit, damit sie sichtbar sind.
- Unverändert: `RegionalRecordService::createIfFaster()` (Landesrekord aus nationalem Rekord) behält seine eigene
  Sperre; Import und manuelle Pflege fügen nicht chronologisch ein.

### Einzelergebnisse

Für jedes Ergebnis, das auch mit seiner Endzeit geprüft wird (Status leer oder `EXH`, Nation `AUT`, Sportklasse
vorhanden), wird jede rekordfähige Zwischenzeit als **eigener Kandidat** geprüft:

- Strecke = Distanz der Zwischenzeit, Zeit = `split_time`; Stil, Sportklasse, Geschlecht, Bahn, Verein, Jugend und
  Landesverband wie bei der Endzeit desselben Ergebnisses.
- Zeitnahme: `swim_events.timing`, sonst `meets.timing` muss `AUTOMATIC` sein.
- Angelegt wird immer als **PENDING**, Grund `RecordCheckerService::PENDING_SPLIT`
  ("Zwischenzeit – Bestätigung durch den Verband nötig"); bei einem `EXH`-Start bleibt es beim selben Grund
  (Zwischenzeit hat Vorrang, beide brauchen die Bestätigung).
- Maßstab ist wie bei der Endzeit der Rekord, der am Wettkampftag in der Kategorie mit der Split-Distanz galt (siehe
  "Chronologische Rekordkette").

### Reihenfolge innerhalb einer Veranstaltung

Die Zwischenzeiten werden **nach** allen Endzeiten des Wettkampfs geprüft (Einzel und Staffel), je Kategorie wieder
schnellste zuerst. So ist der Maßstab für eine 50-m-Zwischenzeit schon ein neuer 50-m-Rekord aus der Endzeit desselben
Wettkampfs.

**Je Kategorie und Wettkampf nur die schnellste Zwischenzeit** (Entscheidung Erik, 25.10.2026): Schwimmen mehrere
Athleten (oder ein Athlet in mehreren Rennen) eine rekordfähige 50-m-Freistil-Zwischenzeit, entsteht nur aus der
schnellsten ein ausstehender Rekord. Das gilt auch über einen erneuten Rekord-Check hinweg: Ein schon vorhandener,
gleich schneller oder schnellerer Zwischenzeit-Rekord desselben Wettkampfs sperrt die Kategorie.

### Herkunft am Rekord

Neue Spalte `swim_records.from_split` (boolean, Default `false`): `true` = Rekord aus einer Zwischenzeit. Dazu ein
lesbarer `comment`, z. B. "Zwischenzeit aus 100 m Freistil" bzw. "Startschwimmer 4×50 m Freistil". Der Rekord verweist
wie gewohnt über `result_id` (bzw. `relay_result_id` beim Staffel-Startschwimmer) auf das Ergebnis.

- **Zwischenzeiten des Rekords** (`record_splits`): nur die Zwischenzeiten des Ergebnisses **bis einschließlich** der
  Split-Distanz (z. B. beim 800-m-Rekord aus 1500 m die Splits bis 800 m).
- **Erneuter Rekord-Check:** Die bestehende Doppelt-Sperre (`result_id` + `record_type`) wird um die Distanz erweitert,
  damit ein 100-m-Ergebnis seinen Endzeit-Rekord und seinen 50-m-Zwischenzeit-Rekord desselben Typs haben kann.
- **Rekord-Flags am Ergebnis:** Ein Zwischenzeit-Rekord setzt bei der Bestätigung **kein** Flag (`is_national_record`
  usw.) am Ergebnis — die Flags beziehen sich auf die Endzeit des Bewerbs.
  `SwimRecord::approve()` überspringt das Flag bei `from_split = true`.
- In Rekordliste, Rekord-Detail und Prüfergebnis des Rekord-Checks ist die Herkunft sichtbar (Hinweis
  "aus Zwischenzeit").

### Staffel-Startschwimmer (eigene Phase)

- Nur **Freistilstaffeln** (Stil Freistil; Lagenstaffeln nie, da Rücken am Start).
- Zeit = Zwischenzeit der Staffel bei Distanz = Teilstreckenlänge (`swim_events.distance`, bei Staffeln je Schwimmer),
  nur wenn diese eine rekordfähige Freistilstrecke ist. Keine Zwischenzeiten innerhalb der ersten Teilstrecke.
- Die Staffel muss gültig beendet sein (Status leer oder `EXH`), Zeitnahme wie oben.
- Startschwimmer = Mitglied an Position 1 mit zugeordnetem Athleten, Nation `AUT`, Sportklasse aus dem Mitglied bzw.
  der S-Klasse des Athleten; Geschlecht des Athleten (nicht die Staffelwertung).
- Verein = Staffelverein; Landesverband aus dem Staffelverein; Jugend aus dem Geburtsjahr des Startschwimmers.
- Vereinszugehörigkeit am Starttag wie bei Staffelrekorden (`clubEvidence`); ein Verband als Staffelverein ergibt keinen
  Rekord. Eine gemischte/AK-Besetzung der übrigen Positionen spielt für den Einzelrekord keine Rolle.
- Rekord: `relay_count = 1`, `athlete_id` = Startschwimmer, `relay_result_id` = Staffelergebnis, keine
  `relay_team_members`, PENDING mit Grund `PENDING_SPLIT`.

### Nachprüfung der importierten Ergebnisse

Artisan-Befehl `php artisan records:check-splits {--dry-run} {--meet=}`:

- geht alle Wettkämpfe nach `start_date` durch, **älteste zuerst**, und prüft nur die Zwischenzeiten (Einzel und
  Staffel-Startschwimmer) — die Endzeiten sind schon geprüft;
- legt Rekorde nach den Regeln oben an (PENDING) und ordnet sie chronologisch ein, sobald der Verband sie bestätigt;
- `--dry-run` läuft in einer Transaktion, die am Ende zurückgerollt wird, und gibt je Wettkampf aus, welche
  Zwischenzeit-Rekorde entstehen würden; `--meet=<id>` beschränkt auf einen Wettkampf;
- ein zweiter Lauf legt nichts doppelt an (Doppelt-Sperre).

Auf dev führt Erik den Befehl aus; Claude prüft vorher nur lesend.

## Phasen

1. **Chronologische Rekordkette:** gemeinsamer Baustein zum Einfügen, Maßstab "am Wettkampftag" in
   `RecordCheckerService::checkRecordType()`, `SwimRecord::approve()` darauf umgestellt, spätere langsamere Rekorde
   INVALID (inkl. Flag-Rücksetzung), Meldung im Prüfergebnis. Tests (`--group=split-records-p1`).
2. **Einzel-Zwischenzeiten:** Migration `from_split`, Prüfung in `RecordCheckerService` (Strecken-/Stil-Regel,
   Zeitnahme, PENDING, Reihenfolge nach den Endzeiten, Doppelt-Sperre mit Distanz, gekürzte `record_splits`), kein
   Ergebnis-Flag bei der Bestätigung. Tests (`--group=split-records-p2`).
3. **Anzeige:** Herkunft "aus Zwischenzeit" und INVALID-Hinweis in Rekordliste, Rekord-Detail und Prüfergebnis des
   Rekord-Checks. Tests (`--group=split-records-p3`).
4. **Staffel-Startschwimmer:** Einzelrekord aus der ersten Teilstrecke einer Freistilstaffel. Tests
   (`--group=split-records-p4`).
5. **Nachprüfung:** `records:check-splits` mit `--dry-run`/`--meet`. Tests (`--group=split-records-p5`). Danach Lauf
   auf dev durch Erik.
6. **Abschluss:** Abschnitte in [records.md](records.md), Einträge "Rekorde aus Zwischenzeiten" und "Rekord-Check ohne
   Datumsprüfung bei nachträglicher Prüfung" in [../open-points.md](../open-points.md) entfernen.
