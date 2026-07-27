# Spec: Vereinsmeldungen (Club Entries)

Vereinsverantwortliche erfassen, bearbeiten und löschen Einzel- und Staffelmeldungen für einen Wettkampf. Es werden nur
vereinseigene Athleten angeboten, und die Sportklassen-/Geschlechtsbeschränkungen der Events werden automatisch
durchgesetzt. Diese Spec beschreibt den **implementierten** Stand.

Fachbegriffe: siehe [../domain-glossary.md](../domain-glossary.md). Tabellen/Beziehungen:
siehe [../data-model.md](../data-model.md).

## Beteiligte Bausteine

| Baustein                      | Datei                                                             |
|-------------------------------|-------------------------------------------------------------------|
| Controller                    | `App\Http\Controllers\ClubEntryController`                        |
| Service (Eignung, Bestzeiten) | `App\Services\ClubEntryService`                                   |
| Staffelklassen-Logik          | `App\Services\RelayClassValidator`                                |
| Autorisierung                 | `App\Policies\EntryPolicy`                                        |
| Zeit-Konvertierung            | `App\Support\TimeParser`                                          |
| Modelle                       | `Entry`, `RelayEntry`, `RelayEntryMember`, `SwimEvent`, `Athlete` |
| Views                         | `resources/views/club-entries/*`                                  |

## Datenmodell

- **Einzelmeldungen** nutzen die bestehende `entries`-Tabelle (`athlete_id` NOT NULL), unique je
  `(meet_id, swim_event_id, athlete_id)`.
- **Staffelmeldungen** nutzen `relay_entries` (ohne `athlete_id`) mit
  `relay_entry_members` (`athlete_id`, `sport_class`), unique je
  `(relay_entry_id, athlete_id)`. `RelayEntry` hat Default-`status = 'pending'`
  und die Scopes `pending()` / `confirmed()`.

## Autorisierung & Multi-Tenancy

Gesteuert über `EntryPolicy`. Alle konkreten Fähigkeiten (`createEntry`,
`updateEntry`, `deleteEntry`) delegieren an `manageEntries(User, Meet)`:

1. Admins (`user.is_admin`) dürfen immer.
2. Nicht-Admins brauchen ein zugeordnetes `user.club_id`, sonst verboten.
3. Ohne gesetzten `meet.entries_deadline` ist die Meldung offen.
4. Mit Meldeschluss gilt: erlaubt, solange **heute ≤ `entries_deadline`**.

Der Controller autorisiert je Aktion (`authorize('manageEntries', $meet)` bzw.
`authorize('deleteEntry', $meet)`) und stellt zusätzlich sicher, dass der Meet zum Verein passt und Entry/RelayEntry
tatsächlich zu diesem Meet gehören. Der meldende Verein ergibt sich aus dem eingeloggten User, nicht aus dem Request.

## Eignung von Athleten

### Einzel-Events — `eligibleAthletes(SwimEvent, Club)`

Ein Athlet des Vereins ist geeignet, wenn:

1. **Geschlecht passt**: `event.gender` = `athlete.gender`, wobei `X` und `A`
   "alle" bedeuten.
2. **Sportklasse passt**: mindestens eine Klassennummer des Athleten ist in
   `event.sport_classes` enthalten (leerzeichen-/kommasepariert, z. B. `"1 2 9 10"`). Hat das Event **keine**
   Sportklassen definiert, sind alle Athleten erlaubt.

Sortierung nach Nachname, Vorname.

### Staffel-Events — `eligibleRelayAthletes(SwimEvent, Club, ?excludeRelayEntryId)`

Geeignet sind Vereinsathleten mit passendem Geschlecht, die **nicht bereits in einer anderen Staffelmeldung desselben
Events** (`meet_id` + `swim_event_id`)
gemeldet sind. Beim Bearbeiten einer bestehenden Staffel (`excludeRelayEntryId`) bleiben deren eigene Mitglieder
wählbar.

## Bestzeiten

`bestTimes(Athlete, SwimEvent, Meet)` liefert die **Jahresbestzeit je Kurs**:

```
['LCM' => ?int, 'SCM' => ?int]   // Werte in Hundertstelsekunden
```

- **Zeitraum**: 1. Januar des Vorjahres bis zum **Tag vor** `meet.start_date`
  (`whereBetween('start_date', …)`, DB-portabel).
- Nur gültige Ergebnisse: `status IS NULL` (kein DSQ/DNS/DNF), `swim_time > 0`.
- Getrennt für Kurs `LCM` und `SCM` (kein SCY).

`absoluteBestTime(Athlete, SwimEvent, string $course)` liefert dieselbe Abfrage **ohne** Datumsfilter (für die
Validierung/Anzeige).

> **Hinweis zum Matching:** Beide Abfragen filtern auf die konkrete
> `swim_event_id` des übergebenen Events (nicht auf Stroke + Distanz + Kurs).
> Ergebnisse aus gleichartigen Events **anderer** Meets werden dadurch nur
> gefunden, wenn deren `Result.swim_event_id` auf genau dieses Event zeigt.
> Falls Bestzeiten meet übergreifend über die Disziplin gesucht werden sollen,
> müsste hier auf `stroke_type_id` + `distance` + `course` gematcht werden.
> (Das aktuelle Verhalten ist durch `ClubEntryServiceTest` abgedeckt.)

## Staffelklassen — `RelayClassValidator`

`resolveRelayClass(array $memberClasses)` ermittelt die Staffelklasse aus den Sportklassen der Mitglieder (Strings wie
`['S11','S12','S13']`). Regeln:

| Ergebnis | Bedingung                                                        |
|----------|------------------------------------------------------------------|
| `S21`    | alle Mitglieder S21 (Trisomie)                                   |
| `S14`    | nur S14 und/oder S21                                             |
| `S15`    | alle Mitglieder S15 (Deaf)                                       |
| `S49`    | nur S11 / S12 / S13 (Visual)                                     |
| `S20`    | nur S1–S10, Summe der Nummern ≤ 20 (Physical)                    |
| `S34`    | nur S1–S10, Summe > 20 und ≤ 34 (Physical)                       |
| `null`   | Summe > 34, Mischung mit Sonderklassen, oder SB/SM-Klassen dabei |

Weitere Methoden:

- `isNationalOnlyClass(string)` → `true` nur für **S21**: Trisomie existiert bei World Para Swimming nicht, daher keine
  WR/ER/OR, nur AUT / AUT.JR / AUT.\<LV\>.
- `extractMemberClasses(Collection $entries, $event)` → Sportklassen der Mitglieder. Priorität: gespeicherte
  `Entry.sport_class`, sonst
  `AthleteSportClass` passend zum Stroke (`BREAST`→SB, `MEDLEY`/`IMRELAY`→SM, sonst S).
- `isJuniorRelay(Collection $entries, int $meetYear)` → `true`, wenn alle Mitglieder mit bekanntem Geburtsdatum ein
  Jahrgangsalter (`meetYear − Geburtsjahr`) ≤ 18 haben; `false`, wenn kein Geburtsdatum bekannt.

## Controller & Routen

`ClubEntryController` (Route-Model-Binding auf `Meet`, `Entry`, `RelayEntry`):

**Einzelmeldungen**

| Methode                        | Zweck                                     |
|--------------------------------|-------------------------------------------|
| `index(Meet)`                  | Übersicht der Einzelmeldungen des Vereins |
| `create(Meet)`                 | Formular Einzelmeldung                    |
| `store(Request, Meet)`         | Einzelmeldung anlegen/aktualisieren       |
| `edit(Meet, Entry)`            | Formular bearbeiten                       |
| `update(Request, Meet, Entry)` | Änderungen speichern                      |
| `destroy(Meet, Entry)`         | Meldung löschen                           |

**Staffelmeldungen**

| Methode                                  | Zweck                      |
|------------------------------------------|----------------------------|
| `indexRelay(Meet)`                       | Übersicht Staffelmeldungen |
| `createRelay(Meet)`                      | Formular Staffel           |
| `storeRelay(Request, Meet)`              | Staffel anlegen            |
| `editRelay(Meet, RelayEntry)`            | bearbeiten                 |
| `updateRelay(Request, Meet, RelayEntry)` | speichern                  |
| `destroyRelay(Meet, RelayEntry)`         | löschen                    |

**JSON-Endpunkte (AJAX)**

| Methode                           | Rückgabe                                      |
|-----------------------------------|-----------------------------------------------|
| `eligibleAthletes(Request, Meet)` | geeignete Athleten für ein Event (`event_id`) |
| `bestTimes(Request, Meet)`        | Jahresbestzeiten LCM/SCM für Athlet + Event   |

Beim Anlegen einer Einzelmeldung wird `Entry::updateOrCreate` auf
`(meet_id, swim_event_id, athlete_id)` verwendet; eine erneute Meldung aktualisiert also den bestehenden Datensatz.
`entry_course` fällt auf
`meet.course` zurück; `sport_class` wird aus dem Athleten passend zum Event aufgelöst.

## Validierung

**Einzelmeldung (`store`)**

```php
'swim_event_id' => ['required', 'integer', 'exists:swim_events,id'],
'athlete_id'    => ['required', 'integer', 'exists:athletes,id'],
'entry_time'    => ['nullable', 'string', 'max:20'],
'entry_course'  => ['nullable', 'in:LCM,SCM,SCY'],
```

Zusätzlich: Das Event muss zum Meet gehören und ein **Einzel-Event** sein (`relay_count = 1`); der Athlet muss zum
Verein des Users gehören (`club->athletes()->findOrFail(...)`).

**Staffelmeldung (`storeRelay`)**

```php
'swim_event_id'  => ['required', 'integer', 'exists:swim_events,id'],
'athlete_ids'    => ['nullable', 'array'],
'athlete_ids.*'  => ['integer', 'exists:athletes,id'],
'entry_time'     => ['nullable', 'string', 'max:20'],
'entry_course'   => ['nullable', 'in:LCM,SCM,SCY'],
```

Zusätzlich: Das Event muss zum Meet gehören und ein **Staffel-Event** sein (`relay_count > 1`); die Mitglieder werden
auf Vereinszugehörigkeit und maximale Anzahl (`relay_count`) geprüft (`resolveAndValidateAthletes`).

## Zeitformat

`entry_time` wird als String übermittelt (`MM:SS.ss` bzw. `HH:MM:SS.ss`, oder Codes wie `NT`) und über
`TimeParser::parse` in **Hundertstelsekunden**
umgewandelt; nicht parsebare Werte landen als `entry_time_code`. Anzeige über
`TimeParser::display` (`MM:SS.ss`, Stunden nur bei Bedarf).

## LENEX-Export

Staffelmeldungen aus `relay_entries` werden beim Meldungsexport als LENEX
`RELAY`-Elemente ausgegeben. Details im LENEX-Modul (`docs/specs/lenex-import-export.md`, folgt in einer späteren
Phase).

## Tests

- `tests/Unit/ClubEntryServiceTest.php` — Eignung, Bestzeiten (Zeitraum, Kurs, Status-Filter), Zeitformatierung.
- `tests/Unit/RelayClassValidatorTest.php` — Staffelklassen-Regeln.
- `tests/Feature/ClubEntryTest.php` — CRUD Einzelmeldungen inkl. Autorisierung.
- `tests/Feature/RelayEntryTest.php`, `tests/Feature/RelayEntryFeatureTest.php`
  — Staffelmeldungen.
- `tests/Feature/EntryPolicyTest.php` — Meldeschluss/Autorisierung.
