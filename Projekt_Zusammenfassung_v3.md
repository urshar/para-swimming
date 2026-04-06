# Para Swimming NatDB — Projektzusammenfassung v4

## Tech Stack

- Laravel 13, Livewire Starter Kit, Flux UI, Tailwind 4, Alpine.js, MySQL, PHP 8.4
- IMask.js für Zeitformat-Eingaben (MM:SS.cs)
- Blade-Views: `@extends('layouts.app')` + `@section('content')` — KEIN `<x-layouts.app>`
- Flux UI Komponenten: `<flux:button>`, `<flux:field>`, `<flux:label>`, `<flux:select>` etc. — KEIN `x-flux::` Namespace

---

## Domäne

Österreichische Para-Schwimm Rekordverwaltung (ÖBSV).
Rekordtypen: National (AUT), Jugend (AUT.JR), regional (AUT.WBSV etc.), International (WR/ER/OR).
Sportklassen: S1–S21, SB1–SB14, SM1–SM14, Staffel-Klassen (49, 34 etc.)
Staffeln haben `relay_count > 1`, Einzel `relay_count = 1`.

---

## Abgeschlossene Aufgaben (Session 1 — aus v3)

### Datenbank-Migrationen

| Datei                         | Inhalt                                                                             |
|-------------------------------|------------------------------------------------------------------------------------|
| `000003b`                     | `regional_association` Enum zu `clubs`                                             |
| `000003c`                     | `lenex_club_id` entfernt, `VERBAND` zu Type-Enum                                   |
| `000009c`                     | `is_junior_record`, `is_regional_record`, `is_regional_junior_record` zu `results` |
| `000010` (swim_records)       | `club_id` direkt in der Create-Migration                                           |
| `000011` (relay_team_members) | Neue Tabelle für Staffelmitglieder                                                 |

### Models

- **Club** — `REGIONAL_ASSOCIATIONS` Konstante (9 Verbände), `regional_record_type` Accessor
- **SwimRecord** — `club_id` in `$fillable`, `club()` BelongsTo, `relayTeam()` HasMany (ordered by position)
- **RelayTeamMember** — position, first/last_name, birth_date, gender, athlete_id

### Services

- **RecordCheckerService** — prüft AUT, AUT.JR, AUT.WBSV, AUT.WBSV.JR etc.
- **RecordImportService** — importiert LENEX 3.0 Rekord-LXF/XML
    - `preview()` → `import()` Flow
    - `AUT.JG` → `AUT.JR` Mapping, NT-Einträge werden übersprungen
    - Sportklasse: BREAST→SB, MEDLEY→SM, sonst→S
- **LenexResolverService** — Club-Matching über `code+nation` → `name+nation`

### Wichtige Entscheidungen

- `lenex_club_id` nicht persistiert (instabil)
- `lenex_athlete_id` nicht persistiert (nur Memory-Cache pro Import)
- `club_id` auf `swim_records`: Verein **zum Zeitpunkt des Rekords**
- Staffeln haben `athlete_id = null`, Club + Team via `relay_team_members`

---

## Abgeschlossene Aufgaben (Session 2 — Rekord-Export)

### Neue Dateien

| Datei                            | Pfad                                   |
|----------------------------------|----------------------------------------|
| `RecordLenexExportService.php`   | `app/Services/`                        |
| `RecordExportController.php`     | `app/Http/Controllers/`                |
| `export.blade.php` (Rekorde-Tab) | in `lenex/export.blade.php` integriert |

### RecordLenexExportService

Exportiert SwimRecords als LENEX 3.0 XML.

**LENEX-Struktur:**

```xml

<LENEX version="3.0">
    <CONSTRUCTOR name="Para Swimming NatDB" version="1.0">
        <CONTACT name="a-timing.wien" email="a.steiner@a-timing.wien"/>
    </CONSTRUCTOR>
    <RECORDLISTS>
        <RECORDLIST type="AUT" course="LCM" gender="M" handicap="14"
                    nation="AUT" updated="2024-05-01">
            <RECORDS>
                <RECORD swimtime="00:00:58.34">
                    <SWIMSTYLE distance="100" relaycount="1" stroke="FREE"/>
                    <MEETINFO name="ÖSTM" city="Wien" date="2024-05-01" nation="AUT"/>
                    <ATHLETE lastname="..." firstname="..." birthdate="..." gender="M">
                        <CLUB name="..." code="..." nation="AUT"/>
                    </ATHLETE>
                </RECORD>
                <!-- Staffel: -->
                <RECORD swimtime="00:02:31.96">
                    <SWIMSTYLE distance="50" relaycount="4" stroke="FREE"/>
                    <RELAY>
                        <CLUB name="..." code="..." nation="AUT"/>
                        <RELAYPOSITIONS>
                            <RELAYPOSITION number="1">
                                <ATHLETE lastname="..." firstname="..." birthdate="..." gender="M"/>
                            </RELAYPOSITION>
                        </RELAYPOSITIONS>
                    </RELAY>
                </RECORD>
            </RECORDS>
        </RECORDLIST>
    </RECORDLISTS>
</LENEX>
```

**Gruppierung:** Eine RECORDLIST pro `(record_type, course, gender, sport_class)`.
`handicap` = Klassenziffer (z.B. S14 → "14"), `nation` aus record_type abgeleitet (AUT.* → "AUT").
`updated` = Max(`set_date`) aller Records der Gruppe.

**Filter:**

- `is_current = true`
- `record_status` NOT IN `['INVALID', 'TARGETTIME']`
- `swim_time IS NOT NULL` und `swim_time > 0` (kein NT / 00:00.00)

**Download-Format:** XML wird als `.lef` in ein ZIP verpackt → Download als `.lxf`

### RecordExportController

```
GET  /records/export          → showForm()
POST /records/export/download → download()
```

Kategorien: `national` (AUT+AUT.JR), `regional` (AUT.XXXX+AUT.XXXX.JR), `international` (WR/ER/OR), `custom`.
Filter: `courses[]` (LCM/SCM/SCY, leer=alle), `gender` (M/F, leer=beide).
`$gender = (string) $request->input('gender', '')` — Cast nötig, leerer Radio-Value kommt als `null`.

### LenexExportController (geändert)

`showForm()` übergibt jetzt zusätzlich `'regionalTypes' => Club::REGIONAL_ASSOCIATIONS` an die View.

### lenex/export.blade.php (geändert)

Tab-Switcher (Alpine.js `x-data="{ tab: 'meet' }"`):

- Tab **Wettkampf**: bestehender Meet-Export unverändert → `POST lenex.export.download`
- Tab **Rekorde**: neues Formular → `POST records.export.download`

### Routes (geändert)

```php
// Ersetzte: Route::post('export', ...) im records-Block
Route::get('export', [RecordExportController::class, 'showForm'])->name('export');
Route::post('export/download', [RecordExportController::class, 'download'])->name('export.download');
```

`use App\Http\Controllers\RecordExportController;` zum use-Block in `web.php` hinzufügen.
Die alte `export()`-Methode im `RecordController` kann gelöscht werden.

---

## Offene Punkte

- `records/import-preview.blade.php` — `StrokeType::find()` in der Blade-View ist nicht ideal, besser per eager load im
  Controller lösen
- Export: Splits werden exportiert, aber noch nicht geprüft ob `TimeParser::format()` mit `split_time` identisch zu
  `swim_time` funktioniert

---

## Routes (vollständig, Rekorde)

```php
GET  /records                    → records.index
GET  /records/create             → records.create
POST /records                    → records.store
GET  /records/import             → RecordImportController@showForm
POST /records/import/preview     → RecordImportController@preview
POST /records/import/run         → RecordImportController@run
GET  /records/export             → RecordExportController@showForm
POST /records/export/download    → RecordExportController@download
POST /records/check/{meet}       → RecordController@checkMeet
GET  /records/{record}           → records.show
GET  /records/{record}/edit      → records.edit
PUT  /records/{record}           → records.update
DELETE /records/{record}         → records.destroy
POST /records/{record}/restore   → records.restore
```

## Routes (LENEX)

```php
GET  /lenex/import               → LenexImportController@showForm
POST /lenex/import               → LenexImportController@import
GET  /lenex/import/confirm-meet  → LenexImportController@confirmMeet
POST /lenex/import/run           → LenexImportController@runImport
GET  /lenex/import/review        → LenexImportController@review
POST /lenex/import/resolve-clubs → LenexImportController@resolveClubs
POST /lenex/import/resolve-athletes → LenexImportController@resolveAthletes
GET  /lenex/export               → LenexExportController@showForm
POST /lenex/export/download      → LenexExportController@download
```
