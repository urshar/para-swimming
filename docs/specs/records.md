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

`checkMeet(Meet)` lädt die gültigen Ergebnisse eines Meets (Status leer) sowie die Starts außer Konkurrenz (`EXH`) (mit
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

**Außer Konkurrenz** (Ergebnisstatus `EXH`, Einzel und Staffel): Es werden alle Rekordtypen geprüft (AUT, AUT.JR,
regional, regional JR), neue Rekorde aber als **PENDING** angelegt (Grund `RecordCheckerService::PENDING_EXHIBITION`).
Unklare Nationalität prüft weiterhin nur den Nationalrekord. Ein erneuter Rekord-Check legt aus demselben Ergebnis
keinen Rekord desselben Typs doppelt an.

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
Erst mit der Genehmigung wird der Vorgänger historisiert: Wechselt der Status von PENDING auf APPROVED
(Rekordbearbeitung oder Status-Schnelländerung in der Liste), ruft `RecordController` `SwimRecord::approve()` auf. Das
löst alle geltenden, langsameren Rekorde derselben Kategorie ab und setzt das Rekord-Flag am Ergebnis; ist inzwischen
ein schnellerer Rekord anerkannt, wandert der bestätigte direkt in die Historie (`APPROVED.HISTORY`).

### Jugend- und Regionalrekorde

- **Jugend**: Einzel — `Wettkampfjahr − Geburtsjahr ≤ 18`; Staffel — alle Mitglieder mit bekanntem Geburtsdatum ≤ 18
  (`RelayClassValidator::isJuniorRelay`). AUT.JR wird als APPROVED angelegt (bei `EXH` als PENDING).
- **Regional**: aus `club.regional_record_type` des **Vereins im Ergebnis** (Verein zum Zeitpunkt des Starts), nur
  ohne Verein im Ergebnis aus dem Verein des Athleten; jeweils Basis- und JR-Variante. Ein Verein ohne Landesverband
  (z. B. ÖBSV als Nationalteam) ergibt keinen Regionalrekord. Bis `fix/regional-record-club` (07.10.2026) kam der
  Verband aus dem *aktuellen* Verein des Athleten — falsch zugeordnete Altfälle findet die Import Prüfliste.
  Kärnten heißt seit 27.07.2026 `KBSV` (vorher `KLSV`); alte `AUT.KLSV*`-Rekorde hat die Migration
  `2026_10_07_100002_rename_klsv_regional_record_types` umbenannt.

### Staffelrekorde — `checkRelayResult`

Geprüft werden die Staffelergebnisse (`relay_results`, siehe [meet-results.md](meet-results.md)); die Mitglieder sind
die eingesetzten Schwimmer des Ergebnisses (`relay_result_members`). Kein Rekord entsteht, wenn

- nicht alle Positionen besetzt sind oder die Zusammensetzung nicht zur Wertung passt
  (`RelayResult::hasRecordComposition()`: Herren nur Männer, Damen nur Frauen, Mixed gleich viele von beiden). Eine
  **Herrenstaffel mit Damenbeteiligung** (3 + 1, 1 + 3) ist ein gültiges Ergebnis in der Herrenwertung, kann aber
  **keinen ÖR, ÖJR oder Regionalrekord** aufstellen;
- ein Schwimmer am Starttag nicht zum Staffelverein gehörte (z. B. gemischte Staffel, AK-Staffel mit vereinsfremdem
  Schwimmer) — siehe "Vereinszugehörigkeit am Starttag" unten;
- ein Mitglied keinem Athleten zugeordnet ist;
- ein Athlet eine andere Nation als AUT hat;
- sich über `RelayClassValidator` keine gültige Staffelklasse ergibt (`resolveRelayClass`).

Das Rekord-Geschlecht ist die Wertung der Staffel (M, F, X). Bei Erfolg werden die Teammitglieder als
`RelayTeamMember` (Position, Name, Geburtsdatum, optional `athlete_id`) gespeichert; der Rekord verweist über
`swim_records.relay_result_id` auf das Staffelergebnis (Einzelrekorde über `result_id`), die Bestätigung eines
ausstehenden Rekords setzt das Flag am Staffelergebnis.

**Vereinszugehörigkeit am Starttag** (gilt für ÖR, ÖJR und Regionalrekorde offen/Jugend): Alle Mitglieder müssen am
Starttag beim Staffelverein gewesen sein, nicht heute — nach einem Vereinswechsel bleibt eine alte Staffel
rekordfähig. Je Mitglied entscheidet die erste vorhandene Quelle:

1. **Einzelergebnisse im selben Wettkampf** (`results.club_id`) — der stärkste Beleg;
2. sonst die **Vereins-History** (`athlete_club_history`) mit einem Eintrag, der das Wettkampfdatum abdeckt;
3. sonst der **heutige Verein**: stimmt er mit dem Staffelverein überein, zählt das Mitglied; sonst ist die
   Zugehörigkeit unbelegt und der Rekord wird **PENDING** angelegt (Grund `RecordCheckerService::PENDING_CLUB`), der
   Verband bestätigt ihn wie einen AK-Rekord.

Spricht ein Beleg (Stufe 1 oder 2) für einen anderen Verein, entsteht kein Rekord.

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
- **Athleten-Matching**: `license`, sonst Name + Geburtsdatum + Geschlecht. Der Namensvergleich ist
  normalisiert (Unicode-Kleinschreibung, Leerraum um Bindestriche entfernt: "Weber-Treiber" ↔
  "Weber - Treiber"), das Geburtsdatum wird per portablem `whereDate()` verglichen.
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

## Import-Vorschau — Vorschläge & Vorbelegung

Nicht exakt gefundene Athleten/Vereine werden in der Vorschau (`records/import-preview.blade.php`) nicht
nur als "unbekannt" gelistet, sondern mit **Zuordnungs-Vorschlägen** versehen — nie automatisch
übernommen, nur als (ggf. vorbelegte) Auswahl im Dropdown.

- **Athleten (`suggestAthletes()`)**: LENEX-Rekordfiles tragen bei unbekanntem Tag/Monat oft
  `JJJJ-01-01` oder ein leeres Geburtsdatum, wodurch der exakte Match scheitert. Kandidaten dann über
  Name + Geschlecht + **Geburtsjahr** (portabel `SUBSTR(birth_date, 1, 4)`), bei leerem Datum über
  Name + Geschlecht. **Vorbelegung** nur bei genau einem Jahr-Treffer (leeres Datum: nie). Im Dropdown
  wird das **volle Geburtsdatum** des Kandidaten gezeigt, damit die Abweichung erkennbar ist.
- **Vereine (`suggestClubs()`)**: Kandidaten über normalisierten Namen/Kurznamen bzw. Code exakt
  (Nation ignoriert) oder mehrwortiges Wortgrenzen-Präfix ("Flying Flippers Schwimmteam" ↔
  "Flying Flippers"). **Vorbelegung** nur bei genau einem Treffer.
- **Namens-Normalisierung (`normalizeName()`)**: Unicode-Kleinschreibung, Leerraum um Bindestriche
  entfernt, sonstiger Leerraum kollabiert — greift auch im exakten Athleten-Match.
- **Live-Vereinsname**: Die Vereins-Auswahl im Abschnitt "Unbekannte Vereine" ist per Alpine
  (`recordImportPreview`, `resources/js/record-import-preview.js`) mit der Namensanzeige bei den
  unbekannten Athleten verknüpft — eine (auch vorbelegte) Zuordnung aktualisiert den dort gezeigten
  Vereinsnamen sofort.

## Prüfliste nach dem Import — `RecordImportReviewService`

Seit `feature/record-import-review` (07.10.2026). Gespeichert in `import_review_items` (Model `ImportReviewItem`),
abzuarbeiten unter **Rekorde → Import Prüfliste** (`records.import-review.*`, nur Admin; der Menüpunkt zeigt die
Zahl offener Einträge). Vier Arten:

- **Vereinskonflikt (`club_conflict`)**: Der Verein laut Rekord weicht vom Stammverein (`Athlete::club_id`) ab.
  Maßgeblich ist je Athlet der **jüngste Einzelrekord**; nur ohne Einzelrekord der jüngste **Staffelrekord** — dabei
  zählen die in der DB gefundenen Staffelmitglieder, und nur bei nationalen/regionalen Rekorden (`AUT*`), weil
  internationale Staffeln als Nationalteam schwimmen. Rekorde für einen **Verband** (`Club::TYPE_VERBAND`, z. B. der
  ÖBSV als Nationalteam) zählen gar nicht.
  **Relevant** (vorbelegt und in die Liste) ist ein Konflikt nur, wenn der Athlet nach dem Rekord nicht schon
  nachweislich für den aktuellen Verein angetreten ist: weder Eintritt (`athlete_club_history.joined_at`) noch ein
  Wettkampfergebnis beim aktuellen Verein liegt nach dem Rekorddatum. Die Vereins-History ist im Bestand fast leer, die
  Ergebnisse tragen dagegen immer einen Verein.
- **Geburtsdatum abweichend (`year_match`)**: Ein unbekannter Athlet aus der Datei wurde in der Vorschau einer
  bestehenden Person mit anderem Geburtsdatum zugeordnet (typisch: Jahrgang mit `-01-01`, siehe oben). Nur zur
  Kontrolle; Aktion "Geprüft".
- **Nationalität nicht AUT (`nationality`)**: AUT- oder Regional-Einzelrekord (aktuell oder historisch) eines Athleten
  mit bekannter, anderer Nationalität — darf es nicht geben (siehe Nationalitätsregel oben), entsteht typisch, wenn die
  Nationalität nach der Rekordprüfung korrigiert wird. Aktion "Rekord entfernen" (`SwimRecord::removeFromHistory()`):
  löscht den Rekord, verknüpft Vorgänger und Nachfolger direkt bzw. macht den Vorgänger wieder aktuell und setzt das
  Rekord-Flag am Ergebnis zurück. Ist die Nationalität falsch eingetragen: ignorieren und beim Athleten korrigieren.
  Solche Athleten bekommen keinen Vereinskonflikt.
- **Regionalrekord: falscher Verband (`regional_mismatch`)**: Einzel-Regionalrekord, dessen Verband nicht zum
  Landesverband des Rekord-Vereins (`swim_records.club_id`) passt — Altfälle aus der Zeit vor
  `fix/regional-record-club`. Vereine ohne Landesverband und unbekannte Typen (z. B. `AUT.IND`) werden nicht bewertet.
  Aktion "Rekord entfernen"; danach auf dem Wettkampf (verlinkt) "Rekorde prüfen" erneut starten, damit der richtige
  Regionalrekord entsteht — bei mehreren Wettkämpfen in zeitlicher Reihenfolge.

**Ablauf beim Import:** Die Vorschau zeigt Konflikte bekannter Athleten/Vereine im Abschnitt "Vereinskonflikte" mit
einer Checkbox je Athlet (`club_updates[athlete_id] = club_id`, vorbelegt = relevant). Angehakte werden beim Import
übernommen — Vereinswechsel über `AthleteClubTransferService` (History-Eintrag ab Rekorddatum, wie auf der
Athletenseite) und als "übernommen" protokolliert. Alle übrigen relevanten Konflikte (auch die erst beim Import
aufgelösten Athleten/Vereine) landen offen in der Liste. Dasselbe Vereinspaar je Athlet wird nur einmal geführt; ein
ignorierter Eintrag kommt nicht wieder.

**Bestand prüfen** (`scanExisting()`): wendet dieselben Regeln auf alle gespeicherten Rekorde und Staffelmitglieder an
und nimmt neue relevante Konflikte und Nationalitäts-Befunde offen auf (Quelle "Bestandsprüfung"). Beim Import werden
nur die neu angelegten Rekorde auf die Nationalität geprüft.

## Zusammenführung der ÖBSV-Typen — `NationalRecordMergeService`

Das ÖBSV-Rekordfile verwendet `AUT.IND` / `AUT.REL` (nationale Einzel- bzw. Staffelrekorde) und `AUT.IND.JG` /
`AUT.REL.JG` (Jugend). Der Import ordnet sie seit `fix/merge-national-record-types` (07.10.2026) über
`RecordImportService::TYPE_MAP` direkt `AUT` bzw. `AUT.JR` zu. Den Bestand aus dem Import vom 19.09.2026 führt der
Befehl `php artisan records:merge-national-types` (erst mit `--dry-run`) zusammen. Er schreibt immer einen
CSV-Bericht nach `storage/app/private/record-merge/` (je Rekord: Aktion, Grund, Typ alt/neu, aktuell vorher/nachher).

Regeln je Kategorie (Zieltyp, Schwimmart, Sportklasse, Geschlecht, Bahn, Strecke, Staffelgröße):

- **Nur Import-Rekorde:** Typ umbenennen, die Kette aus der Datei bleibt.
- **Doppelt** (gleicher Athlet bzw. Staffelverein, gleiche Zeit, Datum darf abweichen): Der Rekord aus dem Ergebnis
  bleibt (Verknüpfung zum Ergebnis), der Import-Rekord entfällt.
- **Kette nach Datum** (gleicher Tag: langsamere zuerst): Ein Rekord bleibt nur, wenn er schneller ist als alles davor;
  Gleichstand zählt nicht (wie bei Rekordprüfung und Import).
- **ÖBSV-Liste maßgeblich bis zu ihrem Stand** (jüngstes Datum der Import-Rekorde, 19.05.2019): Ein Ergebnis-Rekord
  aus dieser Zeit, der schneller ist als der beste offizielle Rekord der Kategorie, wird entfernt — von der Liste nicht
  anerkannt (z. B. Nationalität, Klassifizierung).
- Entfernte Rekorde werden gelöscht (Splits/Staffelmitglieder mit), ihr Rekord-Flag am Ergebnis zurückgesetzt; die
  Kette wird neu verknüpft, genau ein aktueller Rekord (`APPROVED`, sonst `APPROVED.HISTORY`).
- Kategorien ohne Import-Rekord und Regionalrekorde bleiben unverändert.

Probelauf auf Dev (07.10.2026): 554 Kategorien, 703 Rekorde entfernt (510 Ergebnis-Rekorde ohne echten Rekord, 117
Doppelte, 72 nicht in der Liste, 4 Import-Einträge ohne Verbesserung), in 203 Kategorien wechselt der aktuelle Rekord.

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
| `GET /records/import-review` · `POST …/scan` · `POST …/{item}/apply` · `…/ignore`   | `records.import-review.index` · `.scan` · `.apply` · `.ignore`     | Prüfliste (Admin)  |
| `GET /records/{record}/edit` · `PUT /records/{record}`                              | `records.edit` · `records.update`                                  | bearbeiten         |
| `GET /records/{record}` · `DELETE /records/{record}`                                | `records.show` · `records.destroy`                                 | Detail/Löschen     |
| `POST /records/{record}/restore`                                                    | `records.restore`                                                  | wiederherstellen   |

## Genehmigungs-Workflow (Zusammenfassung)

1. Ein Ergebnis eines AUT-Athleten, das schneller als der aktuelle Rekord ist, wird als **APPROVED** angelegt und löst
   den Vorgänger sofort ab.
2. Ist die Nationalität unklar (`nation = null` bzw. Club-Nation fehlt beim Import), entsteht ein **PENDING**-Rekord,
   der den aktuellen Rekord noch **nicht** ablöst und in `pending_records` zur Bestätigung erscheint.
   Ebenso bei Starts außer Konkurrenz (`EXH`) — dort für alle Rekordtypen. Bestätigen = Status auf APPROVED setzen.
3. Nicht-AUT-Ergebnisse werden gar nicht als AUT-Rekord angelegt.

## Tests

- `tests/Unit/RecordCheckerServiceTest.php` — Rekord-Erkennung, Nationalitäts- und Ablöselogik.
- `tests/Unit/RelayClassValidatorTest.php` — Staffelklassen-Auflösung (auch von der Staffel-Rekordprüfung genutzt).
- `tests/Feature/RelayRecordClubTest.php` — Vereinszugehörigkeit der Staffelmitglieder am Starttag.
- `tests/Feature/RecordImportReviewTest.php` — Vereinskonflikte (Vorschau, Import, Staffeln, Verbände, Relevanz),
  Geburtsdatums-Kontrolle, Nationalität nicht AUT (inkl. Neu-Verknüpfung der Historie), Bestandsprüfung und Aktionen
  der Prüfliste.
- Die Rekordstatistik ist in `docs/specs/statistics.md` beschrieben (`RecordStatisticsService`, Abgrenzung über
  `set_date`).
