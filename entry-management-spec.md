# Feature: Vereins-Meldungsverwaltung (Club Entry Management)

## Übersicht

Vereinsverantwortliche können Einzel- und Staffelmeldungen für eine Veranstaltung erfassen,
bearbeiten und löschen. Dabei werden nur vereinseigene Schwimmer angezeigt und die
Sportklassen-/Altersbeschränkungen der einzelnen Events automatisch enforced.

---

## Datenmodell (bestehend, relevant)

```
Meet            id, name, start_date, course
SwimEvent       id, meet_id, stroke_type_id, distance, relay_count, gender,
                sport_classes (space-separated: "1 2 3 9 10"), event_number, session_number
StrokeType      id, lenex_code, name_de
Athlete         id, club_id, first_name, last_name, gender, birth_date
AthleteSportClass  athlete_id, category (S/SB/SM), class_number, sport_class (z.B. "S9")
Entry           id, meet_id, swim_event_id, athlete_id, club_id,
                entry_time (int ms), entry_time_code, entry_course, sport_class, status
Club            id, name, short_name, code
Result          id, meet_id, swim_event_id, athlete_id, club_id, swim_time
```

---

## Neue DB-Tabelle: `relay_entries`

Für Staffelmeldungen brauchen wir eine separate Tabelle, da die bestehende `entries`-Tabelle
auf `athlete_id NOT NULL` ausgelegt ist (Einzelmeldungen).

```sql
CREATE TABLE relay_entries (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meet_id         BIGINT UNSIGNED NOT NULL,
    swim_event_id   BIGINT UNSIGNED NOT NULL,
    club_id         BIGINT UNSIGNED NOT NULL,
    relay_name      VARCHAR(100) NOT NULL,          -- z.B. "Staffel A"
    sport_class     VARCHAR(10) NULL,               -- ermittelt aus Mitgliedern, z.B. "S49"
    entry_time      INT UNSIGNED NULL,              -- Meldezeit in ms
    entry_time_code VARCHAR(10) NULL,               -- 'NT', 'Q', etc.
    entry_course    VARCHAR(10) NULL,
    status          VARCHAR(10) NULL,
    created_at      TIMESTAMP, updated_at TIMESTAMP,
    FOREIGN KEY (meet_id) REFERENCES meets(id),
    FOREIGN KEY (swim_event_id) REFERENCES swim_events(id),
    FOREIGN KEY (club_id) REFERENCES clubs(id)
);

CREATE TABLE relay_entry_members (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    relay_entry_id   BIGINT UNSIGNED NOT NULL,
    athlete_id       BIGINT UNSIGNED NULL,          -- nullable: Platz reserviert aber noch kein Athlet
    position         TINYINT UNSIGNED NULL,
    created_at       TIMESTAMP, updated_at TIMESTAMP,
    FOREIGN KEY (relay_entry_id) REFERENCES relay_entries(id) ON DELETE CASCADE,
    FOREIGN KEY (athlete_id) REFERENCES athletes(id)
);
```

**Alternative (einfacher):** Die bestehende `entries`-Tabelle für Staffeln wiederverwenden,
indem athlete_id nullable gemacht wird und ein `is_relay` Flag + `relay_name` Spalte ergänzt wird.
Staffelmitglieder kommen in eine eigene `entry_relay_members`-Tabelle.

---

## Bestzeit-Logik

### Zeitraum
- Meet-Datum: `start_date`
- Jahreszeitraum: `1. Januar des Vorjahres` bis `Tag vor Meet-Datum`
- Beispiel: Meet am 25.04.2026 → Jahreszeitraum = 01.01.2025 – 24.04.2026

### Bestzeit des Jahres (Jahresbestzeit)
```sql
SELECT MIN(r.swim_time) as best_time
FROM results r
JOIN swim_events se ON r.swim_event_id = se.id
JOIN meets m ON r.meet_id = m.id
WHERE r.athlete_id = :athlete_id
  AND r.swim_time IS NOT NULL
  AND r.status IS NULL                              -- kein DSQ/DNS/DNF
  AND se.stroke_type_id = :stroke_type_id
  AND se.distance = :distance
  AND se.relay_count IS NULL                        -- Einzelwettbewerb
  AND m.course = :course                            -- nur gleiche Bahnlänge
  AND m.start_date BETWEEN :jan_1_prev_year AND :day_before_meet
```

### Absolute Bestzeit
```sql
-- gleiche Query ohne Datums-Filter
```

### Kurs-Mapping
- Meet course = 'LCM' → Results aus LCM-Meets
- Meet course = 'SCM' → Results aus SCM-Meets
- Yards (SCY) werden nicht angezeigt

---

## Berechtigungsprüfung Schwimmer → Event

### Sportklassen-Matching
`SwimEvent.sport_classes` enthält Klassennummern (ohne Präfix): `"1 2 3 9 10"`

Für Brust (`BREAST`) → Präfix `SB`, für Lagen/Medley (`MEDLEY`) → `SM`, sonst `S`.

Schwimmer ist berechtigt wenn:
```php
$athleteClass = $athlete->sportClasses
    ->firstWhere('category', $category); // S/SB/SM je nach Stroke

$allowedNumbers = explode(' ', $event->sport_classes); // ["1","2","9","10"]

$eligible = in_array($athleteClass->class_number, $allowedNumbers);
```

### Geschlecht-Matching
- `SwimEvent.gender = 'M'` → nur männliche Athleten
- `SwimEvent.gender = 'F'` → nur weibliche Athleten
- `SwimEvent.gender = 'X'` → alle

### Alters-Matching (AgeGroup)
In `SwimEvent` gibt es keine direkte `min_age`/`max_age` Spalte — diese Info steckt in
`sport_classes`. Für Para-Swimming gibt es keine echten Altersgrenzen in den Events,
daher: **Altersfilterung nur wenn der Event explizit Alterskategorien hat.**

Falls die DB eine `agegroups`-Tabelle hat mit `min_age`/`max_age` → Join und Filtern.
Falls nicht → Altersfilter vorerst weglassen, nur Sport-Klasse + Geschlecht prüfen.

---

## Staffel-Logik

### Erlaubte Schwimmer für Staffel-Event
```
SwimEvent.gender = 'X' (Mixed) → M + F anzeigen
SwimEvent.gender = 'M'         → nur M
SwimEvent.gender = 'F'         → nur F

SwimEvent.sport_classes = "11 12 13" (= S49 Visual)
→ Schwimmer mit S11, S12 oder S13 anzeigen
```

### Staffelklasse ermitteln
Wird aus den Mitgliedern berechnet (bestehender `RelayClassValidator`).
Bei der Meldung: nach Auswahl der Mitglieder automatisch berechnen und anzeigen.

---

## Controller-Struktur

```
ClubEntryController
├── index(meet)           – Übersicht aller Meldungen des eigenen Vereins
├── createIndividual(meet) – Formular: Schwimmer + Event auswählen
├── storeIndividual(meet)  – Einzelmeldung speichern
├── editIndividual(entry)  – Bearbeiten
├── updateIndividual(entry)
├── destroyIndividual(entry)
├── createRelay(meet)      – Formular: Staffel anlegen
├── storeRelay(meet)
├── editRelay(relayEntry)
├── updateRelay(relayEntry)
└── destroyRelay(relayEntry)

API-Routen (AJAX)
├── GET /api/meets/{meet}/eligible-athletes?event_id=X  – Berechtigte Schwimmer
├── GET /api/meets/{meet}/best-times?athlete_id=X&event_id=Y  – Bestzeiten
└── GET /api/meets/{meet}/relay-athletes?event_id=X  – Athleten für Staffel
```

---

## UI-Flow (Einzelmeldung)

```
1. User öffnet Meet → Tab "Meldungen" → Button "Neue Meldung"
2. Schritt 1: Event auswählen (Dropdown, gruppiert nach Session)
3. → AJAX lädt berechtigte Schwimmer des Vereins
4. Schritt 2: Schwimmer auswählen
5. → AJAX lädt Bestzeiten (Jahresbestzeit + Absolutbestzeit)
6. Anzeige: "Jahresbestzeit: 1:29.38 | Absolute Bestzeit: 1:28.90"
7. Feld "Meldezeit" prefilled mit Jahresbestzeit (editierbar)
8. Validierung: Meldezeit ≥ Absolute Bestzeit (darf nicht schneller sein)
9. Speichern
```

## UI-Flow (Staffelmeldung)

```
1. User klickt "Neue Staffelmeldung"
2. Event auswählen (nur Staffel-Events: relay_count > 1)
3. Staffelname eingeben (Pflicht)
4. → AJAX zeigt geeignete Athleten des Vereins (nach Sportklasse + Geschlecht gefiltert)
5. Optional: bis zu relay_count Athleten als Mitglieder wählen
6. Staffelklasse wird automatisch berechnet und angezeigt
7. Meldezeit optional eintragen
8. Speichern
```

---

## Validierungsregeln (Backend)

### Einzelmeldung
```php
[
    'swim_event_id' => 'required|exists:swim_events,id',
    'athlete_id'    => 'required|exists:athletes,id',
    'entry_time'    => 'nullable|integer|min:1000',   // ms, min 1 Sekunde
]
// + Custom: athlete gehört zum Club des Users
// + Custom: athlete ist für den Event berechtigt (Sportklasse + Geschlecht)
// + Custom: entry_time >= absolute Bestzeit des Athleten in dieser Disziplin
// + Custom: noch keine Meldung für athlete + event vorhanden (Unique-Check)
```

### Staffelmeldung
```php
[
    'swim_event_id' => 'required|exists:swim_events,id',
    'relay_name'    => 'required|string|max:100',
    'members'       => 'nullable|array|max:{relay_count}',
    'members.*'     => 'exists:athletes,id',
    'entry_time'    => 'nullable|integer|min:1000',
]
// + Custom: Alle members gehören zum Club des Users
// + Custom: Alle members sind für den Staffel-Event berechtigt
```

---

## Service: `ClubEntryService`

```php
class ClubEntryService
{
    /**
     * Gibt Schwimmer zurück, die für einen Event berechtigt sind und
     * zum gegebenen Club gehören.
     */
    public function eligibleAthletes(SwimEvent $event, Club $club): Collection

    /**
     * Jahresbestzeit und absolute Bestzeit für Athlet + Event + Course.
     * Zeitraum: 1.1.Vorjahr bis Tag-vor-Meet.
     */
    public function bestTimes(Athlete $athlete, SwimEvent $event, Meet $meet): array
    // returns: ['year_best' => int|null, 'absolute_best' => int|null, 'course' => string]

    /**
     * Athleten die für eine Staffel geeignet sind (Sportklasse + Geschlecht).
     */
    public function eligibleRelayAthletes(SwimEvent $event, Club $club): Collection

    /**
     * Absolute Bestzeit für Validierung der manuell eingetragenen Meldezeit.
     */
    public function absoluteBestTime(Athlete $athlete, SwimEvent $event, string $course): ?int
}
```

---

## Zeitformat

In der DB: Millisekunden als Integer (z.B. `89380` = 1:29.38)

Hilfsmethoden aus `TimeParser` bereits vorhanden.

Anzeige: `mm:ss.hh` oder `ss.hh` wenn < 60 Sekunden.

---

## Auth / Multi-Tenancy

Der eingeloggte User muss einem Club zugeordnet sein:
```php
// User-Model braucht: club_id oder Relation zu Club
auth()->user()->club_id
```

Falls noch nicht vorhanden → `users.club_id` FK ergänzen.

---

## Migrations-Reihenfolge

1. `add_club_id_to_users_table` (falls fehlend)
2. `create_relay_entries_table`
3. `create_relay_entry_members_table`

---

## Zu klärende Punkte

1. Hat `users` bereits eine `club_id` Spalte?
2. Gibt es eine separate `agegroups`-Tabelle mit `min_age`/`max_age` in der DB?
3. Soll ein User mehrere Clubs verwalten können?
4. Soll die Meldung direkt als LENEX exportierbar sein (entries-Export)?
5. Braucht es ein "Meldeschluss"-Datum am Meet nach dem keine Meldungen mehr möglich sind?
