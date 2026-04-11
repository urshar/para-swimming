# Para Swimming NatDB — Projektzusammenfassung (Stand: April 2026)

## Tech Stack
- Laravel 13, Livewire Starter Kit, Flux UI, Tailwind 4, Alpine.js, MySQL, PHP 8.4
- IMask.js für Zeitformat-Eingaben (MM:SS.cs)
- Pest 3 für Tests

---

## Domäne
Österreichische Para-Schwimm Rekordverwaltung (ÖBSV).
Rekordtypen: National (AUT), Jugend (AUT.JR), Regional (AUT.WBSV etc.), International (WR/ER/OR).
Sportklassen: S1–S21, SB1–SB21, SM1–SM21, Staffel-Klassen (S20, S34, S49, S21, S14, S15).
Staffeln haben `relay_count > 1`, Einzel `relay_count = 1`.

---

## Models (relevante Felder)

### Nation
```php
$fillable = ['code', 'name_de', 'name_en', 'is_active']
// code = 'AUT', name_de = 'Österreich', name_en = 'Austria'
```

### Club
```php
$fillable = ['name', 'short_name', 'code', 'nation_id', 'type', 'regional_association', 'swrid']
// type Enum: CLUB, VERBAND
// regional_association Enum: WBSV, BBSV, KLSV, NOEVSV, OOEBSV, SBSV, STBSV, TBSV, VBSV
const REGIONAL_ASSOCIATIONS = [
    'BBSV'   => 'Burgenländischer Behindertensportverband',
    'KLSV'   => 'Kärntner Behindertensportverband',
    'NOEVSV' => 'Niederösterreichischer Versehrtensportverband',
    'OOEBSV' => 'Oberösterreichischer Behindertensportverband',
    'SBSV'   => 'Salzburger Behindertensportverband',
    'STBSV'  => 'Steirischer Behindertensportverband',
    'TBSV'   => 'Tiroler Behindertensportverband',
    'VBSV'   => 'Vorarlberger Behindertensportverband',
    'WBSV'   => 'Wiener Behindertensportverband',
]
// Accessor: regional_record_type → 'AUT.WBSV' etc.
```

### Athlete
```php
$fillable = ['first_name', 'last_name', 'gender', 'birth_date', 'nation_id', 'club_id', 'license']
// SoftDeletes
```

### AthleteSportClass
```php
$fillable = ['athlete_id', 'category', 'class_number', 'sport_class',
             'classification_scope', 'classification_status', 'frd_year']
// category: S, SB, SM
```

### StrokeType
```php
$fillable = ['name_de', 'name_en', 'lenex_code', 'code']
// lenex_code: FREE, BACK, BREAST, FLY, MEDLEY
```

### SwimRecord
```php
$fillable = ['stroke_type_id', 'nation_id', 'athlete_id', 'club_id', 'result_id',
             'superseded_by_id', 'supersedes_id', 'record_type', 'sport_class',
             'gender', 'course', 'distance', 'relay_count', 'swim_time',
             'record_status', 'is_current', 'set_date', 'meet_name', 'meet_city', 'meet_course',
             'comment']
// record_status: APPROVED, PENDING, INVALID, APPROVED.HISTORY, PENDING.HISTORY, TARGETTIME
// Staffeln: athlete_id = null, club_id gesetzt, relayTeam() HasMany RelayTeamMember
// markAsSupersededBy(SwimRecord $new): setzt is_current=false, superseded_by_id
```

### RelayTeamMember
```php
$fillable = ['swim_record_id', 'position', 'first_name', 'last_name', 'birth_date', 'gender', 'athlete_id']
```

### Meet
```php
// start_date (Carbon), course: LCM|SCM|SCY
// results() HasMany Result
```

### Result
```php
// athlete_id NOT NULL (Staffeln bekommen Placeholder-Athleten in Tests)
// club_id, swim_event_id, sport_class, swim_time, status
// Flags: is_national_record, is_junior_record, is_regional_record, is_regional_junior_record
```

### Entry
```php
// meet_id, swim_event_id, athlete_id, club_id, sport_class
// Staffelmitglieder = Entries desselben Events + Clubs
```

### SwimEvent
```php
// meet_id, stroke_type_id, distance, relay_count, gender, session_number, event_number, round
```

---

## Services

### RecordCheckerService (`app/Services/RecordCheckerService.php`)
`readonly class` mit Constructor Injection `RelayClassValidator`.

**`checkMeet(Meet $meet): array`**
- Lädt alle gültigen Results des Meets
- Einzelrekorde → `checkResult()`, Staffeln (`relay_count > 1`) → `checkRelayResult()`
- Gibt `['new_records' => [...], 'pending_records' => [...], 'checked' => int]` zurück

**`checkResult()`** — Einzelrekorde:
- Nationalitätsprüfung: AUT → APPROVED, null → PENDING, sonst → skip
- Prüft AUT, AUT.JR, AUT.WBSV, AUT.WBSV.JR
- Jugend: `Wettkampfjahr − Geburtsjahr ≤ 18`

**`checkRelayResult()`** — Staffeln:
- Lädt Entries desselben Events + Clubs (= Staffelmitglieder)
- Validiert Sportklassen-Kombination via `RelayClassValidator`
- Prüft AUT, AUT.JR, AUT.WBSV, AUT.WBSV.JR
- Speichert Staffelmitglieder als `RelayTeamMember` beim neuen SwimRecord

**`checkRecordType()`** — privat, legt SwimRecord an:
- Parameter `?int $athleteId = null` — Einzelrekorde übergeben `$result->athlete_id`, Staffeln `null`

### RelayClassValidator (`app/Services/RelayClassValidator.php`)
Validiert Staffel-Sportklassen-Kombinationen:

| Klasse | Regel |
|---|---|
| S21 (Trisomie) | Alle Mitglieder S21. Nur national gültig (kein WR/ER/OR) |
| S14 (Intellectual) | Nur S14 und/oder S21 |
| S15 (Deaf) | Alle S15 |
| S49 (Visual) | Nur S11, S12, S13 |
| S20 (Physical) | Nur S1–S10, Summe ≤ 20 |
| S34 (Physical) | Nur S1–S10, Summe 21–34 |
| > 34 | Ungültig → null |

```php
resolveRelayClass(array $memberClasses): ?string
extractMemberClasses(Collection $entries, $event): array
isJuniorRelay(Collection $entries, int $meetYear): bool
isNationalOnlyClass(string $relayClass): bool  // true für S21
```

### RecordImportService (`app/Services/RecordImportService.php`)
Importiert LENEX 3.0 Rekord-LXF/XML.

**Flow:** `preview()` → `import()`

**`preview()` gibt zurück:**
```php
[
    'records'          => [...],  // nationale/internationale Rekorde
    'regional_records' => ['WBSV' => [...], ...],  // nach Verband gruppiert
    'pending_records'  => [...],  // Club-Nation fehlt/unklar (pending_key)
    'unknown_clubs'    => [...],
    'unknown_athletes' => [...],
    'skipped'          => int,
]
```

**`import()` Parameter:**
```php
import(string $filePath, array $approvedClubs, array $approvedAthletes,
       array $newClubData, array $newAthleteData,
       array $approvedRegional = [],   // ['WBSV' => 'import'|'skip']
       array $approvedPending = [])    // ['pending_key' => 'import'|'skip']
// Gibt zurück: ['imported' => int, 'skipped' => int, 'regional_auto' => int]
```

**Nationalitätsprüfung beim Import:**
- Club `nation="AUT"` → APPROVED
- Club `nation=""` (fehlt) → PENDING (in pending_records)
- Club `nation != "AUT"` → überspringen
- S21 + WR/ER/OR → überspringen (S21 nur national gültig)

**Regionalrekorde automatisch:**
- Nach nationalem Einzel-Import: `checkAndImportRegionalRecord()` — prüft Club.regional_association
- Nach nationalem Staffel-Import: `checkAndImportRegionalRecordForRelay()` — Jugend-Check über relay_members
- Gemeinsam genutzt: `createRegionalRecord()` (kein Duplikat-Code)

**Wichtige Konstanten:**
```php
TYPE_MAP = ['AUT.JG' => 'AUT.JR']
STROKE_MAP = ['FREE', 'BACK', 'BREAST', 'FLY', 'MEDLEY']
// Sportklasse-Prefix: BREAST→SB, MEDLEY/IMRELAY→SM, sonst→S
```

---

## Controllers

### RecordController (`app/Http/Controllers/RecordController.php`)
```php
checkMeet(Meet $meet): RedirectResponse
// Ruft checker->checkMeet($meet) auf
// Speichert Ergebnis als IDs in Session (nicht als Objekte — Serialisierung!)
// Session: ['checked' => int, 'new_record_ids' => [['id','types']], 'pending_record_ids' => [['id','athlete_name']]]
```

### RecordImportController (`app/Http/Controllers/RecordImportController.php`)
```
GET  /records/import          → showForm()
POST /records/import/preview  → preview()
POST /records/import/run      → run()
// run() nimmt: clubs[], athletes[], new_clubs[], new_athletes[], regional[], pending[]
```

---

## Views

### `resources/views/records/`
- **`import.blade.php`** — Drag-and-Drop Zone (wie LENEX-Import), Alpine.js
- **`import-preview.blade.php`** — `<details>`/`<summary>` statt Alpine für Klapp-Sektionen
  - Unbekannte Clubs: `flux:select` Dropdown (Neu anlegen/Überspringen)
  - Unbekannte Athleten: `flux:select` Dropdown
  - Ausstehende Rekorde (Nation unklar): Radio import/skip, Standard: skip
  - Regionale Rekorde: pro Verband Radio import/skip, Standard: skip
- **`check-result.blade.php`** — Partial für `meets/show`
  - Lädt SwimRecords per ID aus DB (Session enthält nur IDs, keine Objekte)
  - Grün: neue Rekorde, Amber: ausstehende (PENDING)

### `resources/views/meets/`
- **`show.blade.php`** — Flash-Message + `@include('records.check-result')` wenn Session `record_check_result` vorhanden
  - Alpine submit-Pattern: `x-data="{ submit() { if(confirm('...')) this.$el.submit() } }"`

---

## Tests

### `tests/Unit/RelayClassValidatorTest.php`
- Kein DB-Zugriff, testet `resolveRelayClass`, `isJuniorRelay`, `extractMemberClasses`, `isNationalOnlyClass`
- Gruppe: `->group('relay-validator')` auf jedem `it()`

### `tests/Feature/RecordCheckerServiceTest.php`
- `RefreshDatabase`, kein `HasFactory` — alle Models mit `Model::create()` bzw. `Nation::forceCreate()`
- `makeRelayResult()` legt Placeholder-Athleten an (results.athlete_id NOT NULL)
- Gruppe: `->group('relay-checker')` auf jedem `it()`

```bash
# Ausführen:
php artisan test tests/Unit/RelayClassValidatorTest.php
php artisan test tests/Feature/RecordCheckerServiceTest.php
php artisan test --group=relay-validator --group=relay-checker  # NICHT mit Komma!
```

---

## Wichtige Entscheidungen
- `lenex_club_id` nicht persistiert (instabil)
- `club_id` auf `swim_records`: Verein zum Zeitpunkt des Rekords
- Session speichert nur Record-IDs (keine Eloquent-Objekte — JSON-Serialisierung)
- Staffel-Results: `athlete_id` in DB NOT NULL → Placeholder-Athlet in Tests
- `->group('relay-a', 'relay-b')` funktioniert nicht — `--group=a --group=b` verwenden
- `readonly class RecordCheckerService` — Constructor Injection RelayClassValidator

---

## Nächste Aufgabe
**LENEX-Export von Meldungen** (Entries):
- Bestehender `LenexExportService` exportiert bereits Wettkampf-Struktur + Ergebnisse
- Erweiterung: Export-Typ `entries` soll Meldungen (`entries` Tabelle) exportieren
- LENEX-Struktur: `<CLUB><ATHLETES><ATHLETE><ENTRIES><ENTRY eventid="..." entrytime="..." />`
- Staffel-Meldungen haben kein `athlete_id` → werden über Club-Ebene exportiert
- Relevante Routen/Controller: `LenexExportController`, `LenexExportService`

---

## Routen (Rekorde)
```
GET  /records                    → records.index
GET  /records/import             → RecordImportController@showForm
POST /records/import/preview     → RecordImportController@preview
POST /records/import/run         → RecordImportController@run
POST /meets/{meet}/check-records → RecordController@checkMeet (records.check)
```

## LENEX-Dateistruktur
```xml
<!-- Einzel-Rekord -->
<RECORD swimtime="00:01:05.39">
  <SWIMSTYLE distance="100" relaycount="1" stroke="FREE" />
  <MEETINFO city="Wien" date="2024-05-06" name="ÖSTM" nation="AUT" />
  <ATHLETE firstname="Max" lastname="Mustermann" birthdate="1992-02-24" gender="M">
    <CLUB code="VSCAW" nation="AUT" name="Versehrtensportklub Wien" />
  </ATHLETE>
</RECORD>

<!-- Staffel-Rekord -->
<RECORD swimtime="00:02:31.96">
  <SWIMSTYLE distance="100" relaycount="4" stroke="FREE" />
  <RELAY>
    <CLUB code="BSVLI" nation="AUT" name="BSV BBRZ Linz" />
    <RELAYPOSITIONS>
      <RELAYPOSITION number="1">
        <ATHLETE firstname="Sven" lastname="Schünemann" birthdate="1984-05-26" gender="M" />
      </RELAYPOSITION>
    </RELAYPOSITIONS>
  </RELAY>
</RECORD>
```
