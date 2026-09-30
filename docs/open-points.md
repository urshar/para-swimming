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
deutlich über den ursprünglichen "nur show.blade.php"-Umfang hinaus, auf **alle** Admin-Header. Dokumentiert in
`specs/admin-ui-rework.md` (Abschnitt "Header-/Titelleisten-Muster vereinheitlicht"); der zugehörige Open Point unten
wurde entfernt.

"Pflichtfeld-Sternchen" unten bekommt bewusst **keinen eigenen Branch** — bleibt wie bisher rein opportunistisch,
mitgenommen, nur wenn eine betroffene Datei ohnehin aus anderem Anlass geändert wird.

**Gruppe 2 — erst kurze Entscheidungsrunde mit Erik, dann eigener Branch je Punkt.** Vorgeschlagene Reihenfolge nach
Aufwand (kleine zuerst). **Erledigt:** `feature/meets-status-column` ("Status-Spalte in meets/index", PR #13),
`feature/form-tooltip-hints` ("Tooltip/Popover statt Info-Text" → Info-Icon + Tooltip),
`feature/entries-best-times` ("Jahresbestzeiten im Admin-Formular" + "Absolute Bestzeit + Klick-Übernahme", PR #15 —
beide Punkte zusammen in einem Branch) und
`feature/relay-entry-time-suggestion` ("Meldezeit bei Staffelmeldungen aus den Athleten herleiten") und
`feature/statistics-multi-year-chart` ("Statistik: 5-Jahres-Vergleichsgrafik" — deutlich über den ursprünglichen
Umfang hinaus: eigener Jahresvergleich-Menüpunkt, Status je Veranstaltung, manueller Veranstaltungs-Vergleich;
dokumentiert in `specs/statistics.md`; dabei sind die zwei Folge-Punkte "Staffel-Ergebnisse importieren" und
"Weitere Statistiken" unten entstanden) und `feature/meet-entries-overview` ("Gesamte, editierbare Meldeliste einer
Veranstaltung" → meet-weite "Alle Meldungen", Einzel + Staffel, alle Vereine, PR #18; erweitert um das
wettkampfübergreifende Admin-Cockpit "Meldungen" mit Einzel-/Staffel-Tabs, Status-/Problemfiltern und
Kennzahlen-Kacheln, `feature/entries-cockpit`, dokumentiert in `specs/club-entries.md` "Meldungen-Cockpit"),
`feature/index-filter-autosubmit` ("Index-Filter einheitlich: sofort filtern bei Feldänderung" → die fünf
GET-Formular-Index-Filter athletes/clubs/classifiers/results/meets auf Auto-Submit ohne "Filtern"-Button umgestellt;
`entries` war über das Cockpit schon so) und `feature/championship-filter-autosubmit` (der Livewire-Rest: Auto-Submit
auf den Meisterschafts-Unterseiten Normen/Qualifikanten/Förderansicht über `wire:model.live` + Listbox-Selects, plus
die Auswahl-Rangliste als GET-Form über `indexFilters`) — beide dokumentiert in `specs/admin-ui-rework.md`
"Index-Filter-Auto-Submit vereinheitlicht", sowie `feature/nations-add-delete` ("Nationen anlegen & löschen" →
`create`/`store`/`destroy`, beliebiger eindeutiger 3-Buchstaben-IOC-Code; Löschen immer angeboten, aber blockiert mit
Hinweis, solange Athleten/Vereine/Veranstaltungen/Rekorde/Klassifizierer — inkl. soft-gelöschter — darauf verweisen,
`Nation::referenceCounts()`) und `feature/context-back-buttons` ("'Zurück'-Buttons kontextsensitiv" → gemerkte
Listen-URL je Bereich über `App\Support\ListUrl` + Middleware `remember.list:<bereich>` für Rekorde, Veranstaltungen,
Vereine, Klassifizierer, Nationen, Ergebnisse, Meldungen, Athleten; Weiterleitungen nach Speichern/Löschen ebenso;
Muster in CLAUDE.md dokumentiert) — die zugehörigen Open Points unten wurden entfernt.

Kandidaten-Pool (unten je ausführlich beschrieben). Die "nach Aufwand"-Reihenfolge oben gilt nur grob — welcher
Punkt als Nächstes drankommt, entscheidet Erik:

1. `feature/record-import-review` — "Post-Import Review-Liste" unten (größter/komplexester Punkt)
2. "Staffelnamen / -bezeichnung (frei vergebbar, sonst Vereinsname + laufende Nummer)" unten
3. "Meldeliste nach Bewerben: Abschnitt-Datum (Session → Tag) fehlt" unten
4. "Meldegelder (Gebühren je Verein/Athlet, Summe) — PDF + online" unten
5. "Meldeschluss: nach Ablauf kontrolliert wiedereröffnen (Admin, Zeitfenster)" unten
6. "'Außer Konkurrenz' (AK) bei Meldungen setzbar machen" unten
7. "Ergebnisse einer Veranstaltung manuell erfassen & löschen (Sammelansicht)" unten
8. "Statistik-Kacheln auf `meets/show` anklickbar machen (Drill-down mit Rücksprung)" unten
9. "Meetstruktur / Wertungsgruppen beim Anlegen überarbeiten + LENEX-Export gibt falsche Wertung aus" unten
10. "Athleten-Import aus MSAccess-Datei + Athleten-Datenmodell erweitern" unten
11. "Vereins-Rollen / Berechtigungen (was Vereins-User sehen und dürfen)" unten
12. "Staffel-Ergebnisse importieren + Relay-Gender pflegen" unten (Import-Parser + Datenmodell)
13. "Weitere Statistiken (sobald eine ordentliche Datenbasis vorhanden ist)" unten — hängt an #12
14. "Normen-Import: MQS/MET auch aus XML importieren (zusätzlich zum xlsx)" unten

Vor Start jedes Punkts aus Gruppe 2 zuerst die im jeweiligen Eintrag unter "Wer entscheidet" genannten Fragen mit
Erik klären, erst danach Branch anlegen/implementieren.

**Gruppe 3 — blockiert, keine Umsetzung möglich bis dahin, in dieser Reihenfolge im Blick behalten:**

1. "Barrierefreiheitserklärung — Konformitätsstand & Schlichtungsverfahren" unten — Konformitätsstand braucht eine
   echte Prüfung (aktiv einplanbar), Schlichtungsverfahren eine Vorstandsentscheidung
2. **"Impressum & Datenschutzerklärung — echter Inhalt statt Platzhalter" unten — ganz zuletzt**, da der Inhalt vom
   Vorstand noch offen ist

## Normen-Import: MQS/MET auch aus XML importieren (zusätzlich zum xlsx)

**Seit:** Wunsch Erik (30.09.2026) beim Umbau der Meisterschafts-Filter.

**Was fehlt:** Der Normen-Import (`championships/import/form.blade.php` → `ChampionshipStandardImportController`/
`ChampionshipStandardImportService`) akzeptiert aktuell **nur eine `.xlsx`-Datei** im WPS-Excel-Layout (Zeile 1 Titel,
Zeile 2 Events/Class/Men/Women, Zeile 3 MQS/MET, ab Zeile 4 die Daten in den Spalten A–F; füllt ausschließlich MQS und
MET, ÖBSV-Prozentsätze/-Zeiten bleiben unberührt). Gewünscht: MQS und MET **auch aus einer XML-Datei** importieren
können.

**Warum zurückgestellt:** Ohne eine Beispiel-XML ist weder das Quellformat (Schema/Struktur) noch das Mapping auf
Bewerb/Sportklasse/Geschlecht/MQS/MET bekannt. Zu klären ist außerdem, ob es sich um ein WPS-eigenes XML, um LENEX oder
ein anderes Format handelt — davon hängt der Parser ab.

**Wer entscheidet / liefert:** Erik — eine Beispiel-XML mit Normen (MQS/MET) und die Angabe, welches Format das ist
(WPS-XML/LENEX/…). Offene Fragen: dieselbe "nur MQS/MET füllen, ÖBSV unberührt"-Regel wie beim xlsx-Import? Ein
gemeinsamer Datei-Upload, der xlsx UND xml automatisch erkennt, oder ein eigener Auswahlpunkt?

**Zum Schließen nötig:** Nach Erhalt der Beispiel-XML: `ChampionshipStandardImportService` um einen XML-Parser erweitern
(Format erkennen: xlsx vs. xml), Mapping auf Bewerb/Klasse/Geschlecht/MQS/MET, dieselbe Vorschau- und
Bestätigungsstrecke (`ChampionshipStandardImportPreview`) und die "nur MQS/MET"-Regel wiederverwenden; der Datei-Upload
akzeptiert zusätzlich `.xml`.

## Staffel-Ergebnisse importieren + Relay-Gender pflegen

**Seit:** Umsetzung `feature/statistics-multi-year-chart` (27.09.2026): Beim Bau der Staffel-Auswertungen fiel auf,
dass gar keine Staffelergebnisse in der Datenbank liegen.

**Was fehlt:** Der LENEX-Import legt zwar die Staffel- **Bewerbe** an (`relaycount` aus dem SwimStyle →
`swim_event.relay_count > 1`, aktuell 151 Bewerbe), aber **keine Staffel-Ergebnisse**: von 12.487 importierten
Ergebnissen sind 0 Staffelergebnisse. Damit bleiben die bereits gebauten und getesteten Staffel-Zählungen
(`ParticipationStatisticsService::relayStartsByEventGender`, Grafik "Staffelstarts nach Typ" im Jahresvergleich)
leer bzw. Platzhalter.

Zusätzlich (**Relay-Gender**): Selbst mit Ergebnissen ließen sich 130 der 151 Staffel-Bewerbe nicht nach
Herren/Damen/Mixed einordnen — ihr `gender` ist `A` (unspezifiziert); nur 21 haben `M`/`F`/`X`. Für die getrennte
H/D/Mixed-Auswertung muss das Staffel-Geschlecht gepflegt oder hergeleitet werden.

**Warum zurückgestellt:** Staffelergebnisse sind in LENEX anders aufgebaut als Einzelergebnisse (`<RELAY>` mit
`<RELAYPOSITIONS>` und mehreren Athleten je Ergebnis, statt eines einzelnen `<RESULT>` je Schwimmer). Der
`LenexParserService` verarbeitet aktuell nur Einzelergebnisse; es gibt kein `RelayResult`-Modell (nur `RelayEntry`
für Meldungen). Das ist ein Import-Parser- **und** Datenmodell-Thema, kein Quick-Fix — bewusst getrennt von der
Statistik-Iteration gehalten.

**Wer entscheidet:** Datenmodell-Frage — eigenes `RelayResult`-Modell vs. Staffelergebnisse als spezielle
`results`-Zeilen (mit Staffelposition/eingesetzten Schwimmern). Und: Relay-Gender aus dem Bewerb bzw. den
eingesetzten Athleten herleiten oder manuell pflegbar machen?

**Zum Schließen nötig:** `LenexParserService` um Staffelergebnisse erweitern (`<RELAY>` → Ergebniszeilen inkl.
eingesetzter Schwimmer/Position), das Datenmodell dafür, und die Relay-Gender-Pflege. Danach liefern die
bestehenden Zählungen echte Zahlen und die Platzhalter-Grafik im Jahresvergleich wird automatisch belegt.

## Weitere Statistiken (sobald eine ordentliche Datenbasis vorhanden ist)

**Seit:** Umsetzung `feature/statistics-multi-year-chart` (27.09.2026): Erik hat beim Bau des Jahresvergleichs
weitere sinnvolle Auswertungen als "für später" freigegeben — erst wenn eine belastbare Datenbasis vorhanden ist
(vollständigere Importe, insbesondere Staffelergebnisse, s. o.), damit die Zahlen aussagekräftig sind.

**Was fehlt:** Zusätzliche Trend-/Kennzahl-Auswertungen, u. a.:

- Geschlechteranteil in Prozent (statt nur absoluter Zahlen)
- Altersgruppen im Zeitverlauf
- Nationen im Zeitverlauf
- Ø Starts je Teilnehmer
- neue vs. wiederkehrende Athleten je Jahr

**Warum zurückgestellt:** Ohne vollständige/konsistente Datenbasis (v. a. fehlende Staffelergebnisse, teils
unspezifizierte Felder) wären die Zahlen irreführend. Erst Datenbasis, dann diese Auswertungen.

**Wer entscheidet:** Erik — welche dieser Auswertungen tatsächlich gebraucht werden und in welcher Form
(Dashboard-Abschnitt, Jahresvergleich, eigener Bereich).

**Zum Schließen nötig:** Je Kennzahl eine Methode in `MultiYearStatisticsService`/`ParticipationStatisticsService`
plus Darstellung (analog zu den bestehenden `flux:chart`/`TrendChart`-Auswertungen). Sinnvoll erst, wenn die
Datenbasis steht.

## Staffelnamen / -bezeichnung (frei vergebbar, sonst Vereinsname + laufende Nummer)

**Seit:** Feedback Erik (27.09.2026) beim Bau der meet-weiten Meldeliste (`feature/meet-entries-overview`).

**Was fehlt:** Staffeln haben keinen eigenen Namen. In Listen (Meldeliste, Startliste) und im Datenmodell
erscheinen mehrere Staffeln desselben Vereins in einem Bewerb alle nur als Vereinsname (z. B. "BSV Spittal") —
in den Auswertungsprogrammen von Schwimmveranstaltungen und in Startlisten nicht unterscheidbar.

Gewünscht: Der Verein kann einen Staffelnamen **frei** vergeben. Ist keiner gesetzt, wird der Vereinsname genommen
und bei **mehreren Staffeln desselben Vereins im selben Bewerb** eine laufende Nummer angehängt (z. B.
"BSV Spittal 1", "BSV Spittal 2").

**Warum zurückgestellt:** Querschnittlich — ein optionales Namensfeld an `RelayEntry` (Migration) + Eingabe im
Staffel-Formular + Anzeige in Meldeliste/Startlisten + LENEX-Export. Der LENEX-Export vergibt Staffeln bereits eine
laufende `number` (`LenexExportService::buildRelay`), aber es gibt kein Namensfeld und die App-Anzeige nutzt nur den
Vereinsnamen. Eigenes Thema, nicht Teil der Meldelisten-Übersicht.

**Wer entscheidet:** Erik — ob nur die laufende Nummerierung (Fallback) reicht oder auch der frei vergebbare Name
gebraucht wird (Letzteres braucht Feld + Formularfeld).

**Zum Schließen nötig:** `name`/Bezeichnung an `RelayEntry` (nullable) + Formularfeld; eine Anzeige-Logik "Name,
sonst Vereinsname (+ laufende Nummer bei mehreren im selben Bewerb)" als Accessor, überall genutzt (Meldeliste,
Startlisten, LENEX-`name`).

## Meldegelder (Gebühren je Verein/Athlet, Summe) — PDF + online

**Seit:** Feedback Erik (27.09.2026) beim Bau der meet-weiten Meldeliste (`feature/meet-entries-overview`).

**Erledigt (28.09.2026, `feature/entry-lists`):** Die vier **Melde-Listen** sind umgesetzt und in
`docs/specs/club-entries.md` (Abschnitt "Meldebasierte Listen") dokumentiert: **Teilnehmerliste** (pro Verein,
PDF + Excel, Sport-Austria-Vorlage), **Sportpasskontrolle** (alle Vereine, admin-only, PDF + Excel, ÖBSV-Vorlage),
**Meldeliste nach Namen** und **Meldeliste nach Bewerben** (je PDF, Admin = ganze Veranstaltung, Verein = nur eigene).
Ebenfalls erledigt: das Club-Scoping der linksseitigen Meldungsliste (`entries.index`), auf das dieser Punkt früher
verwies.

**Was noch fehlt — Meldegelder:** Aus den Meldungen die Gebühren je Verein/Athlet samt Summe erzeugen (PDF + online).

**Warum zurückgestellt:** **Meldegelder brauchen ein neues Datenmodell:** aktuell gibt es kein Gebühren-Feld (kein
`entry_fee`/Meldegeld im Schema). Nötig wären eine Gebühren-Konfiguration (je Meet, evtl. je Bewerb/Staffel,
evtl. Grundgebühr je Verein), eine Berechnung über die Meldungen und die PDF-/Online-Darstellung — ein eigenes
Thema mit Design-Entscheidungen. Die vorhandene Melde-Listen-Infrastruktur (`MeetEntryListService`/
`MeetEntryListController`) kann als Vorbild/Anschlusspunkt dienen.

**Wer entscheidet:** Erik — Gebührenmodell (Pauschale vs. je Start vs. je Bewerb; Staffel-Gebühren; Grundgebühr je
Verein; wer legt die Beträge fest und wo).

**Zum Schließen nötig:** Gebühren-Datenmodell (Konfiguration je Meet/Bewerb) + Berechnung über die Meldungen +
Darstellung (PDF + online), analog zu den bestehenden Melde-Listen.

## Meldeliste nach Bewerben: Abschnitt-Datum (Session → Tag) fehlt

**Seit:** `feature/entry-lists` (28.09.2026), beim Bau der Meldeliste nach Bewerben.

**Was fehlt:** Die "Übersichtsliste nach Wettkämpfen" gruppiert nach **Abschnitt** (Session,
`swim_events.session_number`)
und zeigt in der swimify-Vorlage je Abschnitt den konkreten **Wochentag + Datum** ("Abschnitt 1 - Samstag, 17. Oktober
2026"). Unser Datenmodell kennt aber **keine Zuordnung Session → Kalendertag** — nur `meet.start_date`/`end_date`.
Daher zeigt die Liste den Wochentag/das Datum nur bei **eintägigen** Veranstaltungen; bei mehrtägigen steht lediglich
"Abschnitt N" (siehe `MeetEntryListService::sessionLabel`).

**Warum zurückgestellt:** Braucht ein neues Feld/Datenmodell (Datum bzw. Datum+Startzeit je Session) plus Pflege
(manuell im Meet-/Session-Formular und/oder aus dem LENEX-Import, wo `<SESSION date=…>` vorhanden ist). Eigenes
kleines Thema, nicht Teil der Listen-Formatierung.

**Wer entscheidet:** Erik — ob die Session-Datumsangabe gebraucht wird und woher sie kommt (manuell pflegen vs. aus
LENEX übernehmen).

**Zum Schließen nötig:** Session-Datum am Datenmodell (z. B. `session_date` je `swim_event` bzw. eine eigene
Session-Struktur), Pflege/Import, dann `sessionLabel` das echte Datum je Abschnitt nutzen lassen.

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

## Meldeschluss: nach Ablauf kontrolliert wiedereröffnen (Admin, Zeitfenster)

**Seit:** `feature/relay-entry-time-suggestion` (21.09.2026), Beobachtung Erik.

**Aktueller Stand (verifiziert im Code):** Der Meldeschluss wird für **Vereins-User bereits durchgesetzt** —
`EntryPolicy::manageEntries()` gibt nach Ablauf `false` zurück (`Carbon::today()->lte(entries_deadline)`), und
alle mutierenden Pfade in `ClubEntryController` (Einzel- UND Staffelmeldung: create/store/edit/update/destroy)
rufen `authorize('manageEntries', $meet)` bzw. `authorize('deleteEntry', $meet)`; `club-entries/index`
blendet die Buttons aus und zeigt "Meldeschluss war am …". **Admins sind per Policy dagegen IMMER erlaubt, ohne
Zeitlimit** — das ist vermutlich der Grund, warum "Meldungen noch möglich" beobachtet wurde (Test als Admin).

**Was fehlt / gewünscht (Erik):** Nach Meldeschluss soll die Veranstaltung **geschlossen** sein; nur der Admin
darf danach etwas ändern/hinzufügen. Zusätzlich soll der Admin die Veranstaltung **für einen begrenzten
Zeitraum (z. B. 24 h) wieder öffnen** können, damit z. B. Vereine kontrolliert Nachmeldungen/Korrekturen machen
können — danach schließt sie automatisch wieder.

**Offene Entscheidungen (Erik):**

- Behält der Admin die unbegrenzte Direkt-Bearbeitung (Admin-Override wie heute), oder soll auch für den Admin
  nach Ablauf erst "wiedereröffnen" nötig sein?
- Wer darf während des Wiedereröffnungs-Fensters melden — nur der Admin, oder wieder die Vereine (das ist der
  eigentliche Nutzen)?
- Fenster fix 24 h oder frei wählbar (Datum/Uhrzeit)? Pro Veranstaltung global oder je Verein?
- Soll das Wiederöffnen protokolliert werden (wer/wann/bis wann)?

**Wer entscheidet:** Erik — die vier Punkte oben (v. a. wer im Fenster melden darf und ob der Admin-Override
bleibt).

**Zum Schließen nötig:** Migration `entries_reopened_until` (nullable `timestamp`) auf `meets`; `EntryPolicy`
erweitern (Vereins-User zusätzlich erlaubt, wenn `entries_reopened_until` gesetzt und `now()` davor — die
zentrale Policy deckt automatisch alle o. g. Controller-Pfade ab); Admin-UI zum Wiederöffnen (Button
"+24 h" / freies Datum, Anzeige des aktiven Fensters inkl. Ablauf) auf `meets/show` bzw. in der
Meldungsverwaltung; sichtbarer Status ("wieder geöffnet bis …") in `club-entries/index(-relay)`. **Zusätzlich
als Absicherung:** ein Regressionstest, der bestätigt, dass ein Vereins-User nach Ablauf auf ALLEN Pfaden (Einzel +
Staffel, store/update/destroy) 403 bekommt und während eines aktiven Wiederöffnungs-Fensters wieder
darf — damit ein etwaiges echtes Leck (statt nur des Admin-Overrides) auffliegt.

## Post-Import Review-Liste: Club-Konflikte + Jahres-Fallback-Matches (LENEX-Rekordimport)

**Teil B (Matching-Vorschläge) erledigt (19.09.2026, `feature/record-import-match-suggestions`):** Der
Jahres-Fallback für Athleten ist umgesetzt (Name + Geschlecht + Geburtsjahr bei `JJJJ-01-01`-Platzhalter/
Datums-Abweichung; Name + Geschlecht bei leerem Datum), zusätzlich **Vereins-Vorschläge** (exakter
normalisierter Name/Code oder Wortgrenzen-Präfix) und eine **Namens-Normalisierung** (Leerraum um
Bindestriche, "Weber-Treiber" ↔ "Weber - Treiber"). Nicht exakt gefundene Athleten/Vereine bekommen in der
Import-Vorschau **vorbelegte Zuordnungs-Vorschläge** (nur bei genau einem eindeutigen Treffer), das volle
Geburtsdatum wird angezeigt — siehe `RecordImportService::suggestAthletes()`/`suggestClubs()` und
`docs/specs/records.md`. **Offen bleibt dieser Punkt für:** Teil A (Club-Konflikt-Erkennung nach dem Import,
also `Athlete.club_id` ≠ LENEX-Verein) **und** die persistierte, jederzeit abarbeitbare Review-Liste (eigene
Tabelle/Report statt nur Flash/Vorschau).

**Seit:** Admin-UI-Rework Phase 10, Rückfragen zu Saram Stephan / Hochenberger Philip / Rottmann Kilian in
`oebsv.lxf` (31.08.2026). Ursprünglich zwei getrennte Punkte, auf Wunsch von Erik zusammengelegt ("sodass wir das in
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

**Farbe vorhanden, Abstand kollabiert (2 Dateien):**

- `resources/views/clubs/form.blade.php`
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
stichprobenartig mit `getBoundingClientRect()` nachmessen, statt nur optisch zu prüfen, Datei aus der jeweiligen
Liste streichen. Beide Listen sind erst leer, wenn alle 22 Dateien auf diesem Weg durchlaufen sind.

### Randnotiz aus derselben Rückmeldung — kein Open Point, nur zur Information festgehalten

Erik beschrieb beim Datumsfeld in `wps/import/form.blade.php` einen "schwarzen Rahmen" beim manuellen Eintippen. Live
nachgestellt (fokussiertes Segment-`<input>` des Date pickers untersucht): Jedes der vier Ziffern-Segmente
(Tag/Monat/Jahr) trägt bewusst `focus:outline-[revert]` — laut `vendor/livewire/flux-pro/CLAUDE.md` ("Focus rings:
prefer native browser outlines … Never use `focus:outline-none focus:ring-2...`") ist das eine **bewusste**
Design-Entscheidung von Flux Pro selbst: der native Browser-Fokusring statt eines eigenen Stils. In diesem
Test-Environment gemessen als `1px auto`-Outline in einem Amber-/Orange-Ton (`rgb(229, 151, 0)`), nicht Schwarz — die
genaue Farbe ist browser-/OS-abhängig (`-webkit-focus-ring-color`) und kann auf Eriks System anders/dunkler
ausfallen. Da dieses Verhalten absichtlich auf nativen Browser-Fokus statt auf eigenes Styling setzt, wurde hier
**nichts geändert** — ein Override würde der eigenen Konvention des Pakets widersprechen und bei einem Paket-Update
vermutlich wieder verschwinden. Falls der native Fokusring bei Erik tatsächlich als störend schwarz erscheint, bitte
Rückmeldung mit Browser/OS, dann gezielt nachschauen (ggf. als eigener, kleiner Punkt hier ergänzen, statt in P14 zu
verstecken).

Zusätzlich aufgefallen: Die englische Fehlermeldung "The valid from field is required" im mitgeschickten Screenshot
kommt **nicht** von Flux, sondern von Laravels eigener Validierung — `.env` dieser lokalen Entwicklungsumgebung setzt
`APP_LOCALE=en`/`APP_FALLBACK_LOCALE=en` (`config/app.php` fällt sonst auf `env('APP_LOCALE', 'en')` zurück), obwohl
`lang/de/` im Repo existiert. `.env` ist lokal/maschinenspezifisch und nicht Teil des Repos — falls dieses
Entwicklungssystem wie erwartet auf Deutsch laufen soll, `APP_LOCALE=de` und `APP_FALLBACK_LOCALE=de` lokal setzen.

## "Außer Konkurrenz" (AK) bei Meldungen setzbar machen

**Seit:** `feature/admin-ui-header-pattern` (20.09.2026), Wunsch Erik.

**Was fehlt:** Bei Meldungen (Einzel **und** Staffel) soll ein Kennzeichen "außer Konkurrenz" (AK) setzbar sein.

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

**Was fehlt / zu klären:** Einzeln existiert schon einiges — `meets/show` hat "Ergebnis erfassen"
(`meets.results.create`), `results/show` hat jetzt Bearbeiten/Löschen, und es gibt `results/index` (global,
nach Meet filterbar). Gewünscht ist aber eine **auf eine ausgewählte Veranstaltung fokussierte** Möglichkeit,
Ergebnisse **manuell zu erfassen und zu löschen** — vermutlich eine meet-gebundene Ergebnis-Sammelansicht (alle
Ergebnisse des Meets auf einen Blick, mit Anlegen/Löschen), statt des globalen `results/index` mit Filter.

**Warum zurückgestellt:** Überschneidet sich teils mit der bereits umgesetzten Meldungs-Übersicht (meet-weite
"Alle Meldungen" + das wettkampfübergreifende Meldungen-Cockpit) — die betrifft aber **Meldungen**, nicht
**Ergebnisse**; zu klären, ob das eine gemeinsame Meet-Detail-Arbeitsfläche (Meldungen + Ergebnisse) werden soll
oder zwei getrennte Ansichten.

**Wer entscheidet:** Erik — konkret was heute fehlt (nur ein schnellerer Zugang zum vorhandenen Erfassen/Löschen,
oder eine echte neue Sammelansicht pro Meet?) und ob Ergebnis- und Meldungsverwaltung zusammengelegt werden.

**Zum Schließen nötig:** Nach Klärung: ggf. neue meet-gebundene Ergebnis-Übersicht (Liste aller `Result` eines Meets
mit Inline-Löschen + "Ergebnis erfassen"), verlinkt von `meets/show`.

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

**Zum Schließen nötig:** Nach Erhalt der Datei: Quellschema sichten, Athleten-Migration (en) für neue Felder,
Import-Weg festlegen (CSV/XLSX-Zwischenschritt vs. direkter Reader), Import-Service mit Matching/Update-Logik,
Vorschau/Bestätigung analog Rekord-Import.

## Vereins-Rollen / Berechtigungen (was Vereins-User sehen und dürfen)

**Seit:** `feature/admin-ui-header-pattern` (20.09.2026), Wunsch Erik.

**Was fehlt:** Ein klar definiertes Rollen-/Berechtigungsmodell für **Vereins-User** (Nicht-Admins). Heute
unterscheidet die App im Wesentlichen `is_admin` vs. Vereins-User mit `club_id`; die genauen Rechte sind über
einzelne `@if`/Policy-Checks verstreut, nicht als zusammenhängende Rolle definiert.

**Entschieden — Vereins-User sollen, dürfen (Erik, 20.09.2026):**

- **Eigene Meldungen erfassen/bearbeiten** (Einzel + Staffel des eigenen Vereins, nur bis Meldeschluss).
- **Eigene Athleten pflegen** (Athleten des eigenen Vereins anlegen/bearbeiten).
- **Eigene Ergebnisse einsehen** (Ergebnisse der eigenen Athleten ansehen, nicht bearbeiten).
- **Vereinsstammdaten bearbeiten** (eigene Vereinsdaten wie Name/Kontakt pflegen).

**Warum zurückgestellt:** Querschnitts-Feature über viele Controller/Policies/Views. Offene Detailfragen: Reicht die
bestehende `club_id`-Bindung als "Rolle", oder braucht es echte Rollen (mehrere Rollentypen, evtl. mehrere User pro
Verein mit unterschiedlichen Rechten)? Wie strikt ist "nur eigene" überall durchzusetzen (Policies für `Athlete`,
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
**detailliert, auf diese Veranstaltung gefiltert** anzeigen. Beispiel: Klick auf "Ergebnisse" → Liste **aller**
Ergebnisse dieser Veranstaltung. Der **Zurück-Button** der Detailansicht soll dann wieder **auf diese
`meets/show`-Seite** zurückführen (nicht auf den Index).

**Warum zurückgestellt / Überschneidungen:** Teilweise existieren Zielansichten schon, teils nicht:

- **Ergebnisse:** `results/index` ist bereits per `?meet_id=` filterbar — hier reicht ggf. ein Link + der
  kontextsensitive Rücksprung. Überschneidet sich mit "Ergebnisse einer Veranstaltung manuell erfassen & löschen".
- **Einzel-/Staffelmeldungen:** eine **meet-weite** (vereinsübergreifende) Meldungsliste gibt es inzwischen ("Alle
  Meldungen", `meets.entries-overview`, Einzel + Staffel); der Kachel-Klick würde dorthin verlinken.
  Wettkampfübergreifend zusätzlich das Meldungen-Cockpit (`entries.index`).
- **Disziplinen:** stehen bereits als Tabelle auf derselben Seite — Klick könnte nur zum Abschnitt scrollen (Anker)
  statt eine eigene Seite zu öffnen.
- **Teilnehmer / Clubs:** dafür gibt es noch keine meet-gebundene Detailliste.

Der geforderte **Rücksprung auf `meets/show`** hängt zudem am allgemeinen Punkt "'Zurück'-Buttons kontextsensitiv"
(oben) — hier konkret: Die Detailseite muss sich merken, dass sie von `meets/show` kam.

**Wer entscheidet:** Erik — welche der sechs Kacheln wirklich eine eigene Detailansicht bekommen (vs. Anker/kein
Link), und ob das zusammen mit den bestehenden Punkten (meet-weite Meldeliste / Ergebnisse je Meet) in **einer**
Meet-Detail-Arbeitsfläche gelöst wird.

**Zum Schließen nötig:** Je Kachel entscheiden (eigene gefilterte Detailseite vs. Anker), Kacheln als Links
gestalten, Zielansichten (soweit fehlend) bauen bzw. bestehende meet-filtern, und den Zurück-Button der
Zielansichten auf `meets/show` zurückführen (Session-Rücksprung-URL wie bei `athletes.list_url`).
