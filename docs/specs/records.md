# Spec: Rekorde (Records)

Das Records-Modul verwaltet nationale und regionale Para-Schwimm-Rekorde: Es erkennt neue Rekorde automatisch aus
Wettkampfergebnissen, erlaubt manuelle Pflege, importiert und exportiert Rekorde im LENEX-Format und führt einen
Genehmigungs-Workflow sowie eine lückenlose Rekord-Historie.

Fachbegriffe (Rekordtypen, `record_status`): [../domain-glossary.md](../domain-glossary.md). Tabellen (`swim_records`,
`record_splits`, `relay_team_members`):
[../data-model.md](../data-model.md).

## Beteiligte Bausteine

| Baustein         | Datei                                          |
|------------------|------------------------------------------------|
| Rekord-Erkennung | `App\Services\RecordCheckerService`            |
| Staffelklassen   | `App\Services\RelayClassValidator`             |
| LENEX-Import     | `App\Services\RecordImportService`             |
| LENEX-Export     | `App\Services\RecordLenexExportService`        |
| CRUD & Prüflauf  | `App\Http\Controllers\RecordController`        |
| Import (HTTP)    | `App\Http\Controllers\RecordImportController`  |
| Export (HTTP)    | `App\Http\Controllers\RecordExportController`  |
| Modelle          | `SwimRecord`, `RecordSplit`, `RelayTeamMember` |

## Rekordtypen

Automatisch geprüft werden **nur nationale und regionale** Rekorde:

- `AUT` — Nationalrekord (altersunabhängig)
- `AUT.JR` — Jugendrekord (Jahrgangsalter ≤ 18 im Wettkampfjahr)
- `AUT.<LV>` — Regionalrekord eines Landesverbands (z. B. `AUT.WBSV`), je LV auch eine `.JR`-Variante. Der Typ ergibt
  sich aus dem Verein über den Accessor
  `Club::regional_record_type` (`'AUT.' . regional_association`).

**WR / ER / OR werden nicht automatisch geprüft** — internationale Rekorde kommen nur über den Import oder die manuelle
Pflege in die Datenbank.

## Rekord-Erkennung — `RecordCheckerService`

`checkMeet(Meet)` lädt die gültigen Ergebnisse eines Meets (mit
`athlete.nation` u. a.) und prüft jedes einzeln; Einzel- und Staffelergebnisse laufen über getrennte Zweige
(`checkResult` / `checkRelayResult`). Rückgabe:
neue Rekorde, ausstehende Rekorde (`pending_records`) und die Anzahl geprüfter Ergebnisse.

### Nationalitätsregel (Einzelrekorde)

Aus `result.athlete.nation.code`:

| Nation | Verhalten                                                  |
|--------|------------------------------------------------------------|
| `AUT`  | Rekord wird als **APPROVED** angelegt                      |
| `null` | Rekord wird als **PENDING** angelegt (Nationalität unklar) |
| sonst  | **übersprungen** (kein AUT-Rekord)                         |

### Vergleich & Anlage — `checkRecordType`

Für jeden geprüften Typ wird der aktuelle Rekord gesucht über die Kombination
`record_type + stroke_type_id + sport_class + gender + course + distance +
relay_count` mit `is_current = true`. Gibt es **keinen** aktuellen Rekord oder ist die neue Zeit **schneller**
(`swim_time <`), entsteht in einer Transaktion:

1. ein neuer `SwimRecord` mit `supersedes_id = <alter Rekord>`, `is_current = true`,
   `set_date = meet.start_date` und den denormalisierten Meet-Feldern (`meet_name`, `meet_city`, `meet_course`);
2. die Kopien der Zwischenzeiten (`RecordSplit` aus `result.splits`);
3. **nur bei `record_status = APPROVED`**: der alte Rekord wird per
   `markAsSupersededBy()` abgelöst.

**Wichtig:** Ein **PENDING**-Rekord löst den bestehenden aktuellen Rekord **nicht** ab — er wartet auf die Genehmigung.
Erst mit der Genehmigung wird der Vorgänger historisiert.

### Jugend- und Regionalrekorde

- **Jugend**: Einzel — `Wettkampfjahr − Geburtsjahr ≤ 18`; Staffel — alle Mitglieder mit bekanntem Geburtsdatum ≤ 18
  (`RelayClassValidator::isJuniorRelay`). AUT.JR wird als APPROVED angelegt.
- **Regional**: aus `club.regional_record_type`, jeweils Basis- und JR-Variante.

### Staffelrekorde — `checkRelayResult`

Mitglieder werden aus den `Entry`-Datensätzen desselben Events + Vereins ermittelt. Über `RelayClassValidator` werden
die Sportklassen extrahiert und die Staffelklasse bestimmt (`resolveRelayClass`); ergibt sich **keine gültige**
Klasse, entsteht kein Rekord. Zusätzlich müssen **alle Athleten die Nation AUT**
haben (ein Nicht-AUT-Mitglied bricht ab). Bei Erfolg werden die Teammitglieder als `RelayTeamMember` (mit Position,
Name, Geburtsdatum, optional `athlete_id`)
gespeichert.

## Historie & Ablösung

`SwimRecord::markAsSupersededBy(newRecord)` setzt am abgelösten Rekord:

- `is_current = false`,
- `superseded_by_id = <neuer Rekord>`,
- `record_status`: `APPROVED → APPROVED.HISTORY`, `PENDING → PENDING.HISTORY`, sonst unverändert.

`getHistoryChain()` folgt `supersedes_id` rückwärts und liefert die Kette vom ältesten zum aktuellen Rekord. Scopes:
`current()`, `history()`, `ofType()`.

## Manuelle Pflege (CRUD) & Prüflauf

`RecordController`:

| Methode                                            | Zweck                             |
|----------------------------------------------------|-----------------------------------|
| `index(Request)`                                   | Rekordliste (mit Filtern)         |
| `show(SwimRecord)`                                 | Detailansicht inkl. Historie      |
| `createManual()` / `storeManual(Request)`          | Rekord manuell anlegen            |
| `edit(SwimRecord)` / `update(Request, SwimRecord)` | bearbeiten                        |
| `destroy(SwimRecord)`                              | löschen                           |
| `restore(SwimRecord)`                              | (Soft-)Wiederherstellung          |
| `checkMeet(Meet)`                                  | Prüflauf über einen Meet anstoßen |
| `importForm()` / `import(Request)`                 | Import (Delegation)               |
| `export(Request)`                                  | Export (Delegation)               |

## LENEX-Import — `RecordImportService`

Importiert LENEX-3.0-Rekorddateien (`.lxf` oder `.xml`) in drei Schritten:
`parse()` (Datei lesen, Rekorde und unbekannte Entities extrahieren) →
`preview()` (Vorschau mit unbekannten Vereinen/Athleten für die Bestätigungsseite) → `import()` (nach Bestätigung:
Vereine/Athleten anlegen, Rekorde speichern).

Regeln und Eigenheiten:

- `swimtime = "NT"` wird übersprungen.
- LENEX-Typ `AUT.JG` wird auf `AUT.JR` gemappt (`TYPE_MAP`).
- **Athleten-Matching**: `license`, sonst Name + Geburtsdatum + Geschlecht.
- **Vereins-Matching**: `code` + Nation, sonst Name + Nation. Vereine mit
  `name = "???"` oder leerem Schlüssel werden ignoriert.
- **Staffeln**: `RELAY > CLUB` und `RELAYPOSITIONS > RELAYPOSITION > ATHLETE`; Team landet in `relay_team_members`,
  `club_id` = Verein zum Zeitpunkt des Rekords.
- **Zeitparsing**: LENEX `HH:MM:SS.cs` / `MM:SS.cs` → Hundertstelsekunden.
- **Stroke-Mapping**: FREE/BACK/BREAST/FLY/MEDLEY → gleichnamiger `lenex_code`.

**Nationalitätsprüfung beim Import** (Club-Nation als Indikator):

| LENEX `<CLUB nation>` | Verhalten                                           |
|-----------------------|-----------------------------------------------------|
| `AUT`                 | Import als `APPROVED`                               |
| fehlt/leer            | Import als `PENDING`, in `pending_records` gelistet |
| ≠ `AUT`               | Rekord wird übersprungen                            |

`import()` erhält die in der Vorschau getroffenen Entscheidungen als Parameter (`$approvedClubs`, `$approvedAthletes`,
`$newClubData`, `$newAthleteData`,
`$approvedRegional`, `$approvedPending`), jeweils mit Werten wie
`club_id` / `'new'` / `'skip'` bzw. `'import'` / `'skip'`.

Der HTTP-Ablauf (`RecordImportController`): `showForm()` → `preview(Request)`
→ `run(Request)`.

## LENEX-Export — `RecordLenexExportService`

`build(...)` erzeugt aus den (gefilterten) Rekorden ein LENEX-Dokument; die Auslieferung als Download übernimmt
`RecordExportController`
(`showForm()` → `download(Request)`).

## Routen

Alle unter `auth`, Prefix `records`:

| Route                                                                               | Name                                                               | Aktion             |
|-------------------------------------------------------------------------------------|--------------------------------------------------------------------|--------------------|
| `GET /records`                                                                      | `records.index`                                                    | Liste              |
| `GET /records/create` · `POST /records`                                             | `records.create` · `records.store`                                 | manuell anlegen    |
| `GET /records/import` · `POST /records/import/preview` · `POST /records/import/run` | `records.import` · `records.import.preview` · `records.import.run` | Import             |
| `GET /records/export` · `POST /records/export/download`                             | `records.export` · `records.export.download`                       | Export             |
| `POST /records/check/{meet}`                                                        | `records.check`                                                    | Prüflauf über Meet |
| `GET /records/{record}/edit` · `PUT /records/{record}`                              | `records.edit` · `records.update`                                  | bearbeiten         |
| `GET /records/{record}` · `DELETE /records/{record}`                                | `records.show` · `records.destroy`                                 | Detail/Löschen     |
| `POST /records/{record}/restore`                                                    | `records.restore`                                                  | wiederherstellen   |

## Genehmigungs-Workflow (Zusammenfassung)

1. Ein Ergebnis eines AUT-Athleten, das schneller als der aktuelle Rekord ist, wird als **APPROVED** angelegt und löst
   den Vorgänger sofort ab.
2. Ist die Nationalität unklar (`nation = null` bzw. Club-Nation fehlt beim Import), entsteht ein **PENDING**-Rekord,
   der den aktuellen Rekord noch **nicht** ablöst und in `pending_records` zur Bestätigung erscheint.
3. Nicht-AUT-Ergebnisse werden gar nicht als AUT-Rekord angelegt.

## Tests

- `tests/Unit/RecordCheckerServiceTest.php` — Rekord-Erkennung, Nationalitäts- und Ablöselogik.
- `tests/Unit/RelayClassValidatorTest.php` — Staffelklassen-Auflösung (auch von der Staffel-Rekordprüfung genutzt).
- Die Rekordstatistik ist in `docs/specs/statistics.md` beschrieben (`RecordStatisticsService`, Abgrenzung über
  `set_date`).
