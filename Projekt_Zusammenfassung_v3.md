# Para Swimming NatDB — Projektzusammenfassung

## Tech Stack
- Laravel 13, Livewire Starter Kit, Flux UI, Tailwind 4, Alpine.js, MySQL, PHP 8.4
- IMask.js für Zeitformat-Eingaben (MM:SS.cs)

---

## Domäne
Österreichische Para-Schwimm Rekordverwaltung (ÖBSV).  
Rekordtypen: National (AUT), Jugend (AUT.JR), Regional (AUT.WBSV etc.), International (WR/ER/OR).  
Sportklassen: S1–S21, SB1–SB14, SM1–SM14, Staffel-Klassen (49, 34 etc.)  
Staffeln haben `relay_count > 1`, Einzel `relay_count = 1`.

---

## Abgeschlossene Aufgaben

### Datenbank-Migrationen
| Datei | Inhalt |
|---|---|
| `000003b` | `regional_association` Enum zu `clubs` |
| `000003c` | `lenex_club_id` entfernt, `VERBAND` zu Type-Enum |
| `000009c` | `is_junior_record`, `is_regional_record`, `is_regional_junior_record` zu `results` |
| `000010` (swim_records) | `club_id` direkt in der Create-Migration |
| `000011` (relay_team_members) | Neue Tabelle für Staffelmitglieder |

### Models
- **Club** — `REGIONAL_ASSOCIATIONS` Konstante (9 Verbände), `regional_record_type` Accessor
- **Result** — neue Record-Flags in `$fillable` und `$casts`
- **RelayTeamMember** — neues Model (position, first/last_name, birth_date, gender, athlete_id)
- **SwimRecord** — Ergänzungen nötig: `club_id` in `$fillable`, `club()` BelongsTo, `relayTeam()` HasMany (siehe `SwimRecord_additions.php`)

### Services
- **RecordCheckerService** — prüft AUT, AUT.JR, AUT.WBSV, AUT.WBSV.JR etc.
    - Jugend-Regel: `Wettkampfjahr − Geburtsjahr ≤ 18` (Stichtag 31.12.)
    - WR/ER/OR werden NICHT automatisch gesetzt
- **RecordImportService** — importiert LENEX 3.0 Rekord-LXF/XML
    - `preview()` → `import()` Flow mit Bestätigungsseite
    - Parst `<ATHLETE>` (Einzel) und `<RELAY>` (Staffel) korrekt
    - `<RELAY><RELAYPOSITIONS>` → `relay_team_members`
    - Club mit `name="???"` wird übersprungen
    - `AUT.JG` → `AUT.JR` Mapping
    - NT-Einträge werden übersprungen
    - Sportklasse: BREAST → SB, MEDLEY/IMRELAY → SM, sonst → S
    - Neue Athleten bekommen `AthleteSportClass` automatisch angelegt
    - Regionale Rekorde (`AUT.WBSV` etc.) separat gruppiert, Verband-Entscheidung per `$approvedRegional`
    - Private Hilfsmethoden: `parseAthleteXml()`, `parseClubXml()`, `resolveClubs()`, `resolveAthletes()`, `createSportClass()`, `resolveClubId()`
- **LenexResolverService** — `lenex_club_id` vollständig entfernt, Matching nur über `code+nation` → `name+nation`

### Controllers
- **RecordController**
    - `index()`: Kategorie-Tabs (international/national/regional), Einzel/Staffel-Filter (`relay=single|relay|''`), Untertyp-Dropdown
    - `formData()`: gibt `strokeTypes`, `nations`, `athletes`, `clubs` zurück
    - `storeRelayMembers()`: speichert Staffelteam beim manuellen Anlegen/Bearbeiten
    - Validation: `club_id`, `relay_members[]` inkl.
- **RecordImportController** — `showForm()` → `preview()` → `run()`, `$approvedRegional` weitergegeben

### Views
- **records/index** — Einzel/Staffel Toggle-Tabs, Disziplin-Format `4x 25m Freistil`, Staffel zeigt nummeriertes Team, Verein aus `record->club` (nicht `athlete->club`)
- **records/show** — Staffel zeigt Team-Liste mit Positionen, Verein mit Hinweis bei Vereinswechsel
- **records/form** — Verein-Dropdown (getrennt vom Athleten), Staffelteam-Block (4 Zeilen, Alpine.js x-show)
- **records/import** — Upload-Formular
- **records/import-preview** — Unbekannte Clubs/Athleten, regionale Rekorde aufklappbar pro Verband
- **clubs/index** — Typ-Badge, Verband-Spalte, Athleten aktiv/inaktiv
- **clubs/form** — Regionalverband-Dropdown
- **meets/show** — "Rekorde prüfen" Button als POST-Formular

---

## Wichtige Entscheidungen
- `lenex_club_id` nicht persistiert (instabil, ändert sich pro Export)
- `lenex_athlete_id` nicht persistiert (nur Memory-Cache pro Import)
- `club_id` auf `swim_records`: Verein **zum Zeitpunkt des Rekords** (kann vom aktuellen Verein abweichen)
- Staffeln haben `athlete_id = null`, Club + Team via `relay_team_members`
- Sportklasse-Prefix hängt vom Stroke ab: BREAST→SB, MEDLEY→SM, sonst→S

---

## Offene Punkte / Pending
- SwimRecord Model manuell ergänzen (siehe `SwimRecord_additions.php`):
    - `'club_id'` in `$fillable`
    - `club()` BelongsTo Relation
    - `relayTeam()` HasMany Relation (ordered by position)
- `records/import-preview.blade.php` — `StrokeType::find()` in der Blade-View ist nicht ideal, besser per eager load im Controller lösen
- Export (LENEX): `club_id` und `relay_team_members` noch nicht im Export berücksichtigt

---

## LENEX-Dateistruktur (neue Erkenntnis)
```xml
<!-- Einzel -->
<RECORD swimtime="00:01:05.39">
  <SWIMSTYLE distance="100" relaycount="1" stroke="FREE" />
  <MEETINFO city="Dornbirn" date="2012-05-06" name="ÖBSV - ÖSTM" nation="AUT" />
  <ATHLETE firstname="Peter" lastname="Tichy" birthdate="1992-02-24" gender="M">
    <CLUB code="VSCAW" nation="AUT" name="Versehrtensportklub ASVÖ Wien" />
  </ATHLETE>
</RECORD>

<!-- Staffel mit Team -->
<RECORD swimtime="00:02:31.96">
  <SWIMSTYLE distance="50" relaycount="4" stroke="FREE" />
  <RELAY>
    <CLUB code="BSVLI" nation="AUT" name="BSV BBRZ Linz" />
    <RELAYPOSITIONS>
      <RELAYPOSITION number="1">
        <ATHLETE firstname="Sven" lastname="Schünemann" birthdate="1984-05-26" gender="M" />
      </RELAYPOSITION>
    </RELAYPOSITIONS>
  </RELAY>
</RECORD>

<!-- Staffel ohne Team (Club unbekannt) -->
<RECORD swimtime="00:01:17.52">
  <SWIMSTYLE distance="25" relaycount="4" stroke="FREE" />
  <RELAY>
    <CLUB name="???" />
  </RELAY>
</RECORD>
```

---

## Routes (relevante Rekord-Routen)
```php
GET  /records                    → records.index
GET  /records/create             → records.create
POST /records                    → records.store
GET  /records/import             → RecordImportController@showForm
POST /records/import/preview     → RecordImportController@preview
POST /records/import/run         → RecordImportController@run
GET  /records/export             → records.export
POST /meets/{meet}/check-records → records.check-meet
GET  /records/{record}           → records.show
GET  /records/{record}/edit      → records.edit
PUT  /records/{record}           → records.update
DELETE /records/{record}         → records.destroy
POST /records/{record}/restore   → records.restore
```
