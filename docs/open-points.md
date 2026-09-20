# Offene Punkte

Bewusst zurückgestellte Entscheidungen und TODOs, die nicht in einer einzelnen Phase untergehen sollen. Jeder Eintrag:
was fehlt, warum es zurückgestellt wurde, was zum Schließen gebraucht wird. Erledigte Punkte werden aus dieser Datei
entfernt (Historie steht im jeweiligen Phasen-Abschnitt von `specs/public-frontend-modules.md`), nicht nur abgehakt
liegen gelassen.

## Umsetzungsreihenfolge (Erik, 18.09.2026)

Das Admin-UI-Rework (`feature/admin-ui`) ist mit [PR #5](https://github.com/urshar/para-swimming/pull/5)
abgeschlossen und in `main` gemergt. Für die verbleibenden offenen Punkte unten **kein einzelner Folge-Mega-Branch**
mehr — die Punkte sind fachlich zu unterschiedlich (UI-Konsistenz, Import-Parser, neues Datenmodell-Feature), um sich
wie zusammenhängende Phasen eines Themas zu verhalten. Stattdessen ein eigener, kurzlebiger Branch je Punkt (oder je
eng zusammengehöriger Punktgruppe), jeweils mit eigener PR. Innerhalb jedes Branches gilt weiterhin die normale
phasenweise Arbeitsweise aus `CLAUDE.md` (Plan → Freigabe → Umsetzung → Tests → Sign-off).

Drei Gruppen, in dieser Reihenfolge abzuarbeiten:

**Gruppe 1 — erledigt.** Das Titelleisten-/Header-Muster wurde als `feature/admin-ui-header-pattern` umgesetzt —
deutlich über den ursprünglichen „nur show.blade.php"-Umfang hinaus, auf **alle** Admin-Header. Dokumentiert in
`specs/admin-ui-rework.md` (Abschnitt „Header-/Titelleisten-Muster vereinheitlicht"); der zugehörige Open Point unten
wurde entfernt.

„Pflichtfeld-Sternchen" unten bekommt bewusst **keinen eigenen Branch** — bleibt wie bisher rein opportunistisch,
mitgenommen nur wenn eine betroffene Datei ohnehin aus anderem Anlass geändert wird.

**Gruppe 2 — erst kurze Entscheidungsrunde mit Erik, dann eigener Branch je Punkt.** Vorgeschlagene Reihenfolge nach
Aufwand (kleine zuerst):

2. `feature/meets-status-column` — „Status-Spalte in meets/index" unten
3. `feature/form-tooltip-hints` — „Tooltip/Popover statt Info-Text" unten
4. `feature/entries-year-best-times` — „Jahresbestzeiten fehlen bei der admin-seitigen Meldungserfassung" unten
5. `feature/entries-absolute-best-time` — „Absolute Bestzeit bei Einzelmeldungen + Übernahme per Klick" unten
6. `feature/relay-entry-time-suggestion` — „Meldezeit bei Staffelmeldungen ... herleiten" unten
7. `feature/statistics-multi-year-chart` — „Statistik: 5-Jahres-Vergleichsgrafik" unten
8. `feature/meet-entries-overview` — „Gesamte, editierbare Meldeliste einer Veranstaltung" unten
9. `feature/record-import-review` — „Post-Import Review-Liste" unten (größter/komplexester Punkt)

Vor Start jedes Punkts aus Gruppe 2 zuerst die im jeweiligen Eintrag unter „Wer entscheidet" genannten Fragen mit
Erik klären, erst danach Branch anlegen/implementieren.

**Gruppe 3 — blockiert, keine Umsetzung möglich bis dahin, in dieser Reihenfolge im Blick behalten:**

10. „LENEX-Export: `"`/`&` als `&quot;`/`&amp;` kodiert" unten — wartet auf Eriks Rückmeldung (welches Programm,
    wie geöffnet)
11. „Barrierefreiheitserklärung — Konformitätsstand & Schlichtungsverfahren" unten — Konformitätsstand braucht eine
    echte Prüfung (aktiv einplanbar), Schlichtungsverfahren eine Vorstandsentscheidung
12. **„Impressum & Datenschutzerklärung — echter Inhalt statt Platzhalter" unten — ganz zuletzt**, da der Inhalt vom
    Vorstand noch offen ist

## Statistik: 5-Jahres-Vergleichsgrafik (Starts/Teilnehmer, Damen/Herren, Staffeln)

**Seit:** Phase-13-Planung, Design-Feedback Erik (15.09.2026): "Ich würde auch eine Grafik benötigen, um zu sehen,
wieviele Starts im Vergleich der letzten 5 Jahre waren, Teilnehmer, getrennt nach Damen und Herren, Staffeln
(Herren, Damen, Mixed) eventuell gehört das in die open points." — von Erik selbst als eigener Punkt vorgeschlagen,
statt es in den Phase-13-Umfang zu mischen.

**Was fehlt:** Eine Grafik im Statistik-Dashboard, die Starts/Teilnehmer der letzten 5 Jahre nebeneinander zeigt,
aufgeschlüsselt nach Damen/Herren sowie Staffeln getrennt nach Herren/Damen/Mixed.

**Warum zurückgestellt:** `StatisticsDashboard`/`StatisticsService` werten aktuell immer nur **ein** gewähltes Jahr
aus (`ReportConfiguration::fromArray(['year' => $this->year, ...])`) — ein Mehrjahresvergleich bräuchte eine neue
Datenabfrage über mehrere Jahre hinweg, nicht nur eine neue Darstellung der bestehenden Auswertung. Dazu kommen
offene Design-Fragen: Zeigt die Grafik feste "letzte 5 Kalenderjahre" oder ab dem gewählten Jahr rückwärts? Zählen
Staffelstarts pro Staffel oder pro Athlet? Wo im Dashboard steht die Grafik (eigener Reiter/Abschnitt)? Das sollte
vor der Umsetzung geklärt werden, nicht nebenbei in Phase 13 entschieden.

**Wer entscheidet:** Erik — insbesondere die Zeitraum- und Zählweise-Fragen oben.

**Zum Schließen nötig:** Neue Methode in `StatisticsService` (oder ein eigener Service) für eine
Mehrjahres-Zeitreihe der gewünschten Kennzahlen, dann eine Grafik dafür im Dashboard (voraussichtlich
`flux:chart`, siehe Phase 13 — dort erstmals im Projekt eingeführt).

## „Zurück"-Buttons kontextsensitiv statt fest auf den Index

**Seit:** `feature/admin-ui-header-pattern` (19.09.2026), Rückmeldung Erik beim Header-Rework Gruppe 1 (records).

**Was fehlt:** Viele „Zurück"-Buttons führen fest auf die jeweilige Index-/Listenseite (`records.index`,
`meets.index` …), nicht auf die tatsächlich vorher aufgerufene Ansicht. Beispiel: gefilterte Rekordliste → Detail →
„Bearbeiten"; der „Zurück"-Button auf dem Formular springt auf `records.index` statt zurück auf die Detailseite bzw.
die vorher gewählte (gefilterte) Liste. `athletes/show` macht es bereits richtig — es merkt sich die zuletzt
aufgerufene Listen-URL in der Session (`athletes.list_url`, siehe `AdminUiAthletesTest`); records/meets/… tun das
nicht. `records/import-preview` zeigt korrekt auf den vorherigen Schritt (`records.import`) — der Rest zeigt stumpf
auf den Index.

Konkret bei `records/show`: Der Back-Link übergibt **nur** `type` (`records.index?type=…`), aber keinen der übrigen
Filter (`sportClass`, `ageGroup`, `gender`, `course`, `category`, `relay`, `status`). Die `records.index` fällt ohne
`sportClass`-Parameter auf ihren Default zurück und zeigt dann **immer S01/SB01/SM01**, unabhängig davon, aus welcher
Sportklasse/Ansicht der Nutzer kam. Das „Zurück" landet also gerade nicht in der Darstellung, aus der man kam — es
reicht nicht, nur `type` mitzugeben, es muss der komplette Filter-Zustand (bzw. die vollständige vorherige URL)
wiederhergestellt werden.

**Warum zurückgestellt:** Der Header-Rework (`feature/admin-ui-header-pattern`) ist bewusst rein kosmetisch
(Anordnung/Farbe/Höhe der Buttons) und fasst die Back-**Ziele** nicht an. Kontextsensitive Rücknavigation ist ein
eigenes Verhalten: Referrer/letzte-Liste je Bereich in der Session merken (wie bei Athleten) oder gezielt
`url()->previous()` mit sinnvollem Fallback — plus die Entscheidung, wie weit „zurück" gehen soll (unmittelbar
vorherige Seite vs. gemerkte Listenansicht inkl. Filter).

**Wer entscheidet:** Erik — pro Bereich das gewünschte Verhalten (immer zur letzten Liste inkl. Filter? zur
unmittelbar vorherigen Seite? nur bestimmte Flows?).

**Zum Schließen nötig:** Das Muster von `athletes.list_url` (Session-gespeicherte Rücksprung-URL) auf die übrigen
Bereiche übertragen bzw. einen einheitlichen Back-Ziel-Helfer bauen, dann die betroffenen `route('*.index')`
-Back-Links auf das gemerkte Ziel umstellen.

## Index-Filter einheitlich: sofort filtern bei Feldänderung statt „Filtern"-Button

**Seit:** `feature/admin-ui-header-pattern` (20.09.2026), Rückmeldung Erik beim Header-Rework
(Athleten/Vereine/Klassifizierer).

**Was fehlt:** Die Index-Filter verhalten sich uneinheitlich. `records/index` filtert bereits automatisch bei jeder
Feldänderung (Alpine `x-model` + `x-init="$watch(...)"` → Auto-Submit, kein „Filtern"-Button — siehe die ausführliche
Begründung im Kommentar dort). Die übrigen Index-Filter verlangen dagegen einen Klick auf „Filtern"
(`type="submit"`, `icon="funnel"`): **athletes, clubs, classifiers, results, meets, entries** (6 Seiten).
Zusätzlich wirken einzelne Elemente auf derselben Seite sofort (z. B. der A–Z-Buchstabenfilter auf `athletes/index`
sind Links, die sofort navigieren), während die Text-/Select-Felder daneben erst auf „Filtern" reagieren — genau
diese Mischung fällt als inkonsistent auf. Gewünscht: In allen Index-Filtern soll die Liste sofort aktualisiert
werden, sobald ein Feld ausgewählt/eingetragen wird (mindestens athletes, clubs, classifiers; sinngemäß auch
results, meets, entries).

**Ergänzung (Erik, 20.09.2026):** Dasselbe gilt für die Filter der Meisterschafts-Unterseiten
(**Qualifikanten**, **Förderansicht**, **Auswahl-Rangliste** — die drei Ansichten zu einer Meisterschaft, plus
„Normen"/`championships.show`). Dort sollen die bestehenden Filter angepasst und die **Dropdown-Boxen ausgetauscht**
werden — auf dasselbe Muster (`flux:select variant="listbox"` + Auto-Submit statt nativer/alter Dropdowns). Diese
Ansichten sind Livewire-Tabellen (`championship-qualification-table`, `championship-development-table`), die Filter
laufen dort ggf. über `wire:model` statt der GET-Form — beim Umbau zu prüfen, ob das Alpine-Auto-Submit-Muster
greift oder die Livewire-Variante (`wire:model.live`) die passendere ist.

**Warum zurückgestellt / offene Entscheidung:** Kein reines Copy-Paste vom records-Muster, weil dort **nur Selects**
gefiltert werden. athletes/clubs/… haben zusätzlich ein **Text-Suchfeld** — ein Auto-Submit bei jedem Tastendruck
ist unbrauchbar (Submit pro Zeichen, Fokusverlust). Braucht eine Entscheidung: Debounce (z. B. 300–400 ms) auf dem
Suchfeld, oder Text erst bei „Enter"/Blur, Selects sofort. Außerdem: „Filtern"-Button ganz entfernen (wie
`records/index`) oder als No-JS-Fallback behalten? Der „Zurücksetzen"-Button bleibt in jedem Fall.

**Wer entscheidet:** Erik — Debounce-Verhalten des Suchfelds und ob der „Filtern"-Button verschwindet.

**Zum Schließen nötig:** Das `x-model` + `$watch`-Auto-Submit-Muster aus `records/index.blade.php` (mit Debounce für
Text-Inputs) auf die 6 Index-Filter (athletes, clubs, classifiers, results, meets, entries) übertragen — Selects
sofort, Suchfeld entprellt —, danach je Seite live verifizieren.

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

## Absolute Bestzeit bei Einzelmeldungen + Übernahme per Klick

**Seit:** Admin-UI-Rework Phase 9, Design-Feedback nach Live-Test der Athleten-Auswahl; Übernahme-Verhalten
entschieden am 20.09.2026.

**Was fehlt:** In `club-entries/create.blade.php` wird bei Athlet+Event-Auswahl aktuell nur die *Jahresbestzeit*
angezeigt (`ClubEntryService::bestTimes()` — Zeitraum Vorjahr bis Meetbeginn). Gewünscht: zusätzlich die *absolute
Bestzeit* (ohne Datumsfilter) anzeigen. Die Backend-Methode dafür existiert bereits (`ClubEntryService::absoluteBestTime(Athlete $athlete, SwimEvent $event,
string $course): ?int`), wird aber aktuell nirgends aufgerufen/ausgeliefert.

**Entschieden (Erik, 20.09.2026):** Ein **Einfachklick** auf eine der beiden angezeigten Zeiten (Jahres- oder absolute
Bestzeit) übernimmt sie direkt als Meldezeit. Der separate „Bestzeit übernehmen"-**Button entfällt** dadurch (wird
durch das Klick-auf-Zeit-Verhalten ersetzt).

**Wer entscheidet:** Keine offene Frage mehr — nur noch Umsetzung.

**Zum Schließen nötig:** `ClubEntryController::bestTimes()` (AJAX-Endpunkt) um die absolute Bestzeit ergänzen (LCM +
SCM, wie schon bei der Jahresbestzeit); `single-entry-form.js` um das zusätzliche Datenfeld und einen `@click`
-Handler auf **beide** Zeit-Anzeigen erweitern, der `entryTime`/`entryCourse` setzt (gleiche Methode wie das
bestehende `applyBestTime()`); den bisherigen „Bestzeit übernehmen"-Button entfernen; `create.blade.php`-Anzeige um
die zweite Zeile (absolute Bestzeit) ergänzen, beide Zeiten als klickbar kenntlich machen (Cursor/Hover). Live
verifizieren, dass ein Klick die Meldezeit + Bahnlänge korrekt setzt.

## Post-Import Review-Liste: Club-Konflikte + Jahres-Fallback-Matches (LENEX-Rekordimport)

**Teil B (Matching-Vorschläge) erledigt (19.09.2026, `feature/record-import-match-suggestions`):** Der
Jahres-Fallback für Athleten ist umgesetzt (Name + Geschlecht + Geburtsjahr bei `JJJJ-01-01`-Platzhalter/
Datums-Abweichung; Name + Geschlecht bei leerem Datum), zusätzlich **Vereins-Vorschläge** (exakter
normalisierter Name/Code oder Wortgrenzen-Präfix) und eine **Namens-Normalisierung** (Leerraum um
Bindestriche, „Weber-Treiber" ↔ „Weber - Treiber"). Nicht exakt gefundene Athleten/Vereine bekommen in der
Import-Vorschau **vorbelegte Zuordnungs-Vorschläge** (nur bei genau einem eindeutigen Treffer), das volle
Geburtsdatum wird angezeigt — siehe `RecordImportService::suggestAthletes()`/`suggestClubs()` und
`docs/specs/records.md`. **Offen bleibt dieser Punkt für:** Teil A (Club-Konflikt-Erkennung nach dem Import,
also `Athlete.club_id` ≠ LENEX-Verein) **und** die persistierte, jederzeit abarbeitbare Review-Liste (eigene
Tabelle/Report statt nur Flash/Vorschau).

**Seit:** Admin-UI-Rework Phase 10, Rückfragen zu Saram Stephan / Hochenberger Philip / Rottmann Kilian in
`oebsv.lxf` (31.08.2026). Ursprünglich zwei getrennte Punkte, auf Wunsch von Erik zusammengelegt ("so dass wir das in
einem machen können") — beide brauchen dieselbe Grundlage: Eine persistierte, abarbeitbare Review-Liste nach dem
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

## Pflichtfeld-Sternchen (`*`): Farbe nachrüsten + Abstands-Bug beheben

**Seit:** Admin-UI-Rework Phase 10 (03.09.2026) bzw. WPS-Design-Feedback-Runde (04.09.2026, Abstands-Bug entdeckt);
am 04.09.2026 auf Eriks Wunsch als Phase-14-Bestandteil vorgesehen, aber ausdrücklich **kein** eigener Sweep, sondern
nur opportunistisch mitnehmen, wenn eine betroffene Datei ohnehin aus anderem Anlass geändert wird — dieser
Grundsatz gilt unverändert fort, auch nach Abschluss von Phase 14 (siehe `docs/specs/admin-ui-rework.md`, Phase 14:
Full-Bleed-Tabellen und Datepicker-Rollout sind dort abgeschlossen dokumentiert; nur dieser Punkt bleibt bewusst
offen).

**Was fehlt — zwei getrennte Mängel, dieselben Dateien betreffend:**

1. **Farbe fehlt komplett.** Das etablierte Muster (`<flux:label>Feld<span class="text-red-500
   dark:text-red-400 ms-1">*</span></flux:label>`, siehe `swim-events/form.blade.php`,
   `results/form.blade.php`, `athletes/form.blade.php` u. a.) fehlt noch in neun Dateien — dort steht bei
   Pflichtfeldern weiterhin ein reines, unfarbiges `*`.
2. **Farbe vorhanden, aber Abstand kollabiert auf 0px.** Das früher kopierte Muster mit einem Leerzeichen vor dem
   `<span>` (`Feld <span class="text-red-500 dark:text-red-400">*</span>`) sieht im Code richtig aus, hat aber
   einen Flexbox-Whitespace-Bug: `<ui-label>` rendert `inline-flex items-center`, und der Leerraum am Ende eines
   Text-Knotens direkt vor einem folgenden `<span>` wird beim Rendern vollständig entfernt statt nur kollabiert.
   Live mit `getBoundingClientRect()` nachgemessen: Abstand war exakt `0px`. Betrifft 13 Dateien, darunter auch
   zwei in Phase 10 bereits "fertig" markierte Basiswerte-Formulare. Der korrekte Fix: kein Leerzeichen im
   Label-Text, stattdessen `ms-1` auf dem `<span>` (verifiziert in `wps/import/form.blade.php`, Abstand danach
   `4px`).

**Noch ohne Farbe (7 Dateien):**

- `resources/views/admin/documents/form.blade.php`
- `resources/views/admin/users/index.blade.php`
- `resources/views/age-groups/form.blade.php`
- `resources/views/cups/form.blade.php`
- `resources/views/kader-types/form.blade.php`
- `resources/views/lenex/export.blade.php`
- `resources/views/sport-class-groups/form.blade.php`

**Farbe vorhanden, Abstand kollabiert (8 Dateien):**

- `resources/views/swim-events/form.blade.php`
- `resources/views/results/form.blade.php`
- `resources/views/entries/form.blade.php`
- `resources/views/entries/edit.blade.php`
- `resources/views/clubs/form.blade.php`
- `resources/views/club-entries/create.blade.php`
- `resources/views/club-entries/create-relay.blade.php`
- `resources/views/classifiers/form.blade.php`

**Erledigt (Phase 14, 16.09.2026, opportunistisch mitgenommen — diese 7 Dateien wurden ohnehin für Teil B
angefasst):** `qualifying-time-lists/_general-fields.blade.php`, `records/form.blade.php` (Farbe ergänzt),
`base-times/import.blade.php`, `base-times/versions/form.blade.php`, `meets/_grunddaten-fields.blade.php`,
`athletes/show.blade.php`, `athletes/form.blade.php` (Abstands-Bug behoben). Aus beiden Listen oben gestrichen.

**Warum zurückgestellt UND wie umgesetzt wird — auf Eriks ausdrücklichen Wunsch (04.09.2026) anders als Teil
A/B:** Kein eigener Sweep über alle verbliebenen 15 Dateien. Stattdessen weiterhin: **nur mitnehmen, wenn eine
dieser Dateien ohnehin im Rahmen einer anderen Änderung angefasst wird** (in Phase 14, z. B. durch Teil A, oder
später) — dann bei dieser Gelegenheit das Sternchen auf das korrekte Muster (`Feld<span class="text-red-500
dark:text-red-400 ms-1">*</span>`, kein Leerzeichen, `ms-1`) bringen und aus der jeweiligen Liste oben streichen.
Kein gesonderter Termin nur dafür.

**Wer entscheidet:** Keine offene Design-Frage — Fix-Muster ist bekannt und mehrfach verifiziert. Nur die
Reihenfolge/der Anlass ist offen (siehe oben).

**Zum Schließen nötig:** Wenn eine Datei aus einer der beiden Listen aus anderem Anlass geändert wird: alle
`*`-Pflichtfeld-Markierungen darin auf `Feld<span class="text-red-500 dark:text-red-400 ms-1">*</span>` bringen,
stichprobenartig mit `getBoundingClientRect()` nachmessen statt nur optisch zu prüfen, Datei aus der jeweiligen
Liste streichen. Beide Listen sind erst leer, wenn alle 22 Dateien auf diesem Weg durchlaufen sind.

### Randnotiz aus derselben Rückmeldung — kein Open Point, nur zur Info festgehalten

Erik beschrieb beim Datumsfeld in `wps/import/form.blade.php` einen "schwarzen Rahmen" beim manuellen Eintippen. Live
nachgestellt (fokussiertes Segment-`<input>` des Datepickers untersucht): Jedes der vier Ziffern-Segmente
(Tag/Monat/Jahr) trägt bewusst `focus:outline-[revert]` — laut `vendor/livewire/flux-pro/CLAUDE.md` ("Focus rings:
prefer native browser outlines... Never use `focus:outline-none focus:ring-2...`") ist das eine **bewusste**
Design-Entscheidung von Flux Pro selbst: der native Browser-Fokusring statt eines eigenen Stils. In diesem
Test-Environment gemessen als `1px auto`-Outline in einem Amber-/Orange-Ton (`rgb(229, 151, 0)`), nicht Schwarz — die
genaue Farbe ist browser-/OS-abhängig (`-webkit-focus-ring-color`) und kann auf Eriks System anders/dunkler
ausfallen. Da dieses Verhalten absichtlich auf nativen Browser-Fokus statt auf eigenes Styling setzt, wurde hier
**nichts geändert** — ein Override würde der eigenen Konvention des Pakets widersprechen und bei einem Paket-Update
vermutlich wieder verschwinden. Falls der native Fokusring bei Erik tatsächlich als störend schwarz erscheint, bitte
Rückmeldung mit Browser/OS, dann gezielt nachschauen (ggf. als eigener, kleiner Punkt hier ergänzen statt in P14 zu
verstecken).

Zusätzlich aufgefallen: Die englische Fehlermeldung "The valid from field is required" im mitgeschickten Screenshot
kommt **nicht** von Flux, sondern von Laravels eigener Validierung — `.env` dieser lokalen Entwicklungsumgebung setzt
`APP_LOCALE=en`/`APP_FALLBACK_LOCALE=en` (`config/app.php` fällt sonst auf `env('APP_LOCALE', 'en')` zurück), obwohl
`lang/de/` im Repo existiert. `.env` ist lokal/maschinenspezifisch und nicht Teil des Repos — falls dieses
Entwicklungssystem wie erwartet auf Deutsch laufen soll, `APP_LOCALE=de` und `APP_FALLBACK_LOCALE=de` lokal setzen.

## Nationen anlegen & löschen (Add/Delete in der Nationenverwaltung)

**Seit:** `feature/admin-ui-header-pattern` (20.09.2026), Rückmeldung Erik beim Header-Rework.

**Was fehlt:** Die Nationenliste (`nations/index`) bietet aktuell nur **Bearbeiten** je Zeile. Es gibt keinen
„Neu"-Button und kein „Löschen". Die Route ist bewusst beschränkt: `Route::resource('nations', …)->only(['index',
'edit', 'update'])` (`routes/web.php`) — **kein** `create`/`store`/`destroy`. Gewünscht: Nationen anlegen und löschen
können.

**Warum zurückgestellt — kein Header-/Cosmetic-Fix, sondern Feature mit Datenintegritäts-Frage:** Nationen sind
IOC-Referenzdaten (geseedet) und werden von `athletes`, `clubs`, `swim_records`, `meets` u. a. per FK referenziert.
Ein Löschen einer *verwendeten* Nation würde die FK-Constraint verletzen (DB-Fehler) — es braucht einen Guard
(Löschen nur, wenn nichts darauf verweist; sonst Hinweis „N Athleten/Vereine hängen daran"). Zusätzlich offene
Fragen: Sollen Nationen überhaupt frei anlegbar sein (Kollision mit dem IOC-Seed / der `<x-flag>`-Code-Zuordnung),
oder nur solche außerhalb des Seeds? Welche Felder beim Anlegen (Code, name_de, name_en, is_active)?

**Wer entscheidet:** Erik — ob anlegen/löschen überhaupt gewünscht ist (angesichts IOC-Referenzcharakter) und wie
mit referenzierten Nationen beim Löschversuch umgegangen wird (blockieren mit Hinweis vs. gar nicht anbieten).

**Zum Schließen nötig:** Routen (`create`/`store`/`destroy`) + Controller-Methoden mit Validierung (eindeutiger
Code) ergänzen, Anlege-Formular-View, FK-sicherer Delete-Guard, „Neu"-Button im Header (Regel: Einzelbutton inline)
und Delete-Button je Zeile (rot, mit Confirm) in `nations/index`.

## „Außer Konkurrenz" (AK) bei Meldungen setzbar machen

**Seit:** `feature/admin-ui-header-pattern` (20.09.2026), Wunsch Erik.

**Was fehlt:** Bei Meldungen (Einzel **und** Staffel) soll ein Kennzeichen „außer Konkurrenz" (AK) setzbar sein.

**Entschieden (Erik, 20.09.2026):**
- **Umfang:** AK ist sowohl bei Einzel- (`Entry`) als auch bei Staffelmeldungen (`RelayEntry`) setzbar.
- **Wirkung:** AK-Starts werden **nur aus der Cup-/Punktewertung** ausgeschlossen. Rekorde und Ranglisten (WPS)
  zählen weiterhin normal, und der Start erscheint ganz normal in Ergebnissen und im LENEX-Export.

**Warum zurückgestellt:** Neues Feld + Auswirkung auf die Cup-Wertungslogik, kein Bugfix. Offene Detailfragen:
Wandert AK von der Meldung automatisch auf das zugehörige Ergebnis (`Result`), oder wird es dort separat gepflegt?
Wo genau greift der Cup-Ausschluss (in `CupRankingService`/Tageswertungs-Aggregation — die Zeilen mit AK
überspringen)? Wird AK im LENEX gekennzeichnet (LENEX kennt `ENTRY`-Attribute wie `status`) oder nur intern?

**Wer entscheidet:** Erik — Vererbung Meldung→Ergebnis und ob AK im LENEX-Export mitgegeben werden soll.

**Zum Schließen nötig:** Migration (Boolean-Spalte `out_of_competition`/`ak` auf `entries` und `relay_entries`,
ggf. auch `results`), Checkbox in den Melde-Formularen (`club-entries/create*.blade.php`, `entries/form.blade.php`),
Ausschluss in der Cup-Wertungsberechnung, sichtbare AK-Markierung in Meldungs-/Ergebnislisten.

## Ergebnisse einer Veranstaltung manuell erfassen & löschen (Sammelansicht)

**Seit:** `feature/admin-ui-header-pattern` (20.09.2026), Wunsch Erik.

**Was fehlt / zu klären:** Einzeln existiert schon einiges — `meets/show` hat „Ergebnis erfassen"
(`meets.results.create`), `results/show` hat jetzt Bearbeiten/Löschen, und es gibt `results/index` (global,
nach Meet filterbar). Gewünscht ist aber eine **auf eine ausgewählte Veranstaltung fokussierte** Möglichkeit,
Ergebnisse **manuell zu erfassen und zu löschen** — vermutlich eine meet-gebundene Ergebnis-Sammelansicht (alle
Ergebnisse des Meets auf einen Blick, mit Anlegen/Löschen), statt des globalen `results/index` mit Filter.

**Warum zurückgestellt:** Überschneidet sich teils mit dem bestehenden Punkt „Gesamte, editierbare Meldeliste einer
Veranstaltung" (der betrifft aber **Meldungen**, nicht **Ergebnisse**) — zu klären, ob das eine gemeinsame
Meet-Detail-Arbeitsfläche (Meldungen + Ergebnisse) werden soll oder zwei getrennte Ansichten.

**Wer entscheidet:** Erik — konkret was heute fehlt (nur ein schnellerer Zugang zum vorhandenen Erfassen/Löschen,
oder eine echte neue Sammelansicht pro Meet?) und ob Ergebnis- und Meldungsverwaltung zusammengelegt werden.

**Zum Schließen nötig:** Nach Klärung: ggf. neue meet-gebundene Ergebnis-Übersicht (Liste aller `Result` eines Meets
mit Inline-Löschen + „Ergebnis erfassen"), verlinkt von `meets/show`.

## Meetstruktur / Wertungsgruppen beim Anlegen überarbeiten + LENEX-Export gibt falsche Wertung aus

**Seit:** `feature/admin-ui-header-pattern` (20.09.2026), Rückmeldung Erik.

**Was gemeldet wurde:** (1) Beim Anlegen einer Veranstaltung soll die Struktur rund um die **Wertungsgruppen**
überarbeitet werden. (2) Der **LENEX-Export gibt die falsche Wertung aus** — eine korrekte Wertungsgruppe soll im
LENEX korrekt abgebildet werden.

**Warum zurückgestellt / was gebraucht wird:** Ohne ein konkretes Beispiel ist die Soll-Struktur nicht eindeutig.
Gebraucht wird von Erik: **(a)** ein Beispiel einer *richtigen* Wertungsgruppe (wie sie fachlich aussehen soll —
Alters-/Sportklassen-/Geschlechts-Zuschnitt), **(b)** eine **LENEX-Beispieldatei**, die diese Wertungsgruppe korrekt
enthält, und **(c)** eine Beschreibung, was der aktuelle Export *stattdessen* ausgibt (welches Feld/welche Struktur
falsch ist). Betrifft voraussichtlich `SwimEvent`/`sport_classes`-Zuordnung, die Wertungsgruppen-Logik beim
Meet-Anlegen und `LenexExportService` (AGEGROUP/ranking-Struktur).

**Wer entscheidet / liefert:** Erik — die drei Artefakte oben (Beispiel-Wertungsgruppe, korrekte LENEX-Datei,
Beschreibung des Fehlers), erst danach ist die Umsetzung eindeutig planbar.

**Zum Schließen nötig:** Nach Erhalt der Beispiele: Soll-Struktur der Wertungsgruppen festlegen, Meet-Anlage-UI
anpassen, LENEX-Export gegen die Beispieldatei prüfen und die falsch erzeugte Wertung korrigieren.

## Athleten-Import aus MSAccess-Datei + Athleten-Datenmodell erweitern

**Seit:** `feature/admin-ui-header-pattern` (20.09.2026), Wunsch Erik.

**Was fehlt:** Import von Athletendaten aus einer **MSAccess-Datei** (`.mdb`/`.accdb`), die Erik bereitstellt. Dabei
sind voraussichtlich **Erweiterungen am Athleten-Datenmodell** nötig (zusätzliche Felder, die die Access-Datei führt
und die es bei uns noch nicht gibt).

**Warum zurückgestellt / was gebraucht wird:** Ohne die Datei ist weder das Quellschema (Tabellen/Spalten) noch der
Umfang der nötigen Modell-Erweiterungen bekannt. `.mdb`/`.accdb` ist zudem kein triviales Format in PHP (kein
natives Reading) — Weg zu klären: Export der Access-Datei nach CSV/XLSX durch Erik und Import darüber, oder ein
Konverter. Offene Fragen: Welche Felder kommen dazu (Mapping Access→`athletes`)? Wie werden Dubletten/Bestand
behandelt (Update bestehender vs. nur neue anlegen — analog Rekord-Import-Matching)? Einmal-Migration oder
wiederkehrender Import?

**Wer entscheidet / liefert:** Erik — die Beispiel-Access-Datei und die Liste der zusätzlich benötigten
Athleten-Felder.

**Zum Schließen nötig:** Nach Erhalt der Datei: Quellschema sichten, Athleten-Migration(en) für neue Felder,
Import-Weg festlegen (CSV/XLSX-Zwischenschritt vs. direkter Reader), Import-Service mit Matching/Update-Logik,
Vorschau/Bestätigung analog Rekord-Import.

## Vereins-Rollen / Berechtigungen (was Vereins-User sehen und dürfen)

**Seit:** `feature/admin-ui-header-pattern` (20.09.2026), Wunsch Erik.

**Was fehlt:** Ein klar definiertes Rollen-/Berechtigungsmodell für **Vereins-User** (Nicht-Admins). Heute
unterscheidet die App im Wesentlichen `is_admin` vs. Vereins-User mit `club_id`; die genauen Rechte sind über
einzelne `@if`/Policy-Checks verstreut, nicht als zusammenhängende Rolle definiert.

**Entschieden — Vereins-User sollen dürfen (Erik, 20.09.2026):**
- **Eigene Meldungen erfassen/bearbeiten** (Einzel + Staffel des eigenen Vereins, nur bis Meldeschluss).
- **Eigene Athleten pflegen** (Athleten des eigenen Vereins anlegen/bearbeiten).
- **Eigene Ergebnisse einsehen** (Ergebnisse der eigenen Athleten ansehen, nicht bearbeiten).
- **Vereinsstammdaten bearbeiten** (eigene Vereinsdaten wie Name/Kontakt pflegen).

**Warum zurückgestellt:** Querschnitts-Feature über viele Controller/Policies/Views. Offene Detailfragen: Reicht die
bestehende `club_id`-Bindung als „Rolle", oder braucht es echte Rollen (mehrere Rollentypen, evtl. mehrere User pro
Verein mit unterschiedlichen Rechten)? Wie strikt ist „nur eigene" überall durchzusetzen (Policies für `Athlete`,
`Entry`, `RelayEntry`, `Result`, `Club`)? Sichtbarkeit im Menü je Rolle (viele Admin-Menüpunkte ausblenden).

**Wer entscheidet:** Erik — ob ein echtes Mehr-Rollen-Modell nötig ist oder die vier Fähigkeiten oben als fester
Vereins-User-Satz reichen; Umgang mit Meldeschluss-Sperre; ob mehrere User je Verein.

**Zum Schließen nötig:** Rechte-Matrix festschreiben, Policies für die betroffenen Modelle (eigene-Datensätze-Scope),
Menü-/UI-Sichtbarkeit je Rolle, Tests je Fähigkeit (analog der bestehenden Zugriffskontroll-Tests in
`UserManagementTest`/`WpsQualification*`).

## Statistik-Kacheln auf `meets/show` anklickbar machen (Drill-down mit Rücksprung)

**Seit:** `feature/meets-status-column` (20.09.2026), Wunsch Erik (Screenshot `meets/182`).

**Was fehlt:** Auf der Wettkampf-Detailseite (`meets/show`) gibt es ein Kachel-Raster mit sechs Zählern
(`meets/show.blade.php` ~Zeile 142): **Disziplinen**, **Einzelmeldungen**, **Staffelmeldungen**, **Ergebnisse**,
**Teilnehmer**, **Clubs**. Diese Kacheln sollen **anklickbar** werden und jeweils die dahinterliegenden Daten
**detailliert, auf diese Veranstaltung gefiltert** anzeigen. Beispiel: Klick auf „Ergebnisse" → Liste **aller**
Ergebnisse dieser Veranstaltung. Der **Zurück-Button** der Detailansicht soll dann wieder **auf diese
`meets/show`-Seite** zurückführen (nicht auf den Index).

**Warum zurückgestellt / Überschneidungen:** Teilweise existieren Zielansichten schon, teils nicht:
- **Ergebnisse:** `results/index` ist bereits per `?meet_id=` filterbar — hier reicht ggf. ein Link + der
  kontextsensitive Rücksprung. Überschneidet sich mit „Ergebnisse einer Veranstaltung manuell erfassen & löschen".
- **Einzel-/Staffelmeldungen:** eine **meet-weite** (vereinsübergreifende) Meldungsliste gibt es noch nicht — das ist
  genau der bestehende Punkt „Gesamte, editierbare Meldeliste einer Veranstaltung". Der Kachel-Klick wäre der
  Einstieg dorthin.
- **Disziplinen:** stehen bereits als Tabelle auf derselben Seite — Klick könnte nur zum Abschnitt scrollen
  (Anker) statt eine eigene Seite zu öffnen.
- **Teilnehmer / Clubs:** dafür gibt es noch keine meet-gebundene Detailliste.

Der geforderte **Rücksprung auf `meets/show`** hängt zudem am allgemeinen Punkt „‚Zurück'-Buttons kontextsensitiv"
(oben) — hier konkret: die Detailseite muss sich merken, dass sie von `meets/show` kam.

**Wer entscheidet:** Erik — welche der sechs Kacheln wirklich eine eigene Detailansicht bekommen (vs. Anker/kein
Link), und ob das zusammen mit den bestehenden Punkten (meet-weite Meldeliste / Ergebnisse je Meet) in **einer**
Meet-Detail-Arbeitsfläche gelöst wird.

**Zum Schließen nötig:** Je Kachel entscheiden (eigene gefilterte Detailseite vs. Anker), Kacheln als Links
gestalten, Zielansichten (soweit fehlend) bauen bzw. bestehende meet-filtern, und den Zurück-Button der
Zielansichten auf `meets/show` zurückführen (Session-Rücksprung-URL wie bei `athletes.list_url`).
