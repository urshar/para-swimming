# Offene Punkte

Bewusst zurückgestellte Entscheidungen und TODOs, die nicht in einer einzelnen Phase untergehen sollen. Jeder
Eintrag: was fehlt, warum es zurückgestellt wurde, was zum Schließen gebraucht wird. Erledigte Punkte werden aus
dieser Datei entfernt (Historie steht im jeweiligen Phasen-Abschnitt von `specs/public-frontend-modules.md`), nicht
nur abgehakt liegen gelassen.

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
admin-Variante, da `club-entries.best-times` an eine Club-Auswahl gebunden ist), oder ein eigenständiges,
schlankeres Äquivalent zu bauen. Beides ist eine Design-Entscheidung, keine Bugfix-Zeile.

**Zum Schließen nötig:** Entscheidung, ob die admin-Meldungserfassung dieselbe Such-UI wie club-entries bekommen soll
(Konsistenz) oder eine eigene, einfachere Variante nur für den Best-Times-Hinweis; danach Umsetzung in
`entries/form.blade.php` + ggf. neuer Controller-Endpoint (analog `ClubEntryController::bestTimes()`).

## Barrierefreiheitserklärung — Konformitätsstand & Schlichtungsverfahren

**Seit:** Phase 9 (`/de/barrierefreiheit`, `docs/accessibility.md` §Erklärung zur Barrierefreiheit).

**Was fehlt:** Die veröffentlichte Erklärung nennt bislang nur die Kontaktmöglichkeit
(`schwimmen@obsv.at`) für Rückmeldungen. Zwei Abschnitte fehlen bewusst:

1. **Konformitätsstand** (z. B. "vollständig konform" / "teilweise konform" mit WCAG 2.1 AA,
   inkl. bekannter Einschränkungen) — dafür bräuchte es zuerst eine echte Prüfung
   (siehe `docs/accessibility.md` §Prüfung: axe DevTools, Tastaturdurchlauf, Kontrastprüfung,
   Screenreader-Durchsicht), keine Selbstauskunft ohne Grundlage.
2. **Schlichtungsverfahren** — das österreichische Web-Zugänglichkeits-Gesetz (WZG) richtet sich
   primär an öffentliche Stellen; ob und in welcher Form der ÖBSV als privater Verband freiwillig
   eine Schlichtungsstelle nennen möchte, ist eine Entscheidung des Verbands, keine technische.

**Wer entscheidet:** ÖBSV (Erik/Vorstand) — Inhalt, nicht Umsetzung.

**Zum Schließen nötig:** Entscheidung + Text zu beiden Punkten, dann Ergänzung in
`resources/views/public/accessibility-statement/index.blade.php` (Dateiname zum Zeitpunkt der
Eintragung — bei Umbenennung hier nachziehen) und den zugehörigen `lang/{de,en}/public.php`-Keys.

## Impressum & Datenschutzerklärung — echter Inhalt statt Platzhalter

**Seit:** Phase 9 Nachtrag (`/de/impressum`, `/de/datenschutz`).

**Was fehlt:** Beide Seiten sind aktuell reine Entwürfe mit sichtbar markierten Platzhaltern
(`x-draft-notice`, `x-placeholder-field`) statt echtem Inhalt — via `noindex` + `robots.txt`
gesperrt und **nicht** in `sitemap.xml`, bis das erledigt ist:

1. **Impressum** (`resources/views/public/imprint/index.blade.php`): vollständiger Vereinsname,
   Anschrift, ZVR-Zahl, vertretungsbefugte Person(en), Vereinszweck.
2. **Datenschutzerklärung** (`resources/views/public/privacy-policy/index.blade.php`):
   Rechtsgrundlage für die öffentliche Veröffentlichung von Athletennamen/Ergebnissen/
   Vereinszugehörigkeit (Vorschlag im Platzhalter: berechtigtes Interesse nach Art. 6 Abs. 1
   lit. f DSGVO oder Vereinsstatuten — zu bestätigen), Hosting-Anbieter (Name + Anschrift).
   Kontrolle empfohlen, bevor es live geht: Adresse/E-Mail der österreichischen
   Datenschutzbehörde im Beschwerderecht-Abschnitt sind als aktuell bekannt eingetragen
   (Stand meines Wissens), aber nicht anhand einer Live-Quelle zum Zeitpunkt der Erstellung
   gegengeprüft.

**Wer entscheidet:** ÖBSV (Erik/Vorstand) — Inhalt, nicht Umsetzung. Bei Bedarf mit
rechtskundiger Person/Anwalt gegenprüfen, insbesondere die Rechtsgrundlage für die
Athletendaten-Veröffentlichung.

**Zum Schließen nötig:** Echten Text in beide Views einsetzen, `x-draft-notice`-Aufrufe entfernen,
`@section('robots', 'noindex, nofollow')` entfernen, die beiden Routen aus den
`Disallow`-Zeilen in `app/Http/Controllers/Public/RobotsController.php` streichen und in
`app/Http/Controllers/Public/SitemapController.php::STATIC_ROUTES` aufnehmen.

## Status-Spalte in `meets/index` — I/E/R-Schema statt LENEX-Status

**Seit:** Admin-UI-Rework Phase 9, Design-Feedback-Runde nach `npm run dev`-Test.

**Was fehlt:** Die Spalte "Status" in der Wettkampfliste zeigt aktuell `$meet->lenex_status`
(OFFICIAL/RUNNING/SEEDED, ein LENEX-Importfeld). Gewünscht ist stattdessen ein Schema, das auf
einen Blick zeigt, was zu einem Wettkampf schon existiert: **I** = Disziplinen mit
Wertungsgruppen angelegt, **E** = Meldungen liegen vor, **R** = Ergebnisse liegen vor. Braucht
eine eigene Abfrage pro Zeile (vermutlich `withCount`/`withExists` auf `swimEvents`/`entries`/
`results`, plus Klärung ob "Disziplinen mit Wertungsgruppen" `sport_classes IS NOT NULL` meint
oder etwas anderes) und wahrscheinlich Tooltip-Text pro Buchstabe.

**Wer entscheidet:** Erik — ob `lenex_status` daneben erhalten bleibt oder ersetzt wird, und die
genaue Definition von "I" (welche Wertungsgruppen-Zuordnung genau gemeint ist).

**Zum Schließen nötig:** Definition der drei Zustände abstimmen, `MeetController::index()` um die
nötigen Zähl-/Exists-Abfragen erweitern, `meets/index.blade.php`-Statusspalte umbauen.

## Tooltip/Popover statt Info-Text bei Disziplin-Formular-Hinweisen

**Seit:** Admin-UI-Rework Phase 9, Design-Feedback-Runde nach `npm run dev`-Test.

**Was fehlt:** In `swim-events/form.blade.php` stehen bei "Schwimmer/Staffel" ("1 = Einzel") und
"Sport-Klassen" ("Leerzeichen-getrennt") aktuell permanent sichtbare `flux:description`-Zeilen.
Gewünscht: Anzeige als Tooltip/Popover statt dauerhaft sichtbarem Text. Noch keine entschiedene
Lösung — Nutzer ist offen für Vorschläge (`flux:tooltip`? Info-Icon mit `flux:popover`?).

**Wer entscheidet:** Erik — welche Variante (Tooltip vs. Popover vs. Icon-Trigger).

**Zum Schließen nötig:** Kurze Abstimmung über die Zielkomponente, dann Umbau der beiden Felder
(und ggf. gleichartiger `flux:description`-Hinweise an anderen Stellen, falls das Muster
gefallen soll).

## Meldezeit bei Staffelmeldungen aus den gemeldeten Athleten herleiten

**Seit:** Admin-UI-Rework Phase 9, Design-Feedback-Runde nach `npm run dev`-Test.

**Was fehlt:** Bei `club-entries/create-relay.blade.php`/`edit-relay.blade.php` soll die Meldezeit
sich (wenn möglich) automatisch aus den Bestzeiten der ausgewählten Staffel-Schwimmer als
Vorschlag/Default ableiten lassen — analog zur bereits bestehenden Bestzeit-Übernahme bei
Einzelmeldungen (`ClubEntryService::bestTimes()`). Für Staffeln braucht das eine eigene Regel
(Summe der Einzel-Bestzeiten über die passende Teilstrecke/Bahnlänge? Nur wenn alle vier Plätze
belegt sind? Rundungs-/Sicherheitsaufschlag?) — nicht ohne Rücksprache zu implementieren.

**Wer entscheidet:** Erik — die genaue Herleitungsregel (Summenbildung, Umgang mit fehlenden
Einzel-Bestzeiten einzelner Mitglieder, Kurzbahn/Langbahn-Umrechnung wie bei Einzelmeldungen).

**Zum Schließen nötig:** Regel abstimmen, dann in `ClubEntryService` eine
`relayBestTime()`-ähnliche Methode ergänzen, per AJAX-Endpunkt (analog `best-times`) an
`relay-entry-form.js` liefern, dort als Vorschlag mit "Bestzeit übernehmen"-Button anzeigen
(gleiches UI-Muster wie bei Einzelmeldungen).

## Absolute Bestzeit bei Einzelmeldungen + Übernahme per Doppelklick

**Seit:** Admin-UI-Rework Phase 9, Design-Feedback nach Live-Test der Athleten-Auswahl.

**Was fehlt:** In `club-entries/create.blade.php` wird bei Athlet+Event-Auswahl aktuell nur die
*Jahresbestzeit* angezeigt (`ClubEntryService::bestTimes()` — Zeitraum Vorjahr bis Meetbeginn).
Gewünscht: zusätzlich die *absolute Bestzeit* (ohne Datumsfilter) anzeigen. Die Backend-Methode
dafür existiert bereits (`ClubEntryService::absoluteBestTime(Athlete $athlete, SwimEvent $event,
string $course): ?int`), wird aber aktuell nirgends aufgerufen/ausgeliefert. Zusätzlich:
Doppelklick auf eine der beiden angezeigten Zeiten soll sie automatisch als Meldezeit übernehmen
(bisheriges "Bestzeit übernehmen"-Button-Muster bleibt vermutlich zusätzlich bestehen, oder wird
dadurch ersetzt — zu klären).

**Wer entscheidet:** Erik — ob beide Zeiten permanent nebeneinander stehen oder z. B. als Tabs/
Toggle, und ob der bestehende "Bestzeit übernehmen"-Button neben dem neuen Doppelklick-Verhalten
bestehen bleibt.

**Zum Schließen nötig:** `ClubEntryController::bestTimes()` (AJAX-Endpunkt) um die absolute
Bestzeit ergänzen (LCM + SCM, wie schon bei der Jahresbestzeit), `single-entry-form.js` um das
zusätzliche Datenfeld und einen `@dblclick`-Handler auf die Zeit-Anzeige erweitern, der
`entryTime`/`entryCourse` setzt (gleiche Methode wie das bestehende `applyBestTime()`),
`create.blade.php`-Anzeige um die zweite Zeile ergänzen.
