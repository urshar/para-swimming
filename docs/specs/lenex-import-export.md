# Spec: LENEX Import/Export

Dieses Modul tauscht **Wettkampfdaten** (Meet-Struktur, Meldungen, Ergebnisse)
über das LENEX-3.0-Format mit Wettkampf-Software aus, insbesondere Splash Meet Manager und Swimify. Es importiert
`.lxf`/`.lef`/`.xml` und exportiert `.lxf`.

> **Abgrenzung:** Der **Rekord**-LENEX-Import/-Export ist ein eigenes Modul
> (`RecordImportService` / `RecordLenexExportService`) und in
> [records.md](records.md) beschrieben. Dieses Dokument behandelt den Austausch
> von Meets, Meldungen und Ergebnissen.

Fachbegriffe (LENEX, `.lxf`/`.lef`, Kurse): [../domain-glossary.md](../domain-glossary.md).
Tabellen: [../data-model.md](../data-model.md).

## Beteiligte Bausteine

| Baustein                        | Datei                                                                    |
|---------------------------------|--------------------------------------------------------------------------|
| Parser / Import                 | `App\Services\LenexParserService`                                        |
| Auflösung Clubs/Athleten/Events | `App\Services\LenexResolverService`                                      |
| Export                          | `App\Services\LenexExportService`                                        |
| Import (HTTP, mehrstufig)       | `App\Http\Controllers\LenexImportController`                             |
| Export (HTTP)                   | `App\Http\Controllers\LenexExportController`                             |
| Views                           | `resources/views/lenex/*` (`import`, `confirm-meet`, `review`, `export`) |

## Dateiformat

Eine `.lxf`-Datei ist ein **ZIP-Archiv** mit einer `.lef`-XML-Datei. Der Parser akzeptiert sowohl `.lxf` (ZIP) als auch
direktes `.lef`/`.xml`
(`extractXmlContent`: bei ZIP wird die erste `.lef`/`.xml` entpackt, sonst wird die Datei direkt als XML gelesen). Der
Export erzeugt XML und verpackt es als
`.lxf`-ZIP.

## Import-Typen

Der Parser erkennt den Typ automatisch (`detectType`) und importiert entsprechend gestaffelt:

| Typ         | Importiert                                                        |
|-------------|-------------------------------------------------------------------|
| `structure` | Meet, Sessions, SwimEvents                                        |
| `entries`   | zusätzlich Clubs, Athleten, Meldungen (`Entry`)                   |
| `results`   | zusätzlich Ergebnisse (`Result`) + Zwischenzeiten (`ResultSplit`) |

Einstieg: `import(string $filePath, LenexResolverService $resolver, ?int $forceMeetId = null)`. Hilfsmethoden:
`detectTypeFromFile()`, `extractMeetMeta()`,
`extractAthletesForClubs()`.

## Auflösung — `LenexResolverService`

Beim Import werden Clubs, Athleten und Events aufgelöst: existiert der Datensatz bereits, wird er wiederverwendet; wird
er nicht gefunden, wird er zur **manuellen Bestätigung** vorgemerkt (`unresolvedClubs` / `unresolvedAthletes`).

**Matching-Priorität Clubs:**

1. `code` + `nation_id`
2. `lenex_club_id` + `nation_id`
3. normalisierter `name` + `nation_id`

**Matching-Priorität Athleten:**

1. Zuordnung auf der Klärungsseite (`assignAthlete`, LENEX athleteid → bestehender Athlet)
2. `license` — ohne Leerzeichen verglichen (in der Datenbank oft "W - 1653", in Dateien "W-1653")
3. `license_ipc` (SDMS-ID), ebenfalls ohne Leerzeichen
4. `lenex_athlete_id` + `club_id`
5. `last_name` + `first_name` + `birth_date` (per `whereDate`, portabel) + `gender` + `nation_id`

Lizenzvergleich und Vorschläge liegen in `ImportSuggestionService`, den auch der Rekord-Import nutzt.

Wichtig: `lenex_athlete_id` ist über Exporte hinweg **instabil** und wird **nicht persistiert** — es dient nur als
In-Memory-Cache-Schlüssel innerhalb eines Import-Vorgangs. Der Resolver hält Caches für Clubs, Athleten, Events und
Ausnahmecodes; HANDICAP-Werte werden gegen die `exception_codes`-Tabelle gematcht. Öffentliche Fläche u. a.:
`resolveClub()`, `createClub()`, `addToClubCache()`,
`resolveAthlete()`, `createAthlete()`, `assignAthlete()`, `addToEventCache()`,
`getEventIdFromCache()`, `getUnresolvedClubs()`, `getUnresolvedAthletes()`, `hasUnresolved()`.

## Nationenfilter (internationale Veranstaltungen)

Enthält eine Melde- oder Ergebnisdatei mehrere Nationen, bietet "Wettkampf zuordnen" die Auswahl **"Nur Schwimmer
dieser Nation importieren"** an (Vorauswahl Österreich, `ALL` = alle). Option `only_nation` von
`LenexParserService::import()`:

- Importiert werden nur Schwimmer der Nation (Athlet `nation`, sonst Verein `nation`), Staffeln nur von Vereinen der
  Nation, und nur die Bewerbe, in denen sie starten (`collectNationEventIds`; der Splash-Rückfall
  eventid = number × 10 gilt dabei nur für Meldedateien).
- Vereine anderer Nationen werden gar nicht erst gesucht. Ein nicht gefundener Verein der Nation (typisch:
  Nationalteam "Austria") wird **nicht** zur Klärung vorgemerkt (`resolveClub(..., recordUnresolved: false)`):
  Meldungen und Ergebnisse gehen an den **Heimverein** der Schwimmer. Fehlt auch der (neu angelegter Athlet ohne
  Verein), werden sie gezählt übersprungen ("Athlet ohne Verein" in der Rückmeldung; `club_id` ist Pflicht).
- Mit Filter entfällt die Abfrage der Rahmenbewerbe (internationale Meisterschaften haben keine).
- Die Plätze aus der Datei (RANKINGS) sind die internationalen Plätze (z. B. EM-Platz). Ein Import mit Nationenfilter
  auf einer Ergebnisdatei setzt an der Veranstaltung `keep_file_places` ("Plätze aus der Ergebnisdatei übernehmen",
  im Veranstaltungsformular unter "Punkteberechnung" änderbar): Die Wertung rechnet dann nicht neu, sondern sortiert
  nach dem gespeicherten Platz — siehe [scoring-groups.md](scoring-groups.md).

**Nation der Veranstaltung:** Fehlt sie in der Datei oder ist sie unbekannt (EM Kocaeli 2026: `nation=""`), fragt
"Wettkampf zuordnen" sie ab — Pflicht, wenn die Veranstaltung neu angelegt wird (`meets.nation_id`), Option
`meet_nation`.

## Rahmenbewerbe (nicht gewertet)

Bewerbe mit `is_scored = false` (z. B. Schnupperbewerbe für nicht klassifizierte Schwimmer) werden wie alle Bewerbe
importiert und exportiert, aber: Ihre Ergebnisse, Staffelergebnisse und Meldungen werden übersprungen, und wer
**nur** in solchen Bewerben schwimmt, wird weder gesucht noch auf der Klärungsseite vorgemerkt. Die Kennzeichnung
entsteht beim ersten Import (Abfrage auf der Klärungsseite) oder im Disziplin-Formular; dort werden vorhandene
Ergebnisse beim Kennzeichnen nach ausdrücklicher Bestätigung gelöscht. In der Wettkampfliste zählen Rahmenbewerbe für
die Anzeige "I" nicht; ein gewerteter Bewerb gilt dort als eingerichtet, wenn er Wertungsgruppen oder Sportklassen
hat.

## Platzierungen

Platzierungen stehen in LENEX nicht am Result, sondern in
`EVENT > AGEGROUP > RANKINGS > RANKING`. Der Parser baut daraus einen Index
`resultid → place` (`buildRankingIndex`). Da ein Result in mehreren AGEGROUPs auftauchen kann (Gesamt- +
Klassenwertung), **gewinnt die erste gefundene Platzierung** (die spezifischere AGEGROUP kommt zuerst).

Die AGEGROUPs selbst werden als Wertungsgruppen des Bewerbs übernommen (`importScoringGroups`), der Export schreibt
sie zurück, beim Ergebnisexport mit RANKINGS — siehe [scoring-groups.md](scoring-groups.md).

### Einzelergebnisse: Abgleich mit vorhandenen Ergebnissen

Damit ein erneuter oder nachträglicher Import (auch in eine Veranstaltung, deren Ergebnisse aus einer anderen Quelle
stammen) nichts doppelt anlegt, sucht `findExistingResult` ein vorhandenes Ergebnis in dieser Reihenfolge:

1. Veranstaltung + Bewerb + `lenex_result_id` (erneuter Import derselben Datei),
2. Veranstaltung + Bewerb + Athlet + Lauf + Bahn,
3. Veranstaltung + Bewerb + Athlet, wenn das vorhandene Ergebnis **keinen Lauf und keine Bahn** hat (Altbestand) —
   nur bei genau einem Treffer. Mehrere Treffer werden nicht zugeordnet, sondern neu angelegt und gezählt.

Vorlauf, Finale und Stechen sind in LENEX eigene Bewerbe; je Bewerb kommt ein Athlet also nur einmal vor. Beim
Abgleich gewinnt die Datei (Zeit, Status, Lauf, Bahn, Reaktionszeit, Zwischenzeiten, Rekordkürzel,
`lenex_result_id`); fehlen darin Punkte, Platz oder Sportklasse, bleiben die vorhandenen Werte stehen. Ergebnisse, die
nur in der Datenbank stehen (z. B. manuell erfasste), bleiben unberührt. Die Import-Rückmeldung nennt, wie viele
Ergebnisse neu angelegt und wie viele abgeglichen wurden.

**Dateityp:** `detectType` sucht Ergebnisse bzw. Meldungen per XPath in allen Vereinen, Athleten und Staffeln. Bis
Oktober 2026 prüfte es per SimpleXML-Kettenzugriff nur den ersten Verein und dessen ersten Athleten — hatte dieser
keine Ergebnisse, wurde die Datei als reine Struktur importiert.

**Lauf:** `ENTRY`/`RESULT heatid` ist ein Verweis auf `EVENT > HEATS > HEAT`, nicht die Laufnummer. Der Parser
übersetzt ihn über `HEAT number` (`buildHeatIndex`, z. B. heatid 2168 → Lauf 1); ohne HEATS in der Datei bleibt der
Rohwert. Bis Oktober 2026 wurde die heatid direkt als Lauf gespeichert (betraf Meets 160, 183, 191); ein erneuter
Import korrigiert das über die `lenex_result_id`.

### Staffelmeldungen

`CLUB > RELAYS > RELAY > ENTRIES > ENTRY` wird bei Meldedateien nach allen Athleten importiert
(`importRelayEntry`):

- Abgleich über Veranstaltung + Bewerb + Verein + `relay_number` (RELAY number); App-Meldungen ohne Nummer werden als
  n-te Meldung des Vereins im Bewerb (nach Anlage) zugeordnet — genau so nummeriert der Meldeexport, das Zurückspielen
  einer exportierten Meldedatei erzeugt also keine Doppelten.
- Staffelklasse aus `RELAY handicap`, sonst aus den Klassen der Schwimmer (`RelayClassValidator::resolveRelayClass`).
- Neue Meldungen sind bestätigt (`status = confirmed`); `ENTRY status="EXH"` = außer Konkurrenz.
- Schwimmer aus `RELAYPOSITIONS`; nicht aufgelöste Athleten werden ausgelassen (`relay_entry_members.athlete_id` ist
  Pflicht). Rahmenbewerbe (`is_scored = false`) werden übersprungen.

### Staffelergebnisse

`CLUB > RELAYS > RELAY > RESULTS > RESULT` wird nach allen Athleten importiert (`importRelayResult`), weil die
`RELAYPOSITION`en per `athleteid` auch auf Athleten anderer Vereine verweisen können:

- Wertung aus `RELAY gender` (M/F/X), Staffelklasse aus dem `handicap` der AGEGROUP, in der das Ergebnis platziert ist
  (`handicap="14"` → S14), Platz aus deren RANKING.
- Schwimmer aus `RELAYPOSITIONS`; ein nicht zuordenbarer Athlet bleibt mit Namenskopie, aber ohne `athlete_id` stehen.
  Sportklasse je Schwimmer aus `HANDICAP free`. Zwischenzeiten aus `SPLITS`.
- Staffeln ohne Zeit und ohne Status werden übersprungen; WDR/DNS/DSQ werden importiert.
- Erneuter Import aktualisiert über `meet_id` + `lenex_result_id` (Schwimmer und Zwischenzeiten werden ersetzt).

## Splash-Meet-Manager-Eigenheiten

Der Parser gleicht mehrere Splash-Besonderheiten aus:

- **Event-IDs**: In Entries-Exporten verwendet Splash `eventid = Nummer × 10`
  (10, 20, 30 …). Der Event-Cache wird daher mit beiden Schlüsseln befüllt, plus einem Fallback-Schlüssel
  `num:<event_number>`.
- **`clubid` statt `id`** an CLUB-Elementen.
- **Fehlendes `startdate`** (Entries-Export) → Fallback auf das Datum der ersten Session.
- **Fehlende `meetid`** (Splash Entries-Export) → Meet-Matching über
  `name` + `start_date`.
- **City-Normalisierung** ("Rif / Hallein" vs. "Rif/Hallein").
- **Redundante AGEGROUPs** (Gesamtliste + einzelne Untergruppen) werden dedupliziert.
- **Sportklassen-Trennzeichen**: Splash nutzt Komma, LENEX-Standard Leerzeichen; beides wird eingelesen, dedupliziert
  und numerisch sortiert (1 … 9, 10 … 21). Fehlen AGEGROUPs (Entries-Export), bleibt der bestehende DB-Wert des Events
  erhalten.

## Zeiten & Mappings

- Zeiten: `parseTime()` (→ Hundertstelsekunden) bzw. `parseTimeCode()` für Codes wie `NT`.
- Mapping-Helfer: `mapCourse`, `mapTiming`, `mapGender`, `mapRound`,
  `mapTechnique`, `mapEntryStatus`, `mapResultStatus`, `parseReactionTime`,
  `extractPrimaryClassFromHandicap`.

## Export — `LenexExportService`

`build(Meet $meet, string $exportType): string` erzeugt ein LENEX-3.0-XML (`DOMDocument`, `version="3.0"`) mit
CONSTRUCTOR und
`MEETS > MEET > SESSIONS > EVENTS`. `exportType` ist `structure`, `entries` oder
`results`.

- Für `entries`/`results` werden zusätzlich `CLUBS > CLUB > ATHLETES > ATHLETE`
  (inkl. `HANDICAP`) aufgebaut.
- **Welche Vereine:** bei `entries` die Vereine aus `entries` und `relay_entries`, bei `results` die aus `results`,
  jeweils ergänzt um die per LENEX-Import zugeordneten (`meet_club`), alphabetisch (`Meet::entryClubIds()` /
  `resultClubIds()` / `clubsByIds()`). `meet_club` allein reicht nicht: Meldungen und manuell erfasste Ergebnisse
  befüllen die Pivot-Tabelle nicht. Dieselbe Ableitung nutzt "Teilnehmende Vereine" auf `meets/show`
  (`Meet::participatingClubs()`).
- **Meldungen**: `ATHLETE > ENTRIES > ENTRY` mit `entrytime` (aus Hundertstelsekunden formatiert, `NT` wenn leer).
- **Staffelmeldungen**: `CLUB > RELAYS > RELAY` mit `ENTRIES > ENTRY`
  (`entrytime`) und `RELAYPOSITIONS > RELAYPOSITION` je Mitglied — gespeist aus
  `relay_entries` / `relay_entry_members` (siehe [club-entries.md](club-entries.md)).
- **Staffelergebnisse** (`results`): `CLUB > RELAYS > RELAY` (number, name, gender, handicap = Staffelklasse) mit
  `RESULTS > RESULT` (Zeit, Status, Punkte, Lauf/Bahn, Kommentar, Rekordkürzel, `SPLITS`) und `RELAYPOSITIONS`.
  Schwimmer, die nur in Staffeln starten, stehen unter ihrem eigenen Verein in `ATHLETES` (der Verein kommt dafür
  ggf. dazu), damit `RELAYPOSITION athleteid` auflösbar ist; Schwimmer ohne Athleten-Datensatz (nur Namenskopie)
  fehlen in den Positionen.
- **Ranglisten** (`results`): `AGEGROUP > RANKINGS` je Wertungsgruppe für Einzel- und Staffelbewerbe; Staffelbewerbe
  ohne Wertungsgruppen bekommen je Wertung und Staffelklasse eine AGEGROUP mit `handicap` und RANKINGS (daraus liest
  der Import Staffelklasse und Platz).
- **Läufe**: `EVENT > HEATS > HEAT` (heatid, number) aus den verwendeten Läufen; `ENTRY`/`RESULT heatid` verweist
  darauf (heatid = Bewerbs-ID × 1000 + Lauf).

`build()` gibt reines XML zurück; die Verpackung als `.lxf` übernimmt der Controller: das XML wird per `ZipArchive` als
innere `.lef` in ein ZIP gelegt und als `application/zip` mit Dateiname `<Meet>_<Datum>_<Typ>.lxf` ausgeliefert.

## HTTP-Ablauf

### Import (mehrstufig, sitzungsbasiert)

Der Zwischenstand wird unter einem Session-Key gehalten (nur IDs/Arrays, keine Eloquent-Modelle):

1. `showForm()` → Upload-Formular (`lenex.import`).
2. `import(Request)` — Datei hochladen und validieren (nur `.lxf`/`.lef`/`.xml`), parsen, Ergebnis in der Session
   ablegen → Weiterleitung zu **confirm-meet**.
3. `confirmMeet(Request)` — Meet auswählen bzw. Ziel-Meet bestätigen (`lenex.confirm-meet`).
4. `runImport(Request)` — enthält die Datei Einzelbewerbe ohne jede Klassenangabe (keine AGEGROUP mit `handicap`,
   `unclassifiedEvents`), die in der Ziel-Veranstaltung noch nicht existieren, fragt die Klärungsseite zuerst nach
   **Rahmenbewerben** (`resolveEvents`: angekreuzte werden mit `is_scored = false` angelegt). Bestehende Bewerbe
   behalten ihre Kennzeichnung, beim Nachimport wird also nicht erneut gefragt. Danach (`startImport`) läuft der
   Import via Parser + Resolver. Gibt es ungelöste Clubs/Athleten, folgt **review**; sonst ist der Import fertig.
   Wurde keine bestehende Veranstaltung gewählt, wird die beim ersten Lauf angelegte für die Folgeschritte gemerkt.
5. `review(Request)` — Klärungsseite (`lenex.review`), wie beim Rekord-Import: je unbekanntem Verein bzw. Athleten
   "Neu anlegen", "Überspringen", Vorschläge (Verein: Name/Kurzname/Code; Athlet: Name + Geburtsjahr, bei genau
   einem Treffer vorbelegt) oder "Bestehendem zuordnen" (Vereine: alle; Athleten: gleicher Anfangsbuchstabe des
   Nachnamens). Angezeigt werden Geburtsdatum, Lizenz, Verein und Klasse zum Vergleich.
6. `resolveClubs(Request)` — legt an bzw. merkt die Zuordnung (cache_key → Club-ID) und lässt den Import mit diesen
   Zuordnungen erneut laufen (wiederholbar dank Ergebnis-Abgleich). Die dann noch unbekannten Athleten — auch die
   eines zugeordneten bestehenden Vereins — kommen auf die Klärungsseite.
7. `resolveAthletes(Request)` — legt an bzw. merkt die Zuordnung (athleteid → Athlet) und führt den finalen Import
   aus. Ein zugeordneter Athlet wird wie ein automatisch gefundener behandelt (HANDICAP-Abgleich), seine Stammdaten
   bleiben unverändert. Übersprungene Vereine und Athleten werden samt Ergebnissen nicht importiert.

Die Daten der unbekannten Einträge stehen in der Session; das Formular schickt nur die Auswahl je Eintrag
(`clubs[i][selection]`, `athletes[i][selection]`: `new`, `skip` oder eine ID).

### Export

`showForm()` → Auswahl (`lenex.export`); `download(Request)` baut das XML, verpackt es als `.lxf` und streamt es.

## Routen

Alle unter `auth`, Prefix `lenex`:

| Route                                 | Name                            |
|---------------------------------------|---------------------------------|
| `GET /lenex/import`                   | `lenex.import`                  |
| `POST /lenex/import`                  | `lenex.import.store`            |
| `GET /lenex/import/confirm-meet`      | `lenex.import.confirm-meet`     |
| `POST /lenex/import/run`              | `lenex.import.run`              |
| `GET /lenex/import/review`            | `lenex.import.review`           |
| `POST /lenex/import/resolve-clubs`    | `lenex.import.resolve-clubs`    |
| `POST /lenex/import/resolve-athletes` | `lenex.import.resolve-athletes` |
| `GET /lenex/export`                   | `lenex.export`                  |
| `POST /lenex/export/download`         | `lenex.export.download`         |

## Tests

- `tests/Feature/LenexImportReviewTest.php` — Klärungsseite: Lizenz ohne Leerzeichen, Vorschläge, Zuordnung zu
  bestehenden Vereinen/Athleten, Neuanlage, Überspringen; Rahmenbewerbe (Abfrage, Nachimport, Formular, Anzeige "I").
- `tests/Feature/LenexResultMatchingTest.php` — Abgleich mit vorhandenen Einzelergebnissen, Laufnummer aus HEATS.
- `tests/Feature/LenexRelayExportTest.php` — Export von Staffelmeldungen als LENEX-`RELAY`-Elemente.
- `tests/Feature/LenexExportClubsTest.php` — Vereine im Export ohne `meet_club`-Eintrag (Meldungen, Ergebnisse,
  Import-Zuordnungen) und "Teilnehmende Vereine" auf `meets/show`.

Die Relais-XML-Struktur beim Import (`RELAY > ENTRIES > ENTRY` mit `eventid`/
`entrytime` am `ENTRY`, `RELAYPOSITIONS` innerhalb des `ENTRY`) und die
`entrycourse`-Behandlung sind projekt bekannte Eigenheiten und in den Parser-/Export-Methoden umgesetzt.
