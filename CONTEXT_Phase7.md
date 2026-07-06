# Para Swimming NatDB – Kontext Phase 7

## Projektbeschreibung

Laravel 13 Anwendung zur Verwaltung von Para-Swimming Wettkämpfen, Athleten, Rekorden und Meldungen.
Stack: Laravel 13, Livewire Starter Kit (Flux UI), Alpine.js, Tailwind 4, Pest 3, MySQL.

## Phasenstatus

- [x] Phase 1: User-Management (`is_admin`, `club_id`, RequireAdmin Middleware, Livewire UserManager)
- [x] Phase 2: Meet-Meldeschluss (`entries_deadline`, EntryPolicy, `canManage`)
- [x] Phase 3: Relay-Entries Datenmodell (Migrationen, Models)
- [x] Phase 4: ClubEntryService
- [x] Phase 5: ClubEntryController + Views (Einzelmeldungen)
- [x] Phase 6: Staffelmeldungen CRUD (vollständig inkl. Admin Club-Selektor)
- [ ] **Phase 7: LENEX Export Relay-Erweiterung ← AKTUELL**

---

## Models (alle in `app/Models/`)

```
Meet            id, name, start_date, end_date, city, course, nation_id, organizer,
                altitude, timing, lenex_meet_id, entries_deadline, is_open
SwimEvent       id, meet_id, stroke_type_id, distance, relay_count, gender,
                sport_classes (space-sep: "1 2 9 10"), event_number, session_number,
                round, lenex_event_id
StrokeType      id, lenex_code (FREE/BACK/BREAST/FLY/MEDLEY/IMRELAY), name_de, code
Athlete         id, club_id, first_name, last_name, gender (M/F), birth_date,
                nation_id, license, license_ipc, lenex_athlete_id
AthleteSportClass  athlete_id, category (S/SB/SM), class_number, sport_class (z.B. "S9")
Entry           id, meet_id, swim_event_id, athlete_id, club_id,
                entry_time (ms int), entry_time_code, entry_course, sport_class, status
RelayEntry      id, meet_id, swim_event_id, club_id,
                relay_class (z.B. "S20","S34","S49","S14","S15","S21"),
                entry_time (ms int), entry_time_code, entry_course,
                status (pending/confirmed/withdrawn)
RelayEntryMember  id, relay_entry_id, athlete_id, position (1-4 nullable), sport_class
Result          id, meet_id, swim_event_id, athlete_id, club_id,
                swim_time (ms int), status, sport_class, ...
Club            id, name, short_name, code, nation_id, type, regional_association,
                swrid, lenex_club_id
Nation          id, code, name_de, name_en, is_active
```

### Relationen die für Phase 7 relevant sind

```php
// RelayEntry
relayEntry->meet          // BelongsTo Meet
relayEntry->swimEvent     // BelongsTo SwimEvent (mit swimEvent->strokeType)
relayEntry->club          // BelongsTo Club
relayEntry->members()     // HasMany RelayEntryMember, orderBy('position')

// RelayEntryMember
member->athlete           // BelongsTo Athlete (mit athlete->sportClasses)
member->relayEntry        // BelongsTo RelayEntry

// Meet
meet->relayEntries()      // HasMany RelayEntry (noch nicht definiert — ggf. ergänzen)
```

---

## Services (alle in `app/Services/`)

### LenexExportService — WIRD IN PHASE 7 ERWEITERT

Aktuelle Struktur:

```php
class LenexExportService
{
    public function build(Meet $meet, string $exportType): string
    // exportType: 'structure' | 'entries' | 'results'

    private function buildMeet(Meet $meet): DOMElement
    // Lädt: meet->nation, meet->clubs->athletes->sportClasses, meet->swimEvents->strokeType
    // Ruft bei 'entries'/'results': buildClubs($meet)

    private function buildClubs(Meet $meet): DOMElement
    // Iteriert meet->clubs, pro Club: buildAthlete() für jeden Athleten mit Entry/Result

    private function buildAthlete($athlete, $club, Meet $meet): DOMElement
    // Baut ATHLETE-Element mit HANDICAP + ENTRIES oder RESULTS

    private function buildEntry($entry): DOMElement
    private function buildResult($result): DOMElement
    private function buildHandicap($athlete): DOMElement
    private function formatTime(int $centiseconds): string
    // → LENEX-Format "HH:MM:SS.ss"
}
```

**Wichtig:** `buildClubs()` lädt Athleten aktuell nur über `Entry`/`Result` — Staffelmeldungen
(`RelayEntry`) werden noch nicht exportiert.

### Weitere Services (unverändert)

- `LenexParserService` – LENEX XML Import
- `LenexResolverService` – Club/Athlet-Matching beim Import
- `ClubEntryService` – Athleten-Eignung, Bestzeiten
- `RelayClassValidator` – Staffelklassen-Berechnung
- `RecordCheckerService`, `RecordImportService` – Rekord-Verwaltung

---

## Phase 7: LENEX Export Relay-Erweiterung

### Ziel

Der bestehende `LenexExportService` soll beim Export-Typ `entries` zusätzlich alle
`RelayEntry`-Datensätze exportieren. Die LENEX 3.0-Struktur für Staffeln sieht so aus:

```xml
<CLUB name="BSV Spittal" code="BSV" nation="AUT">
  <ATHLETES>
    <ATHLETE ...>
      <ENTRIES>
        <ENTRY eventid="4" entrytime="NT"/>   <!-- Einzelmeldung -->
      </ENTRIES>
    </ATHLETE>
  </ATHLETES>

  <RELAYS>
    <RELAY eventid="4" number="1" entrytime="04:30.25" entrycourse="SCM">
      <RELAYPOSITIONS>
        <RELAYPOSITION number="1" athleteid="42" handicap="S9"/>
        <RELAYPOSITION number="2" athleteid="17" handicap="S6"/>
        <RELAYPOSITION number="3" athleteid="55" handicap="S8"/>
        <RELAYPOSITION number="4" athleteid="23" handicap="S7"/>
      </RELAYPOSITIONS>
    </RELAY>
  </RELAYS>
</CLUB>
```

### Was zu implementieren ist

1. **`buildClubs()`** — nach dem `<ATHLETES>`-Block pro Club auch `<RELAYS>` aufbauen,
   wenn Staffelmeldungen für diesen Club vorhanden sind

2. **`buildRelays(Club $club, Meet $meet): DOMElement`** — neuer private helper,
   gibt `<RELAYS>` mit allen `<RELAY>`-Elementen des Clubs zurück

3. **`buildRelay(RelayEntry $relayEntry): DOMElement`** — baut ein einzelnes `<RELAY>`:
   - `eventid` = `swimEvent->lenex_event_id ?? swimEvent->id`
   - `number` = laufende Nummer der Staffel dieses Clubs im selben Event (1, 2, ...)
   - `entrytime` = formatTime() oder `'NT'`
   - `entrycourse` = wenn gesetzt
   - `handicap` = relay_class (z.B. "S20")
   - Kind-Element `<RELAYPOSITIONS>` mit `<RELAYPOSITION>` pro Member

4. **`buildRelayPosition(RelayEntryMember $member, int $position): DOMElement`**
   - `number` = position (1-4)
   - `athleteid` = `athlete->lenex_athlete_id ?? athlete->id`
   - `handicap` = `member->sport_class`

5. **Athleten im ATHLETES-Block** — Athleten die nur in Staffeln gemeldet sind (keine
   Einzelmeldung) müssen trotzdem im `<ATHLETES>`-Block erscheinen, damit die
   `athleteid`-Referenz in `<RELAYPOSITION>` aufgelöst werden kann.

6. **Pest Feature-Test** `LenexRelayExportTest.php`

### Änderungen an `buildClubs()`

Aktuell sammelt `buildClubs()` Athleten nur aus `entries`:

```php
$athletes = $meet->entries()->where('club_id', $club->id)
    ->with('athlete.sportClasses')->get()
    ->pluck('athlete')->unique('id');
```

Neu: Union mit Athleten aus RelayEntries:

```php
$relayAthleteIds = RelayEntry::where('meet_id', $meet->id)
    ->where('club_id', $club->id)
    ->with('members.athlete.sportClasses')
    ->get()
    ->flatMap(fn ($re) => $re->members->pluck('athlete'))
    ->filter()
    ->unique('id');

$athletes = $entryAthletes->merge($relayAthleteIds)->unique('id');
```

---

## Bestehende Routen (Kontext)

```php
// Club-Einzelmeldungen
GET  /meets/{meet}/club-entries              → club-entries.index
GET  /meets/{meet}/club-entries/create       → club-entries.create
POST /meets/{meet}/club-entries              → club-entries.store
GET  /meets/{meet}/club-entries/{entry}/edit → club-entries.edit
PUT  /meets/{meet}/club-entries/{entry}      → club-entries.update
DEL  /meets/{meet}/club-entries/{entry}      → club-entries.destroy
GET  /meets/{meet}/club-entries/eligible-athletes → club-entries.eligible-athletes (AJAX)
GET  /meets/{meet}/club-entries/best-times        → club-entries.best-times (AJAX)
GET  /club-entries/pick-meet                 → club-entries.pick-meet

// Staffelmeldungen
GET  /meets/{meet}/relay-entries             → club-entries.relay.index
GET  /meets/{meet}/relay-entries/create      → club-entries.relay.create
POST /meets/{meet}/relay-entries             → club-entries.relay.store
GET  /meets/{meet}/relay-entries/{relay}/edit → club-entries.relay.edit
PUT  /meets/{meet}/relay-entries/{relay}     → club-entries.relay.update
DEL  /meets/{meet}/relay-entries/{relay}     → club-entries.relay.destroy
GET  /meets/{meet}/relay-entries/relay-athletes → club-entries.relay.relay-athletes (AJAX)
GET  /relay-entries/pick-meet               → club-entries.relay.pick-meet

// LENEX
GET  /lenex/export                          → lenex.export
POST /lenex/export/download                 → lenex.export.download
```

---

## Wichtige Patterns aus Phase 5/6

### userClub() — Admin Club-Selektor

```php
private function userClub(): Club
{
    $user = auth()->user();
    if ($user->is_admin) {
        $clubId = request()->integer('club_id');
        if (! $clubId) abort(400, 'Bitte einen Verein auswählen.');
        return Club::findOrFail($clubId);
    }
    if (! $user->club_id) abort(403, 'Kein Verein zugeordnet.');
    return $user->club;
}
```

### clubParam() — Query-Parameter für Redirects

```php
private function clubParam(): array
{
    if (auth()->user()->is_admin && request()->has('club_id')) {
        return ['club_id' => request()->integer('club_id')];
    }
    return [];
}
```

### resolveAndValidateAthletes() — Staffel-Validierung

```php
private function resolveAndValidateAthletes(array $rawIds, Club $club, SwimEvent $event): array|RedirectResponse
{
    $athleteIds = array_unique($rawIds);
    foreach ($athleteIds as $athleteId) {
        $club->athletes()->findOrFail($athleteId);
    }
    if (count($athleteIds) > $event->relay_count) {
        return back()->withInput()->withErrors([...]);
    }
    return $athleteIds;
}
```

---

## Test-Konventionen (Pest)

```php
uses(RefreshDatabase::class);

// Helper-Funktionen immer mit Phase-Suffix um Namespace-Konflikte zu vermeiden
// z.B. makeClub_p7(), makeRelayEntry_p7(), ...

// Keine Factories — direkt Model::create()
// Nation::forceCreate() für guarded fields
// Pest ->group() chaining funktioniert nicht — CLI: --group=lenex-relay-export

// Expectations verketten mit ->and():
expect($relay->relay_class)->toBe('S20')
    ->and($relay->members()->count())->toBe(4);
```

---

## Datei-Übersicht (aktueller Stand)

```
app/
  Models/
    RelayEntry.php
    RelayEntryMember.php
  Services/
    LenexExportService.php      ← Phase 7: wird erweitert
    ClubEntryService.php
    RelayClassValidator.php
  Http/Controllers/
    ClubEntryController.php
    LenexExportController.php   ← Phase 7: ggf. Export-Typ UI anpassen

database/migrations/
  2026_04_13_085006_create_relay_entries_table.php
  2026_04_13_085124_create_relay_entry_members_table.php

resources/views/
  club-entries/
    index.blade.php
    create.blade.php
    edit.blade.php
    index-relay.blade.php
    create-relay.blade.php
    edit-relay.blade.php
    pick-meet.blade.php
    _athlete-picker.blade.php

resources/js/
  relay-entry-form.js
  single-entry-form.js

tests/
  Feature/
    ClubEntryTest.php           (Phase 5)
    RelayEntryFeatureTest.php   (Phase 6)
    LenexRelayExportTest.php    ← Phase 7: neu
```
