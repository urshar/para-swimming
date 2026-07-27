# Domänen-Glossar

Fachbegriffe des Para-Schwimmens und projektspezifische Konzepte. Wie die Begriffe im Datenmodell abgebildet sind, steht
in [data-model.md](data-model.md).

## Kurse (Course)

| Code    | Bedeutung                                                                          |
|---------|------------------------------------------------------------------------------------|
| **LCM** | Langbahn, 50-m-Becken (Long Course Meters)                                         |
| **SCM** | Kurzbahn, 25-m-Becken (Short Course Meters)                                        |
| **SCY** | Kurzbahn Yards, 25-yd-Becken (v. a. USA); in AUT-Wertungen i. d. R. nicht relevant |

## Sportklassen

Para-Schwimmen klassifiziert Athleten nach funktioneller Beeinträchtigung. Die Klasse besteht aus einer **Kategorie**
und einer **Nummer**.

| Kategorie | Gilt für                        | Wertebereich |
|-----------|---------------------------------|--------------|
| **S**     | Freistil, Rücken, Schmetterling | S1–S21       |
| **SB**    | Brust                           | SB1–SB21     |
| **SM**    | Lagen (Medley)                  | SM1–SM21     |

Niedrigere Nummern = stärkere Beeinträchtigung. Ein Athlet kann je Kategorie genau eine Klasse haben (z. B. S9 / SB8 /
SM9). Grobe Gruppierung der Nummern:
körperliche Beeinträchtigung (ca. 1–10), Sehbeeinträchtigung (11–13), intellektuelle Beeinträchtigung (14), weitere
Sonderklassen (15, 21 u. a.).

### Staffel-Sportklassen

Staffeln haben eine eigene Klassen-Systematik (Summe/Kombination der Einzelklassen der Schwimmer), u. a. **S14**,
**S15**, **S20**, **S21**, **S34**, **S49**. Staffel-Events erkennt man am `relay_count > 1`; Einzel-Events an
`relay_count = 1`.

### Sportklassen-Gruppen (Cup)

Für die Cup-Wertung werden Sportklassen zu Gruppen zusammengefasst:

| Code    | Bedeutung                                                              |
|---------|------------------------------------------------------------------------|
| **PI**  | Körperliche Beeinträchtigung (physical impairment)                     |
| **VI**  | Sehbeeinträchtigung (visual impairment)                                |
| **II**  | Intellektuelle Beeinträchtigung (intellectual impairment)              |
| **T21** | Trisomie 21                                                            |
| **HI**  | Hörbeeinträchtigung (hearing impairment)                               |
| **TOP** | Top-Gruppe — virtuell, ohne feste Klassenmitglieder (siehe Top-Gruppe) |

## Melde- und Ergebnis-Status

Meldungen (`Entry`) und Ergebnisse (`Result`) tragen einen Status. Relevante Werte und ihre Behandlung in
Statistik/Wertung:

| Status     | Bedeutung                             |
|------------|---------------------------------------|
| **(leer)** | reguläres Ergebnis / reguläre Meldung |
| **DSQ**    | disqualifiziert                       |
| **DNS**    | nicht angetreten (did not start)      |
| **DNF**    | nicht beendet (did not finish)        |
| **EXH**    | außer Konkurrenz (exhibition)         |
| **SICK**   | krankgemeldet                         |
| **WDR**    | zurückgezogen (withdrawn)             |

**Start** im statistischen Sinn = Athlet ist tatsächlich angetreten. Ausgeschlossen werden **DNS, SICK, WDR**;
eingeschlossen bleiben **DSQ, DNF, EXH**.

## Rekordtypen

`SwimRecord.record_type` unterscheidet nach Geltungsbereich:

| Typ            | Bedeutung                                              |
|----------------|--------------------------------------------------------|
| **WR**         | Weltrekord                                             |
| **ER**         | Europarekord                                           |
| **OR**         | (weiterer internationaler Rekordtyp)                   |
| **AUT**        | Nationalrekord Österreich                              |
| **AUT.JR**     | Österreichischer Jugendrekord                          |
| **AUT.\<LV\>** | Regionalrekord eines Landesverbands (z. B. `AUT.WBSV`) |

`record_status`: `PENDING`, `APPROVED`, `INVALID`, jeweils auch als
`*.HISTORY`-Variante (historisierte Rekorde), sowie `TARGETTIME`. `is_current`
markiert den aktuell gültigen Rekord; über `supersedes_id` /
`superseded_by_id` entsteht die Rekord-Historie.

## Kader

Leistungskader des ÖBSV, modelliert über `KaderType` (z. B. **Nationalkader**)
und zeitlich begrenzte `AthleteKaderMembership`. Kaderzugehörigkeit ist u. a. ein Grund für die Aufnahme in die
**Top-Gruppe** der Cup-Wertung.

## Cup-Wertung (ÖBSV Cup)

| Begriff           | Bedeutung                                                                                                                                |
|-------------------|------------------------------------------------------------------------------------------------------------------------------------------|
| **Tageswertung**  | Wertung je Wettkampftag/Meet (`CupDailyResult`)                                                                                          |
| **Gesamtwertung** | Saison-Gesamtwertung über mehrere Meets (`CupOverallResult`)                                                                             |
| **Jugendwertung** | Wertung nach Altersklassen (`AgeGroup`)                                                                                                  |
| **Top-Gruppe**    | Saisonale Gruppe der leistungsstärksten Athleten; **Snapshot**, kein Live-Check je Ergebnis. Aufnahmegrund `KADER` oder `POINTS_HISTORY` |

Regeln (Auszug): Das **Alter** wird per **31. Dezember des Wettkampfjahres**
berechnet. Deaktivierte Altersklassen führen zu dynamisch neu berechneten Grenzen (erste aktive Gruppe beginnt bei 0,
letzte ohne obere Grenze). Die gezählten Meets stehen als IDs in `counted_meet_ids`.

## Richtzeiten (Qualifikation)

Sollzeiten, die für die Qualifikation zu Meisterschaften erreicht werden müssen (`QualifyingTimeList`,
`QualifyingTime`). Berechnung über die **inverse World-Aquatics-1000-Punkte-Formel**; Werte in **Hundertstelsekunden**
(`value_centiseconds`). Eine erfüllte Qualifikation wird als `Qualification`
festgehalten (Snapshot).

## World-Aquatics-Punkte

Punktebewertung einer Zeit relativ zu einer Basiszeit:

```
P = 1000 × (B / T)³
```

mit `B` = Basiszeit und `T` = geschwommene Zeit. Basiszeiten sind versioniert (`BaseTimeVersion`) und teils aus kürzeren
Disziplinen abgeleitet (`BaseTimeDerivationRule`).

## LENEX

**LENEX 3.0** ist das XML-Austauschformat für Schwimm-Wettkampfdaten. Eine **`.lxf`**-Datei ist ein **ZIP-Archiv**, das
eine **`.lef`**-XML-Datei enthält. Das Projekt importiert und exportiert `.lxf` (kompatibel u. a. mit Splash Meet
Manager und Swimify). Modul-Details in `docs/specs/lenex-import-export.md`
*(folgt in Phase 3)*.

Bekannte Eigenheiten (siehe auch [conventions.md](conventions.md)):

- Splash Meet Manager multipliziert Event-Nummern in Entries-Exports mit 10.
- `lenex_athlete_id` ist über Exporte hinweg instabil und wird nicht persistiert.
- Platzierungen stehen in `EVENT > AGEGROUP > RANKINGS > RANKING`, nicht in den RESULT-Attributen.

## Organisationen & Abkürzungen

| Abkürzung             | Bedeutung                                                          |
|-----------------------|--------------------------------------------------------------------|
| **ÖBSV**              | Österreichischer Behindertensportverband (Auftraggeber/Föderation) |
| **WPS**               | World Para Swimming                                                |
| **WA**                | World Aquatics                                                     |
| **SDMS / IPC-Lizenz** | Athleten-ID im World-Para-Swimming-System (`license_ipc`)          |
| **SWRID**             | Globale ID von swimrankings.net                                    |
| **Landesverbände**    | BBSV, KLSV, NOEVSV, OOEBSV, SBSV, STBSV, TBSV, VBSV, WBSV          |

## Zeiteinheiten im Code

- Alle Zeiten (`entry_time`, `swim_time`, `split_time`, `value_centiseconds`):
  **Integer in Hundertstelsekunden**. `TimeParser` konvertiert zwischen dem LENEX-Format `HH:MM:SS.ss` und Hundertsteln
  (`… * 100 + ss`).
- Parsing und Formatierung (`MM:SS.ss`) über `App\Support\TimeParser`; Eingaben im UI über IMask.
