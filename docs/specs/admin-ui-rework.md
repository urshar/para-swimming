# Spec: Backend-UI-Überarbeitung

Der bestehende, angemeldete Bereich (Athletenverwaltung, Meldewesen, Rekorde, Cup, WPS, Admin-Werkzeuge) ist fachlich
vollständig, zeigte aber im Praxistest wiederkehrende UI-Schwächen: uneinheitliche Formular-Layouts, Filterzeilen, die
umbrechen, statt in einer Zeile zu bleiben, Datumsfelder im falschen Format, und Formularfelder, die fachlich gar nicht
(mehr) am richtigen Ort gepflegt werden.

Diese Spec sammelt die Korrekturen **modulweise**, in eigenen Phasen — wie beim öffentlichen Frontend
(`public-frontend-modules.md`), nur ohne neue Funktionalität: reine UI-Korrekturen an bestehenden Controllern/Views.
Jede Phase: eigener Branch, eigener PR, eigener Abschnitt hier mit Ergebnis und Tests.

**Kein Schnitt nach Layer** (erst alle Controller, dann alle Models): UI-Korrekturen sind praktisch immer
View-Änderungen, der Controller bleibt dünn. Der sinnvolle Schnitt bleibt das fachliche Modul.

## Wiederkehrender technischer Befund: `flux:input` und Breitenklassen

`flux:input` rendert seinen sichtbaren Rahmen als `<div>`-Wrapper mit fix eingebautem `w-full`
(`vendor/livewire/flux/stubs/resources/views/flux/input/index.blade.php`). Eine von außen per `class="w-64"`
übergebene Breite landet zwar auf demselben Wrapper, verliert aber im kompilierten CSS gegen dieses eingebaute
`w-full`, wodurch das Feld ungewollt auf volle Containerbreite aufreißt und Filterzeilen zerreißt.
`flux:select` hat dasselbe `w-full` mit `:where()` ohne CSS-Spezifität gesetzt, ist also nicht betroffen — deshalb
sitzen Selects in einer Filterzeile meist richtig, während Inputs daneben umbrechen.

**Fix:** `flux:input` mit fester Breite in einen eigenen `<div class="w-XX shrink-0">…</div>` wrappen, statt die Breite
direkt an `flux:input` zu übergeben. Wird modulweise mitgezogen, sobald die jeweilige Phase die betroffene View
anfasst — kein blindes Sweep über den ganzen Code auf einmal.

## Phase 1 — Athleten — **abgeschlossen**

| Baustein                                     | Art        | Zweck                                                                                                |
|----------------------------------------------|------------|------------------------------------------------------------------------------------------------------|
| `AthleteController::index()`                 | Controller | Buchstaben-Filter (Nachname), dritte Aktiv-Option, Nation nach IOC-Code, merkt Listen-URL in Session |
| `AthleteController::create()/edit()`         | Controller | Nation nach IOC-Code, kein `exceptionCodes` mehr (Block entfällt)                                    |
| `AthleteController::store()/update()`        | Controller | Sportklassen/Exceptions nicht mehr über dieses Formular synchronisiert                               |
| `AthleteController::validateAthlete()`       | Controller | Validierung um `sport_classes`/`exceptions` bereinigt                                                |
| Views `athletes/{index,form,show}.blade.php` | Blade      | Filterzeile, Buchstaben-Leiste, Formular-Layout, Klassifikations-Panel                               |

### Athleten-Liste

- Filterzeile: Such- und Klasse-Feld über den `w-XX shrink-0`-Wrapper repariert, alle Filter + Button passen wieder in
  eine Zeile.
- Neue Buchstaben-Leiste (A–Z + "Alle") unter der Filterzeile, filtert `last_name LIKE 'X%'` als einfache Links (kein
  JS, behält bestehende Filter über die Query-String bei).
- "Nur aktive"-Select um die Option "Nur inaktive" ergänzt (neuer Wert `2`, `is_active = false`).
- Nation-Dropdown von `orderBy('name_de')` auf `orderBy('code')` (IOC-Code) umgestellt — Liste, Neu- und
  Bearbeiten-Formular.

### Neuer Athlet / Athlet bearbeiten

- Geburtsdatum: `type="date"` (browserabhängiges Format, z. B. mm/dd/yyyy) ersetzt durch natives `<input>` mit
  IMask-Maske `00.00.0000` (dd.mm.yyyy). Punkt-getrennte Eingabe wird von PHP/Carbon als Tag.Monat.Jahr geparst, daher
  genügt der maskierte Wert direkt — kein verstecktes ISO-Feld nötig. `lazy: true`, weil das Feld optional ist (sonst
  stünde bei jedem leeren Feld `__.__.____` statt eines echten Leerwerts).
- "Vereinseintritt": überflüssige Beschreibung "Datum des Vereinsbeitritts" entfernt (Titel sagt es bereits).
- Verein/Vereinseintritt/Status: vorheriges zweizeiliges Grid mit leerem Platzhalter-Feld bei Neuanlage durch ein
  einheitliches Grid ersetzt (3 Spalten bei Neuanlage inkl. Vereinseintritt, 2 Spalten beim Bearbeiten ohne).
- Sportklassen- und Exceptions-Block komplett aus dem Formular entfernt — wird ausschließlich über
  "Klassifikation eintragen" in der Detailansicht gepflegt (einziger Weg, der auch die Klassifikations-History
  fortschreibt; das Formular hätte sonst beim Speichern alle Sportklassen/Exceptions gelöscht und aus einem leeren
  Formular neu angelegt). Nach dem Anlegen eines neuen Athleten Redirect zur Detailseite mit automatisch geöffnetem
  "Klassifikation eintragen"-Panel (`?neue_klassifikation=1`).

### Klassifikation eintragen/bearbeiten (Detailseite)

- Kategorie-Dropdown (S/SB/SM) neben den Exception-Checkboxen entfernt — der Exception-Code ist bereits eindeutig einer
  Lage zugeordnet, die Zuordnung war fachlich überflüssig. Reine UI-Änderung: die DB-Spalte `category`
  bleibt bestehen (nullable), historische Einträge zeigen sie in der Badge-Anzeige weiter an.
- Checkboxen von einer Spalte auf drei Spalten umgestellt. Betrifft beide Stellen: Eintragen- und Bearbeiten-Formular.

### Zurück-Navigation merkt sich die Listen-Ansicht

`AthleteController::index()` merkt sich die zuletzt aufgerufene Listen-URL (inkl. Filter, Buchstabe, Seite) in
`session('athletes.list_url')`. Die "←"-Buttons in Detailansicht und Formular sowie der "Abbrechen"-Button beim
Neuanlegen zeigen darauf statt fix auf `athletes.index()` — ohne vorherigen Listenaufruf (z. B. Direktlink) fällt es
sauber auf die normale Liste zurück. Reine Session-URL, kein Eloquent-Modell in der Session.

**Tests** (`--group=admin-ui-athletes`).

## Geplante nächste Module

Reihenfolge noch offen wird beim jeweiligen Start festgelegt. Kandidaten mit bekanntem `flux:input`-Breitenbefund (siehe
oben) aus einem ersten Grep: `qualifying-time-lists/form.blade.php`, `records/index.blade.php` — dort im Rahmen der
jeweiligen Phase mitfixen.
