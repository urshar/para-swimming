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

Auslöser: `flux:select` (Standard-Variante) rendert ein natives `<select appearance-none>` **ohne** Pfeil-Ersatz (kein
`@tailwindcss/forms`-Plugin im Projekt) — alle bisherigen Dropdowns sind dadurch pfeillos. Zusätzlich: einige
Ein-/Aus-Checkboxen sollen zu `flux:switch` werden. Diese Runde geht dafür **alle** Admin-Module durch, gröber
geschnitten als Runde 1 (Phase 1–7): Phase 8 Stammdaten, 9 Wettkämpfe & Meldungen, 10 Rekorde & Richtzeiten, 11 WPS &
Auswertungen, 12 Cup & Meisterschaften, 13 LENEX & Admin-Werkzeuge.

### Combobox-Fix gefunden: Option markieren statt Wrapper

Der in Phase 7 gefundene Bug (`value=""` am `<flux:select>` wird nie gelesen) hat einen sauberen Workaround, der
**nicht** über den betroffenen Mechanismus läuft: Statt den Wert am äußeren Element zu setzen, wird die passende
`<flux:select.option>` selbst mit `selected` markiert. Im kompilierten `flux.js` nachvollzogen: `UIOption.mount()`
liest `hasAttribute("selected")` **synchron und direkt** (`selectedInitially: this.hasAttribute("selected")`,
`js/option.js`) — komplett unabhängig vom kaputten `Controllable.boot()`-Pfad, der nur beim äußeren `value`-Attribut
greift. Live geprüft (`/athletes`, `/clubs`, Formulare mit vorbelegtem Wert): verstecktes Submit-Feld **und**
sichtbarer Trigger-Text beide korrekt vorbelegt — sowohl mit `flux:select variant="listbox"` (Button-Trigger) als auch
mit `variant="combobox"`.

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

- **`variant="listbox"`** (Button-Trigger, `flux:select.button` bringt `<flux:icon.chevron-down>` fest mit) für kurze,
  statische Options-Listen (Geschlecht, Status, feste Enums) — kein Suchfeld nötig.
- **`variant="listbox" searchable`** zusätzlich für lange, DB-gespeiste Listen (Nation ~96, Verein ~53, Klassifizierer):
  `searchable` blendet ein Suchfeld **im Popover** ein (separates Element, `flux:select.search`), ohne den Trigger
  selbst zu einem Texteingabefeld zu machen — deshalb bleibt die Trigger-Anzeige korrekt, anders als bei
  `variant="combobox"` (dort *ist* der Trigger das Textfeld, und dessen sichtbarer Wert synchronisiert sich nur über
  einen echten Nutzerklick, nicht beim initialen Setzen über `selected` — siehe Phase-7-Befund oben, der für den
  Combobox- **Trigger-Text** also weiterhin gilt). `variant="listbox" searchable` ist daher durchgängig die richtige
  Wahl für lange Listen in dieser App, nicht `variant="combobox"`.

### Checkbox → `flux:switch`: nur bei echten Einzel-Schaltern

`flux:switch` hat einen einfachen `checked`-Boolean-Prop, der (wie `selected` bei Optionen) direkt und synchron aus dem
HTML gelesen wird (`UISwitch.boot()`: `selectedInitially: this.hasAttribute("checked")`, `js/switch.js`) — kein Risiko
wie beim Combobox-`value`-Bug. Nur für **einzelne** Ein-/Aus-Einstellungen (z. B. "Aktiv", "Öffentlich sichtbar") —
Checkbox- **Gruppen** (mehrere Optionen aus einer Liste wählen, z. B. WPS-Exceptions) bleiben Checkboxen.

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
  (`$nations->firstWhere('code', 'AUT')?->id` als Fallback in `old(...)`/`@selected()`). **Nicht** bei Filter-Dropdowns
  (dort bliebe sonst die Liste beim ersten Aufruf ungewollt auf AUT gefiltert) und nicht bei
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
  Löschen-Icon (Mülleimer) `text-red-500!` (war schon vorhanden, aber ohne `!` — siehe Fund unten), Anzeigen-Icon (Auge)
  bleibt neutral (Ausgangspunkt, gegen den die anderen Farben abstechen). Feste Utility-Klasse ohne
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

Umbau von `meets`, `entries`, `results` und `club-entries` (Vereins-Meldewesen) nach dem in Phase 8 etablierten Muster:
statische Dropdowns → `flux:select variant="listbox"` (+ `searchable` bei langen/DB-gespeisten Listen wie
Nation/Athlet/Club/Wettkampf), reine Einzel-Flags → `flux:switch`, Zeilen-Buttons farblich vereinheitlicht (Bearbeiten
`text-amber-500!`, Löschen `text-red-500!`), Filtern-Buttons `variant="primary"` + `ml-auto`.

| Datei                                                   | Änderung                                                                                                                                                     |
|---------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `meets/index.blade.php`                                 | Bahnlänge-Filter → `listbox`; Filtern-Button `ml-auto`+primary; Zeilen-Buttons eingefärbt                                                                    |
| `meets/form.blade.php`                                  | Nation (searchable), Bahnlänge, Zeitnahme, Meldetyp, Cup, Richtzeitenliste, WPS-Version → `listbox`; `is_open`/`is_published`/`wps_approved` → `flux:switch` |
| `meets/show.blade.php`                                  | Disziplin-Zeilen-Buttons eingefärbt                                                                                                                          |
| `entries/index.blade.php`                               | Wettkampf-Filter → `listbox searchable`; Filtern-Button `ml-auto`+primary; Zeilen-Buttons eingefärbt                                                         |
| `entries/form.blade.php`                                | Disziplin (mit `flux:select.group` statt `optgroup`), Athlet, Club, Kurs, Status → `listbox` (+searchable)                                                   |
| `entries/edit.blade.php`                                | Club, Kurs, Status → `listbox` (+searchable)                                                                                                                 |
| `results/index.blade.php`                               | Punkteversion, Wettkampf-Filter, Status-Filter → `listbox` (+searchable); Filtern-Button `ml-auto`+primary; Bearbeiten-Icon eingefärbt                       |
| `results/form.blade.php`                                | Disziplin, Athlet, Club, Status → `listbox` (+searchable); WR/ER/NR-Checkboxen → `flux:switch`                                                               |
| `club-entries/index.blade.php`, `index-relay.blade.php` | Zeilen-Buttons eingefärbt                                                                                                                                    |
| `club-entries/edit.blade.php`                           | Kurs-Select → `listbox`                                                                                                                                      |
| `app/Http/Controllers/ResultController.php`             | Bug-Fix (siehe unten)                                                                                                                                        |

### `flux:select.group` statt `optgroup`

`entries/form.blade.php`s Disziplin-Auswahl gruppierte Optionen bisher über natives `<optgroup label="…">`. Für
`variant="listbox"` (custom `ui-select`-Element statt echtem `<select>`) existiert dafür ein eigenes Flux-Pro-Pendant:
`<flux:select.group label="…">…</flux:select.group>`, das intern per `componentExists('select.variants.' . $variant)`
erkennt, dass es innerhalb eines Nicht-Default-Varianten-Selects steht, und automatisch die passende (custom) Gruppen-
Darstellung rendert (Trennlinie + Label, blendet sich selbst aus, wenn keine sichtbare Option enthalten ist).
Funktioniert identisch zu `flux:select.option` außerhalb von Livewire.

### Bewusst NICHT umgestellt: Alpine-`x-model`-gebundene Selects

`club-entries/create.blade.php` (Einzelmeldung) und `club-entries/create-relay.blade.php` (Staffelmeldung) binden ihre
Event-/Athlet-/Kurs-Selects über `x-model` + `@change` an Alpine-Komponenten (`singleEntryForm`/`relayEntryForm`
aus den zugehörigen `.js`-Dateien), die daraus per AJAX Folgezustand nachladen (verfügbare Athleten, Bestzeiten). Ob
`variant="listbox"` (ein Custom-Element, das Wert-Änderungen anders auslöst als ein natives `<select>`) mit diesem
bereits produktiv genutzten Zwei-Wege-Datenfluss zuverlässig zusammenspielt, ließe sich nur per Browser-Test mit
funktionierendem CSS zuverlässig verifizieren (Sandbox-Einschränkung, siehe frühere Phasen) — das Risiko einer stillen
Regression in einem von echten Vereinen genutzten Meldeformular wurde als zu hoch bewertet. Bewusst als Ausnahme stehen
gelassen (default `flux:select`), analog zur dokumentierten Kombobox-Ausnahme aus Phase 7/Runde 2. Ebenso unangetastet:
`club-entries/pick-meet.blade.php`s nativer Vereins-Umschalter (kein Flux-Select, `onchange`
navigiert direkt per JS) und `club-entries/edit-relay.blade.php`s Kurs-Select (`x-model="entryCourse"`).

### Bug-Fund: Rekord-Flags gingen beim Abhaken nicht verloren — wurden gar nicht zurückgesetzt

Beim Umstellen der WR/ER/NR-Checkboxen auf `flux:switch` fiel in `ResultController::validateResult()` auf: Die
Validierungsregel `'is_world_record' => 'boolean'` (ohne `sometimes`) nimmt das Feld nur dann ins `$validated`-Array
auf, wenn der Request es überhaupt enthält. Sowohl eine native, nicht angehakte Checkbox als auch ein nicht aktivierter
`flux:switch` senden beim Submit **gar keinen Wert** (kein Pflichtfeld-Fallback) — das Feld fehlt dann komplett in
`$validated`, und `$result->update(...)`/`Result::create(...)` lässt einen zuvor gesetzten Wert unverändert. Ergebnis:
Ein bereits als Weltrekord markiertes Ergebnis ließ sich über das Formular **nicht** wieder entmarkieren. Vorher
bestehender Fehler, unabhängig vom UI-Umbau selbst entdeckt (identisches Verhalten hätte auch mit der alten
`flux:checkbox` bestanden) — gemeldet und in derselben Phase mitbehoben, da direkt im bearbeiteten Feld:
`$validated['is_world_record'] = $request->boolean('is_world_record')` (analog zum bereits korrekten Muster in
`MeetController::store()`/`update()`), ebenso für `is_european_record`/`is_national_record`.

**Tests**: `composer test` (volle Suite) — 1387 Tests, weiterhin grün, inkl. `composer lint:check`. Zusätzlich
`php artisan view:cache` zur Kompilierprüfung sämtlicher Blade-Views (fängt Syntaxfehler in den neuen
`flux:select.option`/`flux:select.group`-Blöcken ab, die von der Test-Suite ggf. nicht direkt gerendert werden).

### Design-Feedback-Nachtrag zu Phase 9

- **`meets/form.blade.php` war zu lang** (Rückmeldung: "Beim erstellen eines neuen Wettkampfes... ist das Formular zu
  lange"). Umgebaut auf `flux:tab.group` mit drei Reitern für Admins — **Grunddaten**, **Öffentlichkeit & WPS**,
  **Punkteberechnung** (dieselbe eigene Karte wie vorher, jetzt als dritter Tab statt zweite Karte darunter). Für
  Nicht-Admins (die nur die Grunddaten-Felder sehen, keine Cup-/WPS-/Sichtbarkeits-Verwaltung) lohnen sich Tabs mit nur
  einem sichtbaren Reiter nicht — dort bleibt es bei der einfachen Karte ohne Tabs. Die Grunddaten-Felder wurden dafür
  in ein Partial (`meets/_grunddaten-fields.blade.php`) ausgelagert, um Duplikation zwischen dem Admin-Tab-Pfad und dem
  Nicht-Admin-Pfad zu vermeiden — analog zu `club-entries/_athlete-picker.blade.php` als bereits bestehendem
  Partial-Muster im Projekt.
- **Card-Breite vergrößert**: `max-w-2xl` → `max-w-3xl` (gleiche Breite wie `athletes/form.blade.php` seit Phase 8 —
  einheitliche Formular-Breite für Tab-basierte Formulare).
- Live im Browser geprüft (eingeloggt als Admin): alle drei Tabs vorhanden, Tab-Wechsel per Klick funktioniert
  (`aria-selected` wechselt korrekt, inaktive Panels werden `hidden`), AUT-Vorbelegung bei Nation funktioniert weiterhin
  innerhalb des Tab-Panels, `max-w-3xl` korrekt angewendet (kein `max-w-2xl` mehr im DOM). `composer test`
  weiterhin grün (1387 Tests) + `composer lint:check`.

### Zweiter Design-Feedback-Nachtrag zu Phase 9 (nach `npm run dev`-Test)

- **`meets/index.blade.php`**: Filterzeile brach um, weil die Feldbreiten (anders als bei Athleten/Vereine/
  Klassifizierer) nie auf das schmalere Phase-8-Maß gebracht wurden. Suche `w-64`→`w-44`, Bahnlänge `w-40`→`w-36`, Jahr
  `w-28`→`w-24`.
- **`swim-events/form.blade.php` (Disziplin anlegen/bearbeiten) in Phase 9 übersehen** (falscher Verzeichnisname bei der
  Dateisuche — liegt unter `swim-events/`, nicht `events/`). Jetzt nachgezogen: Runde, Geschlecht, Zeitnahme →
  `listbox`; Schwimmstil → `listbox` mit `flux:select.group` statt `optgroup` (Kategorien Standard/Freiwasser etc.).
- **Pflichtfeld-Sternchen rot markiert**: `<span class="text-red-500 dark:text-red-400">*</span>` statt reinem
  Text-Sternchen — nachgezogen in allen bereits umgebauten Formularen (`athletes/form`, `athletes/show`,
  `clubs/form`, `classifiers/form`, `meets/_grunddaten-fields`, `swim-events/form`, `club-entries/create(-relay)`)
  sowie ergänzt, wo `required`-Felder bislang **gar kein** Sternchen hatten (`entries/form`, `entries/edit`,
  `results/form` — bei Athlet/Club im Ergebnis-Formular nur im Anlege-Fall, da beim Bearbeiten ein deaktiviertes Feld
  ohne Pflicht-Semantik angezeigt wird: `@unless(isset($result))`).
- **`flux:select.group`-Gruppenüberschriften** (z. B. Schwimmstil-Kategorien, Session-Gruppen in
  `entries/form.blade.php`) hatten mit `text-zinc-500`/`dark:text-zinc-300` zu wenig Kontrast. Die Flux-Pro-Vorlage
  reicht kein `class`-Attribut vom `flux:select.group`-Aufruf an das Label-Div durch (nur an den äußeren Wrapper) —
  daher globaler CSS-Override in `resources/css/app.css`: `[data-flux-option-group] > div[aria-hidden='true']`
  auf `!text-blue-600`/`dark:!text-blue-400`. Per `curl` gegen den Vite-Dev-Server verifiziert, dass die Regel korrekt
  kompiliert (`color: var(--color-blue-600) !important` bzw. `--color-blue-400` im `.dark`-Zweig) — im Sandbox-Browser
  selbst nicht sichtbar prüfbar, da dieser (bekannte Einschränkung, siehe frühere Phasen) gar keine Assets vom
  Vite-Dev-Server auf Port 5173 lädt (`read_network_requests` zeigt für `app.css` keine einzige Anfrage).
- **Bug: "Meldungen"-Button auf `meets/show.blade.php` führte bei Admins zu 400 "Bitte einen Verein auswählen"** —
  `ClubEntryController::userClub()` verlangt für Admins zwingend `?club_id=`, aber der Link von der
  Wettkampf-Detailseite übergab keins (anders als der Weg über `pick-meet.blade.php`, die eigene Admin-Vereinsauswahl
  hat). Fix: neue private Methode `clubChooserView()` in `ClubEntryController`, aufgerufen am Anfang von `index()` und
  `indexRelay()` — zeigt bei fehlendem `club_id` (nur für Admins) eine neue, einfache Auswahlseite
  (`club-entries/choose-club.blade.php`, `flux:select variant="listbox" searchable`) statt des Fehlers; Club-User sind
  nicht betroffen (ihr Verein kommt ohnehin aus `auth()->user()->club_id`). Live im Browser end-to-end geprüft:
  `/meets/182/club-entries` zeigt jetzt die Auswahlseite, `/meets/182/club-entries?club_id=43`
  lädt korrekt die Meldungsliste.
- **`meets/show.blade.php` Kopfbereich umgebaut**: Überschrift + Metazeile jetzt in eigener Zeile über volle Breite
  (vorher neben dem Zurück-Pfeil, drängte bei langen Namen). Alle Aktions-"Menüpunkte" von
  `variant="ghost"` auf `variant="filled"` (sichtbarer Button-Hintergrund statt reinem Hover-Text) — inklusive eines
  neuen "Zurück"-Buttons (Text statt nur Pfeil-Icon) als erstes Element derselben Button-Leiste, restliche Buttons per
  `ml-auto`-Wrapper rechtsbündig.

**Nicht in dieser Runde behoben (siehe Rückmeldung/ToDo-Triage):**

- Status-Spalte in `meets/index` (I/E/R-Schema) — eigenständiges neues Feature, eigene Phase.
- Tooltip/Popover statt Info-Text bei "Schwimmer/Staffel" und "Sport-Klassen" — offene Design-Frage, noch keine
  Entscheidung getroffen.

**Tests**: `composer test` (1387 Tests) + `composer lint:check` weiterhin grün nach allen Änderungen dieses Nachtrags.
`php artisan view:cache` zur Kompilierprüfung. Live im Browser (eingeloggt als Admin) verifiziert:
Disziplin-Formular-Dropdowns + Pflichtfeld-Sternchen, meets/show-Kopfbereich-Struktur (Zurück-Button in der Leiste,
`filled`-Klassen bestätigt), kompletter Meldungen-Flow über die neue Vereinsauswahl.

### PhpStorm-Inspektionen behoben (nach Phase 9)

- **`ClubEntryController.php`**: `orderBy('start_date')` → `oldest('start_date')` an zwei Stellen (`pickMeet()`,
  `pickMeetRelay()`) — Laravel-Idea-Hinweis "orderBy () call can be simplified".
- **`club-entries/create(-relay).blade.php`, `edit(-relay).blade.php`**: `x-data="funcName({ … {{ old(...) }} … })"`
  mit über mehrere Zeilen verteilten Blade-Ausdrücken *innerhalb* eines JS-Objektliterals ließ sich von PhpStorms
  JS-Parser nicht mehr als zusammenhängender Ausdruck lesen ("Expression statement is not assignment or call") —
  kaskadierte so weit, dass sogar spätere, damit gar nicht zusammenhängende `@php`/`@endphp`-Blöcke im selben File als
  kaputt gemeldet wurden ("Undefined function 'endphp'", "Element is not exported"). Fix: Konfiguration als **ein**
  zusammenhängender JSON-Wert per `@json($config)` übergeben (Muster aus `meets/form.blade.php`s
  `meetPointSystems(@json($wpsAlpineConfig))`).

  **Dabei zweiten, echten (nicht nur IDE-kosmetischen) Bug gefunden:** `@json()` kodiert nur die *Inhalte* von
  JSON-Strings HTML/JS-sicher (`JSON_HEX_QUOT` etc.), **nicht** die strukturellen Anführungszeichen von JSON selbst
  (`{"key":"value"}` bleibt `"`-delimitiert). In einem **doppelt** gequoteten `x-data="…@json(...)…"`-Attribut bricht
  der Browser das HTML-Attribut daher an der ersten literalen `"` ab — der Rest der Konfiguration (fast alles)
  ging verloren, ohne Fehler in der Konsole (kein Parse-Error, einfach ein kürzeres, falsches Attribut). Betraf **auch
  das bereits bestehende** `meets/form.blade.php` (`x-data="meetPointSystems(@json(...))"`, seit Phase 9) — nie
  aufgefallen, weil in diesem Sandbox-Browser generell keine JS-Assets laden (siehe frühere Phasen) und die
  Struktur-Prüfung sich bislang auf DOM-Refs/Attribut-Präsenz beschränkte, nicht auf den vollständigen Attribut-Wert.
  Per `curl` (echter eingeloggter Request, nicht der Sandbox-Browser) nachgewiesen: `x-data="singleEntryForm({"` —
  abgeschnitten nach der ersten Struktur-Anführung. Fix: **einfach** gequotetes Attribut
  (`x-data='funcName(@json(...))'`), analog zum bereits korrekt gelösten `admin/documents/form.blade.php`
  (`x-data='documentForm({ …, candidates: @json(...) })'`). Nach dem Fix per `curl` und im Browser
  (`getAttribute('x-data')`, `new Function()`-Syntaxcheck) als vollständig und syntaktisch gültig verifiziert.
- **`meets/index.blade.php`, `clubs/index.blade.php`**: Lösch-Bestätigung nutzte
  `x-data @submit.prevent="if(confirm(...)) $el.submit()"`
  (leeres `x-data`, `$el.submit()` inline in der Direktive) — PhpStorm kann `$el` dort nicht als `HTMLFormElement`
  auflösen ("Unresolved function or method submit ()"). Auf das im selben Projekt bereits etablierte Muster umgestellt
  (`meets/show.blade.php`, `club-entries/index.blade.php`):
  `x-data="{ submit() { if (confirm(...)) this.$el.submit() } }" @submit.prevent="submit()"`.
- **`results/form.blade.php`**: `<flux:label>Athlet @unless(isset($result))<span>*</span>@endunless</flux:label>`
  direkt gefolgt von `@if(isset($result))…@else…@endif` ließ PhpStorms Kontrollfluss-Analyse fälschlich "Condition is
  always false" für die zweite, eigentlich unabhängige `isset($result)`-Prüfung melden. Umgebaut: Label und Inhalt
  (inkl. Sternchen nur im Neuanlage-Zweig) jeweils **innerhalb** desselben `@if(isset($result))…@else…@endif`
  — nur noch eine `isset($result)`-Prüfung statt zwei benachbarter, gleichwertiger.

**Tests**: `composer test` (1387 Tests) + `composer lint:check` weiterhin grün nach allen Fixes.

### Nachtrag: verbleibende PhpStorm-Fehler in create-relay/edit-relay

Der `@json()`-Fix (siehe oben) hat die Kaskade nicht vollständig behoben — die Fehler ("Expected: )", "Expected: } or ]
", "Undefined function 'endphp'" saßen tatsächlich an einer eigenen, unabhängigen Stelle: einem
`@php ... @endphp`-Block, der `$genderLabel`/`$gLabel` per `match()` berechnet, **innerhalb** einer `<option>` in einer
`@foreach`-Schleife (`create-relay.blade.php`, `edit-relay.blade.php`) bzw. innerhalb eines `<span>` in einer
`@foreach`-Schleife (`index-relay.blade.php`, dort proaktiv mitgefixt — noch nicht gemeldet, aber identisches Muster).
PhpStorms Blade-Parser kommt mit einem eigenständigen `@php`/`@endphp`-Anweisungsblock so tief verschachtelt
(Component-Tag → foreach → option/span) offenbar nicht zurecht.

Fix: `match()` ist ein PHP- **Ausdruck** (kein Statement) — lässt sich direkt in eine `{{ }}`-Echo-Anweisung schreiben,
ganz ohne separaten `@php`/`@endphp`-Block. Genau dieses Muster wird an anderen Stellen im Projekt bereits so verwendet
(`meets/show.blade.php`, `results/index.blade.php`: `{{ match($x->gender) { 'M' => 'Herren',
… } }}`). Alle drei Stellen umgebaut:

```blade
({{ match($event->gender) {
    'M' => 'Männer',
    'F' => 'Frauen',
    'X', 'MX' => 'Mixed',
    default => 'Offen',
} }})
```

Live im Browser geprüft (`/meets/1/relay-entries/create?club_id=43`, echte Staffel-Disziplinen mit Gender "A" → korrekt
"(Offen)" gerendert). `composer test` (1387) + `composer lint:check` weiterhin grün.

### Dritter Design-Feedback-Nachtrag zu Phase 9

- **Kopfbereiche vereinheitlicht** (Überschrift über volle Breite, Zurück-Button unten links in eigener Button-Leiste,
  analog zu `meets/show.blade.php`): `club-entries/choose-club.blade.php`,
  `club-entries/index.blade.php` (zusätzlich: "Staffelmeldungen" + "Neue Meldung" jetzt per `ml-auto` rechtsbündig statt
  links neben der Überschrift gequetscht), `club-entries/index-relay.blade.php` (identisches Muster, proaktiv
  mitgezogen — hatte bislang gar keinen Zurück-Button), `club-entries/create.blade.php`,
  `club-entries/create-relay.blade.php`, `club-entries/edit.blade.php`, `club-entries/edit-relay.blade.php`. Der
  Zurück-Link berücksichtigt jetzt überall `$clubParams` (Admin-`club_id` bleibt beim Zurückgehen erhalten, vorher ging
  sie auf einigen dieser Seiten verloren und man landete wieder auf der Vereinsauswahl).

- **Bug gefunden und behoben: Athletenauswahl bei Einzelmeldungen lieferte nie einen Treffer.**
  `ClubEntryService::parseEventClasses()` castete die Sportklassen-Codes aus `swim_events.sport_classes`
  (z. B. `"S1 S2 S9 S12"`) direkt per `(int)` — PHP bricht bei einem führenden Nicht-Ziffern-Zeichen ab, `(int)"S1"`
  liefert also `0`. Da `athlete_sport_classes.class_number` nie `0` ist, fiel dadurch **jeder** Athlet durch den
  Sportklassen-Abgleich, unabhängig von seiner tatsächlichen Klasse — exakt die gemeldete Meldung "Keine geeigneten
  Athleten gefunden". Der Docblock der Methode ging fälschlich von nackten Nummern ("1 2 9 10") aus; tatsächlich
  gespeichert werden die vollen Codes mit Kategorie-Präfix (passend zum Formularhinweis "z.B. S1 S2 S3" in
  `swim-events/form.blade.php`). Fix: `preg_replace('/\D+/', '', $v)` vor dem Cast, entfernt den Buchstaben-Präfix. Per
  `curl` gegen den echten, vom User angelegten Wettkampf ("LM Salzburg mit ÖBSV Cup 2026", `meet_id=182`)
  end-to-end verifiziert — der AJAX-Endpunkt `eligible-athletes` liefert jetzt tatsächlich passende Athleten statt einer
  leeren Liste. Neuer Regressionstest in `ClubEntryServiceTest.php` mit Kategorie-Präfix-Sportklassen (vorher testeten
  alle Fälle nur mit nackten Nummern, deckte den Bug nie auf).

- **`club-entries/create.blade.php`: Dropdowns umgestellt** (Disziplin, Athlet, Kurs → `flux:select
  variant="listbox"`) — trotz aktiver `x-model`/`@change`-Bindung, entgegen der ursprünglichen Phase-9-Entscheidung, auf
  ausdrücklichen Wunsch. Die Athleten-Auswahl bleibt weiterhin dynamisch per Alpine `x-for` über
  `flux:select.option` befüllt (`eligibleAthletes`-Array aus dem AJAX-Response) — `flux:select.option` reicht beliebige
  zusätzliche Attribute (`x-bind:value`, `x-text`) unverändert an das zugrundeliegende `<ui-option>`
  durch (bestätigt im Blade-Quelltext von `flux-pro/.../select/option/variants/custom.blade.php`), strukturell im
  gerenderten HTML verifiziert. Flux Pros eigene Custom-Elemente feuern bewusst native `input`/`change`-Events für
  Framework-Interop (siehe `flux-pro/CLAUDE.md`), worauf Alpines `x-model` für Nicht-`<select>`-Elemente aufbaut —
  spricht dafür, dass die Bindung funktioniert. **Nicht live end-to-end mit tatsächlicher Alpine-Ausführung getestet**,
  da in diesem Sandbox-Browser grundsätzlich keine JS-Assets von Port 5173 laden (bekannte Einschränkung). Der Nutzer
  hat eine eigene lokale Umgebung (`npm run dev`) und wurde gebeten, das Formular dort zu prüfen.
- **`club-entries/create-relay.blade.php`, `edit-relay.blade.php`: Kurs-Dropdown ebenfalls auf `listbox`
  umgestellt** (nur `x-model`, kein AJAX-Seiteneffekt — risikoarm). Das **Staffel-Event-Dropdown in
  `create-relay.blade.php` bewusst NICHT umgestellt**: `relay-entry-form.js` liest den `relay_count` des gewählten
  Events über `this.$refs.eventSelect.selectedOptions[0].dataset.relayCount` — eine native-`<select>`-API
  (`.selectedOptions`). Bei `variant="listbox"` ist das zugrundeliegende Element ein `<ui-select>`
  Custom-Element, nicht zwangsläufig mit derselben API kompatibel — ungeprüft ein konkretes Risiko einzugehen, das eine
  zentrale Funktion (Staffelgröße ermitteln) bricht, wurde hier als zu hoch bewertet. Dokumentierte Ausnahme, analog zur
  bereits bestehenden Kombobox-Ausnahme.

**Tests**: `composer test` — 1388 Tests (neuer Regressionstest), `composer lint:check` grün. `php artisan
view:cache` zur Kompilierprüfung. Live per `curl` (echter eingeloggter Request) gegen `meet_id=182` verifiziert:
AJAX-Endpunkt liefert jetzt Athleten; Kopfbereiche strukturell korrekt gerendert.

### Vierter Design-Feedback-Nachtrag zu Phase 9

- **PhpStorm-Inspektionen in `ClubEntryServiceTest.php` behoben**: `->group()` von drei einzelnen
  `describe()->group(...)`-Ketten auf file-level `uses()->group('club-entry-service')` verschoben (PhpStorm kennt
  `.group()` auf `describe()`s Rückgabetyp nicht — entspricht ohnehin der in CLAUDE.md vorgeschriebenen Konvention).
  Mehrere `expect()`-Aufrufe pro Test auf verkettete `->and()` umgestellt. Redundante Default-Wert-Argumente an
  `makeAthlete_p5($club, 'M', ['S9'])`-Aufrufstellen entfernt (Default ist bereits `'M'`/`['S9']`). "Property 'id' not
  found in $1|Closure|null" behoben durch `@return Collection<Athlete>` → `Collection<int, Athlete>` in
  `ClubEntryService.php` (Illuminate-Collection-Generics brauchen zwei Typparameter — Stichprobe im Rest des Projekts
  zeigt `Collection<int, Athlete>` durchgängig als Konvention, die zwei betroffenen Stellen in
  `ClubEntryService.php` waren die einzige Abweichung).
- **`swim-events/form.blade.php`**: Kopfbereich ebenfalls auf Überschrift-über-Zurück-Button umgestellt.
- **Vereinheitlichte "Klasse unbekannt"-Beschriftung**: `edit-relay.blade.php` zeigte bei fehlender `relay_class`
  irreführend "Klasse wird berechnet" (impliziert eine noch ausstehende Berechnung — tatsächlich wird `relay_class`
  aber synchron bei jedem Speichern berechnet; `null` heißt entweder "noch keine Athleten zugeordnet" oder "ungültige
  Klassenkombination", nicht "wird gerade berechnet"). Auf "Klasse unbekannt" vereinheitlicht (wie bereits in
  `index-relay.blade.php`). Die Berechnung selbst (`ClubEntryController::computeRelayClass()` →
  `RelayClassValidator::resolveRelayClass()`) war bereits korrekt verdrahtet und wird sowohl in `index-relay` als auch
  `edit-relay` angezeigt — kein fehlendes Feature.
- **Neue Komponente `<x-flag>`-Analogon: `<x-gender-icon :gender="…"/>`**
  (`resources/views/components/gender-icon.blade.php`):
  einheitliches Symbol+Farbe (♂ blau / ♀ pink / ⚥ violett bei Mixed / ⚥ grau bei "Offen" oder unbekannt), Farbschema wie
  bereits in `meets/show.blade.php` für Geschlecht-Badges (`M→blue, F→pink, sonst zinc`) etabliert. Ersetzt den reinen
  (ungefärbten) Symbol-Ternary in `club-entries/index.blade.php`; neu eingesetzt für: Athlet-Geschlecht als eigene
  Spalte in `club-entries/index.blade.php`, Event-Geschlecht in `club-entries/index-relay.blade.php`,
  Mitglied-Geschlecht je Staffel-Athlet in `club-entries/index-relay.blade.php`.
- **`club-entries/create-relay.blade.php`: Staffel-Event-Dropdown jetzt doch auf `listbox` umgestellt** — die zuvor
  dokumentierte Blockade (`relay-entry-form.js` las `relay_count` über
  `this.$refs.eventSelect.selectedOptions[0].dataset`, eine native-`<select>`-API) wurde behoben, statt die Umstellung
  weiter aufzuschieben: `relay-entry-form.js` bekommt jetzt eine `events`-Map (`{event_id: relay_count}`)
  aus der PHP-Konfiguration mitgegeben (`$events->pluck('relay_count', 'id')` via `@json()`) und schlägt
  `relayCount` dort nach (`this.events[this.selectedEventId]`) statt im DOM zu lesen — funktioniert unabhängig vom
  zugrundeliegenden Select-Markup. `data-relay-count`-Attribut und `x-ref="eventSelect"` dadurch überflüssig, entfernt.
  `RelayEntryFormConfig` in `alpine-components.d.ts` um `events?: Record<string, number>` ergänzt. Per
  `curl` verifiziert: die gerenderte Konfiguration enthält die korrekte `events`-Map.
- **Drei neue Einträge in `docs/open-points.md`**: Status-Spalte in `meets/index` (I/E/R-Schema), Tooltip/Popover statt
  Info-Text bei Disziplin-Formular-Hinweisen, Meldezeit bei Staffeln aus Athleten-Bestzeiten herleiten — alle drei sind
  neue Features bzw. offene Design-Entscheidungen, keine Bugfixes, daher bewusst nicht in dieser Runde umgesetzt.

**Tests**: `composer test` — 1388 Tests, `composer lint:check` grün. `php artisan view:cache` zur Kompilierprüfung.
Konfiguration von `create-relay.blade.php` per `curl` gegen echten Wettkampf (`meet_id=1`)
verifiziert.

### Fünfter Design-Feedback-Nachtrag zu Phase 9

- **Ursache für "Label-Abstand passt nicht" / "Eingabefelder nicht in einer Linie" gefunden**
  (`swim-events/form.blade.php`, Schwimmstil+Distanz vs. Schwimmer/Staffel, Geschlecht vs. Sport-Klassen): `flux:field`
  ist selbst `display:grid` (Label/Control/Description als eigene Zeilen). Steht ein Feld ohne `flux:description` in
  derselben äußeren `grid-cols-*`-Zeile neben einem MIT Description, stretcht CSS Grids Default (`align-items: stretch`)
  das kürzere Feld auf die Höhe des längeren — die zusätzliche Höhe verteilt sich dabei auf seine eigenen `auto`-Zeilen
  (Label UND Control bekommen beide etwas mehr Raum zugewiesen), wodurch Label und Eingabefeld nicht mehr auf gleicher
  Höhe wie in den nicht gestreckten Nachbarspalten sitzen. Fix: globale Regel
  `[data-flux-field]:not(ui-radio, ui-checkbox) { align-self: start; }` in `app.css` — Felder bleiben jetzt immer oben
  an ihrer Grid-Zeile ausgerichtet, unabhängig von einer Description beim Nachbarn. Betrifft potenziell jede
  `grid grid-cols-*`-Feldzeile im ganzen Projekt mit ungleicher Description-Verteilung, nicht nur diese eine Stelle —
  global statt lokal gefixt.

- **Bug gefunden: Geschlechts-Icon bei Staffeln zeigte fälschlich "Offen" (grau) statt der tatsächlichen
  Team-Zusammensetzung.** Ursache: Das Icon wurde aus `$relay->swimEvent->gender`
  gespeist — das ist aber nur die *Zulassung* des Bewerbs (z. B. "A" = offen für alle Geschlechter), nicht die
  tatsächliche Zusammensetzung der gemeldeten Staffel. Eine rein männliche Staffel in einem offenen Bewerb zeigte
  dadurch grau ("Offen") statt blau ("Männer") — wirkte wie ein CSS-Farbfehler, war aber eine falsche Datenquelle. Neue
  Methode
  `RelayEntry::teamGender()` (`app/Models/RelayEntry.php`) leitet das Geschlecht stattdessen aus den tatsächlichen
  Mitgliedern her, nach der vom User vorgegebenen Regel: rein männlich → Herren, rein weiblich → Damen, jede andere
  Kombination bleibt Herren — außer bei exakt zwei Männern und zwei Frauen, das gilt als Mixed. `null` (noch keine
  Mitglieder mit bekanntem Geschlecht) fällt auf die Event-Zulassung zurück. `index-relay.blade.php` verwendet jetzt
  `$relay->teamGender() ?? $relay->swimEvent->gender`. 6 neue Tests in `RelayEntryTest.php`
  (`describe('RelayEntry::teamGender', …)`), `makeAthlete()`-Testhelper um `$gender`-Parameter erweitert. Per `curl`
  gegen echte Daten verifiziert (4 männliche Mitglieder → Icon jetzt blau
  "Männer" statt grau "Offen").
- **Farb-Klassen selbst waren nie das Problem** — per `curl` gegen den Vite-Dev-Server bestätigt, dass `.text-blue-500`/
  `.text-pink-500`/`.text-violet-500`/`.text-zinc-400` korrekt kompiliert sind und im gerenderten HTML auch korrekt
  angewendet werden; das grau wirkende Icon war fachlich korrektes "Offen"-Grau für die falsche (Event- statt Team-)
  Datenquelle.
- **`club-entries/index.blade.php`**: Geschlechts-Icon neben dem Bewerbsnamen vergrößert (`text-xs` → `text-base`).

**Tests**: `composer test` — 1394 Tests (6 neu), `composer lint:check` grün. `php artisan
view:cache` zur Kompilierprüfung. Live per `curl` gegen echte Wettkampf-/Staffeldaten (`meet_id=182`,
`club_id=1`, vier männliche Mitglieder) verifiziert.

### Sechster Design-Feedback-Nachtrag zu Phase 9 (nach Session-Fortsetzung)

- **Bug gefunden: admin `entries/edit` (`/entries/{id}/edit`, `EntryController`) zeigte die Meldezeit als rohe
  Hundertstelsekunden-Zahl** (`8322` statt `01:23.22`) — anders als
  `club-entries/edit.blade.php`, das seit Phase 9 bereits das IMask-`MM:SS.hh`-Muster verwendet. Der admin-seitige Weg
  war beim Umbau übersehen worden. Fix: `entries/edit.blade.php` UND
  `entries/form.blade.php` (Create-Formular — teilt sich `EntryController::sharedEntryRules()`
  mit `update()`, musste also mitgezogen werden, sonst wäre die Zeitparsung dort gebrochen)
  auf dasselbe `x-data='{ entryTime: @json(...) }'` + IMask-Textfeld umgestellt.
  `EntryController::sharedEntryRules()`: `entry_time` von `nullable|integer|min:0` auf
  `nullable|string|max:20`; neue private `parseEntryTime()` (identisches Muster wie
  `ClubEntryController::parseEntryTime()`) übernimmt "MM:SS.hh" → Hundertstelsekunden via
  `TimeParser::parse()`, erkennt "NT"/"NS"/"WO" als Code statt Zeit. `store()`/`update()`
  entsprechend angepasst.
- **Bug gefunden: "Abbrechen" auf `entries/edit` führte immer fix zu `meets.show`**, obwohl die Seite von zwei
  verschiedenen Stellen aus erreichbar ist (`entries/index`, gefiltert nach Wettkampf/Suche, UND potenziell
  `meets/show`) — der User landete beim Abbrechen nicht dort, woher er kam. Fix:
  `href="{{ route('meets.show', $entry->meet) }}"` → `href="{{
  url()->previous() }}"`. Per `curl` mit echter Session (`entries/index` → `entries/1/edit` bzw.
  `meets/182` → `entries/1/edit`) verifiziert: Abbrechen führt jetzt jeweils zur tatsächlichen Herkunftsseite zurück.
- **Feature: `meets/show.blade.php` — Stats-Zeile um vier Werte erweitert.** Bisher nur Disziplinen/Meldungen/Ergebnisse
  (3 Karten); jetzt 6: Disziplinen, **Einzelmeldungen**
  (vormals nur "Meldungen"), **Staffelmeldungen** (neu), Ergebnisse, **Teilnehmer** (neu), **Clubs** (neu). Neue `Meet`
  -Relation `relayEntries(): HasMany` (fehlte bisher komplett —
  `RelayEntry::meet()` existierte, aber keine Rückrichtung). Neue Model-Methoden:
    - `Meet::participantsCount()`: eindeutige Athleten über Einzel- UND Staffelmeldungen (`entries.athlete_id` ∪
      `relay_entry_members.athlete_id` via `relay_entries.meet_id`), portabel über Collection-
      `merge()->unique()->count()` statt einem MySQL-spezifischen
      `DISTINCT`-JOIN.
    - `Meet::participatingClubsCount()`: eindeutige Vereine über Einzel- UND Staffelmeldungen. **Bewusst nicht
      `$meet->clubs` (die `meet_club`-Pivot-Relation)** — die wird nur beim LENEX-Import befüllt, nicht beim regulären
      Melden über die Meldungen-Oberfläche. Per `curl`
      gegen `meet_id=182` verifiziert: `$meet->clubs()->count()` war `0`, obwohl 1 Verein tatsächlich gemeldet hatte —
      hätte als "Clubs: 0"-Karte trotz vorhandener Meldungen in die Irre geführt. Die bereits bestehende "Teilnehmende
      Vereine"-Badges-Sektion weiter unten auf der Seite nutzt weiterhin `$meet->clubs` unverändert (anderer Zweck,
      nicht Teil dieser Änderung).
      `MeetController::show()`: `loadCount()` um `relayEntries` erweitert, `participantsCount`/
      `participatingClubsCount` einmalig berechnet und an die View übergeben.
- **Feature: Button "Ergebnis erfassen" auf `meets/show.blade.php` ergänzt.** Das Ergebnis-Erfassungsformular
  (`results/form.blade.php`, `ResultController::create()`/`store()`, Route `meets.results.create`) existierte bereits
  vollständig und funktionsfähig (u. a. schon in einem früheren Nachtrag dieser Phase mit roten Sternchen nachgezogen),
  war aber von nirgends aus verlinkt — eine Sackgassen-Funktion. Fix ist reine Verlinkung, keine neue Implementierung:
  Button neben "Meldungen"/"Cup-Tageswertung" in der Aktionsleiste, sichtbar nur für Admins (`meets.results.*` liegt
  ohnehin komplett hinter `RequireAdmin`-Middleware, siehe
  `routes/web.php`).

**Tests**: `composer test` — weiterhin 1394 Tests, `composer lint:check` grün (keine neuen Tests nötig — reine Bugfixes
an bereits getesteten Pfaden bzw. reine Verlinkung/Anzeige ohne neue Business-Logik, die nicht bereits durch bestehende
Tests abgedeckt wäre). `php artisan view:cache`
zur Kompilierprüfung. Live per `curl` gegen `meet_id=182`/`entry_id=1` verifiziert (Meldezeit-Anzeige, Abbrechen-Ziel
aus zwei Herkunftsseiten, Stats-Werte, Ergebnis-erfassen-Link).

### Siebter Design-Feedback-Nachtrag zu Phase 9 (nach weiterer Session-Fortsetzung)

- **Bug gefunden und gefixt: `ResultController::store()` griff auf `$data['swim_event_id']` zu, obwohl
  `validateResult()` seit jeher die Struktur `['result' => [...], 'splits' => [...]]` zurückgibt** — der Schlüssel
  existierte nie, jede Ergebnis-Anlage wäre mit `ModelNotFoundException` (404) fehlgeschlagen. Null Testabdeckung auf
  diesem Pfad bestätigte, dass er nie ausgeführt wurde. Fix: `$data['result']['swim_event_id']`. Reiner Zufallsfund beim
  Umbau von `store()` für die Splitzeiten-Änderungen unten — direkt mitgefixt statt in die Open Points, da im selben,
  ohnehin bearbeiteten Zweig.
- **Feature: Ergebnis-Erfassung erlaubt jetzt Athleten ohne Meldung zu diesem Wettkampf.** Bisher listete das
  Anlegen-Formular nur `$meet->entries()->pluck('athlete')` — ein Athlet ohne (oder mit fehlender) Meldung war nicht
  wählbar. `ResultController::create()` liefert jetzt (wie schon `EntryController::create()`) alle Athleten/Vereine
  durchsuchbar; `edit()` bleibt unverändert (Athlet dort weiterhin fix, nicht änderbar).
- **Feature: Club-Auto-Vorbelegung beim Athleten-Wechsel** — sowohl in `entries/form.blade.php` (Meldungserfassung)
  als auch in `results/form.blade.php` (Anlegen-Modus): `$athletes->pluck('club.id', 'id')` als Alpine-Konfiguration,
  `x-model` auf Athlet-/Club-Select, `$watch('athleteId', ...)` setzt `clubId` beim Wechsel — bleibt danach änderbar.
  Per Browser-Test verifiziert (Alpine-State direkt gesetzt, Flux-`ui-select`-Wert und gerenderter Club-Name geprüft).
- **Feature: Splitzeiten in `results/form.blade.php` in einen eigenen `flux:tab.panel` ausgelagert** (statt 10 fixer
  Zeilen im durchlaufenden Formular), Formularbreite `max-w-2xl` → `max-w-4xl`. `swim_time` und alle
  `splits[*][split_time]` von rohen Hundertstel-Zahlenfeldern auf das etablierte IMask-`MM:SS.hh`-Muster umgestellt
  (bisher einzige verbliebene Zeit-Rohwert-Eingaben in einem Ergebnis-Formular). Jede Split-Zeile trägt dafür einen
  eigenen, isolierten `x-data`-Scope (kein gemeinsamer Zustand zwischen den zehn Zeilen nötig).
  `ResultController::validateResult()`: `swim_time`/`splits.*.split_time` von `integer` auf `string` umgestellt, neue
  private `parseTime()` (analog `EntryController::parseEntryTime()`, ohne Status-Code-Sonderfall — Ergebnisse haben
  dafür bereits das separate `status`-Feld) übernimmt die Umrechnung über `TimeParser::parse()`. `store()`/`update()`
  reichen jetzt `$data['splits']` (bereits geparst) an `storeSplits()` durch statt wie zuvor ungenutzt
  `$request->input('splits', [])` roh weiterzureichen — sonst wären ab dieser Umstellung Zeit-Strings statt Integers in
  die `split_time`-Spalte gewandert. **Beim ersten Implementierungsversuch selbst einen Bug gebaut und über Browser-Test
  gefunden**: die pro-Zeile
  `x-data`-Deklaration stand zunächst in doppelten Anführungszeichen (`<div x-data="{ splitTime: @json(...) }">`) —
  exakt die bereits dokumentierte Fallstricke-Klasse (`@json()` erzeugt doppelte Anführungszeichen, die ein
  doppelt-quotiertes HTML-Attribut vorzeitig beenden). Wirkung: das Feld blieb beim Bearbeiten eines Ergebnisses mit
  Splits immer leer statt die vorhandene Zeit zu zeigen. Fix: einfache Anführungszeichen (`x-data='{ ... }'`), wie im
  Rest der Datei. Alle Vorkommen in dieser Session per `grep` auf das Muster durchsucht — keine weiteren Treffer.
- **Bug gefixt: "EXH" fälschlich als "Ausstellungsstart" übersetzt** in `entries/edit.blade.php`,
  `entries/form.blade.php`, `results/form.blade.php` — der Rest der Anwendung (z. B.
  `championship-development-table.blade.php`) verwendet bereits korrekt "außer Konkurrenz". Alle drei Stellen auf
  "EXH – Außer Konkurrenz" korrigiert.
- **Bug gefixt: `meets/show.blade.php`-Stats zeigten bei rein per LENEX importierten Meets (nur Ergebnisse, keine
  Meldungen) fälschlich 0 Teilnehmer/Clubs trotz hunderter Ergebnisse.** Konkret nachvollzogen an Meet 171 (142
  Ergebnisse, 0 Meldungen — das importierte LENEX enthielt keinen Meldungen-Abschnitt). `Meet::participantsCount()`/
  `participatingClubsCount()` (aus dem sechsten Nachtrag) betrachteten bisher nur `entries`/`relay_entries`. Fix:
  beide Methoden beziehen jetzt zusätzlich `results.athlete_id`/`results.club_id` mit ein (Vereinigung, weiterhin
  Collection-`merge()->unique()->count()`, kein MySQL-only-SQL). Live verifiziert: Meet 171 zeigte davor 0/0, danach
  korrekt 39 Teilnehmer/12 Clubs (per Tinker-Skript gegenverifiziert); nach testweisem Anlegen eines weiteren
  Ergebnisses live auf 40/13 hochgezählt und der Testdatensatz wieder gelöscht.
- **Feature: Formular-Header vereinheitlicht** — Muster aus `meets/show.blade.php` (Überschrift allein oben, darunter
  eine Zeile mit dem Zurück-Button links und `ml-auto` für weitere Buttons rechts) auf alle Formulare der Bereiche
  Wettkämpfe/Meldungen/Ergebnisse/Dokumente übertragen: `meets/form.blade.php` und
  `admin/documents/form.blade.php` hatten den Zurück-Button bisher direkt neben der Überschrift in derselben Zeile;
  `entries/form.blade.php`, `entries/edit.blade.php`, `results/form.blade.php` hatten gar keinen Header/Zurück-Button.
  Zurück-Ziel jeweils `url()->previous()`, außer wo die Seite eindeutig nur von einer Stelle erreichbar ist (Meet-
  bezogene Create-Formulare → `route('meets.show', $meet)`, analog zur bereits bestehenden Abbrechen-Logik).
- **Feature: Abstand zwischen Eingabefeld und darunterstehendem `flux:description`-Hinweistext verkleinert.**
  Ursache in `vendor/livewire/flux/…/field.blade.php` gefunden: die Standard-Variante von `flux:field` setzt per CSS
  `[&>*:not([data-flux-label])+[data-flux-description]]:mt-3` — jede Beschreibung nach einem Kontrollelement bekommt
  automatisch 12px Abstand. Bereits in einem früheren Nachtrag für die Meldezeit-Felder mit `class="mt-1"`
  überschrieben; dieses Mal konsequent auf alle betroffenen `flux:description`-Vorkommen in
  `meets/form.blade.php`, `meets/_grunddaten-fields.blade.php`, `entries/form.blade.php` und
  `admin/documents/form.blade.php` angewendet.
- **Feature: Dokumente-Dropdowns ("Kategorie", "Sprache") auf die Listbox-Optik umgestellt**, die der Rest der Anwendung
  verwendet (`variant="listbox"`, `flux:select.option` + `:selected="…"` statt nativem `<option>` +
  `@selected()`). Die dritte, dynamisch per `x-for` befüllte "Sprachvariante zu"-Auswahl bleibt bewusst ein natives
  `<select>` — die Optionen kommen aus einem JS-Array, nicht aus einer Blade-Schleife, eine Umstellung wäre unnötig
  invasiv für ein Feld ohne das gemeldete Problem. `document-form.js`s `x-model="category"`-Bindung funktioniert mit der
  Listbox-Variante unverändert (kein JS geändert).
- **Umgebungsproblem gefunden und behoben (kein Code-Bug): eine verwaiste `public/hot`-Datei** (Überbleibsel eines nicht
  sauber beendeten `npm run dev`) ließ Laravels `@vite()`-Direktive Skripte von einem nicht laufenden Vite-Dev-Server
  einbinden — `resources/js/app.js` (registriert u. a. `IMask`, `documentForm`,
  `meetPointSystems` global) lud dadurch die gesamte Session über nicht, ohne dass das im Server-HTML sichtbar war.
  Betraf vermutlich auch frühere Nachträge dieser Phase, soweit sie auf `npm run dev` bzw. denselben Dev-Server
  angewiesen waren. `public/hot` gelöscht → Laravel liefert wieder die bereits vorhandenen, aktuellen
  `public/build`-Assets aus; per Browser-Konsole vor/nach dem Fix verifiziert (`IMask is not defined` /
  `documentForm is not defined` verschwanden). `composer dev` (Vite im Dev-Modus) neu starten legt die Datei bei Bedarf
  wieder korrekt an.

**Tests**: `composer test` — weiterhin 1394 Tests, `composer lint:check` grün. `php artisan view:cache` zur
Kompilierprüfung. Vollständiger Browser-Test mit echter Session gegen `meet_id=171` (0 Meldungen/142 Ergebnisse, gezielt
gewählt um den LENEX-only-Fall abzudecken): Ergebnis-Anlage mit Athlet ohne Meldung, Club-Auto-Vorbelegung, maskierte
Schwimm-/Splitzeit, Tab-Wechsel, Speichern (Redirect + korrekte DB-Werte per Tinker-Skript geprüft, Testdatensatz wieder
gelöscht) und ein zusätzlicher Update-Rundlauf auf einem historischen Ergebnis mit Splits (Werte vor/nach identisch).
Dokumente-Formular: Header-Layout, Listbox-Dropdown-Rendering und `x-model`-Reaktivität per
Browser-Konsole/JS-Introspektion geprüft.

### Korrekturen nach Design-Feedback zum siebten Nachtrag (zwei eigene Regressionen)

Der Nutzer meldete nach dem siebten Nachtrag zwei Klassen von Problemen zurück, die sich beide auf eigene Fehler aus
diesem Nachtrag zurückführen ließen:

- **Weiße statt blaue Buttons, fehlende Icon-Farben, unstrukturierter Datepicker-Kalender, Hinweistext-Abstand
  "nicht übernommen".** Ursache: `public/build` war zum Zeitpunkt des vorherigen Fixes ~8 Tage alt (älter als jede
  einzelne Datei in `resources/css`/`resources/js`) — das Löschen der verwaisten `public/hot`-Datei (siehe siebter
  Nachtrag) hat die Session korrekt von einem toten Vite-Dev-Server auf `public/build` umgeschaltet, aber dieser Build
  war selbst veraltet und enthielt weder aktuelle Tailwind-Klassen noch aktuelle Flux-Styles. `npm run build`
  neu ausgeführt → aktueller Build (`app-DC135VSO.css`, deutlich größer als der alte `app-ByByB95u.css`). Per Browser
  verifiziert: Speichern-Button `background-color: oklch(0.546 0.245 262.881)` (Blau) statt Weiß, Datepicker-Kalender
  rendert wieder als vollständiges Grid mit blauer Selektion statt als unstrukturierte Zahlenkette.
- **PhpStorm-Inspektionsfehler in `documents/form.blade.php` (":39/:40") und `results/form.blade.php` (":258")** — beide
  echte Bugs, keine falsch-positiven IDE-Meldungen. Ursache: `@blade`s `@json()`-Direktive
  (`Illuminate\View\Compilers\Concerns\CompilesJson::compileJson()`) zerlegt ihr Argument naiv per
  `explode(',', ...)` — jedes Komma IM Argument (nicht nur das trennende Komma vor optionalen
  `$options`/`$depth`-Parametern) zerschneidet den Ausdruck. `@json(old("key", "default"))` hat durch Zufall genau ein
  internes Komma und wird beim Wiederzusammensetzen zufällig syntaktisch korrekt rekonstruiert — verändert dabei aber
  unbemerkt die JSON-Encoding-Flags (`$options` wird zum String-Rest statt der beabsichtigten
  `JSON_HEX_*`-Konstanten). Bei `documents/form.blade.php`s `@json([...])` mit mehreren Feldern UND einem
  verschachtelten `old(...)` (3 interne Kommas) führte dieselbe Zerlegung zu einem **abgeschnittenen, syntaktisch
  kaputten PHP-Ausdruck** — `ParseError: Unclosed '[' … does not match ')'`, 500 bei jedem Laden des Formulars.
  `php artisan view:cache` hatte das nicht erkannt (kompiliert Blade-Direktiven zu PHP-Text, ohne die erzeugte Datei
  tatsächlich zu requiren/parsen) — erst ein echter Seitenaufruf im Browser deckte es auf. **Fix, app-weit durchsucht (
  kein weiterer Treffer):** `@json()` bekommt grundsätzlich nur noch eine einzelne, bereits fertige Variable (z. B.
  `$oldAthleteId = old('athlete_id', ''); … @json($oldAthleteId)`), nie einen Ausdruck mit eigenen Kommas — in
  `entries/form.blade.php`, `results/form.blade.php` und
  `admin/documents/form.blade.php` entsprechend umgestellt. **Gleichzeitig CLAUDE.md-Konvention nachgezogen** (
  "Alpine-Logik in separate .js-Dateien auslagern und via Alpine.data () registrieren (reduziert IDE-Warnungen)"): das
  bisher wiederholt inline in `x-init` stehende IMask-Muster (Meldezeit/Schwimmzeit/Splitzeiten) in eine registrierte
  Komponente
  `resources/js/masked-time-field.js` (`Alpine.data('maskedTimeField', …)`) ausgelagert und in
  `results/form.blade.php` (Schwimmzeit + alle 10 Splitzeiten-Zeilen) sowie `entries/form.blade.php` (Meldezeit)
  eingesetzt — reduziert nicht nur IDE-Rauschen, sondern hätte den obigen `@json()`-Bug in der ursprünglichen
  10-fach-inline-Variante deutlich schwerer auffindbar gemacht. Ein weiterer, bei diesem Umbau selbst gemachter Fehler
  (doppelte statt einfache Anführungszeichen um
  `x-data="maskedTimeField(@json(...))"`) wurde noch vor dem Melden per eigenem Browser-Test gefunden und behoben.
- **Nicht behoben, bewusst außerhalb dieses Nachtrags:** die gemeldeten PhpStorm-Hinweise zu
  `classifiers/show.blade.php`,
  `components/action-message.blade.php`, `flux/icon/*` und `flux/navlist/group.blade.php` betreffen Dateien, die in
  dieser Runde nicht angefasst wurden. `action-message.blade.php` (`@this.on(...)`) und die `flux/icon/*`-Hinweise (
  "Method 'classes' not found …", doppelter `match`-Arm) sind bekannte Fehlalarme des PhpStorm-Blade-Plugins bei
  Livewires `@this`-Magic-Property bzw. bei den generierten Flux-Icon-Komponenten — nicht behebbar, ohne
  Vendor-/Publish-Code zu verändern, der beim nächsten `flux:publish`/Composer-Update ohnehin überschrieben würde.
  `classifiers/show.blade.php` (`match($cl->status)`, "Potentially polymorphic call") ist eine Typinferenz-Warnung auf
  funktionierendem, unverändertem Code. `flux/navlist/group.blade.php` (Tailwind-Kurzschreibweisen wie
  `mb-[2px]` → `mb-0.5`) ist rein kosmetisch und funktional folgenlos, aber eine app-weit genutzte Sidebar-Komponente
  außerhalb des Themas dieser Runde — auf Wunsch in einer eigenen, dedizierten Änderung.

**Tests**: `composer test` — weiterhin 1394 Tests, `composer lint:check` grün. `php artisan view:clear` + frischer
Browser-Tab (kein Cache) für jede der drei korrigierten Dateien: keine Konsolenfehler mehr, `documentForm`/
`maskedTimeField`-Alpine-Daten korrekt befüllt, Ergebnis-Anlage per Browser erneut vollständig durchgespielt
(Athlet-Auswahl → Club-Auto-Vorbelegung → maskierte Schwimm-/Splitzeit → Speichern → DB-Werte per Tinker-Skript
verifiziert, Testdatensatz gelöscht).

### Achter Design-Feedback-Nachtrag zu Phase 9

- **Meetsliste**: Suchfeld "Name oder Stadt" von `w-44` auf `w-72` verbreitert.
- **Hilfetext-Abstand — eigentliche Ursache gefunden.** Das `mt-1` aus dem sechsten/siebten Nachtrag hatte nie gewirkt:
  Flux' `field.blade.php` setzt intern `[&>*:not([data-flux-label])+[data-flux-description]]:mt-3`, ein Selektor mit
  strukturell höherer Spezifität als eine einzelne Utility-Klasse — gewinnt unabhängig von der Reihenfolge im
  Stylesheet. Bestätigt durch den eigenen Nutzer-Test: manuelles Setzen von `mt-0` im DevTools änderte am gerenderten
  Abstand nichts, weil auch das nur eine gleich-spezifische Klasse gewesen wäre. Fix:
  Tailwind-v4-`!important`-Syntax (`mt-1!`), bereits an anderer Stelle im Projekt verwendet (`size-3!` in
  `flux/navlist/group.blade.php`) — schlägt jede Spezifität. App-weit auf alle 25 betroffenen
  `flux:description`-Stellen angewendet (nicht nur `meets/form.blade.php`), per Sweep + Gegenprobe (0 verbleibende
  `mt-1` ohne `!`) und Kontrolle der kompilierten CSS-Regel (`.mt-1\!{margin-top:var(--spacing)!important}`).
- **Bahnlänge**: SCM (häufigste Bahnlänge bei österreichischen Wettkämpfen) steht jetzt zuerst in
  `meets/_grunddaten-fields.blade.php` und im `meets/index`-Filter; die Vorbelegung beim Neuanlegen bleibt bewusst LCM
  (nicht Teil der Anfrage).
- **Disziplin-Nr. automatisch vorbelegt**: `SwimEventController::create()` ermittelt `max(event_number)+1` über die
  bereits angelegten Disziplinen des Wettkampfs, änderbar wie gefordert.
- **`swim-events/form.blade.php` verbreitert** (`max-w-2xl` → `max-w-4xl`), Schwimmstil-Feld auf `col-span-2`
  (doppelte Spaltenbreite in der Grid-Zeile), Distanz von Zahlenfeld auf `flux:select` mit den erlaubten Werten
  (25/50/75/100/150/200/400/800/1500 m) umgestellt — ein beim Bearbeiten evtl. abweichender Bestandswert bleibt als
  Zusatzoption erhalten statt beim Öffnen verworfen zu werden.
- **Staffelmeldung (Create + Edit) zweispaltig**: `club-entries/_athlete-picker.blade.php` zeigt "Verfügbare Athleten"
  jetzt links und "Startaufstellung" rechts (`grid grid-cols-2`) statt untereinander. Formular-Card auf
  `max-w-3xl` verbreitert. Das Ausblenden bereits gemeldeter Athleten (auch über mehrere Staffeln desselben Vereins im
  selben Event hinweg, `ClubEntryService::eligibleRelayAthletes()`) war serverseitig bereits korrekt implementiert — per
  Live-Abfrage gegen echte Daten verifiziert (Event mit 2 Staffeln/8 gesetzten Athleten desselben Vereins: alle 8
  korrekt aus der verfügbaren Liste ausgeblendet).
- **Reaktionszeit**: Eingabe von Hundertstelsekunden-Integer auf Sekunden mit Komma umgestellt (z. B. `0,14`, Fehlstart
  `-0,03`) — `ResultController::parseReactionTime()` normalisiert Komma→Punkt und rechnet zurück in die gespeicherte
  Hundertstelsekunden-Spalte; Vorbelegung beim Bearbeiten über `number_format(..., 2, ',', '')`.

**Tests**: `composer test` — 1394 Tests weiterhin grün (3236 Assertions), `composer lint:check` grün, `npm run build`
neu ausgeführt. Stichproben live gegen den Dev-Server: Distanz-Dropdown-Optionen per DOM-Abfrage geprüft,
Event-Nr.-Vorbelegung (21 bei 20 bestehenden Disziplinen), `reaction_time`-Parser per Tinker-Skript mit 5 Fällen
durchgespielt (Diagnose-Skripte danach wieder gelöscht).

### Neunter Design-Feedback-Nachtrag zu Phase 9

- **Bugfix: Meldung löschen sprang auf die Wettkampf-Detailseite statt auf die (ggf. gefilterte) Meldungsliste.**
  `EntryController::destroy()` (admin-seitige, meet-übergreifende Meldungsliste `entries/index.blade.php`) leitete hart
  auf `route('meets.show', $meet)` weiter — bei mehreren Löschungen hintereinander ging so bei jeder der
  Filter-/Listenkontext verloren. Auf `back()` umgestellt; per Fetch-Test gegen eine Wegwerf-Meldung verifiziert
  (`entries?meet_id=1` → löschen → wieder `entries?meet_id=1`).
- **Dokumenten-Liste (`admin/documents/index.blade.php`)**: Header auf das etablierte Formular-Muster umgestellt (Titel
  oben, "Zurück" darunter links, "Dokument hochladen" darunter rechts — wie schon in
  `club-entries/index.blade.php`). Bearbeiten-/Löschen-Icons hatten keine bzw. eine zu schwache Farbklasse
  (`text-red-500` ohne `!`) — auf `text-amber-500!`/`text-red-500!` umgestellt, konsistent mit
  `meets/index.blade.php` u. a. Lösch-Formular vom bare `x-data @submit.prevent="…$el.submit()"`-Muster auf das
  robustere, bereits anderswo verwendete `x-data="{ submit() {...} }"`-Muster umgestellt.
- **PhpStorm-Befunde eingeordnet**: Die Meldungen zu `club-entries/_athlete-picker.blade.php` ("Element is not
  exported", "Unresolved variable or type athlete", Parse-Fehler bei `x-for="(athlete, index) in …"`) sowie zu
  `entries/form.blade.php` (:91) und `results/form.blade.php` (:139/:261) ("'with' statement", "Missing import
  statement") sind größtenteils ein bekanntes PhpStorm-Limit: `x-for="(item, index) in items"` ist keine gültige
  eigenständige JS-Syntax, und dynamisch per `Alpine.data()` registrierte Komponenten wie `maskedTimeField(...)`
  kann die IDE ohne Alpine-Plugin nicht auflösen — genau das von CLAUDE.md vorgeschriebene Muster (Alpine-Logik in
  `.js` auslagern). Funktional unauffällig, nicht behebbar ohne die Konvention zu brechen. Ein echter, behebbarer Anteil
  steckte aber in `results/form.blade.php`: `use App\Support\TimeParser;` stand mitten in
  `@section('content')` statt (wie in `club-entries/edit-relay.blade.php` korrekt vorgemacht) am Dateianfang vor
  `@extends` — verschoben, behebt die "Missing import statement"-Meldung für die TimeParser-Aufrufe.
  `documents/index.blade.php` :77 ("Unresolved function or method submit ()", `$el.submit()`) hat dieselbe Ursache und
  ist mit dem obigen Umbau des Lösch-Formulars miterledigt.

**Tests**: `composer test` — 1394 Tests weiterhin grün, `composer lint:check` grün, `npm run build` neu ausgeführt.
Löschen-Redirect per Fetch gegen eine per Tinker-Skript angelegte Wegwerf-Meldung verifiziert, Dokumenten-Liste (mit und
ohne `$meet`-Kontext) per Browser-DOM-Abfrage auf Header-Struktur und Icon-Farbklassen geprüft.

## Phase 10 — Rekorde & Richtzeiten — **abgeschlossen**

Runde 2 (Chevron-Dropdowns & Switches, siehe Ansage in "Runde 2" oben) für `records`, `qualifying-time-lists` und
`world-aquatics-base-times` (View-Verzeichnis `base-times`). Gleiches Muster wie Phase 8/9: natives `<select>` +
`<option>`/`@selected()` → `flux:select variant="listbox"` + `flux:select.option :selected=…`, `<optgroup>` →
`flux:select.group`, echte Einzel-Schalter (`flux:checkbox`) → `flux:switch`.

- **`records/index.blade.php`**: 4 Filter-Selects (Untertyp je Kategorie, Geschlecht, Bahn) umgestellt. Geschlecht/ Bahn
  zusätzlich von einer expliziten "Alle"-Leeroption auf `placeholder="…" clearable` umgestellt (gleiches Muster wie
  `meets/index.blade.php`). Lösch-Formular + Bearbeiten-/Löschen-Icon-Farben (`text-amber-500!`/`text-red-500!`,
  robustes `x-data="{ submit() {...} }"`-Muster) im selben Aufwasch mitgezogen — gleiche Lücke wie zuletzt bei
  `admin/documents/index.blade.php`.
- **`records/form.blade.php`**: 7 Selects, davon eines mit `<optgroup>` → `flux:select.group` (Rekord-Typ, gruppiert
  nach International/National/Regional).
- **`records/import-preview.blade.php`**: 2 Selects ("Neu anlegen"/"Überspringen" je unbekanntem Verein/Athlet).
  `records/export.blade.php` bewusst **nicht** angefasst — die dortigen Radio-/Checkbox-Gruppen (Kategorie, Bahnen,
  Geschlecht, Verbände) sind Mehrfachauswahlen, keine Einzel-Schalter, und damit kein `flux:switch`-Kandidat.
- **`qualifying-time-lists/form.blade.php`**: `is_active` und `overwrite_manual` (`flux:checkbox` → `flux:switch`, echte
  Einzel-Schalter), 2 bereits auf `flux:select.option` umgestellte, aber noch ohne `variant="listbox"`
  laufende Selects in der Richtzeiten-Schnellerfassungszeile ergänzt.
- **`base-times/import.blade.php`**: 1 Select (Zielversion). Das Neu/Bestehend-Radiopaar bleibt Radio — echte
  Alternative, kein Ein/Aus-Schalter.
- **Bug gefunden und behoben: auto-submitting Filter-Selects funktionieren mit `flux:select` nicht mehr über natives
  `onchange="this.form.submit()"`.** `qualifying-time-lists/qualifications.blade.php` hatte vier Filter (Bewerb,
  Geschlecht, Sportklasse, Verein), die bei Auswahl automatisch die Seite neu luden. `flux:select` ist ein Custom
  Element (`<ui-select>`), kein natives `<select>` — das interne "change"-Event, das beim Auswählen einer Option
  gefeuert wird, ist mit `{bubbles:false}` deklariert (`vendor/livewire/flux/dist/flux.min.js`). Auch ein direkt auf
  `<ui-select>` gesetztes Alpine-`@change` kam im Live-Test nicht an (vermutlich weil der interne Dispatch nicht auf dem
  `<ui-select>`-Host selbst erfolgt). Funktionierender Ersatz: `x-model` (ohnehin der für Flux-Komponenten
  vorgeschriebene Weg) auf eine Alpine-Zustandsvariable je Filter, dazu je ein `$watch(...)` in
  `x-init`, das bei Änderung `submitFilter()` auslöst — das kombinierte "Bewerb"-Select (`stroke_type_id_distance`
  = `"{stroke_type_id}|{distance}"`) zerlegt sich dabei selbst in die zwei versteckten Formularfelder, bevor es
  submitted. `x-model` übernimmt dabei auch gleich die Vorbelegung beim Laden — das bisherige `:selected()` auf den
  einzelnen Optionen wird für diese vier Felder nicht mehr gebraucht. Per Browser-Test verifiziert: Auswahl eines
  Geschlechts löst den Reload mit korrektem Query-Parameter aus, die Auswahl bleibt nach dem Reload sichtbar vorbelegt,
  die Trefferzahl ändert sich tatsächlich (413 → 29 nach Bewerb+Geschlecht-Filter).

**Tests**: `composer test` — 1394 Tests weiterhin grün, `composer lint:check` grün, `php artisan view:cache`
(kompiliert alle Views, keine Syntaxfehler) vor und nach jedem Modul. `npm run build` neu ausgeführt. Live im Browser
geprüft: alle konvertierten Selects rendern als `<ui-select>` mit Pfeil, `records/create` zeigt alle 9 Selects korrekt
inkl. gruppierter Rekord-Typ-Optionen, die beiden Switches auf `qualifying-time-lists/{id}/edit`
rendern als `<ui-switch>`, die vier Auto-Submit-Filter auf der Qualifikationsseite wie oben beschrieben end-to-end
durchgespielt.

### Design-Feedback-Nachtrag zu Phase 10 — Filterzeile Rekordlisten

`records/index.blade.php` bekam eine grundlegend andere Filterzeile: Kategorie-Tabs (International/National/ Regional)
und der Einzel/Staffel-Toggle (bislang eigene `<a href>`-Link-Reihen außerhalb des Filterformulars)
wurden zu zwei zusätzlichen Selects **innerhalb** des einen Filterformulars, die bei Auswahl sofort neu laden
(`x-model` + `$watch` in `x-init`, gleiches Muster wie das gerade erst gefundene `qualifying-time-lists`-Problem oben).
Sportklasse wechselte vom Freitextfeld auf ein Dropdown. Alle sechs Filter (Kategorie, Art, Typ, Sportklasse,
Geschlecht, Bahn) stehen jetzt in einer Zeile, linksbündig, mit dem Filtern-Button per `ms-auto` rechtsbündig am
Zeilenende — wieder das aus `club-entries/index.blade.php` bekannte Muster.

- **Sportklassen-Dropdown datengetrieben statt Freitext.** Die Anzahl und die Nummern der Optionen kommen aus
  `BaseTimeSportClass` (Tabelle der in den Basiswerten gepflegten Klassencodes, aktuell S1–S19 sowie S21 — 20 Einträge).
  Da ein `SwimRecord.sport_class` immer genau eine von S/SB/SM ist, fasst je eine Dropdown-Option alle drei zu einer
  Klassennummer zusammen zusammen: Label zweistellig gepolstert ("S01,SB01,SM01" … "S21,SB21,SM21"), Wert ungepolstert
  und kommagetrennt ("S1,SB1,SM1"), damit er direkt den echten `sport_class`-Werten entspricht.
  `RecordController::buildSportClassOptions()` liefert Optionen + Default abhängig vom Einzel/Staffel-Filter; gefiltert
  wird über `whereIn('sport_class', explode(',', $sportClass))` — ein Wert reicht dabei genauso wie drei.
- **Staffel-Sportklassen sind eine fixe Liste, keine Ableitung.** Bei "Staffeln" zeigt das Dropdown stattdessen die
  sechs kombinierten Staffelklassen aus `RelayClassValidator` (S14, S15, S20, S21, S34, S49) — keine Vorauswahl.
- **Defaults wirken serverseitig, nicht nur kosmetisch auf die Vorbelegung.** Neu: Einzel/Staffel-Filter default
  `single` (vorher `''` = alle), Bahn default `SCM`, Sportklasse default die erste Basiswerte-Nummer (S1,SB1,SM1). Alle
  drei fließen direkt in die Query — ein Aufruf von `/records` ohne Parameter zeigt also von vornherein
  Einzel/National/SCM/S1,SB1,SM1 gefilterte Rekorde, nicht die volle ungefilterte Liste. Ungültige/inkonsistente
  Kombinationen (z. B. ein Sportklassen-Wert aus dem Einzel-Set, während `relay=relay` gesetzt ist — passiert beim
  Wechsel der Art, weil das Sportklassen-Select seinen alten Wert noch mitschickt) werden serverseitig auf den
  jeweiligen Default zurückgesetzt, nach demselben Muster wie der bereits bestehende `$recordType`-Fallback.

**Tests**: `composer test` — 1394 Tests weiterhin grün (keine Tests für `RecordController::index()` vorhanden, daher
kein Regressionsrisiko durch die Default-Änderung), `composer lint:check` grün (inkl. `composer lint` für
`ordered_class_elements` an der neuen `buildSportClassOptions()`-Methode). Live im Browser: Aufruf ohne Parameter zeigt
korrekt National/Einzel/S01,SB01,SM01/SCM (25m) vorbelegt und tatsächlich gefiltert (0 Treffer verifiziert gegen einen
direkten DB-Query — es existieren schlicht keine S1/SB1/SM1-Rekorde in den Testdaten, kein Bug). Wechsel auf "Staffeln"
per Klick: Sportklassen-Optionen wechseln live auf die sechs Staffelklassen, Auswahl wird zurückgesetzt. Wechsel der
Kategorie auf "International": `type` korrigiert sich automatisch von "AUT" auf
"Weltrekorde".

### Zweiter Design-Feedback-Nachtrag zu Phase 10 — Jugend/Offen getrennt, Bug-Fund Verbandsauswahl

- **Jugend/Offen aus der Verbandsliste herausgelöst.** Bisher gab es je Verband zwei Options-Zeilen ("BBSV – … " /
  "BBSV – … (Jugend)"); nach Wunsch jetzt ein eigenes Dropdown "Alle/Jugend/Offen" (`age_group`), das Typ-Dropdown zeigt
  nur noch die Region selbst — bei National ein einzelner Eintrag "Österreich", bei Regional ein Eintrag je
  Landesverband. `record_type` in der DB kodiert Region und Jugend-Status weiterhin in einem String (z.B.
  `"AUT.WBSV.JR"`); der Controller baut daraus `$recordTypes` — ein einzelner Wert bei "Jugend"/"Offen", zwei Werte
  (`whereIn`) bei "Alle". International bekommt das neue Dropdown gar nicht erst angezeigt (kennt keine Jugendrekorde).
- **Verbandslabel gekürzt.** Statt des vollen Vereinsnamens ("BBSV – Burgenländischer Behindertensportverband", im
  schmalen Dropdown abgeschnitten) jetzt "BBSV Burgenland" — Kürzel + Bundesland. Dafür eine neue Konstante
  `Club::REGIONAL_ASSOCIATION_STATES` (Verbandscode → Bundesland) neben der bestehenden
  `REGIONAL_ASSOCIATIONS` (Verbandscode → voller Name) ergänzt. Typ- und Bahn-Dropdown zusätzlich verbreitert (`w-56`→
  `w-64` bzw. `w-36`→`w-44`), wie gewünscht, obwohl das gekürzte Label allein schon nicht mehr abgeschnitten worden
  wäre.
- **Filtern-Button auf `variant="primary"`** — vorher ohne Variant (weiß/ghost), jetzt wie die Filtern-Buttons aus Phase
  9 (z.B. `meets/index.blade.php`).
- **Bug gefunden und behoben: Verbandsauswahl wirkte beim Kategoriewechsel leer.** Die `:selected`-Markierung der
  Typ-Options verglich bislang gegen den rohen Query-Parameter (`request('type', 'AUT.WBSV')`) statt gegen den vom
  Controller bereits korrigierten `$baseType`. Nach einem Wechsel von National auf Regional stand im Query-String noch
  der alte, für Regional ungültige Wert ("AUT") — keine Option matchte, das Dropdown zeigte keinen Text an, obwohl der
  Controller serverseitig längst korrekt auf den ersten Verband zurückgefallen war (dieser interne Fallback bestimmte
  auch, welche Datensätze tatsächlich angezeigt wurden — nur die Anzeige im Dropdown hinkte hinterher). Fix:
  `:selected="$baseType === $type"` statt `request('type', …)` — exakt das gleiche Diagnosemuster wie beim `mt-1`
  /Spezifitäts-Fund vorhin: die Anzeige folgte einer anderen Quelle als die tatsächlich wirksame serverseitige Logik.

**Tests**: `composer test` — 1394 Tests weiterhin grün, `composer lint:check` grün. Live im Browser: Wechsel National →
Regional zeigt jetzt korrekt "BBSV Burgenland" im Typ-Feld (vorher leer) trotz weiterhin veraltetem
`type`-Query-Parameter; alle 9 Landesverbände korrekt als "Kürzel Bundesland" gelistet; Regional → International
korrigiert `type` auf "Weltrekorde" und blendet das Jugend/Offen-Dropdown aus; Auswahl "Jugend" bei National erzeugt
zwei separate Badges ("Österreich" + "Jugend") statt eines kombinierten Labels; Filtern-Button rendert mit
`--color-accent` (Primary-Farbe).

### Dritter Design-Feedback-Nachtrag zu Phase 10 — Sportklasse breiter, Ort-Spalte

- Sportklassen-Dropdown `w-44` → `w-56`.
- **Neue Spalte "Ort"** in der Rekordtabelle (zwischen Verein und Datum): Flagge (`<x-flag>`, gleiche Komponente wie in
  `public/records/index.blade.php` — dort existierte diese Spalte bereits) + `meet_city`. `meetNation` zum Eager-Load in
  `RecordController::index()` ergänzt, um N+1-Queries zu vermeiden.

**Tests**: `composer test` — 1394 Tests weiterhin grün, `composer lint:check` grün. Live im Browser gegen einen echten
Datensatz (S5, SCM, "Tirol, Innsbruck", AUT) geprüft: Ort-Spalte zeigt Flagge (`fi fi-at`) + Ortsname,
Sportklassen-Select trägt die `w-56`-Klasse.

### Vierter Design-Feedback-Nachtrag zu Phase 10 — redundante Spalten, zwei weitere Bugs derselben Ursachen

- **Klasse-/Geschlecht-/Bahn-Spalten ausgeblendet, wenn genau darauf gefiltert ist** — jede Zeile hätte ohnehin
  denselben Wert (schon als Badge sichtbar). `$columnCount` in der View berechnet den `colspan` der Leerzustands-Zeile
  passend zur tatsächlichen Spaltenzahl statt eines festen Werts.
- **Bug gefunden und behoben: "Alle" bei Jugend/Offen sprang nach dem Filtern immer auf "Offen" zurück, zeigte nur die
  offene statt beider Listen.** Exakt derselbe, bereits einmal in diesem Projekt dokumentierte Flux-Bug wie beim
  vorherigen Nachtrag vermutet, hier aber neu bestätigt: die "Alle"-Option hatte `value=""`, und ein leeres `value`
  kommt bei `<flux:select>` beim Absenden nie im Request an (`age_group` fehlte im Query-String komplett) — der
  Controller fiel dadurch immer auf den `OPEN`-Default zurück. Fix: "Alle" bekommt einen echten Sentinel-Wert
  (`value="ALL"`) statt eines leeren Strings.
- **Beim Nachziehen dieses Fixes auffällig geworden: dasselbe `value=""`-Muster steckte auch im Einzel/Staffel/Alle-
  Dropdown** (`relay`, aus dem vorletzten Nachtrag) — noch nicht gemeldet, aber identische Ursache, daher im selben
  Aufwasch mit demselben Sentinel-Muster behoben (`value="ALL"` statt `""`).
- **Zweiter, unabhängiger Bug beim Verifizieren gefunden: ein geleertes Bahn-Dropdown (`course`, `clearable`) blieb
  fälschlich auf "SCM (25m)" hängen, die Bahn-Spalte verschwand nie.** Andere Ursache als der `value=""`-Bug:
  Laravels `ConvertEmptyStringsToNull`-Middleware wandelt ein leeres `course=`-Feld in `null` um, bevor es den
  Controller erreicht — die bisherige Prüfung (`in_array($course, ['', 'LCM', 'SCM'], true)`) erkannte `null` nicht als
  gültigen "kein Filter"-Zustand und setzte fälschlich den Default zurück. `$sportClass` hatte dasselbe Problem bereits
  vermieden (`$sportClass !== null && …`); `$course` bekam denselben Schutz.

**Tests**: `composer test` — 1394 Tests weiterhin grün, `composer lint:check` grün. Live im Browser: `age_group=ALL`
liefert per direktem DB-Gegencheck exakt die Summe aus Jugend- und offenen Rekorden (20 + 8 = 28, alle 28 Zeilen
gerendert); `relay=ALL` bleibt nach dem automatischen Neuladen sichtbar ausgewählt; ein geleertes Bahn-Dropdown zeigt
die Bahn-Spalte wieder und hält den Filter nicht mehr fälschlich auf SCM; Spalten-Ausblendung per direktem
Server-Response (ohne Alpine) für mehrere Filterkombinationen gegengeprüft.

### Fünfter Design-Feedback-Nachtrag zu Phase 10 — Jugend/Offen-Spalte, feste Stil-Sortierung

- **Neue Spalte "Jugend/Offen"**, nur eingeblendet wenn `age_group=ALL` (sonst durch die Filterauswahl selbst schon
  eindeutig, redundant wie die Klasse-/Geschlecht-/Bahn-Spalten). Leitet sich aus `record_type` ab
  (`str_ends_with($record->record_type, '.JR')`) — kein neues Datenfeld nötig, die Information steckt schon im
  bestehenden Wert. `$columnCount` entsprechend erweitert.
- **Feste Stil-Reihenfolge Freistil → Brust → Rücken → Schmetterling → Lagen statt der bisherigen
  Sportklasse→Geschlecht→Distanz-Sortierung.** Rückfrage zu zwei offenen Punkten geklärt: Schmetterling (in der Vorgabe
  nicht genannt) kommt zwischen Rücken und Lagen; bei Geschlecht = Alle steht Geschlecht (Frauen vor Herren) HINTER der
  Distanz in der Sortierhierarchie (je Stil+Distanz erst Frauen, dann Herren — nicht erst alle Frauen-Distanzen
  komplett). Sportklasse bleibt wie bisher das äußerste Sortierkriterium (unverändert, war nicht Teil der Anfrage) —
  Stil/Distanz/Geschlecht sortieren nur innerhalb einer Sportklasse.
  `SwimRecord.stroke_type_id` ist nur eine FK, kein Code-String — für die feste Reihenfolge muss `stroke_types`
  gejoint werden (`RecordController::index()`), mit explizitem `select('swim_records.*')` davor, sonst überschreiben
  gleichnamige Spalten aus `stroke_types` (z.B. `id`) die von `swim_records`. Die Stil-Reihenfolge läuft über
  `orderByRaw()` mit einem portablen `CASE lenex_code WHEN … END` (kein MySQL-spezifisches Konstrukt, läuft auf SQLite
  genauso). Die Geschlecht-Sortierung (`CASE gender WHEN 'F' THEN 1 WHEN 'M' THEN 2 …`) ist immer aktiv statt bedingt
  auf `age_group`/`gender` verzweigt zu werden — bei einem bereits gefilterten Einzelgeschlecht ist sie einfach ein
  No-op, das spart bedingten SQL-Aufbau. **Beim Verifizieren zunächst ein Verdacht auf einen Sortierbug, der sich als
  Test-Artefakt herausstellte:** Eine Testabfrage mit `sport_class=S1,SB1,SM1` (drei Sportklassen gleichzeitig) zeigte
  scheinbar Freistil→Rücken→ Schmetterling→Brust→Lagen statt der erwarteten Reihenfolge — tatsächlich aber drei
  getrennte, je nach Sportklasse strukturell unterschiedliche Blöcke (S-Klassen führen nur
  Freistil/Rücken/Schmetterling, SB-Klassen nur Brust, SM-Klassen nur Lagen — die Para-Schwimm-Klassifikation trennt das
  strukturell), jeder Block intern korrekt sortiert. Per direkter SQL-Abfrage (`CASE`-Wert einzeln berechnet, roher
  `DB::select()`) gegengeprüft:
  keine Sortier-Logik fehlerhaft, nur die Testmethode irreführend.

**Tests**: `composer test` — 1394 Tests weiterhin grün auch mit dem neuen `join()` (bestätigt SQLite-Kompatibilität des
`CASE`-Ausdrucks), `composer lint:check` grün. Live im Browser: `age_group=ALL` zeigt die neue Spalte korrekt (
"Offen"/"Jugend" je Zeile), `colspan` im Leerzustand stimmt (9 bei Klasse+Bahn gefiltert, Geschlecht offen, Alle
Jugend/Offen). Sortierung innerhalb einer einzelnen Sportklasse (S5) per direktem Server-Response verifiziert:
Freistil (25/50/100/200/400m) → Rücken (25/50/100m) → Schmetterling (50m), Geschlecht durchgehend Damen vor Herren je
Distanz.

### Sechster Design-Feedback-Nachtrag zu Phase 10 — PhpStorm-Befunde, echter Bug gefunden, Athlet-Anzeige

- **Duplicate-match-arm-Meldung behoben**: `$category === 'international'` und `$ageGroup === 'OPEN'` lieferten
  identisch `[$baseType]` — als eine gemeinsame Arm-Bedingung (`match(true)` erlaubt mehrere komma-getrennte Bedingungen
  je Arm) statt zwei separater Arme mit gleichem Körper geschrieben. Grammatik-Hinweis an einem Doc-Kommentar (Bezug des
  Relativpronomens unklar durch eingeschobenes Beispiel in Klammern) durch Umstellen des Satzes behoben.
- **Bug gefunden und behoben, kein totes Element**: `storeRelayMembers()` war zwar als "unused" markiert, aber **kein
  Kandidat zum Löschen** — die Methode existierte bereits vollständig implementiert (`RelayTeamMember` aus den
  `relay_members[]`-Formularfeldern anlegen), wurde aber nie aus `storeManual()` oder `update()` aufgerufen.
  Staffel-Rekorde, die über `records/form.blade.php` mit ausgefüllter Startaufstellung angelegt oder bearbeitet wurden,
  verloren die Namen der Staffelmitglieder komplett — validiert, aber nie gespeichert. Beide Aufrufstellen ergänzt. Per
  Fetch-Test verifiziert: Staffel-Testrekord mit zwei Mitgliedern angelegt, beide korrekt mit
  `position`/Name in `relay_team_members` gefunden, Testdaten wieder gelöscht.
- **`importForm()`, `import()`, `export()` waren echte tote Elemente — gelöscht.** Alle drei waren ein früher
  Scaffold-Stand (der `import()`-Rumpf hatte noch ein `// TODO: RecordImportService implementieren` und tat nichts
  Echtes), lange bevor die tatsächliche Import/Export-Funktionalität in eigenen Controllern (`RecordImportController`,
  `RecordExportController`) fertig gebaut wurde. Die Routen `records.import`/
  `records.export` zeigen seitdem auf diese, nicht auf `RecordController` — per Grep bestätigt: keine Route, kein
  Blade-Aufruf, kein Test referenziert die drei Methoden auf `RecordController`.
- **Athlet-Anzeige in der Rekordliste**: Flagge (`<x-flag>`, gleiche Komponente wie bei der neuen Ort-Spalte) vor dem
  Namen statt Nationscode in Klammern danach; die Klammer zeigt jetzt stattdessen das Geburtsjahr. Bei
  Staffel-Mitgliedern (kein eigener Nations-Datensatz, daher keine Flagge möglich) zumindest das Geburtsjahr ergänzt, wo
  vorhanden.

**Tests**: `composer test` — 1394 Tests weiterhin grün, `composer lint:check` grün. Live im Browser: Flagge (`fi
fi-at`) + Name + `(2008)` für einen echten Datensatz bestätigt.

### Siebter Design-Feedback-Nachtrag zu Phase 10 — alle Filter lösen sofort aus, Filtern-Button entfernt

Bisher lösten nur Kategorie und Einzel/Staffel sofort eine neue Suche aus (siehe oben, gleicher `value=""`- Bug-Fund),
die übrigen vier Felder (Region/Typ, Jugend/Offen/Alle, Sportklasse, Geschlecht, Bahn) brauchten den Filtern-Button.
Jetzt lösen alle sieben Felder bei Änderung sofort aus — gleiches, schon etablierte `x-model` +
`$watch`-Muster, nur auf alle Felder ausgeweitet statt nur auf zwei. Der Filtern-Button ist damit überflüssig und wurde
entfernt (es gibt kein Freitextfeld auf dieser Seite, das ein bewusstes "erst abschicken" bräuchte — anders als z.B. die
Namenssuche auf `qualifying-time-lists/qualifications.blade.php`, die einen eigenen Button behält).

`x-model` übernimmt dabei auch gleich die Vorbelegung für alle sieben Felder — die verbliebenen `:selected()`- Bindungen
(Region/Typ, Sportklasse, Geschlecht, Bahn) sind dadurch überflüssig geworden und entfernt. Der beim Region-Feld
gefundene Bug (Anzeige folgte dem rohen `request('type', …)` statt dem korrigierten `$baseType`) bleibt mit
`x-model="baseType"` behoben, jetzt sogar robuster als vorher: `x-model` bindet direkt an die vom Controller bereits
korrigierte PHP-Variable, kein `:selected`-Vergleich mehr nötig, der bei einem Mismatch ins Leere läuft.

**Tests**: `composer test` — 1394 Tests weiterhin grün, `composer lint:check` grün. Live im Browser alle sieben Felder
einzeln durchgeklickt: Geschlecht, Sportklasse, Bahn (inkl. Leeren über den Clear-Button, per direktem
Alpine-State-Zugriff getestet), Jugend/Offen, Region/Typ — jedes löst sofort einen Reload mit korrektem Query-Parameter
aus und bleibt danach sichtbar vorbelegt. Region-Feld nach Kategoriewechsel auf Regional weiterhin korrekt "BBSV
Burgenland" statt leer (Bugfix hält mit dem neuen Mechanismus). Filtern-Button ist aus dem DOM verschwunden.

### Achter Design-Feedback-Nachtrag zu Phase 10 — Rekord manuell eintragen komplett überarbeitet

`records/form.blade.php` (Create + Edit) auf das etablierte P9-Formularmuster umgestellt: Header (Titel oben, Zurück
darunter links), Card auf `max-w-4xl` verbreitert, die drei bisherigen Cards (Rekord-Klassifizierung, Leistung,
Splitzeiten) in `flux:tab.group` verschoben — eine Card umschließt jetzt Formular und Tabs zusammen, gleiches Muster wie
`results/form.blade.php`.

- **Regional-Bezeichnungen** im Rekord-Typ-Dropdown auf "Kürzel Bundesland" umgestellt (`Club::REGIONAL_
  ASSOCIATION_STATES`, wie im Listenfilter) statt des vollen, oft abgeschnittenen Vereinsnamens — pro Verband weiterhin
  zwei Zeilen (offen/Jugend), da das Formular anders als der Filter kein eigenes Jugend/Offen-Dropdown hat und
  `record_type` beides in einem String kodieren muss.
- **Bahn-Default SCM**, **Disziplin-Feld verbreitert** (`col-span-2` in einer `grid-cols-4`-Zeile, gleiches Muster wie
  `swim-events/form.blade.php`), **Distanz vom Zahlenfeld auf Dropdown** mit denselben erlaubten Werten
  (25/50/75/100/150/200/400/800/1500 m) inkl. Erhalt eines abweichenden Bestandswerts als Zusatzoption.
- **`mt-1!`-Fix** auf die Schwimmzeit-Beschreibung nachgezogen (hatte noch gar keine Klasse, also auch keinen Abstand).
- **Athlet-/Verein-Labels**: `ms-1` zwischen Label und Klammerzusatz ("(leer bei Staffeln)" /
  "(zum Zeitpunkt des Rekords)") — vorher ein einzelnes Leerzeichen im Blade-Quelltext, das visuell praktisch keinen
  Abstand ergab.
- **Athlet ↔ Verein bidirektional verknüpft, plus Aktiv/Inaktiv-Filter — alles rein clientseitig, keine neue Route.**
  Athlet gewählt → Verein wird automatisch vorbelegt (etabliertes `athleteClubMap` + `$watch`-Muster aus
  `entries/form.blade.php`/`results/form.blade.php`). Umgekehrt: Verein zuerst gewählt → die Athletenliste schränkt sich
  per `x-show` auf dessen Athleten ein (beide Richtungen greifen ineinander, ohne AJAX-Rundgang — alle 542 Athleten
  inkl. `club_id` sind bereits als Optionen im DOM, wie es entries/results/form auch schon so machen). Inaktive Athleten
  sind standardmäßig ausgeblendet (`is_active`-Flag je Option), ein Switch
  "Auch inaktive Athleten anzeigen" blendet sie ein — beim Bearbeiten eines Rekords mit bereits inaktivem Athleten ist
  der Switch von vornherein aktiv, sonst wäre die eigene Auswahl in der Liste unsichtbar.
- **Wettkampf/Ort/Land neu proportioniert** (`grid-cols-6`: Wettkampf 3, Ort 1, Land 2) statt gleich breiter Drittel,
  damit "Code – Name" bei Austragungsland nicht abgeschnitten wird.
- **Schwimmzeit-Eingabe eigens verschmälert** (`w-56`-Wrapper-Div um das `flux:input`, nicht direkt eine Breitenklasse
  am Feld — `flux:input` bringt intern ein festes `w-full` mit, das eine Klasse am selben Element überstimmt, siehe
  Kommentar in `athletes/index.blade.php`) statt die volle Card-Breite auszufüllen.

**Beim Verifizieren zwei Verdachtsfälle, die sich als Test-Artefakte herausstellten, nicht als Bugs:**
Erstens ein per Klick gewählter Verein ohne einzige Zeile ("AG Down Syndrom Vorarlberg") zeigte 0 Athleten — gegen die
DB geprüft: der Verein hat tatsächlich 0 Athleten, korrektes Verhalten. Zweitens ein direktes Setzen von
`Alpine.$data(form).clubId` per JS (statt eines echten Klicks durchs Dropdown) triggerte die `x-show`-Reaktivität nicht
zuverlässig — beim Nachstellen über echte Klicks funktionierte die Filterung sofort korrekt. Für die
Aktiv/Inaktiv-Filterung gab es in den Testdaten keinen einzigen inaktiven Athleten (542/542 aktiv) — ein Datensatz
testweise auf inaktiv gesetzt, Verhalten bestätigt, wieder zurückgesetzt.

**Tests**: `composer test` — 1394 Tests weiterhin grün (keine Controller-Änderung, nur die View), `composer
lint:check` grün, `php artisan view:cache` fehlerfrei. Live im Browser: Verein-zuerst-wählen schränkt die Athletenliste
korrekt ein (2/2 für einen echten Verein mit Athleten), Athlet-zuerst-wählen setzt den Verein korrekt, Inaktiv-Switch
blendet einen testweise deaktivierten Athleten korrekt ein/aus, kompletter Anlegen-Rundgang per Fetch (inkl.
Distanz-Dropdown-Wert, Athlet, Verein) gegen die DB verifiziert und wieder gelöscht, Bearbeiten-Ansicht eines
bestehenden Rekords zeigt Athlet/Verein/Schwimmzeit korrekt vorbelegt.

### Neunter Design-Feedback-Nachtrag zu Phase 10 — Gliederung Leistung-Tab, PhpStorm-Befunde

- **"Leistung"-Tab zweigeteilt**: erst "Rekordinhaber" (Athlet/Verein, Staffelteam), dann — durch eine
  Trennlinie/Zwischenüberschrift "Rekorddaten" abgesetzt — Schwimmzeit, Nation, Datum, Wettkampf/Ort/Land, Anmerkung.
  Vorher stand die Schwimmzeit zuerst, unabhängig davon, wer den Rekord hält.
- **Label "Datum" → "Rekorddatum"**, um es von anderen Datumsfeldern im Formular (Staffelmitglieder-Geburtsjahr)
  eindeutig zu unterscheiden.
- **PhpStorm-Befunde**: redundanter `(bool)`-Cast entfernt (der Ausdruck war durch `&&`/`!` schon ein Bool). Das
  Staffelteam-`x-data` nutzte noch das alte Muster (PHP-Ternäre direkt im JS-Objektliteral statt
  `@json()` mit vorberechneten Variablen, einfach angeführt) — auf das seit dem `@json()`-Komma-Fallstrick etablierte
  Muster umgestellt; das dürfte auch die gemeldeten "Missing opening directive"/"Method expression is not of Function
  type"-Befunde miterledigen, da PhpStorms Blade-Parser bei roher `{{ }}`-Verschachtelung in einem JS-Attribut
  nachweislich (siehe frühere Nachträge) die Klammerbalance verliert. `@unless`/`@endunless`
  bei der Inaktiv-Kennzeichnung auf `@if(! …)`/`@endif` umgestellt (funktional identisch, aber die im Projekt weitaus
  geläufigere Direktive).

**Tests**: `composer test` — 1394 Tests weiterhin grün, `composer lint:check` grün. Live im Browser:
Reihenfolge innerhalb des Leistung-Tabs per Textindex im tatsächlichen `[data-flux-tab-panel="leistung"]`-Element
geprüft (Rekordinhaber → Athlet → Rekorddaten → Schwimmzeit), "Rekorddatum"-Label vorhanden, kompletter Anlegen-Rundgang
mit Jugend-Regionaltyp per Fetch gegen die DB verifiziert und wieder gelöscht.

### Zehnter Design-Feedback-Nachtrag zu Phase 10 — Layout, Athleten-Filter nach Klasse/Geschlecht

- **"Rekordinhaber"-Titel und "Auch inaktive Athleten anzeigen"-Schalter in einer Zeile** (`flex justify-between`).
- **Rekorddaten neu geordnet**: Wettkampf + Ort zuerst (`grid-cols-3`, Wettkampf `col-span-2`), dann Schwimmzeit +
  Rekorddatum + Austragungsland (`grid-cols-3`), zum Schluss Anmerkung. "Nation" war in der Anfrage nicht erwähnt —
  thematisch in den Abschnitt "Rekordinhaber" verschoben (wessen Nationalität, nicht wo der Wettkampf stattfand;
  Letzteres bleibt "Austragungsland" bei den Rekorddaten).
- **Neuer Athleten-Filter nach Geschlecht + aktueller Sportklasse aus dem Klassifizierung-Tab.** `gender` und
  `sport_class` dort bekamen `x-model`, dieselbe `x-show`-Bedingung wie beim Verein-Filter prüft zusätzlich Geschlecht
  (`athlete->gender`) und ob die eingegebene Sportklasse in `athlete->sportClasses` vorkommt (Codes clientseitig als
  rohe JS-Array-Literal-Elemente eingebettet, kein `@json()` — der ganze `x-show`-Ausdruck ist doppelt angeführt,
  `@json()`s eingebettete doppelte Anführungszeichen hätten das Attribut gebrochen). **Zum genannten Problem**
  (Sportklassen ändern sich durch Reviews — ein alter Rekord kann einem Athleten gehören, der inzwischen anders
  klassifiziert ist): kein Open Point geworden, sondern direkt gelöst — ein zweiter Schalter "Klasse/Geschlecht
  ignorieren" (Standard aus) schaltet die Prüfung bei Bedarf komplett ab. Deckt beide genannten Fälle ab: im Normalfall
  (neuer Rekord) zählt die aktuell gültige Klasse automatisch, ohne dass jemand etwas umschalten muss; beim Nacherfassen
  eines alten Rekords mit inzwischen reklassifiziertem Athleten bleibt der Zugriff auf die volle, ungefilterte Liste
  eine bewusste Ein-Klick-Aktion statt eines stillen Workarounds. Eine "echte" historische Klassifizierung zum
  Rekorddatum aufzulösen wäre ein separates, deutlich größeres Feature (Klassifizierungshistorie existiert aktuell
  nicht) — dafür bliebe bei Bedarf weiterhin die Option, das später als eigenen Punkt aufzunehmen.
- **PhpStorm-Befunde, zweiter Versuch**: Das Staffelteam-`x-data` war schon auf `@json()` umgestellt (voriger Nachtrag),
  blieb aber einzeilig — auf dasselbe mehrzeilige Format wie das äußere Formular-`x-data` gebracht, falls die
  Formatierung selbst zur Verwirrung beigetragen hat. Ob das die gemeldeten "Missing opening directive"/"Method
  expression is not of Function type"-Befunde tatsächlich auflöst, lässt sich von hier aus nicht direkt in PhpStorm
  nachvollziehen — die betroffenen Blade-Konstrukte selbst (`@if`/`@endif`,
  `@for`/`@endfor` in Komponenten-Slots) sind Standard-Blade-Syntax, an mehreren Stellen dieser Datei und anderswo im
  Projekt identisch verwendet und dort nicht gemeldet.

**Tests**: `composer test` — 1394 Tests weiterhin grün, `composer lint:check` grün. Live im Browser: Filter nach
Geschlecht=Damen + Sportklasse=S9 liefert exakt die 4 Athletinnen, die auch eine direkte DB-Abfrage liefert;
"Klasse/Geschlecht ignorieren" stellt alle 542 wieder her; Layout-Reihenfolge (Rekordinhaber+Schalter, Wettkampf+Ort,
Rekorddaten, Schwimmzeit/Rekorddatum/Austragungsland, Anmerkung) per Textindex im echten Tab-Panel-Element bestätigt;
kompletter Anlegen-Rundgang für einen Staffel-Rekord mit Team-Mitgliedern gegen die DB verifiziert und wieder gelöscht.

### Elfter Design-Feedback-Nachtrag zu Phase 10 — Splitzeiten-Schrittweite, Status-Spalte/-Filter/-Schnelländerung, Show-Header

- **Splitzeiten: Distanz-Schrittweite 50 statt 1** (`step="50"` am Zahlenfeld) und **Zeilenanzahl abhängig von der
  gewählten Distanz statt fix 10** — vorher waren bei 800m/1500m-Rekorden nicht genug Zeilen für alle 50m-Splits
  vorhanden. Neue Berechnung `$maxSplitRows = (int) (max($distanceOptions) / 50) - 1` (= 29 bei 1500m als größter
  erlaubter Distanz) ersetzt die feste `@for`-Obergrenze; die Klassifizierung-Tab-Distanz bekam
  `x-model="distanceValue"`, jede Splitzeiten-Zeile blendet sich per
  `x-show="!distanceValue || (i+1)*50 < parseInt(distanceValue)"` passend zur aktuell gewählten Distanz ein/aus (reaktiv
  bei Distanzwechsel, kein Reload nötig).
- **Rekordlisten: Status-Spalte, -Filter, -Schnelländerung.** `RecordController::index()` filtert jetzt zusätzlich nach
  `record_status` (neue Konstante `STATUS_OPTIONS`, ungültige/leere Werte werden zu "kein Filter"
  normalisiert). Neues `flux:select variant="listbox" x-model="status"` im Filter (gleiches
  `x-init`+`$watch`-Auto-Submit-Muster wie die übrigen Listenfilter), Status-Badge in der aktiven-Filter-Zeile, neue
  Tabellenspalte mit Status-Badge (Farbe je Status: APPROVED emerald, PENDING amber, INVALID red, TARGETTIME blue) —
  Spalte blendet sich aus, sobald der Status-Filter selbst aktiv ist (gleiches Muster wie die übrigen
  spaltenausblendenden Filter). Pro Zeile ein neues `flux:dropdown`+`flux:menu` mit einem `arrow-path`-Icon-Button
  (eingefärbt nach aktuellem Status): jeder Menüpunkt ist ein eigenes kleines `POST`-Formular auf eine neue Route
  `PATCH records/{record}/status` → `RecordController::updateStatus()`, der aktuell aktive Status ist deaktiviert und
  mit einem Häkchen markiert. Erlaubt die Status-Änderung direkt aus der Liste, ohne das Bearbeiten-Formular zu öffnen.
  **Selbst gefundener Bug vor der Verifizierung**: die Icon-Einfärbung war zunächst als Laufzeit-String-Verkettung
  geschrieben (`'text-'.$farbe.'-500!'`) — Tailwinds Content-Scanner erkennt nur wörtlich im Quelltext vorkommende
  Klassennamen, keine zur Laufzeit zusammengesetzten Strings, die passende CSS-Regel wäre also nie kompiliert worden.
  Behoben durch eine statische PHP-Map (`$statusIconClasses`) mit dem vollen Klassennamen als Literal pro Status; per
  Grep im kompilierten `public/build/assets/app-*.css` bestätigt, dass alle vier `text-{farbe}-500!`
  Regeln korrekt mit `!important` kompiliert sind.
- **`records/show.blade.php`: Titelleiste auf das seit Phase 9 etablierte Muster umgestellt** (Titel/Badges oben,
  darunter eine Zeile mit "Zurück" links und den übrigen Aktions-Buttons rechtsbündig) — diese Seite war bisher nie
  migriert worden und hatte noch die alte einzeilige Anordnung.

**Tests**: `composer test` — 1394 Tests weiterhin grün, `composer lint:check` grün, `php artisan view:cache`
fehlerfrei, `npm run build` erfolgreich. Live im Browser: Splitzeiten-Zeilen reagieren korrekt auf die Distanz
(1500m-Rekord → alle 29 Zeilen sichtbar, 100m-Rekord → nur 1 Zeile sichtbar), `step="50"` an allen 29 Distanzfeldern
bestätigt; Status-Schnelländerung per echtem Fetch-Rundgang gegen die neue Route getestet (APPROVED → PENDING → zurück
auf APPROVED, jeweils gegen die DB verifiziert); Show-Seiten-Header eines 1500m-Rekords zeigt Titel oben, "Zurück" als
ersten Button links, "Bearbeiten"/"Löschen" rechtsbündig darunter.

### Zwölfter Design-Feedback-Nachtrag zu Phase 10 —
`$el.submit()`-Muster projektweit bereinigt, Vereinszuordnung beim Import

- **PhpStorm-Befund "Unresolved function or method submit ()" auf `records/show.blade.php` (:39/:224/:231) war mit dem
  vorigen JSDoc-Cast-Versuch (`/** @type {HTMLFormElement} */ ($el).submit()`) nicht behoben** — laut Screenshot bestand
  die Meldung unverändert weiter. Der tatsächlich in Phase 9 bereits etablierte und dort bestätigt wirksame Fix (siehe
  dortiger Nachtrag zu `meets/index.blade.php`/`clubs/index.blade.php`) ist ein anderer: nicht `$el` bare innerhalb der
  `@submit.prevent`-Direktive referenzieren, sondern eine benannte Methode im `x-data`-Objekt anlegen und darin
  `this.$el.submit()` aufrufen — `x-data="{ submit() { if(confirm(...))
  this.$el.submit() } }" @submit.prevent="submit()"`. Auf allen drei Stellen in `records/show.blade.php`
  nachgezogen.
- **Auf Zuruf ("mach es gleich überall wo du es findest") das gesamte Projekt nach dem bare `$el.submit()`-Muster
  durchsucht**: 16 Dateien nutzen `$el.submit()` insgesamt, die meisten bereits korrekt mit dem
  `this.$el`-in-benannter-Methode-Muster. Eine verbleibende Fundstelle mit der bare Variante innerhalb einer bereits
  benannten Methode: `athletes/index.blade.php` (:131, `del() { … $el.submit() }` → `del() { …
  this.$el.submit() }`). Keine weiteren Treffer mehr (`grep` nach `[^.]\$el\.submit\(\)` liefert 0 Treffer).
- **Import-Vorschau: unbekannte Vereine jetzt auch einem bestehenden Verein zuordenbar** (z. B. bei
  Tippfehlern/abweichender Schreibweise im LENEX-Export), statt nur "Neu anlegen"/"Überspringen". Die Backend-Logik in
  `RecordImportService::resolveClubs()` unterstützte das bereits (jeder Entscheidungswert außer `'new'`/`'skip'` wird
  als `(int)` gecastete bestehende Club-ID behandelt) — reine UI-Lücke.
  `RecordImportController::preview()` lädt jetzt zusätzlich alle Vereine (`Club::orderBy('name')->get(['id',
  'name'])`), die View zeigt sie als `flux:select.group label="Bestehendem Verein zuordnen (falsch
  geschrieben?)"` mit einer Option je Verein (`value` = Club-ID) unterhalb der bestehenden "Neu
  anlegen"/"Überspringen"-Optionen. Bei 53 Vereinen im Bestand eine flache Liste statt Combobox mit Suche — genug, um
  sie ohne Scrollen-über-Suchfeld durchzugehen.

**Tests**: `composer test` — 1394 Tests weiterhin grün, `composer lint:check` grün, `php artisan view:cache`
fehlerfrei. Live im Browser: Löschen-Bestätigung auf `records/show.blade.php` (Haupt-Rekord und Historie-Zeilen) sowie
`athletes/index.blade.php` per simuliertem `submit`-Event mit gemocktem `confirm()`
verifiziert (Dialog wird weiterhin aufgerufen, `submit()` bei Abbruch korrekt nicht ausgelöst). Für die Vereinszuordnung
ein Test-LENEX mit einem absichtlich unbekannten Verein per echtem Fetch-Rundgang gegen
`/records/import/preview` hochgeladen: `flux:select.group`-Abschnitt "Bestehendem Verein zuordnen" im gerenderten HTML
gefunden, alle 53 Vereine als Optionen mit numerischer Club-ID bestätigt, "Neu anlegen"/"Überspringen" weiterhin
vorhanden. Test-Datei und Session-Artefakte danach entfernt.

### Dreizehnter Design-Feedback-Nachtrag zu Phase 10 — Import-Vorschau: Dropdown-Breite, Kurzname, alphabetisch, Athleten-Zuordnung

- **Vereins-Dropdown verbreitert** (`w-64` → `w-96`) und zeigt jetzt `Club::display_name` (Kurzname, falls vorhanden,
  sonst voller Name) statt immer des vollen Namens — lesbarer bei langen Vereinsnamen.
- **Alphabetische Sortierung nach dem angezeigten Namen statt dem vollen Namen**: `Club::orderByRaw('COALESCE
  (short_name, name)')` statt `orderBy('name')` — sonst würde z. B. "BS Raiffeisen Osttirol" (voller Name)
  einsortiert, obwohl "BS Osttirol" (Kurzname) angezeigt wird. `COALESCE` ist Standard-SQL, läuft identisch auf MySQL
  und SQLite. Ein einzelner Ausreißer bleibt bewusst unangetastet: "ÖBSV" landet wegen Byte-/Codepoint-Sortierung (Ö >
  Z) ganz am Ende statt nahe "O" — echte deutsche Locale-Kollation wäre DB-Engine-spezifisch und würde die
  MySQL/SQLite-Portabilität verletzen (siehe CLAUDE.md), für einen einzelnen Verein in einer 53er-Liste kein
  ausreichender Grund dafür.
- **Unbekannte Athleten jetzt ebenfalls einer bestehenden Person zuordenbar** — exakt wie beim Vereins-Matching zuvor
  war das eine reine UI-Lücke: `RecordImportService::resolveAthletes()` behandelte einen Entscheidungswert außer
  `'new'`/`'skip'` schon immer als `(int)` gecastete bestehende Athlet-ID.
  `RecordImportController::preview()` lädt jetzt zusätzlich alle Athleten (`Athlete::orderBy('last_name')
  ->orderBy('first_name')->get(...)`), sortiert alphabetisch nach Nachname. Bei 542 Athleten wäre eine ungefilterte
  Liste pro unbekanntem Athleten unbrauchbar — die View grenzt deshalb pro Zeile auf Athleten mit demselben
  Anfangsbuchstaben des Nachnamens ein wie der nicht gefundene Athlet (Beispiel aus der Anfrage:
  "Kasper Thomas" nicht gefunden → Gruppe "Bestehendem Athlet zuordnen (Nachname K…)" zeigt nur K-Nachnamen). Anzeige je
  Option: `Athlete::display_name` ("Nachname, Vorname") plus Geburtsjahr in Klammern zur Unterscheidung gleichnamiger
  Personen. Keine Treffer für den Anfangsbuchstaben → Gruppe wird ausgeblendet statt leer angezeigt.
- **Beim Testen mit dem echten ÖBSV-Rekordfile aufgefallen, nicht behoben**: Der Nachname "Kasper" kommt im File zweimal
  als eigener `unknown_athletes`-Eintrag vor — einmal als `"KASPER"`, einmal als `"KASPER "` (mit Leerzeichen am Ende),
  weil `RecordImportService::parseAthleteXml()` LENEX-Attribute unbeschnitten übernimmt. Beide erscheinen dadurch als
  zwei getrennte Zeilen in der Vorschau, obwohl es derselbe Sportler ist — betrifft nicht nur diese Datei, sondern jeden
  Export mit Whitespace-Rauschen in Namen. Nicht angefasst, da nicht Teil der Anfrage; bei Bedarf ein `trim()` auf
  `firstname`/`lastname`/`license` in
  `parseAthleteXml()`/`parseClubXml()`.

**Tests**: `composer test` — 1394 Tests weiterhin grün, `composer lint:check` grün, `php artisan view:cache`
fehlerfrei. Live gegen das echte, vom Nutzer beigefügte `oebsv.lxf` getestet (1344 Records, davon 496 regulär
verwertbar, 97 unbekannte Athleten, 13 unbekannte Vereine): Vereins-Dropdown zeigt Kurznamen alphabetisch korrekt
sortiert (`BS Osttirol` statt `BS Raiffeisen Osttirol`, an der richtigen Stelle einsortiert); Athleten-Beispiel "KASPER,
Thomas" (identisch zum in der Anfrage genannten Beispiel) erzeugt korrekt die Gruppe
"Bestehendem Athlet zuordnen (Nachname K…)" mit ausschließlich K-Nachnamen, alphabetisch sortiert (Kapl, Karajic, Kaser,
Kases, Kaspar, Kaufmann, Kerl, Kern, …). Test-Datei und Session-Artefakte danach entfernt.

### Vierzehnter Design-Feedback-Nachtrag zu Phase 10 — Trim-Fix, Card-/Dropdown-Breite

- **Trim-Problem aus dem vorigen Nachtrag behoben**: `RecordImportService::parseAthleteXml()` (last_name, first_name,
  birth_date, gender, license) und `parseClubXml()` (code, name, nation) trimmen jetzt alle LENEX-Attributwerte. Vorher
  erzeugte z. B. `"KASPER"` und `"KASPER "` (Leerzeichen am Ende) zwei getrennte
  `unknown_athletes`-Einträge für dieselbe Person — am echten `oebsv.lxf` bestätigt: unbekannte Athleten sanken dadurch
  von 97 auf 92 (5 zusammengeführte Whitespace-Duplikate), der "KASPER, Thomas"-Fall aus der Anfrage taucht jetzt nur
  noch einmal auf.
- **Import-Vorschau-Seite verbreitert** (`max-w-3xl` → `max-w-4xl`).
- **Athleten-Dropdown verbreitert** (`w-72` → `w-96`, wie schon das Vereins-Dropdown), damit die
  Untergruppen-Überschrift ("Bestehendem Athlet zuordnen (Nachname K…)") nicht umbricht.

**Tests**: `composer test` — 1394 Tests weiterhin grün, `composer lint:check` grün, `php artisan view:cache`
fehlerfrei. Trim-Fix direkt gegen `RecordImportService::preview()` mit dem echten `oebsv.lxf` verifiziert (Anzahl
unbekannter Athleten 97 → 92, `KASPER, Thomas` nur noch ein Eintrag). Für die Dropdown-Breite: die Browser-Pane lieferte
in dieser Session durchgehend nur ein statisches Platzhalterbild bei Screenshots (auch nach neuem Tab) —
`getBoundingClientRect()`/`getComputedStyle()` degradierten ebenfalls auf 0/Default-Werte (bekannte Einschränkung bei
"hidden" Pane, hier aber auch nach Refokussieren nicht behoben). Ausgewichen auf eine pane-unabhängige
Canvas-Textmessung mit der tatsächlichen App-Schrift (System-Sans-Stack aus der kompilierten CSS, `text-xs`/
`font-medium` = 12px/500 laut CSS-Variablen): Athleten-Überschrift ≈ 255px, Vereins-Überschrift (bereits unbeanstandet
bei `w-96` im Einsatz) ≈ 284px — beide deutlich unter der verfügbaren Breite von 384px (`w-96`) abzüglich Innenabstand.
Die bisherige `w-72` (288px) ließ dagegen nur
~272px nutzbare Breite bei 255px Textbedarf, was das gemeldete Umbrechen erklärt. Echte visuelle Bestätigung per
Screenshot war in dieser Session nicht möglich — falls die Überschrift trotzdem noch umbricht, bitte mit Screenshot
melden.

### Fünfzehnter Design-Feedback-Nachtrag zu Phase 10 — Club-/Athleten-Matching-Diagnose, LENEX-Export-Formular

- **Rückfragen zum Club-/Athleten-Matching beim Import geklärt, zwei neue offene Punkte dokumentiert** (siehe
  `docs/open-points.md`): (1) "Post-Import Club-Konflikt-Liste" — `SwimRecord.club_id` ist bereits unabhängig vom
  `Athlete.club_id`, Rekorde bei unterschiedlichen Vereinen für denselben Athleten (Saram-Stephan-Fall) sind schon
  korrekt; es fehlt aber ein persistenter, abarbeitbarer Report für Fälle, in denen der LENEX-Verein vom gespeicherten
  `Athlete.club_id` abweicht. (2) "Athleten-Matching: nur Geburtsjahr" — am echten `oebsv.lxf`
  bestätigt: Hochenberger Philip und Rottmann Kilian stehen dort mit `birthdate="…-01-01"` (nur Jahr bekannt, Tag/Monat
  als Platzhalter), während die DB die echten Geburtsdaten führt (`1992-12-10`/`2008-02-04`) —
  `findAthlete()` verlangt exaktes `birth_date`, kein Bug (Namens-Suche ist bereits case-insensitiv), sondern ein
  Domänen-Fall, der über das bestehende manuelle Zuordnungs-Dropdown lösbar ist. Beide Punkte mit vollem Befund in
  `docs/open-points.md`.
- **LENEX-Export-Formular** (`resources/views/lenex/export.blade.php`) an das Flux-Standardmuster angepasst: das einzige
  verbliebene native `<flux:select><option>`-Dropdown ("Wettkampf") auf
  `<flux:select variant="listbox">` + `<flux:select.option :selected="...">` umgestellt (Vorschriften-Muster aus
  `CLAUDE.md`). Dabei einen bisher toten Query-Parameter gefunden und mitbehoben: Der "LENEX Export"-Button auf
  `meets/show.blade.php` verlinkt mit `?meet_id=…`, aber `LenexExportController::showForm()` hat den Parameter nie
  gelesen — die Vorbelegung griff nie. Jetzt liest der Controller `request()->query('meet_id')` und übergibt ihn als
  `selectedMeetId` an die View.

### Sechzehnter Design-Feedback-Nachtrag zu Phase 10 — LENEX-Export-Escaping geprüft, Open Points zusammengelegt

- **LENEX-Export-Sonderzeichen (`"` → `&quot;`, `&` → `&amp;`) geprüft**: kein Bug. `LenexExportService` nutzt PHPs
  `DOMDocument::setAttribute()`, das Attributwerte laut XML-Spezifikation zwingend so kodiert — ein rohes
  `"`/`&` würde die Datei ungültig machen. Rückprobe (Meet-Name testweise auf `Test "Anführungszeichen" &
  Kaufmanns-Und Meisterschaft` gesetzt, Export gebaut, Änderung per `DB::rollBack()` verworfen): Rohdatei enthält
  `&quot;`/`&amp;`, beim Zurücklesen über `SimpleXMLElement` (derselbe Mechanismus wie in echten LENEX-Programmen und im
  eigenen `RecordImportService`) kommt exakt der Originaltext heraus — 1:1-Round-Trip bestätigt. Als offenen Punkt
  dokumentiert (`docs/open-points.md`), da Erik ein reales Fremdprogramm nennt, das die Entities angeblich nicht
  zurückwandelt — das wäre ein Bug in diesem Fremdprogramm oder eine Rohtext-statt-Import-Ansicht, kein Bug bei uns;
  braucht konkrete Gegenprobe (Programmname + Beispieldatei).
- **Zwei Open Points zusammengelegt**: "Post-Import Club-Konflikt-Liste" und "Athleten-Matching: nur Geburtsjahr" auf
  Eriks Wunsch ("so dass wir das in einem machen können") zu einem gemeinsamen Punkt
  "Post-Import Review-Liste: Club-Konflikte + Jahres-Fallback-Matches" zusammengeführt — beide brauchen dieselbe
  Review-Infrastruktur. Der Jahres-Fallback-Regel (Namens- + Geburtsjahr-Match bei `-01-01`-Platzhalterdatum) hat Erik
  ausdrücklich zugestimmt; Umsetzung bleibt trotzdem zurückgestellt, da das Datenmodell für die Review-Liste selbst noch
  eine offene Entscheidung ist (siehe Punkt in `docs/open-points.md`).

**Tests**: Keine Code-Änderung in diesem Nachtrag (nur Diagnose per zurückgerollter Transaktion + Dokumentation) —
`composer test`/`composer lint:check` daher nicht erneut nötig, letzter bekannter Stand weiterhin 1394 Tests grün.

### Siebzehnter Design-Feedback-Nachtrag zu Phase 10 — LENEX-Export-Header auf P9-Muster umgestellt

- **`lenex/export.blade.php`-Header** auf das etablierte Muster umgestellt: Titel in eigener Zeile, darunter ein
  "Zurück"-Button (`variant="filled"`, linksbündig) statt bisher nur einem einzelnen `<h1>` ohne Navigations-Button.
  Ziel des Zurück-Buttons: `meets.index` — Erik per Rückfrage bestätigt (Seite wird sowohl direkt über die Seitenleiste
  als auch von `meets/show.blade.php` mit vorbelegtem Wettkampf erreicht, die
  "Wettkampf"-Tab ist die vorbelegte erste Tab).

**Tests**: `composer lint:check` grün, `php artisan view:cache` fehlerfrei, `composer test` — 1394 Tests weiterhin grün.
Live gegen `https://para-swimming.test` verifiziert: `/lenex/export` zeigt Titel + "Zurück"
untereinander, der Link führt korrekt zu `/meets` (per `read_page` auf `href` geprüft).

### Achtzehnter Design-Feedback-Nachtrag zu Phase 10 — eigentliche "Rekorde exportieren"-Seite gefunden und korrigiert

- **Falsche Seite im vorigen Nachtrag korrigiert**: Eriks Screenshot zeigte einen Header mit Ghost-Icon-Button inline
  vor dem Titel ("← Rekorde exportieren") — das war nicht `lenex/export.blade.php` (dort steht der Titel
  "LENEX Export"), sondern eine komplett eigenständige, bis dahin übersehene Seite:
  `resources/views/records/export.blade.php`, erreichbar über den eigenen Nav-Eintrag "Rekorde" → "Rekorde exportieren"
  (Route `records.export`, `RecordExportController::showForm()`). Beide Seiten senden an dieselbe
  `records.export.download`-Route — echte Duplizierung derselben Funktionalität unter zwei verschiedenen Nav-Einträgen
  (`LENEX` → `Export` → Tab "Rekorde", und `Rekorde` → "Rekorde exportieren"), nicht Gegenstand dieses Nachtrags, aber
  notiert falls Erik das später konsolidieren möchte.
- **Header von `records/export.blade.php` umgestellt**: von `flex items-center gap-3` mit Ghost-Icon-Button inline vor
  dem `<h1>` auf das P9-Muster (Titel eigene Zeile, darunter `mt-4`-Zeile mit gefülltem
  "Zurück"-Button) — Ziel weiterhin `records.index`, wie schon beim bisherigen Ghost-Button.

**Tests**: `composer lint:check` grün, `php artisan view:cache` fehlerfrei, `composer test` — 1394 Tests weiterhin grün.
Live gegen `https://para-swimming.test` verifiziert: `/records/export` zeigt Titel + "Zurück"
untereinander, Link-`href` per `javascript_tool` geprüft → korrekt `/records`. Screenshot lieferte erneut nur das
bekannte statische Platzhalterbild (siehe vorherige Nachträge) — Verifikation daher über `get_page_text` + direkten
`href`-Abgleich statt visuell.

### Neunzehnter Design-Feedback-Nachtrag zu Phase 10 — Rekord-Export-Duplikat konsolidiert

- **Doppelte Rekord-Export-UI entfernt**: `lenex/export.blade.php` hatte einen Tab-Switcher ("Wettkampf"/
  "Rekorde") — die "Rekorde"-Tab war ein vollständiges Duplikat von `records/export.blade.php` (identische Felder:
  Rekord-Kategorie, Verbände, Bahn, Geschlecht) und sendete an dieselbe `records.export.download`-Route. Die
  eigentliche, eigenständige Seite `records/export.blade.php` (Nav: "Rekorde" → "Rekorde exportieren") blieb
  unangetastet die alleinige Quelle für Rekord-Exporte. `lenex/export.blade.php` exportiert jetzt nur noch Wettkämpfe —
  Tab-Switcher, `x-data="{ tab: 'meet' }"` und die komplette Rekorde-Tab-Sektion entfernt, Seite wieder ein einzelnes
  Formular.
- **`LenexExportController::showForm()` aufgeräumt**: `regionalTypes` (nur von der entfernten Rekorde-Tab gebraucht) und
  der jetzt ungenutzte `Club`-Import entfernt.
- Die Nav-Struktur war bereits vorher sauber getrennt (`LENEX` → Import/Export = Wettkampf-Ebene, `Rekorde` → Rekorde
  importieren/exportieren = Rekord-Ebene) — keine Änderung an `layouts/app.blade.php` nötig.

**Tests**: `php -l`/`composer lint:check` grün, `php artisan view:cache` fehlerfrei, `composer test` — 1394 Tests
weiterhin grün (keine bestehenden Tests deckten die entfernte Tab ab). Live geprüft: `/lenex/export` zeigt nur noch das
Wettkampf-Formular ohne Tab-Switcher, `/records/export` weiterhin unverändert eigenständig funktionsfähig.

**Tests**: `composer lint:check` grün, `php -l` fehlerfrei, `php artisan view:cache` fehlerfrei. Live gegen
`https://para-swimming.test` verifiziert (Login als Seed-Admin): `/lenex/export` zeigt "Bitte wählen…" ohne Parameter,
`/lenex/export?meet_id=182` zeigt korrekt "LM Salzburg mit ÖBSV Cup 2026 (2026)" vorbelegt — via
`get_page_text` bestätigt (Browser-Pane-Screenshots lieferten wie im vorigen Nachtrag nur ein Platzhalterbild, diesmal
aber funktionierte `read_page`/`find`/`form_input` nach einem neuen Tab wieder normal).

### Zwanzigster Design-Feedback-Nachtrag zu Phase 10 — Richtzeitenlisten: Breite, Icon-Farben, Header, Spalten-Layout

- **`qualifying-time-lists/index.blade.php` verbreitert**: `max-w-2xl` → `max-w-4xl`, wie von Erik gewünscht ("die selbe
  Breite haben wie die forms in Records", Referenz `records/form.blade.php`).
- **Icon-Farben ergänzt**: Bearbeiten-/Löschen-Icons in der Liste hatten (anders als überall sonst im Projekt, z. B.
  `records/index.blade.php`, `athletes/index.blade.php`) keine Farbklasse — `class="text-amber-500!"`
  (Bearbeiten) bzw. `class="text-red-500!"` (Löschen) ergänzt, Ansicht-Icon bleibt wie überall neutral.
- **Header von `qualifying-time-lists/form.blade.php`** (bedient sowohl "Neue Richtzeitenliste" als auch
  "Richtzeiten … bearbeiten", beides derselbe Blade-Datei) auf das P9-Muster umgestellt: Titel eigene Zeile,
  "Zurück" darunter als eigener gefüllter Button statt inline-Ghost-Icon.
- **`flux:description`-Abstand behoben** (beide Qualifikationszeitraum-Felder): `class="mt-1!"` ergänzt — der in
  `CLAUDE.md` dokumentierte Flux-Fallstrick (`flux:description` bekommt intern eine Vendor-Regel mit `mt-3`
  höherer Spezifität, ein normales `mt-1` wird überstimmt).
- **Vier zusätzliche Cards im Bearbeiten-Modus neu angeordnet**: "Zielpunkte je Sportklasse" und "Automatische
  Berechnung" (beide kurz) stehen jetzt nebeneinander in einem `grid grid-cols-1 md:grid-cols-2 gap-6`
  (`md:` = einspaltig auf schmalen Bildschirmen). "Richtzeiten" (die große, variable Tabelle) und "Qualifikation
  ermitteln" (eigenständiger Folge-Schritt, inhaltlich nicht zu den beiden anderen passend) bleiben bewusst volle Breite
  darunter — Kompromiss zwischen "kürzer" und "nicht künstlich zusammengepresst". Container dafür auf `max-w-4xl`
  verbreitert (vorher `max-w-3xl`), passend zur Records-Formularbreite und damit die zweispaltigen Cards genug Platz
  haben.

**Tests**: `composer lint:check` grün, `php artisan view:cache` fehlerfrei, `composer test` — 1394 Tests weiterhin grün.
Live gegen `https://para-swimming.test` verifiziert: Icon-Klassen (`text-amber-500!`/
`text-red-500!`) per `className`-Check bestätigt (nicht per `getComputedStyle` — das lieferte in dieser Session erneut
für alle Elemente denselben, offensichtlich falschen Farbwert, bekannte Tool-Einschränkung dieser Session, siehe
vorherige Nachträge); `mt-1!` auf beiden `flux:description`-Elementen per `className` bestätigt; Grid mit den zwei
erwarteten Kind-Cards im Bearbeiten-Modus bestätigt.

### Einundzwanzigster Design-Feedback-Nachtrag zu Phase 10 —
`qualifying-time-lists/show.blade.php`: Header, Breite, Inhaltsverzeichnis-Dropdown

- **Breite** `max-w-3xl` → `max-w-4xl` (wie die anderen Formulare/Records-Referenz).
- **Header auf P9-Muster umgestellt**: Titel + Badges in eigener Zeile, darunter `mt-4`-Zeile mit "Zurück" links und den
  übrigen Aktionen rechtsbündig via `ml-auto`-Wrapper (bisher: ein einziger `flex`-Rist mit Ghost-Icon- Zurück-Button,
  Titel, Badges und zwei Ghost-Buttons in einer Zeile, `ms-auto` nur auf dem ersten der beiden).
- **"Richtige Buttons"**: "Qualifizierte Schwimmer anzeigen" und "PDF" von `variant="ghost"` auf
  `variant="filled"` umgestellt — wie bei den Bearbeiten-/Löschen-Buttons in `records/show.blade.php`.
- **Inhaltsverzeichnis als Dropdown in die Button-Leiste verschoben**: die bisherige eigene Card mit Pill-Links
  (Sprung-Anker zu den Sportklassen-Gruppen) ist jetzt ein `flux:dropdown` +
  `flux:menu`/`flux:menu.item href="#group-…"` direkt neben "Zurück" in der Kopfzeile, statt einer zusätzlichen vollen
  Card vor dem eigentlichen Inhalt. Live geprüft: alle Sprungziele (`#group-1` … `#group-sonstige`)
  existieren im DOM, Menüeinträge tragen die korrekten `href`s.
- **Flux-Pro-Check**: kein Pro-Component nötig — `flux:dropdown`/`flux:menu`/`flux:menu.item` sind Free-Tier-Flux und
  bereits an anderer Stelle im Projekt etabliert (Status-Schnelländerung in `records/index.blade.php`). Flux Pros
  `combobox`/`listbox` sind für wertgebundene Formularfelder gedacht (ein Options-Select mit Rückgabewert), nicht für
  eine reine Sprungmarken-Navigation ohne zu sendenden Wert — `flux:menu.item` mit
  `href` ist hier der passendere, einfachere Baustein (rendert intern über `button-or-link-pure`, exakt derselbe
  Mechanismus wie bei `flux:button href="…"`).

**Tests**: `composer lint:check` grün, `php artisan view:cache` fehlerfrei, `composer test` — 1394 Tests weiterhin grün.
Live gegen `https://para-swimming.test` verifiziert: Header-Struktur, Dropdown-Menüeinträge (`data-flux-menu-item`) mit
korrekten `href`s, alle Sprungziel-IDs im DOM vorhanden.

### Zweiundzwanzigster Design-Feedback-Nachtrag zu Phase 10 — Inhaltsverzeichnis klappt Accordion,
`qualifications.blade.php` angeglichen

- **Inhaltsverzeichnis-Button eingefärbt**: `class="text-blue-500!"` auf dem Dropdown-Trigger (war zuvor ungefärbtes
  `filled`, wie "Zurück"/"PDF").
- **Klick im Inhaltsverzeichnis klappt die anderen Abschnitte ein**: Recherche in
  `vendor/livewire/flux-pro/dist/flux.js` ergab, dass Flux' `<ui-disclosure>` (= `flux:accordion.item`) einen
  `Controllable`-Mixin mit einer `.value`-DOM-Property besitzt (derselbe Mechanismus wie bei Slider/Date-Picker)
  — von außen per `element.value = true/false` steuerbar. Genutzt statt Flux' eingebautem `exclusive`-Modus
  (`<flux:accordion exclusive>`, das *jeden* Heading-Klick synchronisiert einklappen lässt) — Erik wollte das Verhalten
  ausdrücklich nur beim Klick **im Inhaltsverzeichnis**, nicht beim direkten Klick auf eine Accordion-Heading.
  Umsetzung: kleines seiten-lokales `x-data` mit `openOnly(id)` (setzt `.value` auf allen
  `[data-flux-accordion-item]` außer der Ziel-ID auf `false`) am äußeren Container, `@click="openOnly('group-…')"`
  zusätzlich zum bestehenden `href="#group-…"` auf jedem `flux:menu.item`. `id` dafür von der äußeren
  `<flux:accordion>` auf das innere `<flux:accordion.item>` verschoben (dort sitzt die tatsächliche
  `<ui-disclosure>`, `.value` muss dort gesetzt werden), `scroll-mt-4` mitgewandert.
- **"Alle aufklappen"**: eigener `flux:menu.item` (mit `flux:menu.separator` davor) im selben Dropdown, ruft
  `openAll()` (setzt `.value = true` auf allen Accordion-Items).
- **`qualifications.blade.php` identisch angeglichen** (Erik: "mit den selben Regeln wie bei show"): Header auf
  P9-Muster (Titel+Badge-Zeile, dann Zurück links/PDF rechts), Inhaltsverzeichnis-Card durch dasselbe Dropdown-Muster
  ersetzt (Farbe, `openOnly`/`openAll`, `id` auf `flux:accordion.item`) — 1:1 dieselbe Umsetzung wie in
  `show.blade.php`, nur ohne die Aktiv/Aktuell-Badges (die gibt es auf dieser Seite nicht) und mit einem einzelnen
  rechten Button (PDF) statt zweien. Container-Breite (`max-w-5xl`, wegen des 5-spaltigen Filter- Formulars) unverändert
  gelassen — nicht Teil der Anfrage.

**Tests**: `composer lint:check` grün, `php artisan view:cache` fehlerfrei, `composer test` — 1394 Tests weiterhin grün.
Live gegen `https://para-swimming.test` verifiziert (beide Seiten, per direkter
`element.value`-Abfrage statt `getComputedStyle`): Klick auf einen Inhaltsverzeichnis-Eintrag klappt alle anderen
Accordion-Items zu (`.value` → `false`) und lässt nur das Ziel offen; "Alle aufklappen" setzt alle wieder auf `true`;
Button-Klasse `text-blue-500!` bestätigt.

### Dreiundzwanzigster Design-Feedback-Nachtrag zu Phase 10 —
`qualifications.blade.php`: Filter ohne Button, Spaltenbreiten, Schwimmer-Zählbug, PDF-Farbe

- **"Schwimmer"-Anzahl im Header war falsch** (Erik: "Ich denke hier werden alle qualifizierten Zeiten
  zusammengezählt" — Verdacht bestätigt): `$qualifications->count()` zählte `Qualification`-Zeilen (eine je Athlet
  **und** Disziplin), nicht eindeutige Athleten. Ein Athlet mit mehreren Quali-Zeiten wurde entsprechend mehrfach
  mitgezählt. Fix: `$qualifications->pluck('athlete_id')->unique()->count()` — sowohl in
  `qualifications.blade.php` als auch im PDF-Pendant `pdf/qualifications.blade.php` (derselbe Bug, dieselbe Zeile,
  gleich mitbehoben statt nur auf der Weboberfläche). Live gegen echte Daten bestätigt: Anzeige sprang von 413 auf 107
  (413 Qualifikations-Zeilen verteilt auf 107 Athleten). Regressionstest ergänzt in
  `tests/Feature/QualificationPhase5And6Test.php` — ein Athlet mit zwei Disziplin-Qualifikationen (FREE + BACK)
  muss "1 Schwimmer" zeigen, nicht "2 Schwimmer".
- **Suchfeld ohne Absende-Button**: lief bisher über `type="submit"` + eigenen Button, jetzt wie die anderen vier Filter
  automatisch über `x-model` + `$watch`. Unterschied zu den `flux:select`-Feldern: das Suchfeld ist ein natives
  `flux:input` (kein Custom Element wie `<ui-select>`), deshalb reicht direktes
  `x-model.debounce.500ms` auf dem Input selbst (500ms Debounce, damit nicht bei jedem Tastendruck sofort abgesendet
  wird — bei den Selects unnötig, da dort ein "change" ohnehin nur bei einer abgeschlossenen Auswahl feuert).
- **Geschlecht/Sportklasse schmaler**: Grid von `md:grid-cols-5` (fünf gleich breite Spalten) auf
  `md:grid-cols-12` mit expliziten `md:col-span-*` je Feld umgestellt — Bewerb 3, Geschlecht 1, Sportklasse 1, Verein 3,
  Suche 4 (Geschlecht/Sportklasse brauchen nur Platz für "M"/"F" bzw. kurze Klassencodes wie "S9"). Mobile
  (`grid-cols-2`, ohne `md:`-Präfix) unverändert.
- **PDF-Button eingefärbt** (`class="text-purple-500!"`) — in `qualifications.blade.php` **und**
  `show.blade.php` (Erik: "auch im show.blade"). Lila gewählt, weil Blau (Inhaltsverzeichnis), Amber (Bearbeiten) und
  Rot (Löschen) auf denselben Seiten bereits andere Aktionen markieren.

**Tests**: `composer lint:check` grün, `php artisan view:cache` fehlerfrei, `composer test` — 1395 Tests grün (1394 + 1
neuer Regressionstest). Live gegen `https://para-swimming.test` verifiziert: kein
`button[type="submit"]` mehr im Filter-Formular, Debounce-Suche löst nach ~500ms tatsächlich eine gefilterte
Server-Anfrage aus (`?search=Marco` in der URL, Trefferzahl reduziert sich korrekt), Spalten-Klassen
(`md:col-span-3/1/1/3/4`) und PDF-Button-Farbe auf beiden Seiten per `className`-Check bestätigt.

### Vierundzwanzigster Design-Feedback-Nachtrag zu Phase 10 —
`qualifications.blade.php`: Bewerb-Sortierung, Filterleiste wie Rekorde

- **"Bewerb"-Dropdown-Reihenfolge** (Erik: "wie wir es schon bei den Rekorden gemacht haben"): bisher nur nach Distanz
  sortiert, jetzt zusätzlich nach Stil in derselben Reihenfolge wie
  `RecordController::index()` (`orderByRaw case stroke_types.lenex_code` — Freistil, Brust, Rücken, Schmetterling,
  Lagen, alles andere danach). Da hier eine PHP-`Collection` statt einer DB-Query sortiert wird (die Optionen kommen aus
  bereits geladenen `Qualification`-Datensätzen, nicht direkt aus einer eigenen Query), keine `orderByRaw` möglich —
  stattdessen ein zusammengesetzter `sprintf()`-Sortierschlüssel (`QualifyingTimeListController::strokeSortKey()` +
  `sprintf('%d-%05d', ...)`), nach demselben, in `CLAUDE.md`
  dokumentierten Muster wie `SportClassSorter::key()`. Live geprüft: Reihenfolge im Dropdown jetzt
  "50m/100m/200m/400m Freistil, 50m/100m Brust, 50m/100m Rücken, 50m/100m Schmetterling, 100m/150m/200m Lagen".
  (Hinweis: Es gibt im Projekt noch eine zweite, abweichende Stil-Reihenfolge in
  `PointConversionService`/`Public\PublicRecordService` — FREE/BACK/BREAST statt FREE/BREAST/BACK. Hier bewusst die von
  Erik referenzierte `RecordController`-Reihenfolge übernommen, nicht die andere.)
- **Filterleiste wie bei den Rekordlisten**: `records/index.blade.php`s Muster übernommen — eine
  `flex flex-wrap items-center gap-3`-Zeile ohne Card-Hintergrund, keine `flux:field`/`flux:label`-Wrapper mehr
  (Platzhaltertext im Select übernimmt die Beschriftung, wie bei den Rekorden), feste `w-*`-Breitenklassen statt
  Grid-Spalten. Geschlecht (`w-28`) und Sportklasse (`w-40`) bewusst schmal, aber breit genug für "Alle"/kurze Codes wie
  "SB14" ohne Abschneiden; Bewerb/Verein/Suche breiter (`w-56`).
- **Container-Breitenbeschränkung entfernt** (`max-w-5xl` → kein Wrapper mehr) — wie `records/index.blade.php`, das
  ebenfalls keine `max-w`-Beschränkung hat. Damit hat die Filterzeile genug Platz für alle fünf Felder in einer Reihe,
  ohne dass Geschlecht/Sportklasse künstlich zusammengequetscht werden müssen.

**Tests**: `php -l`/`composer lint:check` grün, `php artisan view:cache` fehlerfrei, `composer test` — 1395 Tests
weiterhin grün. Live gegen `https://para-swimming.test` verifiziert: Bewerb-Dropdown-Optionen in der erwarteten
Stil-Reihenfolge (per `ui-option`-Textinhalt geprüft), Filter-Formular ohne `bg-white`-Card-Klasse, Breitenklassen aller
Felder per `className`-Check bestätigt.

### Fünfundzwanzigster Design-Feedback-Nachtrag zu Phase 10 — Schwimmer-Badge grün, Suchfeld-Zeilenumbruch behoben

- **"X Schwimmer"-Badge eingefärbt**: `color="zinc"` → `color="emerald"` (Erik-Screenshot zeigte die Badge noch grau).
- **Suchfeld sprang in eine eigene Zeile**: laut Screenshot lag "Name oder Verein" unterhalb der vier Selects statt in
  derselben Zeile. Ursache: `flux:input` setzt selbst `w-full` auf demselben Wrapper-Div, auf das ein von außen
  übergebenes `class="w-56"` ebenfalls zielt — beide Utility-Klassen kollidieren auf derselben
  `width`-Eigenschaft. Erster Versuch mit der sonst funktionierenden Tailwind-v4-Important-Syntax (`w-56!`, wie beim
  `flux:description`-`mt-1!`-Fallstrick in `CLAUDE.md`) **griff hier live geprüft nicht** — anders als beim
  `flux:description`-Fall (dort eine strukturell höher-spezifische Vendor-Selektorregel) stehen sich hier zwei einfache,
  gleich-spezifische Utility-Klassen auf demselben Element gegenüber, und `w-full` blieb wirksam. Fix: `flux:input`
  stattdessen in einen eigenen `<div class="w-56">` gewrappt — dessen `w-full` bezieht sich dann auf die Breite dieses
  Containers statt auf die volle Formularzeile, ohne Kaskaden-Kollision.
- **Hinweis zur Verifikation dieser Session**: Die Geometrie-Werkzeuge (`getBoundingClientRect`,
  `getComputedStyle`, `offsetWidth`) lieferten beim Nachprüfen widersprüchliche/unplausible Werte (z. B. ein frisch an
  `document.body` angehängtes, isoliertes Test-`<div class="w-56">` maß exakt `document.body.
  offsetWidth` statt 224px) — dieselbe, bereits mehrfach in früheren Nachträgen dokumentierte Browser-Pane-Einschränkung
  dieser Session. Die HTML-Struktur (Wrapper-Div ist alleiniges Kind mit Klasse
  `w-56`) wurde stattdessen per `className`/DOM-Struktur bestätigt — das ist Standard-CSS ohne Kaskaden-Fallstrick und
  sollte in einem echten Browser korrekt greifen, konnte in dieser Session aber nicht pixelgenau nachgemessen werden.
  Bitte mit Screenshot rückmelden, falls das Suchfeld trotzdem noch umbricht.

**Tests**: `composer lint:check` grün, `php artisan view:cache` fehlerfrei, `composer test` — 1395 Tests weiterhin grün.

**Rückmeldung von Erik**: Suchfeld sitzt jetzt in einer Zeile — per Screenshot bestätigt, kein weiterer Handlungsbedarf.

### Sechsundzwanzigster Design-Feedback-Nachtrag zu Phase 10 — Fehlermeldung "kein Meet zugeordnet" konkretisiert

**Was gemeldet wurde**: Erik legte eine neue Richtzeitenliste (2026) an und bekam beim Klick auf "Richtzeiten berechnen"
die Meldung "Dieser Richtzeitenliste ist kein Meet zugeordnet (ÖSTM & ÖM-Veranstaltung fehlt)."

**Befund**: Kein Bug — erwartetes, korrektes Verhalten. `QualifyingTimeList` selbst hat kein Formularfeld zur
Meet-Zuordnung; die Verknüpfung läuft andersherum über `Meet.qualifying_time_list_id`
(`meets/form.blade.php`, Feld "Richtzeitenliste (ÖSTM & ÖM)"). Eine frisch angelegte Liste hat also zwangsläufig noch
kein zugeordnetes Meet — das muss separat auf der Wettkampf-Seite gesetzt werden. Das Problem war reine Auffindbarkeit:
die alte Fehlermeldung nannte zwar das Symptom, aber nicht den Lösungsweg.

**Umsetzung**:

- Fehlertext in `QualifyingTimeCalculationService::calculateForList()` erweitert: nennt jetzt explizit den Weg ("im
  gewünschten Wettkampf unter 'Bearbeiten' das Feld 'Richtzeitenliste (ÖSTM & ÖM)' auf {Jahr} setzen").
- `qualifying-time-lists/form.blade.php`: Fehlerbox zeigt bei genau dieser Meldung (Text-Erkennung auf "kein Meet
  zugeordnet") zusätzlich einen Button "Zu den Wettkämpfen" (→ `meets.index`) — ein Klick zum Ziel statt nur eine
  Textzeile.
- Bestehender Test (`QualifyingTimeListPhase2Test.php`, `assertSessionHas('error')`) prüft nur auf Vorhandensein der
  Fehlermeldung, nicht den exakten Text — durch die Wortlaut-Änderung nicht betroffen.

**Tests**: `php -l`/`composer lint:check` grün, `php artisan view:cache` fehlerfrei, `composer test` — 1395 Tests
weiterhin grün. Live gegen die echte, von Erik angelegte Liste (`qualifying-time-lists/3`, Jahr 2026)
reproduziert und verifiziert: neuer Fehlertext und "Zu den Wettkämpfen"-Link (→ `/meets`) erscheinen wie vorgesehen.

### Siebenundzwanzigster Design-Feedback-Nachtrag zu Phase 10 — PhpStorm-Inspections aufgelöst

Erik meldete drei PhpStorm-Befunde (siehe `CLAUDE.md`: "Alle PhpStorm-Inspections auflösen, bevor weitergemacht wird"):

- **`QualifyingTimeCalculationService.php:56` — "orderBy () call can be simplified"**: `->orderBy('start_date')
  ->first()` → `->oldest('start_date')->first()` (Laravel-Idiom, identisches Verhalten).
- **`QualificationPhase5And6Test.php:264` — "Argument matches the parameter's default value"**: In der im vorigen
  Nachtrag ergänzten Testmethode stand `makeStrokeType_qtl7('FREE')` — `'FREE'` ist aber genau der Default-Wert des
  Parameters (`function makeStrokeType_qtl7(string $lenexCode = 'FREE')`), das explizite Argument war redundant.
  Entfernt → `makeStrokeType_qtl7()`. (Das zweite `makeStrokeType_qtl7('BACK')` daneben ist kein Default-Match, bleibt
  unverändert.)
- **`qualifying-time-lists/qualifications.blade.php:82/90/91` — "Method expression is not of Function type" /
  "Unresolved variable $refs" (zweimal)**: Die Filter-Logik stand inline in einem `x-data="{ ... }"`-String mit
  eingebetteten `@js()`-Direktiven — PhpStorms JS-Parser kommt mit dieser Blade/JS-Mischung nicht klar und erkennt
  Alpines magische `$refs`-Eigenschaft dort nicht. Nach dem in `CLAUDE.md` dokumentierten Muster ausgelagert: neue
  `resources/js/qualification-filters.js` (`Alpine.data()`-Komponente, exportiert
  `submitFilter()`/`init()` mit `this.$refs`/`this.$el` wie in `relay-entry-form.js`), registriert in
  `resources/js/app.js`. Blade reduziert auf `x-data="qualificationFilters(@js($filterConfig))"`, die 5 einzelnen
  PHP-Filter-Variablen zu einem `$filterConfig`-Array zusammengefasst (weiterhin eine fertige Variable vor `@js()`, wie
  im `@json()`-Komma-Fallstrick aus `CLAUDE.md` beschrieben).

**Tests**: `php -l`/`composer lint:check` grün (PHP), `node --check` grün (beide JS-Dateien), `composer test` — 1395
Tests weiterhin grün (inkl. der betroffenen Testgruppe `qualifying-time-lists-p5-p6` isoliert erneut geprüft). Der
Dateiinhalt von `qualification-filters.js`/`app.js` wurde direkt per `curl` gegen den laufenden Vite-Dev-Server
abgeglichen — beide werden korrekt mit dem erwarteten Inhalt ausgeliefert.

**Live-Browser-Verifikation nicht möglich, ehrlich dokumentiert**: In der Browser-Pane dieser Session schlägt die Seite
nach der Auslagerung mit `ReferenceError: qualificationFilters is not defined` fehl. Das ist aber nachweislich **kein
Fehler der eigenen Änderung**: dieselbe Pane liefert exakt denselben Fehler für die längst bestehende, unangetastete
`relayEntryForm`-Komponente auf ihrer eigenen, unabhängigen Seite (`meets/{id}/relay-entries/create`) — und sogar
`window.IMask` (eine ganz am Anfang von `app.js` gesetzte, von Alpine unabhängige globale Variable) bleibt `undefined`.
Das beweist, dass `app.js` in dieser Browser-Pane aktuell überhaupt nicht vollständig ausgeführt wird — unabhängig von
dieser Änderung. Geprüft und ausgeschlossen:
neuer Tab, harter Reload (mehrfach), `touch` auf beide JS-Dateien (Vite-Neukompilierung erzwungen) — Fehler bleibt
bestehen. `curl` gegen denselben Vite-Dev-Server liefert für beide Dateien einwandfreien Inhalt. Wirkt wie ein Problem
dieser Browser-Pane-Session selbst (evtl. im Zusammenhang mit den während der ganzen Session wiederkehrenden
`ERR_BLOCKED_BY_CLIENT`-Netzwerkfehlern), nicht der Codeänderung. Bitte in einem echten Browser mit hartem Reload
(Strg+F5) gegenrüfen — falls dort ebenfalls `relayEntryForm`/maskierte Zeitfelder nicht mehr funktionieren, wäre das ein
eigenständiges, von dieser Änderung unabhängiges Problem (z. B. `composer dev`
neu starten); falls dort alles normal funktioniert, war es ausschließlich ein Session-Artefakt dieser Pane.

### Achtundzwanzigster Design-Feedback-Nachtrag zu Phase 10 —
`form.blade.php`: Tabs (Richtzeiten / Verwaltung), Filter, Button-Größen, Sidebar-Link

Erik bestätigte per Screenshot, dass "Qualifikation ermitteln" (Fehlermeldung "kein Meet zugeordnet") wie erwartet
funktioniert, und meldete vier weitere Punkte:

- **Buttons "Speichern" (Zielpunkte) und "OK" (Richtzeiten-Zeile)** waren mit `size="sm"` spürbar kleiner als die
  danebenstehenden `flux:input`-Felder (deren Standardgröße). `size="sm"` entfernt → beide Buttons laufen jetzt in Flux'
  Standardgröße, gleiche Höhe wie die Inputs.
- **Löschen-Icon bei den Richtzeiten-Zeilen** war wie beim vorherigen Rekorde/Richtzeitenlisten-Fix farblos
  (`variant="ghost" icon="trash"` ohne Farbe) → `class="text-red-500!"` ergänzt, gleiches Muster wie in
  `qualifying-time-lists/index.blade.php`.
- **"Qualifikation ermitteln" stand bei vorhandenen Richtzeiten ganz unten** (nach einer langen Richtzeiten- Tabelle) —
  auf Eriks Vorschlag in zwei `flux:tab.group`-Reiter aufgeteilt (Muster aus `meets/form.blade.php`
  übernommen, dort bereits für nicht-triviale mehrteilige Formulare etabliert):
    - Tab **"Richtzeiten"**: die Zeilen-Tabelle samt Add-Formular, jetzt mit einer Filterleiste darüber
      (Sportklasse/Geschlecht/Bewerb/Distanz — wie gewünscht ohne eigenen Filtern-Button).
    - Tab **"Zielpunkte & Qualifikation"**: die beiden nebeneinanderliegenden Zielpunkte-/Automatische-Berechnung- Cards
      plus die "Qualifikation ermitteln"-Card, unverändert im Inhalt.

  Die Richtzeiten-Filterung läuft — anders als bei `qualifications.blade.php` — **rein clientseitig im DOM**, kein
  GET-Reload: Die komplette Zeilenliste steht ohnehin unpaginiert im Markup, ein Server-Roundtrip wäre nur Mehraufwand
  und hätte zusätzlich den aktiven Tab zurückgesetzt. Jede `flux:table.row` trägt
  `data-rzt-gender`/`-sport-class`/`-stroke-id`/`-distance`; die neue, nach dem `Alpine.data()`-Muster aus
  `CLAUDE.md` ausgelagerte `resources/js/qualifying-times-filter.js` blendet bei jeder Filteränderung nicht-passende
  Zeilen aus (`row.style.display`) und zusätzlich leer gewordene Stroke-Gruppen (`data-rzt-group`) bzw.
  Sportklassen-Sektionen (`data-rzt-section`), damit keine leeren Zwischenüberschriften stehen bleiben. In `app.js`
  registriert. Die Filteroptionen selbst sind keine feste Liste, sondern aus der bereits geladenen `$list->times`
  -Relation abgeleitet (nur tatsächlich vorkommende Werte) — die Sportklassen-Sortierung nutzt dafür
  `App\Support\SportClassSorter::key()` (importiert per
  `@php use ...; @endphp` **vor** `@extends`, siehe `CLAUDE.md`-Fallstrick zum Blade-Component-Parser), damit S10 nicht
  vor S2 einsortiert wird.

- **Sidebar-Link zur Qualifikanten-Liste**: In `layouts/app.blade.php` unter "Richtzeiten ÖSTM & ÖM" einen zweiten
  Eintrag "Qualifizierte Schwimmer" ergänzt. Die Route `qualifying-time-lists.qualifications` braucht zwingend eine
  konkrete Liste (`{qualifyingTimeList}`) — es gibt keine listenübergreifende Übersicht —, daher wird dafür die
  aktuellste Liste (höchstes Jahr, analog zu `QualifyingTimeList::isLatest()`) per einer kleinen
  `@php`-Query direkt im Layout aufgelöst und verlinkt; der Eintrag erscheint nur, wenn überhaupt eine Liste existiert.
  `:current` beim ersten Link ("Richtzeitenlisten") dabei von `routeIs('qualifying-time-lists.*')`
  auf die konkreten Routennamen (`index`/`show`/`create`/`edit`) eingeschränkt, damit er nicht mehr auch beim Besuch der
  neuen Qualifikanten-Seite aktiv markiert wird.

**Tests**: `php artisan view:clear && view:cache` grün (keine Blade-Compile-Fehler), `vendor/bin/pint --test`
grün, `node --check` auf der neuen JS-Datei grün, die komplette `qualifying-time-lists-*`-Testgruppe (86 Tests)
sowie `composer test` (volle Suite, weiterhin 1395 Tests) grün — insbesondere `QualifyingTimeGroupingTest`
("Gliederung in der Bearbeiten-Ansicht") deckt ab, dass die Sektionen/Zeilen nach der Restrukturierung in Tabs weiterhin
exakt gleich gerendert werden.

**Live-Browser-Verifikation weiterhin nicht möglich**: Selbst die Login-Seite (keinerlei eigener JS-Code)
liefert in dieser Pane-Session ein leeres `read_page`-Ergebnis — bestätigt erneut, dass es sich um ein Problem der
Tool-Session selbst handelt (siehe vorheriger Nachtrag), nicht um etwas, das mit dieser Änderung zusammenhängt. Bitte
wie gehabt im echten Browser gegenprüfen.

### Neunundzwanzigster Design-Feedback-Nachtrag zu Phase 10 — Sportklassen-Filter gruppiert, dritter Tab "Allgemeine Daten"

Zwei weitere Punkte von Erik:

- **Qualifikations-Filter "Sportklasse" gruppiert nach Nummer**: Bisher stand im Dropdown jede Sportklasse einzeln (z.B.
  "S3", "SB3", "SM3" als drei getrennte Optionen). Erik wollte stattdessen eine gemeinsame Option je Nummer (Beispiel
  "S03/SB03/SM03"), die beim Filtern alle Präfix-Varianten dieser Nummer zusammen anzeigt. Umgesetzt in
  `QualifyingTimeListController::filteredQualifications()`: Die bisherige flache `sportClasses`- Liste wird zusätzlich
  nach `SportClassSorter::number()` gruppiert (zwei neue Methoden dort ergänzt,
  `prefix()`/`number()`, neben dem bestehenden `key()`) — das Label listet dabei nur die für diese Liste tatsächlich
  vorkommenden Präfixe, zweistellig formatiert (`sprintf('%s%02d', ...)`). Der Filter-Query-Parameter
  `sport_class` trägt jetzt die reine Nummer (z.B. `?sport_class=3`) statt eines vollständigen Codes; serverseitig wird
  daraus über `SportClassSorter::number()` die passende `whereIn('sport_class', [...])`-Liste gebaut (portabel, keine
  SQL-Regex nötig — CLAUDE.md-Vorgabe zur DB-Portabilität). Die zurückgegebene `sportClasses`-Variable wurde durch
  `sportClassGroups` ersetzt (Docblock/`compact()` entsprechend angepasst); `qualifications.blade.php`
  (Dropdown, Breite `w-40` → `w-48` wegen der längeren Labels) und `pdf/qualifications.blade.php` (aktiver- Filter-Text
  löst jetzt über `sportClassGroups->firstWhere('number', ...)` das Label auf) beide angepasst. Zwei bestehende Tests
  (`QualificationPhase5And6Test`, `QualificationGroupingTest`) nutzten noch `?sport_class=S9` — auf `?sport_class=9`
  umgestellt; ein neuer Test `'gruppiert beim Sportklassen-Filter S/SB/SM derselben Nummer
  gemeinsam'` deckt explizit ab, dass ein S3- und ein SB3-Athlet bei `?sport_class=3` beide erscheinen, ein S5-Athlet
  nicht, und dass das Label `S03/SB03` im Dropdown auftaucht.

- **`form.blade.php`: dritter Tab "Allgemeine Daten"**: Das Formular für Wettkampfjahr/Aktiv-Schalter/
  Qualifikationszeitraum stand bisher immer oberhalb der Tabs (Zurück-Button darunter, dann sofort die Tabs). Auf Eriks
  Wunsch wandert es beim Bearbeiten in einen eigenen ersten Tab "Allgemeine Daten"; die Feld-Definitionen selbst sind
  dafür in eine neue `qualifying-time-lists/_general-fields.blade.php` ausgelagert (Muster aus
  `meets/_grunddaten-fields.blade.php` übernommen — dort bereits exakt für diesen Zweck etabliert: Felder ohne
  umschließende Card/Form, damit sowohl das Anlegen-Formular als auch der Tab dieselben Felder per `@include`
  einbinden können, ohne sie zu duplizieren). Für eine **neue** Liste (kein `$list`) gibt es weiterhin nur dieses eine
  Formular ohne Tabs — Tabs lohnen sich erst, sobald es überhaupt mehr als einen Bereich gibt (Zielpunkte/
  Richtzeiten/Qualifikation existieren erst nach dem Anlegen).
- **Manuelles Einfügen als eigene Card**: Im Tab "Richtzeiten" stand das Formular zum manuellen Anlegen einer Zeile
  bisher im selben Card-Rahmen wie Filter + Liste darunter. Jetzt eigene Card "Richtzeit manuell hinzufügen" oberhalb,
  die Liste (mit Filter) in einer zweiten, separaten Card darunter.

**Tests**: `view:cache` grün, `vendor/bin/pint --test` grün, die komplette `qualifying-time-lists`/`grouping`- Gruppe
(87 Tests, inkl. dem neuen Gruppierungstest) sowie `composer test` (volle Suite, jetzt 1396 Tests) grün.

### Dreißigster Design-Feedback-Nachtrag zu Phase 10 — Sportklassen-Dropdown korrigiert, Jahr-Feld schmaler, Aktiv-Switch links

Erik korrigierte den Sportklassen-Filter aus dem vorigen Nachtrag ("war mein Fehler") und meldete zwei kleine
Layout-Punkte:

- **Sportklassen-Dropdown zeigt jetzt die vollständige, feste Liste** statt nur der für diese Liste tatsächlich
  vorkommenden Nummern — und immer alle drei Präfixe (S/SB/SM), auch wenn z.B. keine SM-Ergebnisse existieren. Getrennt
  mit Komma statt Schrägstrich. Das ist exakt das Muster, das für den Rekorde-Filter schon existierte
  (`RecordController::buildSportClassOptions()`) — die eigene Gruppierungslogik aus dem letzten Nachtrag
  (`SportClassSorter::prefix()`/`number()`, `sportClassGroups`) komplett verworfen und durch eine neue, wortgleich
  abgeschriebene `QualifyingTimeListController::buildSportClassOptions()` ersetzt: Nummern kommen aus
  `BaseTimeSportClass::query()->pluck('code')` (die vollständige Basiswerte-Klassenliste, nicht aus den Qualifikationen
  dieser Liste), Optionswert `"S{n},SB{n},SM{n}"` (ungepolstert — direkt passend zu den gespeicherten `sport_class`
  -Werten, per `explode(',', ...)` für `whereIn()` nutzbar), Label zweistellig gepolstert mit Komma. Die beiden jetzt
  überflüssigen `SportClassSorter`-Methoden wieder entfernt (nichts referenzierte sie mehr). PDF-Filtertext löst das
  Label jetzt über `$sportClassOptions[$wert]` auf. Drei Tests auf das neue kommagetrennte Werteformat umgestellt
  (`?sport_class=S9,SB9,SM9` statt `?sport_class=9`); der Gruppierungstest bekam zusätzlich zwei `BaseTimeSportClass`
  -Zeilen (Nummer 3 und 5, aber bewusst keine eigene SM-Zeile) und prüft jetzt explizit, dass das Label trotzdem
  `"S03,SB03,SM03"` zeigt.
- **`_general-fields.blade.php`**: Jahr-Feld war ohne Breitenbegrenzung volle Formularbreite — `class="w-32"`
  ergänzt. Aktiv-Switch stand mit Label links/Switch rechts (Flux-Standard `align="right"`) — `align="left"`
  ergänzt, dreht die Reihenfolge um (Flux rendert dafür intern `flux:with-inline-field` statt
  `flux:with-reversed-inline-field` — Switch zuerst, Label danach).

**Tests**: `view:cache` grün, `vendor/bin/pint --test` grün, die komplette `qualifying-time-lists`/`grouping`- Gruppe
(87 Tests) sowie `composer test` (volle Suite, weiterhin 1396 Tests) grün.

### Einunddreißigster Design-Feedback-Nachtrag zu Phase 10 — Sidebar-Label gekürzt, "Filter zurücksetzen" farbig in die Filterzeile, PhpStorm-Inspections

Drei weitere Punkte:

- **Sidebar-Hovermarkierung "zu schmal" bei "Ausgeschlossene Bewerbe"**: Erik schickte einen Screenshot, auf dem
  die Hover-/Aktiv-Markierung im (eingerückten) Untermenü-Eintrag schmaler wirkt als der Text. Direkte
  DOM-Prüfung (Klassenliste des Elements per `outerHTML`, nicht über die in dieser Session wiederholt
  unzuverlässigen Geometrie-APIs) zeigte: Die Tailwind-Klassen sind mit dem direkt daneben liegenden
  "Qualifikanten"-Eintrag (damals noch "Qualifizierte Schwimmer", ebenfalls 23 Zeichen) identisch — kein
  strukturelles CSS-Problem in unserem Code. Die naheliegendste Erklärung: Die feste Sidebar-Breite (`w-64` in
  `layouts/app.blade.php`) plus `whitespace-nowrap` (Flux' `navlist/item.blade.php`, Vendor-Code) lässt den
  längsten Eintrag im eingerückten Untermenü über die verfügbare Breite hinauslaufen — der Text bleibt sichtbar,
  die farbige Markierung (an die tatsächliche Zeilenbreite gebunden) endet aber vorher und wirkt dadurch zu
  schmal. Da der Vendor-Code nicht angepasst werden soll, stattdessen beide langen Labels gekürzt: "Ausgeschlossene
  Bewerbe" → **"Ausschlüsse"**, "Qualifizierte Schwimmer" → **"Qualifikanten"** (deckt sich zusätzlich mit Eriks
  eigener Wortwahl aus dem Auftrag, der diesen Link ursprünglich angefragt hat). Live im Browser nachgeprüft
  (`get_page_text` nach `view:clear`/`view:cache`) — beide neuen Labels erscheinen korrekt in der Sidebar.
- **"Filter zurücksetzen"** auf `qualifications.blade.php` stand bisher als eigener `ghost`-Button in einer
  eigenen Zeile unterhalb der Filterleiste. Jetzt `variant="filled" class="text-red-500!"` (passend zur
  Rot-Farbgebung für "entfernen/zurücksetzen"-Aktionen an anderer Stelle in dieser Phase, z.B. die
  Löschen-Icons) und in dieselbe `<form>`-Zeile wie die Filterfelder verschoben (letztes Element, erscheint nur
  bei aktivem Filter).
- **PhpStorm-Inspections** aus einem weiteren Bericht:
  - `QualificationGroupingTest.php:248` "Method call is provided 6 parameters, but the method signature uses 3
    parameters" — `makeQualifyingTime_qtl9($list, $free, 100, 'M', 'S9', 6000)` rief die lokale 3-Parameter-
    Helper-Funktion `makeQualifyingTime_qtl9(QualifyingTimeList $list, StrokeType $stroke, string $sportClass)`
    mit 6 Argumenten auf — offenbar aus der 6-Parameter-Variante `makeQualifyingTime_qtl7()` (anderes Testfile)
    kopiert. Da PHP überzählige Argumente bei benannten Funktionen stillschweigend ignoriert, band der Aufruf
    `$sportClass` an den 3. Parameter (`100`, weak-typed zu `"100"` gecastet) statt an das eigentlich gemeinte
    `'S9'` am 5. Platz — ein echter (wenn auch für diesen konkreten Test folgenloser, da `Qualification::create()`
    direkt danach `sport_class => 'S9'` explizit setzt) Bug, kein reiner Stil-Hinweis. Korrigiert zu
    `makeQualifyingTime_qtl9($list, $free, 'S9')`.
  - `QualificationPhase5And6Test.php:250` "Argument matches the parameter's default value" — `makeStrokeType_qtl7('FREE')`
    im neuen Gruppierungstest aus dem vorletzten Nachtrag; `'FREE'` ist der Funktions-Default, Argument entfernt.

**Tests**: `view:clear`/`view:cache` grün, `vendor/bin/pint --test` grün, die komplette `qualifying-time-lists`/
`grouping`-Gruppe (87 Tests) sowie `composer test` (volle Suite, weiterhin 1396 Tests) grün.
