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

1. `license`
2. `license_ipc` (SDMS-ID)
3. `lenex_athlete_id` + `club_id`
4. `last_name` + `first_name` + `birth_date` + `gender` + `nation_id`

Wichtig: `lenex_athlete_id` ist über Exporte hinweg **instabil** und wird **nicht persistiert** — es dient nur als
In-Memory-Cache-Schlüssel innerhalb eines Import-Vorgangs. Der Resolver hält Caches für Clubs, Athleten, Events und
Ausnahmecodes; HANDICAP-Werte werden gegen die `exception_codes`-Tabelle gematcht. Öffentliche Fläche u. a.:
`resolveClub()`, `createClub()`,
`resolveAthlete()`, `createAthlete()`, `addToEventCache()`,
`getEventIdFromCache()`, `getUnresolvedClubs()`, `getUnresolvedAthletes()`,
`hasUnresolved()`, `unresolvedCount()`.

## Platzierungen

Platzierungen stehen in LENEX nicht am Result, sondern in
`EVENT > AGEGROUP > RANKINGS > RANKING`. Der Parser baut daraus einen Index
`resultid → place` (`buildRankingIndex`). Da ein Result in mehreren AGEGROUPs auftauchen kann (Gesamt- +
Klassenwertung), **gewinnt die erste gefundene Platzierung** (die spezifischere AGEGROUP kommt zuerst).

## Splash-Meet-Manager-Eigenheiten

Der Parser gleicht mehrere Splash-Besonderheiten aus:

- **Event-IDs**: In Entries-Exporten verwendet Splash `eventid = Nummer × 10`
  (10, 20, 30 …). Der Event-Cache wird daher mit beiden Schlüsseln befüllt, plus einem Fallback-Schlüssel
  `num:<event_number>`.
- **`clubid` statt `id`** an CLUB-Elementen.
- **Fehlendes `startdate`** (Entries-Export) → Fallback auf das Datum der ersten Session.
- **Fehlende `meetid`** (Splash Entries-Export) → Meet-Matching über
  `name` + `start_date`.
- **City-Normalisierung** („Rif / Hallein" vs. „Rif/Hallein").
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
- **Meldungen**: `ATHLETE > ENTRIES > ENTRY` mit `entrytime` (aus Hundertstelsekunden formatiert, `NT` wenn leer).
- **Staffelmeldungen**: `CLUB > RELAYS > RELAY` mit `ENTRIES > ENTRY`
  (`entrytime`) und `RELAYPOSITIONS > RELAYPOSITION` je Mitglied — gespeist aus
  `relay_entries` / `relay_entry_members` (siehe [club-entries.md](club-entries.md)).

`build()` gibt reines XML zurück; die Verpackung als `.lxf` übernimmt der Controller: das XML wird per `ZipArchive` als
innere `.lef` in ein ZIP gelegt und als `application/zip` mit Dateiname `<Meet>_<Datum>_<Typ>.lxf` ausgeliefert.

## HTTP-Ablauf

### Import (mehrstufig, sitzungsbasiert)

Der Zwischenstand wird unter einem Session-Key gehalten (nur IDs/Arrays, keine Eloquent-Modelle):

1. `showForm()` → Upload-Formular (`lenex.import`).
2. `import(Request)` — Datei hochladen und validieren (nur `.lxf`/`.lef`/`.xml`), parsen, Ergebnis in der Session
   ablegen → Weiterleitung zu **confirm-meet**.
3. `confirmMeet(Request)` — Meet auswählen bzw. Ziel-Meet bestätigen (`lenex.confirm-meet`).
4. `runImport(Request)` — führt den Import via Parser + Resolver aus. Gibt es ungelöste Clubs/Athleten, folgt
   **review**; sonst ist der Import fertig.
5. `review(Request)` — zeigt die ungelösten Entitäten (`lenex.review`).
6. `resolveClubs(Request)` / `resolveAthletes(Request)` — wenden die manuellen Zuordnungen an.

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

- `tests/Feature/LenexRelayExportTest.php` — Export von Staffelmeldungen als LENEX-`RELAY`-Elemente.

Die Relais-XML-Struktur beim Import (`RELAY > ENTRIES > ENTRY` mit `eventid`/
`entrytime` am `ENTRY`, `RELAYPOSITIONS` innerhalb des `ENTRY`) und die
`entrycourse`-Behandlung sind projekt bekannte Eigenheiten und in den Parser-/Export-Methoden umgesetzt.
