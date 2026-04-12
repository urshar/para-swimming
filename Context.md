# Para Swimming NatDB – Entwicklungskontext

## Projektbeschreibung

Laravel 13 Anwendung zur Verwaltung von Para-Swimming Wettkämpfen, Athleten, Rekorden und Meldungen.
Stack: Laravel 13, Livewire Starter Kit (Flux UI), Pest Tests.

## Bestehende Architektur

### Key Models (alle in `app/Models/`)

```
Meet            id, name, start_date, end_date, city, course, nation_id, organizer, altitude, timing, lenex_meet_id
                entries_deadline (NEU - noch nicht migriert)
SwimEvent       id, meet_id, stroke_type_id, distance, relay_count, gender,
                sport_classes (space-sep Nummern: "1 2 9 10"), event_number, session_number, round
StrokeType      id, lenex_code (FREE/BACK/BREAST/FLY/MEDLEY/IMRELAY), name_de, code
Athlete         id, club_id, first_name, last_name, gender (M/F), birth_date, nation_id, license
AthleteSportClass  athlete_id, category (S/SB/SM), class_number, sport_class (z.B. "S9")
Entry           id, meet_id, swim_event_id, athlete_id, club_id,
                entry_time (ms int), entry_time_code, entry_course, sport_class, status
Result          id, meet_id, swim_event_id, athlete_id, club_id, swim_time (ms int), status, sport_class, ...
Club            id, name, short_name, code, nation_id, type, regional_association, swrid, lenex_club_id
Nation          id, code, name_de, name_en, is_active
SwimRecord      id, ...Rekord-Felder...
```

### Bestehende Services (alle in `app/Services/`)

- `LenexParserService` – LENEX XML Import
- `LenexExportService` – LENEX XML Export (entries, results, structure)
- `LenexResolverService` – Club/Athlet-Matching beim Import
- `RecordCheckerService` – Rekordprüfung nach Ergebnis-Import
- `RecordImportService` – Rekordlisten-Import (CSV/XML)
- `RelayClassValidator` – Staffelklassen-Berechnung (S20/S34/S49/S14/S15/S21)
- `TimeParser` – Zeitformatierung

### Bestehende Controllers

- `LenexImportController` – Multi-Step LENEX Import
- `LenexExportController` – LENEX Export
- `RecordExportController` – Rekord-Export
- `RecordImportController` – Rekord-Import
- `RecordController` – Rekord-Anzeige/Verwaltung

### Wichtige Business-Logik

**Sport-Klassen-Kategorie je Stroke:**

- BREAST → SB
- MEDLEY / IMRELAY → SM
- alle anderen (FREE, BACK, FLY, ...) → S

**Staffelklassen (RelayClassValidator):**

- S21: alle Mitglieder S21
- S14: nur S14 + S21
- S15: alle S15
- S49: nur S11, S12, S13
- S20: Physical, Summe Klassennummern ≤ 20
- S34: Physical, Summe > 20 und ≤ 34

**Bestzeit-Zeitraum:**

- Jahresbestzeit: 1.1. des Vorjahres bis Tag vor Meet-Datum
- Absolut: alle Zeiten ohne Datum-Filter
- Nur gleicher Kurs (LCM oder SCM), kein SCY
- Nur Results ohne Status (kein DSQ/DNS/DNF)

**Meldeschluss:**

- `meets.entries_deadline` DATE Spalte (noch zu migrieren)
- Nach diesem Datum: User dürfen keine Meldungen mehr anlegen/ändern/löschen
- Admins sind ausgenommen

## Geplante neue Features (Reihenfolge)

### Phase 1: User-Management ✅ (DIESES MODUL)

1. Migration: `users.club_id FK`
2. Livewire User-Verwaltung (Admin: CRUD User + Club-Zuweisung)
3. Profile-Seite (User kann eigenen Club sehen, aber nicht ändern)
4. Pest Tests

### Phase 2: Meet-Meldeschluss

1. Migration: `meets.entries_deadline DATE NULL`
2. Meet-Formular erweitern
3. Gate/Policy für Meldungen
4. Pest Tests

### Phase 3: Relay-Entries Datenmodell

1. Migration: `relay_entries` + `relay_entry_members`
2. Models: `RelayEntry`, `RelayEntryMember`
3. Pest Tests (Modell-Ebene)

### Phase 4: ClubEntryService

1. `app/Services/ClubEntryService.php`
    - `eligibleAthletes(SwimEvent, Club)`
    - `eligibleRelayAthletes(SwimEvent, Club)`
    - `bestTimes(Athlete, SwimEvent, Meet)`
    - `absoluteBestTime(Athlete, SwimEvent, course)`
    - `formatTime(?int): ?string`
    - `parseTime(string): ?int`
2. Pest Tests

### Phase 5: ClubEntryController + Views (Einzelmeldungen)

1. `ClubEntryController` – Einzelmeldung CRUD
2. AJAX-Endpunkte: eligible-athletes, best-times
3. Blade/Livewire Views: index, create, edit
4. Pest Tests (Feature)

### Phase 6: Staffelmeldungen

1. Controller-Methoden für Relay
2. AJAX-Endpunkt: relay-athletes
3. Views: create-relay, edit-relay
4. Pest Tests

### Phase 7: LENEX Export Relay-Erweiterung

1. `LenexExportService` – Relay-Entries einbauen
2. Eigene CLUB/RELAY-Elemente im XML
3. Pest Tests

## Auth / Rollen-Konzept

```
Admin   → kann alles, kein Meldeschluss
Club-User → club_id gesetzt, nur eigene Club-Daten
```

Livewire Starter Kit bringt bereits:

- `users` Tabelle mit name, email, password
- Breeze/Jetstream-ähnliche Auth (Login, Register, Profile)
- Volt-basierte Livewire Components

Geplante Erweiterung:

```php
// users Tabelle:
$table->boolean('is_admin')->default(false)->after('email');
$table->foreignId('club_id')->nullable()->constrained('clubs')->nullOnDelete();
```

Kein separates Rollen-System (Spatie etc.) – einfaches `is_admin` Flag genügt.

## Namenskonventionen

- Routes: `club-entries.index`, `club-entries.create-individual`, etc.
- Views: `resources/views/club-entries/`
- Livewire: `app/Livewire/`
- Services: `app/Services/`
- Tests: `tests/Feature/` und `tests/Unit/`

## Test-Konventionen (Pest)

```php
// Feature Tests
uses(RefreshDatabase::class);

// Helpers:
function makeAdmin(): User { ... }
function makeClubUser(Club $club): User { ... }
function makeMeet(array $attrs = []): Meet { ... }
```

## Aktueller Stand der Phasen

- [x] Phase 1: In Arbeit (nächster Schritt)
- [ ] Phase 2–7: geplant

## Datei-Übersicht neu (wird laufend ergänzt)

```
app/
  Models/
    RelayEntry.php          (Phase 3)
    RelayEntryMember.php    (Phase 3)
  Services/
    ClubEntryService.php    (Phase 4)
  Http/Controllers/
    ClubEntryController.php (Phase 5+6)
  Livewire/
    Admin/UserManager.php   (Phase 1)

database/migrations/
  add_club_id_is_admin_to_users_table.php  (Phase 1)
  add_entries_deadline_to_meets_table.php  (Phase 2)
  create_relay_entries_table.php           (Phase 3)

resources/views/
  admin/users/             (Phase 1)
  club-entries/            (Phase 5+6)

tests/
  Feature/
    UserManagementTest.php  (Phase 1)
    MeetDeadlineTest.php    (Phase 2)
    ClubEntryTest.php       (Phase 5)
    RelayEntryTest.php      (Phase 6)
    LenexRelayExportTest.php (Phase 7)
  Unit/
    ClubEntryServiceTest.php (Phase 4)
```
