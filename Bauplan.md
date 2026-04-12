# Bauplan: Vereins-Meldungsverwaltung

Stand: April 2026 | Laravel 13 + Livewire Starter Kit (Flux UI) + Pest

---

## Reihenfolge der Phasen

### Phase 1 — User-Management 👤

**Dateien:** ~4
**Was:** Users bekommen `is_admin` und `club_id`. Admin kann User anlegen,
bearbeiten und einem Verein zuweisen. Livewire Component.
**Warum zuerst:** Alle anderen Phasen brauchen `auth()->user()->club_id`.

Liefert:

- Migration: `users.is_admin (bool)` + `users.club_id (FK)`
- Livewire Admin-Panel: User-Liste, User anlegen/bearbeiten
- Pest Feature Test: `UserManagementTest`

---

### Phase 2 — Meet Meldeschluss ⏰

**Dateien:** ~3
**Was:** `meets.entries_deadline` Spalte. Policy die prüft ob der User
noch Meldungen abgeben darf.
**Warum hier:** Muss vor dem Meldungs-CRUD fertig sein.

Liefert:

- Migration: `meets.entries_deadline DATE NULL`
- `EntryPolicy` mit `canCreateEntry(User, Meet)`
- Meet-Formular-Erweiterung (entries_deadline Feld)
- Pest Unit Test: `EntryPolicyTest`

---

### Phase 3 — Relay-Entries Datenmodell 🗃️

**Dateien:** ~4
**Was:** Neue Tabellen für Staffelmeldungen + Models.
**Warum hier:** Models müssen vor Service + Controller existieren.

Liefert:

- Migration: `relay_entries` Tabelle
- Migration: `relay_entry_members` Tabelle
- Model: `RelayEntry` mit Relationen
- Model: `RelayEntryMember` mit Relationen
- Pest Unit Test: `RelayEntryModelTest`

---

### Phase 4 — ClubEntryService 🔧

**Dateien:** ~2
**Was:** Die komplette Business-Logik für Berechtigungsprüfung und Bestzeiten.
**Warum hier:** Controller bleibt dünn, Service ist isoliert testbar.

Liefert:

- `app/Services/ClubEntryService.php`
    - `eligibleAthletes(SwimEvent, Club)` → Sportklasse + Geschlecht
    - `eligibleRelayAthletes(SwimEvent, Club)`
    - `bestTimes(Athlete, SwimEvent, Meet)` → Jahresbestzeit + Absolut
    - `absoluteBestTime(...)` → für Validierung
    - `formatTime(?int)` / `parseTime(string)`
- Pest Unit Test: `ClubEntryServiceTest`

---

### Phase 5 — Einzelmeldungen CRUD 📋

**Dateien:** ~5
**Was:** Controller + Views für Einzelmeldungen. AJAX-Endpunkte für
Athleten-Dropdown und Bestzeiten.
**Warum hier:** Erst wenn Service + Policy + Models fertig sind.

Liefert:

- `ClubEntryController` (Einzel-Methoden + 2 AJAX-Endpunkte)
- Routes (web.php + api.php)
- View: `club-entries/index.blade.php` (Übersicht)
- View: `club-entries/create-individual.blade.php` (mit AJAX-Flow)
- View: `club-entries/edit-individual.blade.php`
- Pest Feature Test: `IndividualEntryTest`

---

### Phase 6 — Staffelmeldungen CRUD 🏊

**Dateien:** ~4
**Was:** Controller-Erweiterung + Views für Staffeln.
Staffelklassen-Berechnung via RelayClassValidator.
**Warum hier:** Baut auf Phase 5 Controller auf.

Liefert:

- Controller-Methoden: createRelay, storeRelay, editRelay, updateRelay, destroyRelay
- AJAX-Endpunkt: relay-athletes
- View: `club-entries/create-relay.blade.php`
- View: `club-entries/edit-relay.blade.php`
- Pest Feature Test: `RelayEntryTest`

---

### Phase 7 — LENEX Export Relay-Erweiterung 📤

**Dateien:** ~2
**Was:** `LenexExportService` lernt Staffelmeldungen aus `relay_entries`
korrekt als LENEX RELAY-Elemente zu exportieren.
**Warum zuletzt:** Braucht die fertigen Relay-Modelle.

Liefert:

- Erweiterung `LenexExportService::buildClubs()` → Relay-Entries einbauen
- Pest Feature Test: `LenexRelayExportTest`

---

## Übersicht

| Phase | Inhalt                | Dateien | Abhängig von |
|-------|-----------------------|---------|--------------|
| 1     | User-Management       | ~4      | –            |
| 2     | Meldeschluss          | ~3      | 1            |
| 3     | Relay-Modelle         | ~4      | –            |
| 4     | ClubEntryService      | ~2      | 3            |
| 5     | Einzelmeldungen CRUD  | ~5      | 1, 2, 4      |
| 6     | Staffelmeldungen CRUD | ~4      | 5, 3, 4      |
| 7     | LENEX Relay-Export    | ~2      | 3, 6         |

**→ Starten wir mit Phase 1.**
