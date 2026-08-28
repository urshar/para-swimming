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

## Phase 2 — Globales Layout (Header/Sidebar) — **abgeschlossen**

Kein einzelnes Fach-Modul, sondern die Shell selbst (`resources/views/layouts/app.blade.php`), die von jeder Seite im
angemeldeten Bereich verwendet wird. Auslöser: Die Sidebar war durch die vielen Fach-Module (siehe oben) so lang, dass
ständiges Scrollen nötig war, und es fehlte ein erreichbarer Menüpunkt für Profil/Abmelden.

| Baustein                               | Art      | Zweck                                                                           |
|----------------------------------------|----------|---------------------------------------------------------------------------------|
| Neue Kopfzeile (`flux:header`)         | Blade    | Logo, Dark/Light-Umschalter, Benutzermenü — volle Breite über Sidebar + Content |
| Sidebar-Gruppen (`flux:navlist.group`) | Blade    | Nur die Gruppe der aktuellen Route ist beim Laden aufgeklappt                   |
| Dark/Light-Umschaltung                 | Blade/JS | Umgestellt von eigenem Alpine-State auf Flux' natives `$flux.appearance`-System |

### Kopfzeile

- Logo aus der Sidebar in eine neue, volle Breite über Sidebar UND Content spannende Kopfzeile verschoben. Wichtig:
  Die DOM-Reihenfolge (`flux:header` **vor** `flux:sidebar`) entscheidet über das Grid-Layout, das Flux automatisch
  anlegt (`vendor/livewire/flux/dist/flux.css`, `*:has(>[data-flux-main])` vs.
  `*:has(>[data-flux-sidebar]+[data-flux-header])`)
  — Header vor Sidebar ergibt eine volle Kopfzeile, nicht nur eine neben der Sidebar.
- Logo-Abschnitt der Kopfzeile ist exakt `w-64` breit (dieselbe Breitenklasse wie `flux:sidebar` selbst), damit er mit
  der Sidebar-Spalte fluchtet, unabhängig von der tatsächlichen Sidebar-Breite. Der Dark/Light-Umschalter sitzt direkt
  danach — dadurch auf Höhe des Hauptinhalts, nicht am Logo klebend und nicht ganz am rechten Rand beim Benutzermenü.
- `flux:header` bringt selbst `px-6 lg:px-8` mit, das gegen von außen übergebene Padding-Klassen mit derselben
  Spezifität verliert (derselbe Effekt wie der `flux:input`-Breitenbefund oben) — hier mit dem Tailwind-v4-
  `!`-Suffix (`px-0!`) übersteuert, die Innenabstände tragen die beiden Abschnitte in der Kopfzeile selbst.
- Benutzermenü (Avatar/Name, "Einstellungen", "Abmelden") existierte bereits als
  `components/desktop-user-menu.blade.php`, hing aber nur im nie eingebundenen Starter-Kit-Rest
  `layouts/app/header.blade.php` und war dadurch im echten Layout nie erreichbar. Direkt in die neue Kopfzeile eingebaut
  (kompaktere `flux:profile` statt `flux:sidebar.profile`).

### Sidebar: einklappbare Gruppen

Jede `flux:navlist.group` bekommt `expandable` + ein berechnetes `:expanded` (dieselben `routeIs()`-Bedingungen wie die
enthaltenen Items). Flux stellt das nativ über `<ui-disclosure>` bereit, kein eigenes JS nötig. Beim Laden ist nur die
Gruppe der aktuellen Route offen, alle anderen zeigen nur die Überschrift; der Nutzer kann jede Gruppe weiterhin manuell
auf-/zuklappen (kein exklusives Akkordeon — mehrere können gleichzeitig offen sein).

### Zwei Bugs beim Reachable-Machen der Settings-Seite gefunden

Das neue Benutzermenü macht `/settings/profile` und `/settings/appearance` erstmals über die UI erreichbar — dabei zwei
vorbestehende, bis dahin unbemerkte Bugs aufgedeckt:

- **Dark/Light flippte beim Besuch von Settings → Appearance.** Es liefen zwei unabhängige Dark-Mode-Systeme parallel:
  ein eigener Alpine-`x-data` auf `<html>` (`localStorage['theme']`) und Flux' eigenes, von der Appearance-Seite intern
  bereits genutztes `$flux.appearance`-System (`localStorage['flux.appearance']`, Alpine-Magic aus dem Flux-JS-Bundle).
  Sobald die Appearance-Seite geladen wurde, hat Flux seinen eigenen (nie gesetzten) Zustand angewendet und den eigenen
  Alpine-State überschrieben. Fix: komplett auf Flux' natives System umgestellt (`$flux.dark` als Getter/Setter,
  `@fluxAppearance` in `<head>` für FOUC-freies frühes Anwenden), eigenen Alpine-State entfernt. Header-Umschalter und
  Settings-Seite teilen sich jetzt denselben Zustand.
- **`@fluxStyles`** stand als literaler Text sichtbar oben im Body. In der installierten Flux-Version ist das gar keine
  registrierte Blade-Direktive mehr (nur `@fluxScripts`/`@fluxAppearance`, siehe
  `vendor/livewire/flux/src/AssetManager.php::registerAssetDirective()`) — unregistrierte `@wort`-Muster lässt Blade
  unverändert im Output stehen. Ersetzt durch `@fluxAppearance` (siehe oben).

**Tests**: `composer test` (volle Suite, betrifft jede Seite) — 1387 Tests, keine Regressionen. Kein eigener
`--group`, da reine Layout-/Blade-Änderung ohne neue fachliche Logik.

## Phase 3 — Farbschema — **abgeschlossen**

Kein einzelnes Fach-Modul, sondern global wirkendes Theming (`resources/css/app.css`) plus die Grundinstallation von
Flux Pro (`composer.json`, `.gitignore`). Auslöser: Das bisherige Akzent-Farbschema war monochrom (`neutral-800`/Weiß),
der öffentliche Bereich nutzt seit `36d9157` durchgängig Blau als Akzentfarbe — Admin und öffentlicher Bereich sollten
optisch zusammenpassen.

| Baustein                      | Art    | Zweck                                                                                                                       |
|-------------------------------|--------|-----------------------------------------------------------------------------------------------------------------------------|
| `resources/css/app.css`       | CSS    | `--color-accent`/`--color-accent-content`/`--color-accent-foreground` auf Blau umgestellt (hell + dunkel), Fokusring-Fix    |
| `composer.json`, `.gitignore` | Config | Flux Pro als Composer-Abhängigkeit (`livewire/flux-pro: ^2.17`), privates Repo `composer.fluxui.dev`, `auth.json` ignoriert |

### Drei Flux-Farbtoken, drei unterschiedliche Rollen

Flux unterscheidet nicht nur "eine Akzentfarbe", sondern drei leicht verwechselbare Rollen:

- `--color-accent` — die Füllfarbe selbst (Button-Hintergrund, ausgewählte Kalendertage, aktive Tabs).
- `--color-accent-content` — akzentfarbener **Text auf normalem Seitenhintergrund** (Links, aktiver Sidebar-Eintrag).
- `--color-accent-foreground` — Textfarbe **auf** einer akzentgefüllten Fläche (Button-Beschriftung, Checkbox-Häkchen,
  Switch-Thumb).

Vorher waren alle drei praktisch identisch (`neutral-800`/`neutral-800`/`white`) — funktioniert in einem monochromen
Schema, führt mit Blau als Akzent aber zu Kontrastproblemen. Neue Werte, gegen `docs/accessibility.md`
(WCAG AA: ≥4.5:1 Fließtext, ≥3:1 UI-Komponenten/Fokusringe) geprüft:

| Token                       | Hell                     | Dunkel                      | Kontrast          |
|-----------------------------|--------------------------|-----------------------------|-------------------|
| `--color-accent`            | `blue-600`               | `blue-600`                  | — (Füllfarbe)     |
| `--color-accent-content`    | `blue-600` (auf Weiß)    | `blue-400` (auf `zinc-900`) | ~5,16:1 / ~4,95:1 |
| `--color-accent-foreground` | `white` (auf `blue-600`) | `white` (auf `blue-600`)    | ~6,97:1           |

`--color-accent-content` wechselt zwischen den Modi (helleres Blau im Dunkelmodus, sonst zu dunkel auf dunklem Grund),
`--color-accent`/`--color-accent-foreground` bleiben modusunabhängig — exakt das Muster, das Flux' eigene eingebaute
Farbpresets verwenden (`color="blue"` bei `flux:button variant="primary"`,
`vendor/livewire/flux/stubs/resources/views/flux/button/index.blade.php`), als Gegenprobe herangezogen.

### Fokusring-Kopplung aufgelöst

Die bestehende Fokusring-Regel nutzte `ring-offset-accent-foreground` — im alten Schema zufällig identisch mit dem
Seitenhintergrund. Mit `accent-foreground` jetzt modusunabhängig Weiß hätte das im Dunkelmodus einen weißen Ring-Abstand
auf dunklem Grund erzeugt. Entkoppelt: `ring-offset-white dark:ring-offset-zinc-900`, direkt an die tatsächliche
Flächenfarbe gebunden statt an ein Akzent-Token, das nur zufällig passte.

**Tests**: `composer test` (volle Suite) — 1387 Tests, keine Regressionen. Kein eigener `--group`, reine CSS-Änderung
ohne fachliche Logik.

## Phase 4 — Datumsfelder (`flux:date-picker`) — **abgeschlossen**

23 Datumsfelder in 10 Views von `<flux:input type="date">` auf `<flux:date-picker type="input">` umgestellt. Auslöser:
`type="date"` zeigt browser-/systemabhängig unterschiedliche Formate (z. B. `mm/dd/yyyy` in en-US), was in einer
österreichischen Fachanwendung Verwechslungsgefahr ist. `flux:date-picker` (Flux Pro) rendert stattdessen ein
locale-unabhängiges Tag/Monat/Jahr-Segment-Feld mit Kalender-Popup.

| Baustein                                  | Art      | Zweck                                                                                                  |
|-------------------------------------------|----------|--------------------------------------------------------------------------------------------------------|
| 10 Views (siehe unten)                    | Blade    | `type="date"` → `flux:date-picker type="input"`, `clearable` bei optionalen Feldern                    |
| `athletes/form.blade.php`                 | Blade/JS | Geburtsdatum von IMask-Maske (`type="text"` + `00.00.0000`) auf `flux:date-picker` umgestellt          |
| `livewire/wps-athlete-analysis.blade.php` | Blade    | `x-model` → `wire:model` (date-picker ist ein Custom Element, kein reiner Input)                       |
| `records/form.blade.php`                  | Blade    | Staffel-Geburtsdatum-Grid-Spalte `6rem` → `10rem` verbreitert (Platz für Segment-Trigger), `size="sm"` |

Betroffene Views: `athletes/{show,form}.blade.php`, `base-times/{import,versions/form}.blade.php`,
`championships/form.blade.php`, `wps/import/form.blade.php`, `meets/form.blade.php`,
`qualifying-time-lists/form.blade.php`, `records/form.blade.php`, `livewire/wps-athlete-analysis.blade.php`.

### `locale`-Attribut nötig — Segmentreihenfolge folgt nicht `<html lang>`

`flux:date-picker` bestimmt Reihenfolge/Trennzeichen der Tag/Monat/Jahr-Segmente über `Intl.DateTimeFormat`, primär
anhand von `navigator.language` (Browsersprache des jeweiligen Nutzers) — **nicht** anhand von `<html lang="de">`
(`ui-date-picker.mount()`, `vendor/livewire/flux-pro/dist/flux.js`). Ohne Gegenmaßnahme hätte ein Admin mit
englischsprachigem Browser `mm/dd/yyyy` statt `dd.mm.yyyy` gesehen. Fix: `locale="de-AT"` explizit an jedem
`flux:date-picker` gesetzt (wird als Attribut an das zugrunde liegende `<ui-date-picker>`-Element durchgereicht,
erzwingt Tag→Monat→Jahr mit Punkt-Trennzeichen unabhängig vom Browser).

### Geburtsdatum: IMask-Maske abgelöst

`athletes/form.blade.php` nutzte bisher eine IMask-Maske (`00.00.0000`) statt `type="date"`, um genau dasselbe
Format-Problem zu umgehen. Mit `flux:date-picker` + `locale="de-AT"` entfällt der Sonderfall — der Wert läuft jetzt wie
bei allen anderen Datumsfeldern intern als ISO `yyyy-mm-dd`, passend zur bestehenden
`'birth_date' => 'nullable|date'`-Validierung (`AthleteController::validateAthlete()`).

**Tests**: `composer test` (volle Suite) — 1387 Tests, keine Regressionen. Kein eigener `--group`, reine
Formularänderung.

## Phase 5 — Datei-Upload (`flux:file-upload`) — **abgeschlossen**

Native `<input type="file">` in 3 der 5 ursprünglich geprüften Import-/Upload-Formulare durch `flux:file-upload` +
`flux:file-upload.dropzone` ersetzt (Klick **und** Drag & Drop statt nur Klick).

| Baustein                                                                                     | Art   | Zweck                                                                                      |
|----------------------------------------------------------------------------------------------|-------|--------------------------------------------------------------------------------------------|
| `wps/import/form.blade.php`, `base-times/import.blade.php`, `admin/documents/form.blade.php` | Blade | `<input type="file">` → `flux:file-upload` + Dropzone                                      |
| `resources/js/flux-file-upload-sync.js`                                                      | JS    | Fix: Drag&Drop-Dateien zusätzlich auf das echte `<input>` schreiben (Details unten)        |
| `resources/js/file-upload-field.js`                                                          | JS    | Alpine-Komponente, zeigt gewählten Dateinamen an                                           |
| `resources/js/document-form.js`                                                              | JS    | Dateinamen-Anzeige direkt in bestehenden `x-data`-Scope integriert statt zweite Komponente |

`records/import.blade.php` und `lenex/import.blade.php` bewusst **nicht** umgestellt — beide haben bereits eine eigene,
funktionierende Alpine-Dropzone samt Lade-Overlay während der Analyse (Spinner, Auto-Submit), die
`flux:file-upload` nicht von Haus aus mitbringt; ein Umstieg hätte diesen Teil ohne echten Gewinn neu bauen müssen.

### Gefundener Bug: Drag & Drop schreibt nicht auf das echte `<input>`

`flux:file-upload` hält ausgewählte Dateien intern in einem JS-Array (für Vorschau/Chips und Livewires
`wire:model`-Upload). Beim Klick-Weg setzt der Browser `.files` auf dem versteckten echten `<input>` selbst — das reicht
für ein normales `multipart/form-data`-POST. Beim Drag & Drop passiert das **nicht**: Flux liest die Dateien nur in das
interne Array ein, ohne sie auf das echte `<input>` zurückzuschreiben (verifiziert: kein einziges Vorkommen von
`DataTransfer` im kompletten `flux.js`). Da alle drei betroffenen Formulare klassische (nicht-Livewire) POST-Formulare
sind, hätte eine per Drag & Drop abgelegte Datei optisch ausgewählt gewirkt, wäre beim Absenden aber verschwunden.

**Fix** (`flux-file-upload-sync.js`, global in `app.js` registriert, kein Alpine nötig): `flux:file-upload` feuert bei
jeder Änderung — Klick wie Drop — ein `change`-Event direkt auf dem `<ui-file-upload>`-Element selbst. Der Listener
greift dieses Signal ab und schreibt die aktuelle Dateiliste per `DataTransfer` auf das echte `<input>`
zurück. Per simuliertem `drop`-Event im Browser verifiziert: Datei landet nachweislich im echten, absendbaren
`<input>`.

### Dateinamen-Anzeige nötig

`flux:file-upload` versteckt das eigentliche `<input>` (`sr-only`) und zeigt — anders als ein sichtbares natives
`<input type="file">` — den gewählten Dateinamen nicht von selbst an; `flux:file-item` liefert nur die Optik einer
Datei-Karte, keine Bindung an die aktuelle Auswahl. Für `wps/import` und `base-times/import` eine neue,
wiederverwendbare Alpine-Komponente (`fileUploadField`), für `admin/documents/form.blade.php` direkt in die bestehende
`documentForm()`-Komponente integriert (die dort schon den Dateinamen für den LENEX-Hinweis auswertet).

### Kein natives `required`-Popup mehr

HTML5-`required`-Validierung wirkt nur auf einem echten, form-assoziierten `<input>` — `flux:file-upload` reicht
`required` nicht auf das versteckte `<input>` durch (anders als `accept`, das Flux selbst weiterreicht). Betrifft alle
drei Felder; serverseitig ist `required|file` in allen drei Controllern bereits vorhanden (`WpsPointImportController`,
`BaseTimeImportController`, `DocumentController::store()`), der Fehler erscheint also weiterhin zuverlässig über
`flux:error`, nur ohne sofortiges Browser-Popup.

**Tests**: `composer test` (volle Suite) — 1387 Tests, keine Regressionen. Kein eigener `--group`, reine
Formularänderung.

## Phase 6 — Aufklappbare Bereiche (`flux:accordion`) — **abgeschlossen**

Handgebautes Alpine-Aufklapp-Muster (`x-data="{ open: ... }"` + Button/Icon + `x-show`) durch `flux:accordion` /
`flux:accordion.item` ersetzt, wo es strukturell passt. Von den 6 ursprünglich geprüften Views wurden 4 umgestellt; bei
2 Views (bzw. einem Teilbereich einer dritten) passt `flux:accordion` strukturell nicht — siehe unten.

| Baustein                                                | Art   | Zweck                                                                               |
|---------------------------------------------------------|-------|-------------------------------------------------------------------------------------|
| `qualifying-time-lists/{qualifications,show}.blade.php` | Blade | Sportklassen-Gruppen je Seite von Hand-Alpine auf `flux:accordion` umgestellt       |
| `results/index.blade.php`                               | Blade | Box "übersprungene Ergebnisse" umgestellt                                           |
| `records/import-preview.blade.php`                      | Blade | 2 von 3 `<details>`-Blöcken umgestellt ("Ausstehende Rekorde", "Nationale Rekorde") |

### Zwei Views bewusst nicht umgestellt

- **`athletes/show.blade.php`** (Vereins-History, Klassifikation, Level, Kader) — kein Akkordeon-Muster: Ein Button
  blendet dort ein **Formular** über einer immer sichtbaren Tabelle ein/aus, die Tabelle selbst klappt nie zu. Bei einem
  Akkordeon klappt die Überschrift den ganzen Inhalt auf/zu — passt hier semantisch nicht.
- **`cups/club-ranking.blade.php`** (Vereinsrangliste, Zeilen-Detail) — technisch nicht möglich: Das aufklappbare
  Element ist eine `<tbody x-data="{open:false}">` mit zwei `<tr>`-Zeilen. `flux:accordion.item` rendert ein
  `<ui-disclosure>`-Custom-Element, das kein gültiges `<tbody>` ersetzen kann, ohne die Tabelle zu zerbrechen (Browser
  akzeptieren nur `<tr>` als direktes Kind von `<table>`/`<thead>`/`<tbody>`/`<tfoot>`). Das bestehende Alpine bleibt
  hier die einzig saubere Lösung.
- **`records/import-preview.blade.php`**, dritter `<details>`-Block ("Regionale Rekorde", pro Landesverband) — die
  `<summary>` sitzt dort als Teil einer Flex-Zeile **neben** Import/Überspringen-Radiobuttons.
  `flux:accordion.heading` ist ein `w-full`-Button, der die ganze Zeile für sich will — passt nicht sauber neben weitere
  Controls in derselben Zeile. Als natives `<details>` belassen.

### Innere Polsterung: `first:`/`last:`-Reset schlägt eigene Klassen

`flux:accordion.item` bringt eingebaut `pt-4 first:pt-0 pb-4 last:pb-0` mit (für mehrere Einträge in einer Gruppe mit
Trennlinien gedacht). Bei einem alleinstehenden Element (gleichzeitig erstes und letztes Kind) gewinnt der
`first:`/`last:`-Reset gegen eine zusätzlich angegebene eigene `p-4`/`p-5`-Klasse auf `flux:accordion.item` selbst
(höhere CSS-Spezifität durch die Pseudoklasse, derselbe Effekt wie der `flux:input`-Breitenbefund oben) — die gewünschte
Innenpolsterung landet dadurch wirkungslos. Fix: Polsterung stattdessen auf `flux:accordion.heading`
und `flux:accordion.content` selbst setzen (eigene, nicht-konkurrierende Elemente), nicht auf `flux:accordion.item`.

**Tests**: `composer test` (volle Suite) — 1387 Tests, keine Regressionen. Zusätzlich `records/import-preview.blade.php`
mit einer temporären Pest-Testdatei direkt gerendert (kein Alltagsroute-Test in der Suite vorhanden, Formular braucht
echten Datei-Upload + Session) — bestätigt fehlerfreies Rendern beider umgestellter Blöcke, danach wieder entfernt. Kein
eigener `--group`, reine Formular-/Anzeigeänderung.

## Phase 7 — Tabs & Autocomplete — **abgeschlossen (Autocomplete-Teil zurückgestellt)**

| Baustein                      | Art   | Zweck                                                                                                        |
|-------------------------------|-------|--------------------------------------------------------------------------------------------------------------|
| `records/index.blade.php`     | Blade | Hauptkategorie-Reiter (International/National/Regional) von Hand-Pills auf `flux:tabs`/`flux:tab` umgestellt |
| `admin/users/index.blade.php` | Blade | Verein-Dropdown (`wire:model="club_id"`, Livewire) auf `flux:select variant="combobox"` umgestellt           |

### Tabs: `flux:tab` funktioniert als reiner Navigations-Link

Die Kategorie-Reiter navigieren bei jedem Klick auf eine neue URL (volle Seiten-Neuladung, kein Client-seitiges
Umschalten von Panels) — dafür reicht `flux:tab` mit `href` und `:selected`, ohne
`flux:tab.group`/`flux:tab.panel` (die sind für Client-seitiges Panel-Switching gedacht, hier nicht nötig).
`flux:tab` rendert intern `flux:button-or-link` und wählt automatisch `<a>` statt `<button>`, sobald `href`
gesetzt ist. Per echtem Klick auf einen Reiter im Browser verifiziert: korrekter Link, `data-selected`
folgt der aktuellen Kategorie.

### Autocomplete zurückgestellt:
`flux:select variant="combobox"` liest den Startwert nur aus der JS-Eigenschaft, nicht aus dem HTML-Attribut

Ursprünglich für 13 Dateien mit `club_id`/`nation_id`-Dropdowns geplant (53 Vereine, 96 Nationen — eine Suchbox ist dort
spürbar besser als ein langes natives `<select>`). Nach der Umstellung aller 13 Dateien und einer echten Browser-Prüfung
mit vorbelegtem Wert (`value="{{ request('nation_id') }}"` bzw.
`old(...)`) zeigte sich: ** Die Combobox übernimmt einen serverseitig gerenderten `value`-HTML-Attribut nicht als
Startauswahl.**

Ursache (im kompilierten `vendor/livewire/flux-pro/dist/flux.js` nachvollzogen): `Controllable.boot()`
liest den Startwert über `this.initialState = this.el.value` — das ist eine **JS-Objekteigenschaft**, keine
Attribut-Abfrage (`getAttribute('value')`). Bei einem eigenen Custom Element ist `.value` ohne explizite Reflection
zunächst nur eine leere Eigenschaft; das im HTML stehende `value="1"` wird nie gelesen. Livewire setzt bei `wire:model`
diese Eigenschaft aktiv selbst während seines Hydrate-/Morph-Zyklus — dort funktioniert es also, aber nirgends sonst.

Verifiziert auf `/athletes` (Nation-Filter): Filter gesetzt → Formular abgeschickt → URL zeigt korrekt
`nation_id=1` → nach Neuladen der Seite zeigt die Combobox **keine Auswahl mehr an**, obwohl der Filter aktiv ist. Bei
einem Pflichtfeld ohne `clearable` (z. B. der Verein in `entries/edit.blade.php`) ist das mehr als kosmetisch: Öffnet
man die Seite und speichert ohne die Combobox anzufassen, würde das zugrundeliegende versteckte Submit-Feld leer bleiben
und den bestehenden Wert überschreiben.

Test mit direktem Setzen der JS-Eigenschaft (`element.value = '1'`, am DOM vorbei an Blade) aktualisiert zwar das
versteckte Submit-Feld korrekt, aber das sichtbare Suchfeld bleibt trotzdem leer — die Trigger-Anzeige synchronisiert
sich nur über einen echten Nutzer-Klick auf eine Option, nicht über programmatisches Setzen des Werts. Kein sauber
behebbarer Rand-Fall, sondern eine strukturelle Lücke der Komponente außerhalb von Livewire — ein eigener JS-Fix müsste
Teile der internen Auswahl-/Anzeigelogik von Flux Pro nachbauen, was nicht verhältnismäßig ist.

**Entscheidung**: Die 12 Dateien mit klassischem (nicht-Livewire-)Formular bleiben beim nativen
`flux:select` + `<option>`/`@selected()`. Nur `admin/users/index.blade.php` (`wire:model`, der offiziell unterstützte
Weg für diese Komponente) wurde umgestellt.

**Tests**: `composer test` (volle Suite) — 1387 Tests, keine Regressionen. Tabs per echtem Klick im Browser geprüft; die
verworfene Combobox-Umstellung wurde vor dem Commit vollständig zurückgesetzt (`git checkout --` auf die 12 betroffenen
Dateien). Kein eigener `--group`, reine Formular-/Anzeigeänderung.

**Nachtrag (Phase 8)**: Der obige Befund stimmt nur für den Weg über `value=""` am äußeren `<flux:select>`. Es gibt
einen zweiten, funktionierenden Weg — siehe "Runde 2" unten.

## Runde 2: Chevron-Dropdowns & Switches (Phase 8+)

Auslöser: `flux:select` (Standard-Variante) rendert ein natives `<select appearance-none>` **ohne** Pfeil-Ersatz
(kein `@tailwindcss/forms`-Plugin im Projekt) — alle bisherigen Dropdowns sind dadurch pfeillos. Zusätzlich: einige
Ein/Aus-Checkboxen sollen zu `flux:switch` werden. Diese Runde geht dafür **alle** Admin-Module durch, gröber
geschnitten als Runde 1 (Phase 1–7): Phase 8 Stammdaten, 9 Wettkämpfe & Meldungen, 10 Rekorde & Richtzeiten, 11 WPS &
Auswertungen, 12 Cup & Meisterschaften, 13 LENEX & Admin-Werkzeuge.

### Combobox-Fix gefunden: Option markieren statt Wrapper

Der in Phase 7 gefundene Bug (`value=""` am `<flux:select>` wird nie gelesen) hat einen sauberen Workaround, der
**nicht** über den betroffenen Mechanismus läuft: Statt den Wert am äußeren Element zu setzen, wird die passende
`<flux:select.option>` selbst mit `selected` markiert. Im kompilierten `flux.js` nachvollzogen: `UIOption.mount()`
liest `hasAttribute("selected")` **synchron und direkt** (`selectedInitially: this.hasAttribute("selected")`,
`js/option.js`) — komplett unabhängig vom kaputten `Controllable.boot()`-Pfad, der nur beim äußeren `value`-Attribut
greift. Live geprüft (`/athletes`, `/clubs`, Formulare mit vorbelegtem Wert): verstecktes Submit-Feld **und**
sichtbarer Trigger-Text beide korrekt vorbelegt — sowohl mit `flux:select variant="listbox"` (Button-Trigger) als
auch mit `variant="combobox"`.

```blade
<flux:select variant="listbox" searchable name="nation_id" placeholder="…" clearable>
    @foreach($nations as $nation)
        <flux:select.option value="{{ $nation->id }}" :selected="$currentValue == $nation->id">
            {{ $nation->code }} – {{ $nation->name_de }}
        </flux:select.option>
    @endforeach
</flux:select>
```

Funktioniert identisch mit `wire:model` (Livewire übernimmt dort ohnehin die Wert-Synchronisation).

### Variantenwahl: `listbox` vs. `listbox searchable`

- **`variant="listbox"`** (Button-Trigger, `flux:select.button` bringt `<flux:icon.chevron-down>` fest mit) für
  kurze, statische Options-Listen (Geschlecht, Status, feste Enums) — kein Suchfeld nötig.
- **`variant="listbox" searchable`** zusätzlich für lange, DB-gespeiste Listen (Nation ~96, Verein ~53,
  Klassifizierer): `searchable` blendet ein Suchfeld **im Popover** ein (separates Element, `flux:select.search`),
  ohne den Trigger selbst zu einem Texteingabefeld zu machen — deshalb bleibt die Trigger-Anzeige korrekt, anders als
  bei `variant="combobox"` (dort *ist* der Trigger das Textfeld, und dessen sichtbarer Wert synchronisiert sich nur
  über einen echten Nutzerklick, nicht beim initialen Setzen über `selected` — siehe Phase-7-Befund oben, der für den
  Combobox-**Trigger-Text** also weiterhin gilt). `variant="listbox" searchable` ist daher durchgängig die richtige
  Wahl für lange Listen in dieser App, nicht `variant="combobox"`.

### Checkbox → `flux:switch`: nur bei echten Einzel-Schaltern

`flux:switch` hat einen einfachen `checked`-Boolean-Prop, der (wie `selected` bei Optionen) direkt und synchron aus
dem HTML gelesen wird (`UISwitch.boot()`: `selectedInitially: this.hasAttribute("checked")`, `js/switch.js`) — kein
Risiko wie beim Combobox-`value`-Bug. Nur für **einzelne** Ein/Aus-Einstellungen (z. B. "Aktiv", "Öffentlich
sichtbar") — Checkbox-**Gruppen** (mehrere Optionen aus einer Liste wählen, z. B. WPS-Exceptions) bleiben Checkboxen.

Formulare mit dem klassischen "Checkbox + verstecktes `value=0`"-Trick (verhindert, dass ein abgewähltes Feld beim
Absenden ganz fehlt) behalten das versteckte Feld **vor** dem `flux:switch` — Submittable erzeugt bei
`flux:switch`/aus wie bei einer nativen Checkbox **kein** eigenes Hidden-Feld (`includeWhenEmpty: false`,
`js/submittable.js`), das Formular bräuchte also weiterhin den manuellen Fallback, sofern der Controller nicht schon
`$request->boolean(...)` nutzt (das behandelt ein fehlendes Feld bereits korrekt als `false`).

## Phase 8 — Stammdaten — **abgeschlossen**

Athleten, Vereine, Nationen, Klassifizierer — alle `flux:select`-Dropdowns auf `variant="listbox"` (+ `searchable` bei
langen Listen) umgestellt, alle Einzel-Toggle-Checkboxen auf `flux:switch`.

| Baustein                               | Art   | Zweck                                                                                                                                                           |
|----------------------------------------|-------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `athletes/{index,form,show}.blade.php` | Blade | Alle Dropdowns (Geschlecht, Nation, Verein, Status, Behinderungsart, Klassifikations-Felder, Kaderart) umgestellt; "Aktiver Schwimmer"-Checkbox → `flux:switch` |
| `clubs/{index,form}.blade.php`         | Blade | Nation-, Typ-, Regionalverband-Dropdown umgestellt                                                                                                              |
| `nations/edit.blade.php`               | Blade | "Aktiv"-`flux:checkbox` → `flux:switch`                                                                                                                         |
| `classifiers/{index,form}.blade.php`   | Blade | Typ-, Geschlecht-, Nation-Dropdown umgestellt; "Aktiv"-Checkbox → `flux:switch`                                                                                 |

Bewusst unverändert: die WPS-Exceptions-Checkboxliste (`athletes/show.blade.php`) — echte Mehrfachauswahl, kein
Einzel-Schalter.

`athletes/show.blade.php`s `classification_status`-Select nutzt `x-model="status"` für ein daneben ein-/ausblendendes
FRD-Jahr-Feld — funktioniert unverändert mit `variant="listbox"`, da Flux' `Controllable`-Mixin `Object.defineProperty`
auf `.value` legt und damit für Alpines `x-model` kompatibel ist (dafür ist der Mechanismus gebaut).

**Tests**: `composer test` (volle Suite) — 1387 Tests, keine Regressionen. Live im Browser geprüft: Vorbelegung nach
GET-Filter (`/clubs?nation_id=4`, `/athletes?nation_id=4`), Suchfeld-Filterung im Popover, Switch-Default-Zustand,
Athleten-Detailseite mit Klassifikations-Selects. Kein eigener `--group`, reine Formular-/Anzeigeänderung.

### Design-Feedback-Nachtrag zu Phase 8

Nach erster Durchsicht kam Detail-Feedback zu Layout/Styling, direkt in Phase 8 eingearbeitet (keine eigene Phase):

- **`athletes/form.blade.php`**: Das volle Hinweis-Banner "Vereinswechsel, Klassifikationen … werden in der
  Detailansicht verwaltet" (eigene Zeile über der Stammdaten-Karte) sitzt jetzt kompakt **in derselben Zeile wie die
  Überschrift "Stammdaten"** — nutzt den bis dahin leeren Platz zwischen Überschrift und dem "Aktiver
  Schwimmer"-Schalter, spart eine ganze Zeile Scroll-Strecke bis zum Speichern-Button. Der Link "Zur Detailansicht →"
  ist jetzt ein `flux:button` "Detailansicht" (kein Pfeil-Icon, kein "Zur" mehr nötig).
- **AUT-Standardauswahl**: Alle Nation-Dropdowns in Neuanlage-Formularen (Athlet, Verein, Meet, Rekord — Klassifizierer
  hatte das schon) wählen jetzt automatisch Österreich vor, sofern noch kein Wert gesetzt ist
  (`$nations->firstWhere('code', 'AUT')?->id` als Fallback in `old(...)`/`@selected()`). **Nicht** bei
  Filter-Dropdowns (dort bliebe sonst die Liste beim ersten Aufruf ungewollt auf AUT gefiltert) und nicht bei
  `records/form.blade.php`s "Austragungsland" (Land des Wettkampfs, nicht des Athleten — oft im Ausland).
- **Nation-Sortierung vereinheitlicht**: `ClubController`, `ClassifierController`, `MeetController`, `RecordController`
  sortierten Nationen noch nach `name_de` — jetzt wie `AthleteController` einheitlich nach `code` (IOC-Reihenfolge).
- **`clubs/index.blade.php`**: Spalte "Nation" zeigt jetzt eine Flagge (`<x-flag>`, wie in `nations/index.blade.php`)
  statt Text-Badge; Spalten-Header "Kürzel" → "Code" (zeigte ohnehin schon `$club->code`). Nation-Filter zeigt jetzt
  Code **und** Name, breiter (`w-56` statt `w-40`); Namenssuche schmaler (`w-48` statt `w-64`, war zu breit für ein
  einzelnes Suchfeld).
- **Filtern-Buttons** (`athletes/index`, `clubs/index`, `classifiers/index`): `variant="primary"` (statt Standard) und
  per `ml-auto`-Wrapper an den rechten Rand der Filterzeile geschoben — exakt das im öffentlichen Bereich bereits
  gelöste Muster (`public/qualifying-times/index.blade.php`: `ml-auto` statt nur "letztes Element", sonst bleibt bei
  viel Platz in der Zeile ein sichtbarer Leerraum bis zum tatsächlichen rechten Rand).
- **`classifiers/index.blade.php`**: Filterfeld-Breiten verkleinert (Suche `w-64`→`w-44`, Typ/Aktiv `w-48`→`w-36`,
  Nation `w-40`→`w-44`), passen dadurch in eine Zeile statt umzubrechen.
- **`nations/index.blade.php`**: Komplett überarbeitet — vorher eine ungepaginierte Liste ohne Sortierung. Jetzt
  `flux:table` mit sortierbaren Spalten-Headern (Code/Deutsch/Englisch, Standard: Code aufsteigend) und Pagination
  (25/Seite). `flux:table.column :sortable` rendert intern nur einen `<button>` ohne eigene Navigation (für
  `wire:click` in Livewire-Komponenten gedacht) — ein `<a>` darin wäre ungültiges HTML (interaktiver Inhalt
  verschachtelt). Header daher als eigener `<a>`-Link gebaut, optisch an `flux:table.sortable` angelehnt (gleicher
  Chevron, gleiche Hover-Farbe), statt die eingebaute Sortable-Komponente zu verwenden. `NationController::index()`
  validiert die Sortierspalte gegen eine feste Liste (`code`/`name_de`/`name_en`), Fallback `code` ASC.
- **Zeilen-Buttons farblich vereinheitlicht** (`athletes/index`, `clubs/index`, `classifiers/index`,
  `nations/index`): Bearbeiten-Icon (Stift) durchgängig `text-amber-500!`, WPS-Analyse-Icon (Chart) `text-violet-500!`,
  Löschen-Icon (Mülleimer) `text-red-500!` (war schon vorhanden, aber ohne `!` — siehe Fund unten), Anzeigen-Icon
  (Auge) bleibt neutral (Ausgangspunkt, gegen den die anderen Farben abstechen). Feste Utility-Klasse ohne
  `dark:`-Variante — dieselbe Farbe in Hell- und Dunkelmodus.

  **Fund (Rückmeldung "Buttons sind alle weiß oder schwarz"):** `flux:button variant="ghost"` setzt selbst
  `text-zinc-800 dark:text-white` (`vendor/livewire/flux/.../button/index.blade.php`). Eine von außen übergebene
  Farbklasse wie `class="text-amber-500"` hat dieselbe CSS-Spezifität (0,1,0) — welche Regel gewinnt, entscheidet bei
  Tailwind dann die Reihenfolge der generierten Utilities in der kompilierten CSS-Datei, **nicht** die Reihenfolge im
  `class`-Attribut. Per `curl` gegen den Dev-Server nachgewiesen: `.text-amber-500`/`.text-red-500`/`.text-violet-500`
  stehen alle **vor** `.text-zinc-800` in `app.css` — Letzteres gewinnt also immer (derselbe Mechanismus wie beim
  `flux:input`-Breitenbefund und dem `flux:accordion.item`-Polsterungsbefund oben). Fix: Tailwind-v4-`!`-Suffix
  (`text-amber-500!` statt `text-amber-500`) — erzeugt `color: … !important`, gewinnt unabhängig von der
  Quellreihenfolge. Betraf auch den bereits vorher vorhandenen Löschen-Button (`text-red-500` ohne `!`) — war also
  vermutlich nie wirklich rot.
- **`athletes/form.blade.php` — Layout zweimal überarbeitet.** Erster Versuch: Stammdaten/Kontakt&Adresse/Notizen
  nebeneinander in einem `grid-cols-3`-Layout (Stammdaten `lg:col-span-2`, die anderen beiden gestapelt in
  `lg:col-span-1`) — Rückmeldung: die schmale rechte Spalte drängte Kontakt&Adresse/Notizen unangenehm zusammen.
  Stattdessen `flux:tab.group`/`flux:tabs`/`flux:tab.panel` (zwei Reiter: "Stammdaten", "Kontakt & Adresse" inkl.
  Notizen) — reines Client-seitiges Umschalten ohne Livewire (`ui-tab-group`/`ui-tabs`, `flux:tab` ohne `href`
  fungiert als Button, kein Navigations-Link), jede Karte bekommt dadurch wieder die volle Formularbreite. Der
  Detailansicht-Hinweis (siehe oben) bekam dabei zusätzlich eine eigene Zeile statt in der Überschriftenzeile
  mitzulaufen — wirkte dort auf die schmalere Kartenbreite bezogen zu sehr nach rechts gequetscht.

**Tests**: `composer test` (volle Suite) — 1387 Tests, weiterhin grün. Live im Browser geprüft (DOM-Struktur,
`getComputedStyle`, echte Sortier-Klicks auf `/nations`, AUT-Vorbelegung auf `/athletes/create` und
`/meets/create`) — Layout-Prüfung per `getComputedStyle` zeigte zunächst `display: block` statt `flex`
(bekannte Sandbox-Einschränkung dieses Browser-Tools, Port 5173 blockiert, siehe Phase 3/4/5); per `curl` gegen den
Dev-Server bestätigt, dass `.flex{display:flex}` etc. korrekt kompiliert sind — kein echter Bug.

## Phase 9 — Wettkämpfe & Meldungen — **abgeschlossen**

Umbau von `meets`, `entries`, `results` und `club-entries` (Vereins-Meldewesen) nach dem in Phase 8 etablierten
Muster: statische Dropdowns → `flux:select variant="listbox"` (+ `searchable` bei langen/DB-gespeisten Listen wie
Nation/Athlet/Club/Wettkampf), reine Einzel-Flags → `flux:switch`, Zeilen-Buttons farblich vereinheitlicht
(Bearbeiten `text-amber-500!`, Löschen `text-red-500!`), Filtern-Buttons `variant="primary"` + `ml-auto`.

| Datei | Änderung |
|---|---|
| `meets/index.blade.php` | Bahnlänge-Filter → `listbox`; Filtern-Button `ml-auto`+primary; Zeilen-Buttons eingefärbt |
| `meets/form.blade.php` | Nation (searchable), Bahnlänge, Zeitnahme, Meldetyp, Cup, Richtzeitenliste, WPS-Version → `listbox`; `is_open`/`is_published`/`wps_approved` → `flux:switch` |
| `meets/show.blade.php` | Disziplin-Zeilen-Buttons eingefärbt |
| `entries/index.blade.php` | Wettkampf-Filter → `listbox searchable`; Filtern-Button `ml-auto`+primary; Zeilen-Buttons eingefärbt |
| `entries/form.blade.php` | Disziplin (mit `flux:select.group` statt `optgroup`), Athlet, Club, Kurs, Status → `listbox` (+searchable) |
| `entries/edit.blade.php` | Club, Kurs, Status → `listbox` (+searchable) |
| `results/index.blade.php` | Punkteversion, Wettkampf-Filter, Status-Filter → `listbox` (+searchable); Filtern-Button `ml-auto`+primary; Bearbeiten-Icon eingefärbt |
| `results/form.blade.php` | Disziplin, Athlet, Club, Status → `listbox` (+searchable); WR/ER/NR-Checkboxen → `flux:switch` |
| `club-entries/index.blade.php`, `index-relay.blade.php` | Zeilen-Buttons eingefärbt |
| `club-entries/edit.blade.php` | Kurs-Select → `listbox` |
| `app/Http/Controllers/ResultController.php` | Bug-Fix (siehe unten) |

### `flux:select.group` statt `optgroup`

`entries/form.blade.php`s Disziplin-Auswahl gruppierte Optionen bisher über natives `<optgroup label="…">`. Für
`variant="listbox"` (custom `ui-select`-Element statt echtem `<select>`) existiert dafür ein eigenes Flux-Pro-Pendant:
`<flux:select.group label="…">…</flux:select.group>`, das intern per `componentExists('select.variants.' . $variant)`
erkennt, dass es innerhalb eines Nicht-Default-Varianten-Selects steht, und automatisch die passende (custom) Gruppen-
Darstellung rendert (Trennlinie + Label, blendet sich selbst aus, wenn keine sichtbare Option enthalten ist). Funktioniert
identisch zu `flux:select.option` außerhalb von Livewire.

### Bewusst NICHT umgestellt: Alpine-`x-model`-gebundene Selects

`club-entries/create.blade.php` (Einzelmeldung) und `club-entries/create-relay.blade.php` (Staffelmeldung) binden
ihre Event-/Athlet-/Kurs-Selects über `x-model` + `@change` an Alpine-Komponenten (`singleEntryForm`/`relayEntryForm`
aus den zugehörigen `.js`-Dateien), die daraus per AJAX Folgezustand nachladen (verfügbare Athleten, Bestzeiten). Ob
`variant="listbox"` (ein Custom-Element, das Wert-Änderungen anders auslöst als ein natives `<select>`) mit diesem
bereits produktiv genutzten Zwei-Wege-Datenfluss zuverlässig zusammenspielt, ließe sich nur per Browser-Test mit
funktionierendem CSS zuverlässig verifizieren (Sandbox-Einschränkung, siehe frühere Phasen) — das Risiko einer
stillen Regression in einem von echten Vereinen genutzten Meldeformular wurde als zu hoch bewertet. Bewusst als
Ausnahme stehen gelassen (default `flux:select`), analog zur dokumentierten Kombobox-Ausnahme aus Phase 7/Runde 2.
Ebenso unangetastet: `club-entries/pick-meet.blade.php`s nativer Vereins-Umschalter (kein Flux-Select, `onchange`
navigiert direkt per JS) und `club-entries/edit-relay.blade.php`s Kurs-Select (`x-model="entryCourse"`).

### Bug-Fund: Rekord-Flags gingen beim Abhaken nicht verloren — wurden gar nicht zurückgesetzt

Beim Umstellen der WR/ER/NR-Checkboxen auf `flux:switch` fiel in `ResultController::validateResult()` auf: Die
Validierungsregel `'is_world_record' => 'boolean'` (ohne `sometimes`) nimmt das Feld nur dann ins `$validated`-Array
auf, wenn der Request es überhaupt enthält. Sowohl eine native, nicht angehakte Checkbox als auch ein nicht
aktivierter `flux:switch` senden beim Submit **gar keinen Wert** (kein Pflichtfeld-Fallback) — das Feld fehlt dann
komplett in `$validated`, und `$result->update(...)`/`Result::create(...)` lässt einen zuvor gesetzten Wert
unverändert. Ergebnis: Ein bereits als Weltrekord markiertes Ergebnis ließ sich über das Formular **nicht** wieder
entmarkieren. Vorher bestehender Fehler, unabhängig vom UI-Umbau selbst entdeckt (identisches Verhalten hätte auch
mit der alten `flux:checkbox` bestanden) — gemeldet und in derselben Phase mitbehoben, da direkt im bearbeiteten
Feld: `$validated['is_world_record'] = $request->boolean('is_world_record')` (analog zum bereits korrekten Muster in
`MeetController::store()`/`update()`), ebenso für `is_european_record`/`is_national_record`.

**Tests**: `composer test` (volle Suite) — 1387 Tests, weiterhin grün, inkl. `composer lint:check`. Zusätzlich
`php artisan view:cache` zur Kompilierprüfung sämtlicher Blade-Views (fängt Syntaxfehler in den neuen
`flux:select.option`/`flux:select.group`-Blöcken ab, die von der Test-Suite ggf. nicht direkt gerendert werden).
