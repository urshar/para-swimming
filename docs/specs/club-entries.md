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

Geeignet sind **aktive** Vereinsathleten (`is_active = true`) mit passendem Geschlecht, die **nicht bereits in
einer anderen Staffelmeldung desselben Events** (`meet_id` + `swim_event_id`) gemeldet sind. Beim Bearbeiten
einer bestehenden Staffel (`excludeRelayEntryId`) bleiben deren eigene Mitglieder wählbar.

Der AJAX-Endpunkt liefert je Athlet zusätzlich `gender` und die Sportklassen-Liste (`classes`) für den
client-seitigen Picker-Filter (s. u.).

### Athleten-Picker-Filter (Staffelformular)

Der Athleten-Picker (`club-entries/_athlete-picker.blade.php`, Alpine `relayEntryForm`) filtert die bereits
geladene Liste rein client-seitig:

- **Geschlecht** (Flux-Select): nur die tatsächlich vorkommenden Werte.
- **Sportklassen** (Chips, mehrfach wählbar): Chips zeigen nur die S-Nummern (S7, S14, ...). Die Auswahl matcht
  über die **Klassennummer** — "S7" zeigt jeden Athleten mit Code 7 in irgendeiner Kategorie (S7, SB7 oder
  SM7). Mehrere Chips lassen sich kombinieren (z. B. S14 + S21).

## Bestzeiten

Bestzeiten werden meet-übergreifend über die **Disziplin** (Distanz + `stroke_type_id` + `relay_count` + Kurs)
ermittelt, nicht über die konkrete `swim_event_id` des aktuellen Meets — historische Ergebnisse hängen an den
Events vergangener Meets. (Früher wurde auf `swim_event_id` gematcht, was für ein neu angelegtes Event immer
"NT" lieferte; behoben.) Nur gültige Ergebnisse: `status IS NULL` (kein DSQ/DNS/DNF), `swim_time > 0`. Nur
Kurse LCM/SCM (kein SCY). Zeitraum der Jahresbestzeit: 1. Januar des Vorjahres bis zum **Tag vor**
`meet.start_date` (`whereBetween('start_date', ...)`, DB-portabel).

### Einzelmeldung — `bestTimesForPanel(Athlete, SwimEvent, Meet)`

Liefert je Kurs (LCM/SCM) die **Jahres- und die absolute Bestzeit**, jeweils roh, formatiert und mit Datum:

```
['LCM' => ['year'     => ['raw' => ?int, 'formatted' => string, 'date' => ?string],
           'absolute' => ['raw' => ?int, 'formatted' => string, 'date' => ?string]],
 'SCM' => [...]]
```

`formatted` ist "NT", wenn keine Zeit vorhanden. Das Melde-Formular zeigt daraus ein blaues Panel mit Jahres-
und absoluter Bestzeit je Kurs; Klick auf eine Zeit übernimmt sie als Meldezeit + Bahnlänge. Bereitgestellt per
AJAX (`bestTimes`, s. u.).

### Staffelmeldung — `relayBestTimes(SwimEvent, array $orderedAthleteIds, Meet)`

Liefert je Kurs einen **Meldezeit-Vorschlag als Summe der Einzel-Bestzeiten** der (der Reihe nach) gemeldeten
Athleten über die jeweilige Teilstrecke, plus eine per-Schwimmer-Aufschlüsselung (`legs`) für eine gemischte
Summe:

```
['LCM' => ['year'     => ['raw' => ?int, 'formatted' => string, 'missing' => int, 'total' => int],
           'absolute' => [...],
           'legs'     => [['athlete_id' => int, 'year' => {raw, formatted, date}, 'absolute' => {...}], ...]],
 'SCM' => [...]]
```

- **Teilstrecken-Disziplin** je Startposition: Distanz = Staffeldistanz, `relay_count = 1`, Stil nach
  Staffelart — Freistilstaffel (FREE) → alle Freistil; Lagenstaffel (MEDLEY, genau 4 Beine) → Position 1
  Rücken, 2 Brust, 3 Schmetterling, 4 Freistil; Lagen-Staffel jeder alle Stile (IMRELAY) → alle Einzel-Lagen (MEDLEY);
  sonst Freistil-Fallback.
- **Teilsumme**: summiert wird über die vorhandenen Zeiten; `missing`/`total` (= `relay_count`) melden fehlende
  Positionen (leer oder ohne Ergebnis).
- Das Formular zeigt die Gesamt-Summen (alle JBZ / alle ABZ) je Kurs und erlaubt zusätzlich, je Schwimmer in
  der Startaufstellung JBZ (Jahres-) oder ABZ (absolute Bestzeit) zu wählen (Default JBZ); daraus wird eine **gemischte
  Summe** für den aktuell gewählten Kurs gebildet und ist per Klick übernehmbar.

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

| Methode                                | Rückgabe                                                                     |
|----------------------------------------|------------------------------------------------------------------------------|
| `eligibleAthletes(Request, Meet)`      | geeignete Einzel-Athleten für ein Event (`event_id`)                         |
| `eligibleRelayAthletes(Request, Meet)` | geeignete Staffel-Athleten (aktiv) inkl. `gender` + `classes` für den Filter |
| `bestTimes(Request, Meet)`             | Panel-Bestzeiten (Jahres + absolut, LCM/SCM) für Athlet + Event              |
| `relayBestTime(Request, Meet)`         | Staffel-Summe je Kurs + `legs` (Reihenfolge = `athlete_ids[]`)               |

Route der Staffel-Summe: `GET meets/{meet}/relay-entries/relay-best-time?event_id=&athlete_ids[]=` (Name
`club-entries.relay.relay-best-time`), vor dem `{relayEntry}`-Platzhalter registriert. Die Athleten werden auf
den Verein gescoped, die übergebene Reihenfolge bleibt erhalten (Startposition bestimmt bei Lagenstaffeln den
Stil).

Beim Anlegen einer Einzelmeldung wird `Entry::updateOrCreate` auf
`(meet_id, swim_event_id, athlete_id)` verwendet; eine erneute Meldung aktualisiert also den bestehenden Datensatz.
`entry_course` fällt auf
`meet.course` zurück; `sport_class` wird aus dem Athleten passend zum Event aufgelöst
(`ClubEntryService::resolveSportClass(athleteId, event)`, Lage → Kategorie S/SB/SM). Diese Ableitung teilen sich
Club- und Admin-Flow (s. u.).

## Admin: meet-weite Gesamtübersicht ("Alle Meldungen")

Neben den club-gescopten Ansichten gibt es für Admins eine **meet-weite** Übersicht aller Meldungen einer
Veranstaltung — Einzel- UND Staffelmeldungen, über alle Vereine hinweg, nach Disziplin gruppiert
(`MeetEntriesOverviewController@index`, Route `meets.entries-overview`, nur Admin via `RequireAdmin`; verlinkt von
`meets/show`). Rein lesend/gruppierend: Bearbeiten und Löschen laufen über die bestehenden Formulare
(`entries.edit`/`entries.destroy` bzw. `club-entries.relay.edit`/`.destroy` mit `club_id`). Ein optionaler
Disziplin-Filter (`event_id`) blendet den jeweils unpassenden Abschnitt aus.

**Anlegen aus der Übersicht:**

- *Neue Einzelmeldung* → Admin-Formular (`meets.entries.create`, `EntryController`). Die Sportklassen der Disziplin
  werden über `App\Support\SportClassRanges` zu Bereichen zusammengefasst (`S1 S2 … S15 S21` → `S1-S7, S9-S15, S21`;
  Lücken brechen einen Bereich, kein Null-Padding), und die Athletenauswahl wird bei gewähltem Verein client-seitig
  auf dessen Athleten eingeschränkt.
- *Neue Staffelmeldung* → `club-entries.relay.create`; für Admins ohne gewählten Verein erscheint zuerst die
  Vereinsauswahl (`clubChooserView`), die **direkt** ins Anlege-Formular führt (nicht mehr über die Staffelliste).
- Beide Wege geben eine `return_to`-URL mit; nach dem Speichern kehrt der Flow zur Übersicht zurück (nur interne
  Ziele, Open-Redirect-Schutz), sonst wie bisher zur Detailseite bzw. zur Staffelliste des Vereins.

**Sportklasse (Admin-Einzelmeldung):** `EntryController@store` leitet die Sportklasse bei leerem Feld aus dem
Athleten ab — dieselbe Logik wie im Club-Flow, gemeinsam in `ClubEntryService::resolveSportClass`. Ein
ausgefülltes Feld bleibt als bewusste Abweichung erhalten.

### Meldungen-Cockpit (Admin)

Die linksseitige „Meldungen"-Liste (`entries.index`, `EntryController@index`) ist ein **admin-only Cockpit**
„Was ist zu tun" — der Menüpunkt ist für Vereine ausgeblendet, die Route liegt hinter `RequireAdmin` (Vereine
nutzen weiterhin den eigenen `club-entries`-Weg). Zwei Tabs (`entries/_tabs.blade.php`):

- **Einzel** (`entries.index`): alle Einzelmeldungen wettkampfübergreifend, mit Bearbeiten/Löschen (admin).
- **Staffel** (`relay-entries.index`, `RelayEntryController@index`): alle Staffelmeldungen, rein lesend.

Beide Tabs haben oben **klickbare Kennzahlen-Kacheln** (Schnellfilter, behalten Wettkampf-/Suchkontext und setzen
genau einen Status-/Problemfilter) und eine **Filterleiste ohne Filtern-Button** — jedes Feld löst über die
generische Alpine-Komponente `entriesCockpitFilters` (`resources/js/entries-cockpit-filters.js`, `x-model`+`$watch`,
Suche via `x-model.debounce`) sofort eine neue Suche aus. Die Kachelzahlen zählen im Wettkampf-/Suchkontext, aber
unabhängig vom Status-/Problemfilter (`countFiltered()` nutzt dieselben Filter-Methoden wie die Liste).

| Tab | Statusfilter / Kacheln | Problemfilter | Suche |
| --- | --- | --- | --- |
| Einzel | Normal · WDR · SICK · EXH · RJC | Ohne Meldezeit · Ohne Sportklasse | Athlet |
| Staffel | Ausstehend · Bestätigt | Unvollständig (`members < swim_events.relay_count`, korrelierte Subquery) · Ohne Meldezeit | Verein |

Eine **Doppelmeldung** (gleicher Athlet, gleiche Disziplin) gibt es als Problemfilter bewusst nicht — der
Unique-Constraint `[meet_id, swim_event_id, athlete_id]` auf `entries` verhindert sie bereits auf DB-Ebene.

## Meldebasierte Listen (PDF/Excel)

Aus den Meldungen einer Veranstaltung lassen sich vier Listen erzeugen — Aufbereitung in
`MeetEntryListService`, Excel in `MeetEntryListExportService`, PDF über
`resources/views/pdf/entry-lists/*`, ausgeliefert von `MeetEntryListController`. Zugang über ein
„Listen"-Dropdown auf „Alle Meldungen" (Admin) bzw. der Vereins-Meldungsansicht (`club-entries/index`,
nur für Vereinsnutzer); die Links öffnen in einem **neuen Tab** (`target="_blank"`).

**Scope:** Admin = ganze Veranstaltung (alle Vereine), Vereinsnutzer = nur die eigenen Meldungen
(`scopeClubId`: Admin → `null` = alle, sonst `user.club_id`; ein Nicht-Admin ohne Verein bekommt `0` →
leere Liste). Die **Sportpasskontrolle** ist zusätzlich admin-only (Route-Middleware `RequireAdmin`).

**Kopf aller Listen:** ÖBSV (nicht der swimify-Registrierungsverein „SC Diana Wien" aus den Vorlagen).
ÖBSV- und Sport-Austria-Logo liegen in `resources/images/`, werden in den PDFs als base64 eingebettet
(dompdf-Muster wie `wps-athlete-analysis`), im Excel als `Drawing`.

| Liste | Route (`meets.entry-lists.*`) | Umfang | Format |
|-------|-------------------------------|--------|--------|
| Teilnehmerliste     | `teilnehmer.pdf` / `.xlsx`   | pro Verein            | PDF + Excel |
| Sportpasskontrolle  | `sportpass.pdf` / `.xlsx`    | alle Vereine (Admin)  | PDF + Excel |
| Meldeliste nach Namen    | `nach-namen.pdf`        | Admin alle / Verein eigene | PDF |
| Meldeliste nach Bewerben | `nach-bewerben.pdf`     | Admin alle / Verein eigene | PDF |

- **Teilnehmerliste** — offizielle Sport-Austria-Vorlage (Logo oben rechts): Titel „TEILNEHMER(INNEN)LISTE",
  BETRIFFT/ORT, ZEITRAUM + TAGE (Meet-Dauer), ANZAHL DER PERSONEN; Spalten `lfd. Nr | FAMILIEN- und VORNAME |
  WOHNORT (leer, Handausfüllen) | TAGE | UNTERSCHRIFT (leer)`. Ein ergänztes **VEREIN**-Feld (nicht im
  Original-Vordruck) macht den Ausdruck zuordenbar. Excel: ein Arbeitsblatt je Verein.
- **Sportpasskontrolle** — offizielle ÖBSV-Vorlage (Logo + Briefkopf, „Die Kontrolle wurde durchgeführt von"):
  Spalten `lfd. Nr | ZU- und VORNAME (+ Verein als kleine zweite Zeile) | DATUM der letzten UNTERSUCHUNG (leer) |
  SPORTPASS Nummer (= `athlete.license`) | ANMERKUNG (leer) | FAUS (leer)`. Sortiert nach Verein, dann Name.
- **Meldeliste nach Namen** — Geschlecht (Herren/Damen/Mixed) → Verein (Name + Codezeile
  „CODE / Regionalverband / NATION") → Athlet (Lizenz, „Nachname, Vorname", Jahrgang) mit seinen Bewerben
  (Bewerb | Meldezeit | Sportklasse — Einzel und Staffel in derselben Spalte); Staffeln je Verein mit ihren
  Schwimmern in Positionsreihenfolge.
- **Meldeliste nach Bewerben** — Abschnitt (Session; Wochentag + Datum nur bei **eintägiger** Veranstaltung,
  sonst nur „Abschnitt N" — siehe Open Point) → Bewerb („Nr. X  Bewerb [Herren/Damen/Mixed/Alle]") →
  Teilnehmer alphabetisch, wahlweise **ein- oder zweispaltig** (`?columns=1`, Default 2). Im **einspaltigen
  Admin-Modus** zusätzlich der Verein je Einzelsportler (Name · Jahrgang · Meldezeit · Sportklasse · Verein);
  feste, über alle Bewerbe identische Spaltenbreiten, damit die Spalten untereinander stehen. Staffeln mit
  Schwimmern in Reihenfolge.

Staffelname in den Meldelisten: aktuell Vereinsname + laufende Nummer (Platzhalter, bis das
Staffelnamen-Feature umgesetzt ist — siehe `docs/open-points.md`).

## Validierung

**Einzelmeldung (`store`)**

```php
'swim_event_id' => ['required', 'integer', 'exists:swim_events,id'],
'athlete_id'    => ['required', 'integer', 'exists:athletes,id'],
'entry_time'    => ['nullable', 'string', 'max:20'],
'entry_course'  => ['nullable', 'in:LCM,SCM'],
```

Zusätzlich: Das Event muss zum Meet gehören und ein **Einzel-Event** sein (`relay_count = 1`); der Athlet muss zum
Verein des Users gehören (`club->athletes()->findOrFail(...)`).

**Staffelmeldung (`storeRelay`)**

```php
'swim_event_id'  => ['required', 'integer', 'exists:swim_events,id'],
'athlete_ids'    => ['nullable', 'array'],
'athlete_ids.*'  => ['integer', 'exists:athletes,id'],
'entry_time'     => ['nullable', 'string', 'max:20'],
'entry_course'   => ['nullable', 'in:LCM,SCM'],
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
  — Staffelmeldungen (inkl. `relay-athletes`-Filterdaten: aktiv, `gender`, `classes`).
- `tests/Feature/EntriesBestTimesTest.php` — Panel-Bestzeiten (Jahres + absolut) je Melde-Formular.
- `tests/Feature/RelayBestTimeTest.php` — Staffel-Summe (FREE/MEDLEY-Position/IMRELAY), `legs`,
  Teilsumme/Missing, Panel-/Filter-Rendering.
- `tests/Feature/EntryPolicyTest.php` — Meldeschluss/Autorisierung.
- `tests/Feature/MeetEntriesOverviewTest.php` — meet-weite Admin-Gesamtübersicht (Anzeige, Disziplin-Filter,
  Anlege-Buttons, Sportklassen-Ableitung, `return_to`-Redirects, Staffel-Vereinsauswahl, Admin-only).
- `tests/Unit/SportClassRangesTest.php` — Zusammenfassung der Sportklassen zu Bereichen.
- `tests/Feature/EntriesIndexScopeTest.php` — Meldungs-Cockpit ist admin-only (Verein → 403), Admin sieht alle
  Meldungen samt Bearbeiten-Aktion; Anlegen/Bearbeiten/Löschen admin-only.
- `tests/Feature/EntriesCockpitFilterTest.php` — Einzel-Cockpit: Status-/Problemfilter und Kennzahlen-Kacheln
  (Zahlen, Wettkampf-Kontext, Kachel-Links).
- `tests/Feature/RelayCockpitTest.php` — Staffel-Cockpit (`relay-entries.index`): admin-only + Tabs, Status-/
  Problemfilter (Unvollständig/Ohne Meldezeit), Kennzahlen, Vereins-Suche.
- `tests/Feature/MeetEntryListsTest.php` — meldebasierte Listen: Gruppierung/Sortierung (Teilnehmer je Verein,
  Sportpass nach Verein+Name, nach Namen, nach Bewerben), Excel-Aufbau (Blätter, Logos, Zellen), Vereins-Scope
  und Zugriff (PDF/Excel, Sportpass admin-only, Spaltenwahl).
