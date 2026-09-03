# Offene Punkte

Bewusst zurückgestellte Entscheidungen und TODOs, die nicht in einer einzelnen Phase untergehen sollen. Jeder Eintrag:
was fehlt, warum es zurückgestellt wurde, was zum Schließen gebraucht wird. Erledigte Punkte werden aus dieser Datei
entfernt (Historie steht im jeweiligen Phasen-Abschnitt von `specs/public-frontend-modules.md`), nicht nur abgehakt
liegen gelassen.

## Import-Vorschau: Vereinsname bei unbekannten Athleten live aktualisieren

**Seit:** Admin-UI-Rework Phase 10, Rückfrage nach dem Vereins-/Athleten-Zuordnungs-Feature (30.08.2026).

**Was fehlt:** Wird bei "Unbekannte Vereine" ein Verein einem bestehenden zugeordnet (z. B. "Flying Flippers
Schwimmteam" → bestehender Verein "Flying Flippers"), bleibt der bei "Unbekannte Athleten" angezeigte Vereinsname (z. B.
bei "Zimmermann, Elfriede") weiterhin der alte, unveränderte Text aus dem LENEX-File ("Flying Flippers Schwimmteam").
Das sieht so aus, als würde die Zuordnung nicht greifen — **greift aber tatsächlich korrekt**: geprüft mit einem echten,
in einer Transaktion zurückgerollten Importlauf gegen das beigefügte `oebsv.lxf` (Verein `FFST` → bestehende ID
zugeordnet, `Zimmermann, Elfriede` neu angelegt) — die neu angelegte Athletin bekam korrekt `club_id` des zugeordneten
bestehenden Vereins, nicht den unbekannten. Der Vereinsname-Text bei "Unbekannte Athleten" ist rein optisch veraltet,
keine Datenkorrektheits-Lücke.

**Warum zurückgestellt:** Für eine Live-Aktualisierung müsste der Vereins-Auswahlzustand aus dem Abschnitt
"Unbekannte Vereine" mit der Textanzeige im (weiter unten liegenden, separaten) Abschnitt "Unbekannte Athleten"
verknüpft werden — eine seitenweite Alpine-`x-data` mit einer geteilten `club_key → gewählter Wert`-Map, aus der sich
der angezeigte Name bei jedem unbekannten Athleten reaktiv ableitet. Umfang ist größer als eine Ein-Zeilen-Korrektur;
keine reine Bugfix-Zeile.

**Wer entscheidet:** Keine offene Design-Frage — reine Umsetzungsarbeit, kein Entscheidungsbedarf.

**Zum Schließen nötig:** Seitenweite `x-data` einführen (oder bestehende erweitern), `club_key`-Auswahl der
Vereins-Selects per `x-model` in eine gemeinsame Map schreiben, Textanzeige bei "Unbekannte Athleten" durch einen
reaktiven Ausdruck ersetzen, der bei `'new'`/`'skip'` den LENEX-Namen zeigt und bei einer zugeordneten bestehenden ID
den Namen/Kurznamen dieses Vereins nachschlägt (Vereinsliste ist als `$clubs` bereits im DOM verfügbar).

## Titelleisten-Muster (Titel oben, "Zurück" links / Aktionen rechts in eigener Zeile) auf alle
`show.blade.php` übertragen

**Seit:** Admin-UI-Rework Phase 10, Design-Feedback nach `records/show.blade.php`-Umbau (30.08.2026).

**Was fehlt:** Das in Phase 9 etablierte Titelleisten-Muster (Titel/Badges in einer Zeile, darunter eine
`mt-4`-Zeile mit "Zurück" links und den übrigen Aktions-Buttons rechtsbündig via `ml-auto`) wurde ausdrücklich gelobt (
"Das mit dem Button in rekorde.show gefällt mir sehr gut") und soll konsequent auf **alle** `show`-Seiten angewendet
werden. Aktuell nur in `records/show.blade.php` und den Formularen (`records/form.blade.php` u. a.)
umgesetzt. Noch zu migrieren:

- `resources/views/athletes/show.blade.php`
- `resources/views/championships/show.blade.php`
- `resources/views/classifiers/show.blade.php`
- `resources/views/clubs/show.blade.php`
- `resources/views/meets/show.blade.php`
- `resources/views/qualifying-time-lists/show.blade.php`
- `resources/views/results/show.blade.php`
- `resources/views/wps/athletes/show.blade.php`
- `resources/views/wps/versions/show.blade.php`

(`resources/views/public/meets/show.blade.php` nicht enthalten — öffentlicher Bereich nutzt Tailkit statt Flux, siehe
`docs/specs/public-frontend.md` §3.1, kein Admin-Muster übertragbar.)

**Warum zurückgestellt:** Reine Layout-Fleißarbeit über neun Dateien mit unterschiedlichen bestehenden
Kopfzeilen/Aktions-Buttons — pro Datei muss geprüft werden, welche Aktionen aktuell im Header stehen und wie sie sich
auf "Zurück links / Rest rechts" abbilden, kein Copy-Paste-Batch ohne Sichtprüfung jeder einzelnen Seite.

**Wer entscheidet:** Keine offene Design-Frage — Muster ist bestätigt, es fehlt nur die Umsetzung. Reihenfolge der
Dateien nach Priorität mit Erik abstimmen, falls nicht alle auf einmal gewünscht sind.

**Zum Schließen nötig:** Jede der neun Dateien einzeln auf das Muster aus `records/show.blade.php` umstellen, live
verifizieren, danach aus dieser Liste streichen.

## Pflichtfeld-Sternchen (`*`) rot einfärben — auf weitere Formulare übertragen

**Seit:** Admin-UI-Rework Phase 10, Design-Feedback nach dem Basiswerte-Umbau (03.09.2026).

**Was fehlt:** Bei Pflichtfeldern steht bisher oft ein reines `*` hinter dem Label. Das bereits an mehreren Stellen
etablierte Muster (`<flux:label>Feld <span class="text-red-500 dark:text-red-400">*</span></flux:label>`, siehe
`swim-events/form.blade.php`, `results/form.blade.php`, `athletes/form.blade.php` u. a.) soll konsequent überall
gelten. In den Basiswerte-Formularen (`base-times/versions/form.blade.php`, `base-times/import.blade.php`) bereits
umgesetzt. Noch mit unfarbigem `*` (Stand 03.09.2026):

- `resources/views/admin/documents/form.blade.php`
- `resources/views/admin/users/index.blade.php`
- `resources/views/age-groups/form.blade.php`
- `resources/views/cups/form.blade.php`
- `resources/views/kader-types/form.blade.php`
- `resources/views/lenex/export.blade.php`
- `resources/views/qualifying-time-lists/_general-fields.blade.php`
- `resources/views/records/form.blade.php`
- `resources/views/sport-class-groups/form.blade.php`
- `resources/views/wps/import/form.blade.php`

**Warum zurückgestellt:** Wie beim Titelleisten-Muster oben reine Fleißarbeit über zehn Dateien — pro Datei prüfen,
welche Labels tatsächlich Pflichtfelder markieren (nicht jedes `*` im Text ist zwangsläufig ein Pflichtfeld-Marker),
kein blindes Suchen-und-Ersetzen.

**Wer entscheidet:** Keine offene Design-Frage — Muster ist bereits an über zehn Stellen im Code etabliert und wird
hier nur konsequent zu Ende geführt.

**Zum Schließen nötig:** Jede der zehn Dateien einzeln auf `<span class="text-red-500 dark:text-red-400">*</span>`
umstellen, danach aus dieser Liste streichen.

## Gesamte, editierbare Meldeliste einer Veranstaltung (Admin)

**Seit:** Design-Feedback nach Admin-UI-Rework Phase 9, zweite Session-Fortsetzung (30.08.2026).

**Was fehlt:** Der Admin hat aktuell keine einzige Ansicht, die alle Meldungen einer Veranstaltung — Einzel- UND
Staffelmeldungen, über alle Vereine hinweg — auf einen Blick zeigt und bearbeitbar macht. Stattdessen gibt es zwei
getrennte, unvollständige Wege:

1. `entries/index.blade.php` (globale Meldungsliste, `EntryController`) — nach `meet_id` filterbar, mit
   Bearbeiten/Löschen, aber **nur Einzelmeldungen** (`Entry`-Modell); Staffelmeldungen (`RelayEntry`) tauchen dort gar
   nicht auf.
2. Der "Meldungen"-Button auf `meets/show.blade.php` führt zu `club-entries.index` — das verlangt von einem Admin erst
   die Auswahl **eines** Vereins (`club-entries/choose-club.blade.php`) und zeigt danach auch nur dessen
   Einzelmeldungen; Staffelmeldungen liegen nochmal getrennt unter `club-entries.relay.index`, ebenfalls je Verein
   einzeln.

Um sich einen Überblick über eine ganze Veranstaltung zu verschaffen, müsste ein Admin aktuell jeden Verein einzeln
anklicken (bei größeren Meisterschaften z. B. 50+ Vereine).

**Warum zurückgestellt:** Keine Bugfix-Zeile, sondern eine neue View/Route mit mehreren offenen Design-Fragen:
Einzel- und Staffelmeldungen in einer Tabelle oder zwei Abschnitten? Gruppierung nach Disziplin, nach Verein, oder
beides wählbar? Inline-bearbeitbar oder Klick auf Zeile → bestehendes Formular (`entries.edit`/
`club-entries.relay.edit`)? Bei ggf. hunderten Meldungen (siehe z. B. "72. Österr. Staats- & Österr. Meisteschaften")
Paginierung nötig, vermutlich pro Disziplin statt pro feste Seitengröße. Berechtigung: nur Admins, oder auch
Vereinsvertreter (dann aber nur auf den eigenen Verein eingeschränkt — überschneidet sich mit dem bestehenden
`club-entries`-Zugriff und dessen Meldeschluss-Sperre)?

**Wer entscheidet:** Erik — Layout (eine Tabelle vs. Sektionen), Gruppierung/Sortierung, Inline-Edit vs. Formular-Link,
ob Staffeln von Anfang an mit reinsollen oder eine eigene Folge-Iteration werden.

**Zum Schließen nötig:** Entscheidung zu obigen Punkten, dann neue Route + Controller-Methode (liest `Entry` und
`RelayEntry` meet-weit statt club-gescoped), neue View, Verlinkung von `meets/show.blade.php` aus (ersetzt oder ergänzt
den bestehenden "Meldungen"-Button).

## Jahresbestzeiten fehlen bei der admin-seitigen Meldungserfassung

**Seit:** Design-Feedback nach Admin-UI-Rework Phase 9 (29.08.2026).

**Was fehlt:** `resources/views/club-entries/create.blade.php` (Vereinsmeldungen) zeigt nach Auswahl von Athlet und
Disziplin ein Live-Panel "Jahresbestzeit (Vorjahr bis Meetbeginn)" mit LCM-/SCM-Zeit und einem
"Bestzeit übernehmen"-Button (Alpine-Komponente `singleEntryForm`, gespeist über
`club-entries.eligible-athletes` / `club-entries.best-times`). Die admin-seitige Meldungserfassung
(`resources/views/entries/form.blade.php`, `EntryController`) hat dieses Feature nicht — Athlet/Disziplin werden dort
über einfache `flux:select`-Dropdowns statt der Such-Alpine-Komponente gewählt, es gibt keinen Best-Times-Abruf.

**Warum zurückgestellt:** Kein einzeiliger Fix — würde bedeuten, entweder die komplette Alpine-Suchkomponente aus
club-entries in die admin-Meldungserfassung zu portieren (inkl. eigenem Best-Times-Endpoint-Aufruf für die
admin-Variante, da `club-entries.best-times` an eine Club-Auswahl gebunden ist), oder ein eigenständiges, schlankeres
Äquivalent zu bauen. Beides ist eine Design-Entscheidung, keine Bugfix-Zeile.

**Zum Schließen nötig:** Entscheidung, ob die admin-Meldungserfassung dieselbe Such-UI wie club-entries bekommen soll
(Konsistenz) oder eine eigene, einfachere Variante nur für den Best-Times-Hinweis; danach Umsetzung in
`entries/form.blade.php` + ggf. neuer Controller-Endpoint (analog `ClubEntryController::bestTimes()`).

## Barrierefreiheitserklärung — Konformitätsstand & Schlichtungsverfahren

**Seit:** Phase 9 (`/de/barrierefreiheit`, `docs/accessibility.md` §Erklärung zur Barrierefreiheit).

**Was fehlt:** Die veröffentlichte Erklärung nennt bislang nur die Kontaktmöglichkeit (`schwimmen@obsv.at`) für
Rückmeldungen. Zwei Abschnitte fehlen bewusst:

1. **Konformitätsstand** (z. B. "vollständig konform" / "teilweise konform" mit WCAG 2.1 AA, inkl. bekannter
   Einschränkungen) — dafür bräuchte es zuerst eine echte Prüfung (siehe `docs/accessibility.md` §Prüfung: axe DevTools,
   Tastaturdurchlauf, Kontrastprüfung, Screenreader-Durchsicht), keine Selbstauskunft ohne Grundlage.
2. **Schlichtungsverfahren** — das österreichische Web-Zugänglichkeits-Gesetz (WZG) richtet sich primär an öffentliche
   Stellen; ob und in welcher Form der ÖBSV als privater Verband freiwillig eine Schlichtungsstelle nennen möchte, ist
   eine Entscheidung des Verbands, keine technische.

**Wer entscheidet:** ÖBSV (Erik/Vorstand) — Inhalt, nicht Umsetzung.

**Zum Schließen nötig:** Entscheidung + Text zu beiden Punkten, dann Ergänzung in
`resources/views/public/accessibility-statement/index.blade.php` (Dateiname zum Zeitpunkt der Eintragung — bei
Umbenennung hier nachziehen) und den zugehörigen `lang/{de,en}/public.php`-Keys.

## Impressum & Datenschutzerklärung — echter Inhalt statt Platzhalter

**Seit:** Phase 9 Nachtrag (`/de/impressum`, `/de/datenschutz`).

**Was fehlt:** Beide Seiten sind aktuell reine Entwürfe mit sichtbar markierten Platzhaltern (`x-draft-notice`,
`x-placeholder-field`) statt echtem Inhalt — via `noindex` + `robots.txt`
gesperrt und **nicht** in `sitemap.xml`, bis das erledigt ist:

1. **Impressum** (`resources/views/public/imprint/index.blade.php`): vollständiger Vereinsname, Anschrift, ZVR-Zahl,
   vertretungsbefugte Person (en), Vereinszweck.
2. **Datenschutzerklärung** (`resources/views/public/privacy-policy/index.blade.php`):
   Rechtsgrundlage für die öffentliche Veröffentlichung von Athletennamen/Ergebnissen/ Vereinszugehörigkeit (Vorschlag
   im Platzhalter: berechtigtes Interesse nach Art. 6 Abs. 1 lit. f DSGVO oder Vereinsstatuten — zu bestätigen),
   Hosting-Anbieter (Name + Anschrift). Kontrolle empfohlen, bevor es live geht: Adresse/E-Mail der österreichischen
   Datenschutzbehörde im Beschwerderecht-Abschnitt sind als aktuell bekannt eingetragen (Stand meines Wissens), aber
   nicht anhand einer Live-Quelle zum Zeitpunkt der Erstellung gegengeprüft.

**Wer entscheidet:** ÖBSV (Erik/Vorstand) — Inhalt, nicht Umsetzung. Bei Bedarf mit rechtskundiger Person/Anwalt
gegenprüfen, insbesondere die Rechtsgrundlage für die Athletendaten-Veröffentlichung.

**Zum Schließen nötig:** Echten Text in beide Views einsetzen, `x-draft-notice`-Aufrufe entfernen,
`@section('robots', 'noindex, nofollow')` entfernen, die beiden Routen aus den
`Disallow`-Zeilen in `app/Http/Controllers/Public/RobotsController.php` streichen und in
`app/Http/Controllers/Public/SitemapController.php::STATIC_ROUTES` aufnehmen.

## Status-Spalte in `meets/index` — I/E/R-Schema statt LENEX-Status

**Seit:** Admin-UI-Rework Phase 9, Design-Feedback-Runde nach `npm run dev`-Test.

**Was fehlt:** Die Spalte "Status" in der Wettkampfliste zeigt aktuell `$meet->lenex_status`
(OFFICIAL/RUNNING/SEEDED, ein LENEX-Importfeld). Gewünscht ist stattdessen ein Schema, das auf einen Blick zeigt, was zu
einem Wettkampf schon existiert: **I** = Disziplinen mit Wertungsgruppen angelegt, **E** = Meldungen liegen vor, **R** =
Ergebnisse liegen vor. Braucht eine eigene Abfrage pro Zeile (vermutlich `withCount`/`withExists` auf `swimEvents`/
`entries`/
`results`, plus Klärung ob "Disziplinen mit Wertungsgruppen" `sport_classes IS NOT NULL` meint oder etwas anderes) und
wahrscheinlich Tooltip-Text pro Buchstabe.

**Wer entscheidet:** Erik — ob `lenex_status` daneben erhalten bleibt oder ersetzt wird, und die genaue Definition von
"I" (welche Wertungsgruppen-Zuordnung genau gemeint ist).

**Zum Schließen nötig:** Definition der drei Zustände abstimmen, `MeetController::index()` um die nötigen
Zähl-/Exists-Abfragen erweitern, `meets/index.blade.php`-Statusspalte umbauen.

## Tooltip/Popover statt Info-Text bei Disziplin-Formular-Hinweisen

**Seit:** Admin-UI-Rework Phase 9, Design-Feedback-Runde nach `npm run dev`-Test.

**Was fehlt:** In `swim-events/form.blade.php` stehen bei "Schwimmer/Staffel" ("1 = Einzel") und
"Sport-Klassen" ("Leerzeichen-getrennt") aktuell permanent sichtbare `flux:description`-Zeilen. Gewünscht: Anzeige als
Tooltip/Popover statt dauerhaft sichtbarem Text. Noch keine entschiedene Lösung — Nutzer ist offen für Vorschläge
(`flux:tooltip`? Info-Icon mit `flux:popover`?).

**Wer entscheidet:** Erik — welche Variante (Tooltip vs. Popover vs. Icon-Trigger).

**Zum Schließen nötig:** Kurze Abstimmung über die Zielkomponente, dann Umbau der beiden Felder (und ggf. gleichartiger
`flux:description`-Hinweise an anderen Stellen, falls das Muster gefallen soll).

## Meldezeit bei Staffelmeldungen aus den gemeldeten Athleten herleiten

**Seit:** Admin-UI-Rework Phase 9, Design-Feedback-Runde nach `npm run dev`-Test.

**Was fehlt:** Bei `club-entries/create-relay.blade.php`/`edit-relay.blade.php` soll die Meldezeit sich (wenn möglich)
automatisch aus den Bestzeiten der ausgewählten Staffel-Schwimmer als Vorschlag/Default ableiten lassen — analog zur
bereits bestehenden Bestzeit-Übernahme bei Einzelmeldungen (`ClubEntryService::bestTimes()`). Für Staffeln braucht das
eine eigene Regel (Summe der Einzel-Bestzeiten über die passende Teilstrecke/Bahnlänge? Nur wenn alle vier Plätze belegt
sind? Rundungs-/Sicherheitsaufschlag?) — nicht ohne Rücksprache zu implementieren.

**Wer entscheidet:** Erik — die genaue Herleitungsregel (Summenbildung, Umgang mit fehlenden Einzel-Bestzeiten einzelner
Mitglieder, Kurzbahn/Langbahn-Umrechnung wie bei Einzelmeldungen).

**Zum Schließen nötig:** Regel abstimmen, dann in `ClubEntryService` eine
`relayBestTime()`-ähnliche Methode ergänzen, per AJAX-Endpunkt (analog `best-times`) an
`relay-entry-form.js` liefern, dort als Vorschlag mit "Bestzeit übernehmen"-Button anzeigen (gleiches UI-Muster wie bei
Einzelmeldungen).

## Absolute Bestzeit bei Einzelmeldungen + Übernahme per Doppelklick

**Seit:** Admin-UI-Rework Phase 9, Design-Feedback nach Live-Test der Athleten-Auswahl.

**Was fehlt:** In `club-entries/create.blade.php` wird bei Athlet+Event-Auswahl aktuell nur die *Jahresbestzeit*
angezeigt (`ClubEntryService::bestTimes()` — Zeitraum Vorjahr bis Meetbeginn). Gewünscht: zusätzlich die *absolute
Bestzeit* (ohne Datumsfilter) anzeigen. Die Backend-Methode dafür existiert bereits (`ClubEntryService::absoluteBestTime(Athlete $athlete, SwimEvent $event,
string $course): ?int`), wird aber aktuell nirgends aufgerufen/ausgeliefert. Zusätzlich:
Doppelklick auf eine der beiden angezeigten Zeiten soll sie automatisch als Meldezeit übernehmen (bisheriges "Bestzeit
übernehmen"-Button-Muster bleibt vermutlich zusätzlich bestehen, oder wird dadurch ersetzt — zu klären).

**Wer entscheidet:** Erik — ob beide Zeiten permanent nebeneinander stehen oder z. B. als Tabs/ Toggle, und ob der
bestehende "Bestzeit übernehmen"-Button neben dem neuen Doppelklick-Verhalten bestehen bleibt.

**Zum Schließen nötig:** `ClubEntryController::bestTimes()` (AJAX-Endpunkt) um die absolute Bestzeit ergänzen (LCM +
SCM, wie schon bei der Jahresbestzeit), `single-entry-form.js` um das zusätzliche Datenfeld und einen `@dblclick`
-Handler auf die Zeit-Anzeige erweitern, der
`entryTime`/`entryCourse` setzt (gleiche Methode wie das bestehende `applyBestTime()`),
`create.blade.php`-Anzeige um die zweite Zeile ergänzen.

## Post-Import Review-Liste: Club-Konflikte + Jahres-Fallback-Matches (LENEX-Rekordimport)

**Seit:** Admin-UI-Rework Phase 10, Rückfragen zu Saram Stephan / Hochenberger Philip / Rottmann Kilian in
`oebsv.lxf` (31.08.2026). Ursprünglich zwei getrennte Punkte, auf Wunsch von Erik zusammengelegt ("so dass wir das in
einem machen können") — beide brauchen dieselbe Grundlage: eine persistierte, abarbeitbare Review-Liste nach dem
Rekord-Import.

**Teil A — Club-Konflikte:** Bei einem Rekord-Import gilt laut Erik der im LENEX-File genannte Verein als bindend,
sofern der Verein selbst bereits in der DB existiert ("Initial-Rekordfile"). Aktuell gibt es aber keine Stelle, die nach
dem Import anzeigt, bei welchen Athleten der LENEX-Verein vom aktuell gespeicherten
`Athlete.club_id` abweicht — weder für bereits bekannte Athleten (sofort gematcht in `preview()`/`findAthlete()`, siehe
`RecordImportService.php:342`) noch für zuvor unbekannte Athleten, die im Import-Vorschau-Schritt einem bestehenden
Athleten zugeordnet wurden (`resolveAthletes()`,
[RecordImportService.php:774](app/Services/RecordImportService.php:774) — dort wird `Athlete.club_id` bewusst nicht
angefasst, siehe auch "Import-Vorschau: Vereinsname bei unbekannten Athleten live aktualisieren" unten). Diskrepanzen
wie "Zimmermann, Elfriede steht noch mit dem alten Vereinsnamen in der Liste" fallen nur zufällig beim manuellen
Durchsehen auf.

Vereinswechsel selbst sind dabei kein Fehler — jeder `SwimRecord` trägt bereits seinen eigenen `club_id`
unabhängig vom Athleten (`SwimRecord::club_id`), Rekorde bei unterschiedlichen Vereinen für denselben Athleten (z. B.
Saram Stephan) sind also schon heute korrekt historisch abgebildet. Es geht ausschließlich um den
*aktuellen/Stamm-Verein* am `Athlete`-Datensatz selbst. Eriks Vorschlag: eine Checkbox pro betroffenem Athleten im
Import-Vorschau-Schritt ("Aktuellen Verein des Athleten auf den LENEX-Verein aktualisieren"), plus eine **persistierte**
Liste aller solchen Fälle nach dem Import (nicht nur eine Flash-Message für die aktuelle Session).

**Teil B — Jahres-Fallback-Matches:** Kein Bug in `findAthlete()` — die Namens-Suche ist bereits case-insensitiv
([RecordImportService.php:645-646](app/Services/RecordImportService.php:645)). Geprüft direkt am beigefügten
`oebsv.lxf`: Hochenberger Philip und Rottmann Kilian stehen dort mit `birthdate="1992-01-01"` bzw.
`"2008-01-01"` und leerem `license` — das ÖBSV-File kennt hier offenbar nur das Geburtsjahr und kodiert Tag/Monat als
Platzhalter `01-01`. In der DB stehen die echten Geburtsdaten (`1992-12-10` bzw. `2008-02-04`). Da `findAthlete()` nach
Nachname **+** Vorname **+** exaktem `birth_date` **+** Geschlecht sucht, schlägt der Match trotz korrektem Namen fehl →
beide werden als "unbekannt" eingestuft. **Erik hat der vorgeschlagenen automatischen Jahres-Fallback-Regel zugestimmt**
("Bei deinem Vorschlag beim Import bin ich bei dir."): Bei
`birth_date` mit `-01-01`-Endung zusätzlich per portablem `SUBSTR(birth_date, 1, 4) = ?`
(kein `YEAR()`, läuft auf MySQL wie SQLite) auf Namensgleichheit + Geburtsjahr matchen — aber als *Vorschlag*
in der bestehenden Zuordnungs-Auswahl, nie automatisch ohne Bestätigung übernommen (Risiko: zwei verschiedene Personen
mit gleichem Namen und Geburtsjahr).

**Warum zurückgestellt:** Mehrere offene Entscheidungen, keine Bugfix-Zeile:

1. Datenmodell für die Review-Liste — eigene Tabelle (z. B. `import_review_items` mit `athlete_id`, `type`
   Club-Konflikt/Jahres-Match, `current_club_id`/`lenex_club_id` bzw. `matched_athlete_id`,
   `import_batch_id`/Datum, `status` offen/übernommen/ignoriert) vs. zwei getrennte, schlankere Tabellen.
2. Zeitpunkt der Konflikterkennung: nur beim Import-Preview-Schritt (Checkbox-Entscheidung dort direkt in den
   persistenten Datensatz überführen) oder zusätzlich als eigener Menüpunkt/Report, der jederzeit über alle
   Athleten/Rekorde hinweg neu berechnet werden kann (unabhängig von einem konkreten Import-Lauf)?
3. Bei mehreren Rekorden desselben Athleten mit unterschiedlichen Vereinen im selben Import (Saram-Stephan-Fall):
   welcher Verein gilt als "der aktuelle" für die Checkbox-Vorbelegung — vermutlich der zeitlich jüngste (`set_date`),
   aber zu bestätigen.
4. Für den Jahres-Fallback: soll der Namens-Jahres-Treffer in der Import-Vorschau vorbelegt (aber weiter änderbar)
   erscheinen, oder nur als zusätzliche, unmarkierte Option in der bestehenden Dropdown-Liste?

**Wer entscheidet:** Erik — Datenmodell-Umfang, ob nur Import-Preview oder auch ein jederzeit aufrufbarer Dauer-Report
gewünscht ist, Vorbelegungsregel bei mehreren Vereinen pro Athlet im selben Import, und ob der Jahres-Fallback-Treffer
vorbelegt oder nur als Option angezeigt wird.

**Zum Schließen nötig:** Entscheidung zu obigen Punkten, dann Migration (en) für die Review-Tabelle (n), Erkennung in
`RecordImportService`/`RecordImportController` ergänzen (Club-Vergleich je Rekord + Jahres-Fallback in
`findAthlete()`), Checkbox/Markierung in `import-preview.blade.php`, neue Review-Seite/-Route zum Abarbeiten der offenen
Fälle — beide Teile in einem Arbeitsschritt, da sie dieselbe Review-Infrastruktur teilen.

## LENEX-Export: `"`/`&` als `&quot;`/`&amp;` kodiert — vermutlich kein Bug, Rückmeldung von Erik nötig

**Seit:** Admin-UI-Rework Phase 10, Rückmeldung nach LENEX-Export-Formular-Anpassung (31.08.2026).

**Was gemeldet wurde:** Beim Export werden `"` zu `&quot;` und `&` zu `&amp;` — Erik berichtet, dass Programme, die
diese LENEX-Datei einlesen, diese Zeichen nicht zurückwandeln, sondern buchstäblich `&quot;`/`&amp;`
anzeigen.

**Befund (gegen den echten Export getestet, Änderung in einer Transaktion zurückgerollt, nicht persistiert):**
`LenexExportService` baut die Datei über PHPs `DOMDocument`/`setAttribute()` — das ist Standard-XML-Verhalten, kein Bug
in unserem Code. `"` und `&` **müssen** laut XML-Spezifikation innerhalb eines Attributwerts als Entity kodiert werden,
ein rohes `"` oder `&` würde die Datei ungültig machen. Rückprobe mit
`Test "Anführungszeichen" & Kaufmanns-Und Meisterschaft` als Meet-Name: Export liefert
`Test &quot;Anführungszeichen&quot; &amp; Kaufmanns-Und Meisterschaft` in der Rohdatei — beim Zurücklesen über
`SimpleXMLElement` (derselbe Mechanismus, den jedes echte XML-basierte LENEX-Programm nutzt, auch unser eigener Import
in `RecordImportService`) kommt exakt wieder `Test "Anführungszeichen" & Kaufmanns-Und Meisterschaft`
heraus — 1:1 identisch mit dem Original. Ein Entfernen der Kodierung würde die Datei ungültig machen und wäre selbst der
Bug.

**Warum (noch) nicht als Fix umgesetzt:** Wenn ein reales Programm die Entities nicht zurückwandelt, ist das nur über
zwei Wege erklärbar, die beide von uns aus nicht behebbar wären, ohne selbst ungültiges XML zu erzeugen:
(a) das Programm zeigt/parsed die Datei nicht als XML (z. B. Ansicht der Rohdatei in einem Texteditor statt Import über
die eigentliche Programmfunktion), oder (b) das andere Programm hat selbst einen XML-Parsing-Bug. Bevor hier etwas
geändert wird, braucht es die konkrete Gegenprobe: welches Programm genau, und wie wurde die Datei dort betrachtet
(echter Import vs. Datei/Rohtext geöffnet)?

**Wer entscheidet:** Erik — welches Programm betroffen ist und wie die Datei dort geöffnet wurde. Falls sich
herausstellt, dass es sich tatsächlich um einen waschechten XML-Import in einem Fremdprogramm handelt, das die Entities
nicht dekodiert, wäre das ein Bug in diesem Fremdprogramm, kein Anpassungsbedarf bei uns — außer als pragmatischer
Workaround, falls dieses konkrete Programm für den ÖBSV wichtig genug ist.

**Zum Schließen nötig:** Rückmeldung von Erik (Programmname + exportierte Beispieldatei mit dem beanstandeten Feld),
dann ggf. erneute Prüfung mit genau diesem Programm.

## Zweites Basiswerte-Import-Format: MeetManager/Hy-Tek-"Points"-Textdatei

**Seit:** Admin-UI-Rework Phase 10, Rückmeldung nach dem Basiswerte-Umbau (03.09.2026), Beispieldatei `502-para-2021.txt`
mitgeschickt.

**Was fehlt:** `base-times/import` akzeptiert bisher nur die World-Aquatics-Excel-Datei (`BaseTimeImportService`).
Erik benötigt zusätzlich den Import eines zweiten, in der Praxis genutzten Formats: einer von
MeetManager/Hy-Tek exportierten "Points"-Tabelle als Textdatei. Aufbau der Beispieldatei:

```
Formula=CUBED
Id=502
Name=OeBSV Table
Options=HANDICAP
ShortNameVersion=OeBSV 2021
Version=2021
<BASETIMES>

COURSE;GENDER;RELAYCOUNT;DISTANCE;STROKE;HANDICAP;MINTIME;MAXTIME
SCM;F;1;25;FREE;1;00:25.78
SCM;F;4;25;FREE;S14;01:01.42
SCM;F;1;25;FREE;X;00:10.91
...
```

Ein erster Abgleich mit dem bestehenden Datenmodell/`BaseTimeImportService`:

- `COURSE` (SCM/LCM) und `GENDER` (F/M/X) entsprechen bereits den intern genutzten Werten.
- `STROKE` (FREE/BACK/FLY/BREAST/MEDLEY) ist bereits exakt dasselbe Vokabular wie die Werte in
  `BaseTimeImportService::STROKE_SUFFIX_MAP` — keine neue Übersetzungstabelle nötig.
- `RELAYCOUNT` (1 oder 4) entspricht `base_time_disciplines.relay_count`.
- `HANDICAP` (Sportklassen-Code) ist uneinheitlich befüllt: bei Einzelbewerben (`RELAYCOUNT=1`) ein reiner
  Zahlenwert ("1".."21") ohne "S"-Präfix — müsste beim Import synthetisch zu "S1".."S21" ergänzt werden (kein
  Alias-Lookup wie `SPORT_CLASS_ALIASES`, nur ein Präfix). Bei Staffeln (`RELAYCOUNT=4`) steht dagegen bereits das
  "S"-Präfix wie in der DB gespeichert ("S14", "S15", "S20", "S21", "S34", "S49") — dort keine Umwandlung nötig.
  Zusätzlich der Sonderwert "X" (Einzel) bzw. "SX" (Staffel): **von Erik geklärt (03.09.2026)** — das sind die
  WA-1000-Punkte-Basiswerte für Menschen ohne Behinderung, keine Para-Sportklasse. Diese Zeilen werden beim Import
  einfach übersprungen, keine neue `base_time_sport_classes`-Zeile nötig.
- `MINTIME` ist der eigentliche Basiswert (Format `MM:SS.cs`), inkl. Sentinel `99:99.99` für "nicht anwendbar" —
  der Excel-Import erkennt "nicht anwendbar" über den Literalwert 0 in der Zelle, nicht über einen Zeit-Sentinel;
  hier bräuchte es eine eigene Erkennung dieses Werts.
- `MAXTIME` ist in der Beispieldatei durchgängig leer (jede Datenzeile hat nur 7 statt der im Header genannten 8
  `;`-getrennten Felder): **von Erik geklärt (03.09.2026)** — wird hier nicht verwendet und kann beim Import
  ignoriert werden. Gehört zur Rudolph-Tabelle (DSV, Deutscher Schwimm-Verband); so eine Tabelle gibt es im
  Behindertensport nicht.

**Warum zurückgestellt:** Neues Datei-Format mit eigenem Parser, kein reiner UI-Fix — Erik hat selbst vorgeschlagen,
das zunächst als offenen Punkt festzuhalten statt sofort umzusetzen.

**Referenz:** Format-Beschreibung des Herstellers (Splash Meet Manager):
<https://wiki.swimrankings.net/index.php/Meet_Manager:Custom_Points>

**Wer entscheidet:** Keine offene Design-Frage mehr — von Erik geklärt: Das Textformat kommt **zusätzlich** zum
bestehenden Excel-Import hinzu (keine Ablöse), "X"/"SX" (WA-1000-Punkte-Basiswerte ohne Behinderung) werden beim
Import ignoriert, `MAXTIME` (gehört zur Rudolph-Tabelle/DSV, gibt es im Behindertensport nicht) wird ignoriert, die
Kopf-Metadaten (`Formula`, `Id`, `Name`, `Options`, `ShortNameVersion`, `Version`) werden nicht benötigt — sie
gehören zum Splash-Meet-Manager-eigenen Datenformat (siehe Referenz) und müssen nicht ausgewertet/gespeichert werden,
weitere `Options`-Werte außer `HANDICAP` sind Erik nicht bekannt, der Parser muss also vorerst nur diesen einen Fall
abdecken. Es fehlt nur noch die Umsetzung.

**Zum Schließen nötig:** Neuer Parser (z. B. `BaseTimeTextImportService`, ggf. mit gemeinsamer Basis/Interface zum
bestehenden `BaseTimeImportService` für die Persistierungs-Logik: Kopfzeilen bis `<BASETIMES>` überspringen,
`;`-Tabelle ab der Kopfzeile lesen, Zeilen mit `HANDICAP` "X"/"SX" überspringen, `MAXTIME`-Feld ignorieren),
Dateityp-Umschalter/-Erkennung in `base-times/import.blade.php` (.txt zusätzlich zu .xlsx, additiv — bestehender
Excel-Import bleibt unverändert bestehen), Tests analog `BaseTimeImportServiceTest`.
