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
Muster in CLAUDE.md dokumentiert) und `feature/relay-names` ("Staffelnamen / -bezeichnung" → optionales Feld
`relay_entries.name`, sonst Vereinsname + laufende Nummer nur für mehrere unbenannte Staffeln im selben Bewerb;
`App\Support\RelayNames` für App-Listen, PDF-Meldelisten und LENEX-`RELAY@name`, dokumentiert in
`specs/club-entries.md` "Staffelnamen") und `feature/meet-session-dates` ("Meldeliste nach Bewerben:
Abschnitt-Datum" → neue Tabelle `meet_sessions` mit Datum + Startzeit je Abschnitt, pflegbar über "Abschnitte
bearbeiten" auf `meets/show` und aus dem LENEX-Import; genutzt von der Meldeliste nach Bewerben und dem LENEX-Export,
der vorher jedem Abschnitt den Veranstaltungsbeginn gab; dokumentiert in `data-model.md`) und `feature/entry-fees`
("Meldegelder" → Gebühren nach LENEX je Veranstaltung/Abschnitt/Bewerb, Pflege-Seite, LENEX-Import/-Export,
Abrechnung je Verein online + PDF; `TEAM` noch nicht berechnet, siehe unten; dokumentiert in
`specs/club-entries.md` "Meldegelder") und `feature/entries-reopen` ("Meldeschluss: nach Ablauf kontrolliert
wiedereröffnen" → Admin öffnet befristet für alle Vereine (24 h, 48 h oder frei gewählter Zeitpunkt, protokolliert
wer/wann), Admin-Override bleibt; nach Meldeschluss neu angelegte Meldungen werden als Nachmeldung gekennzeichnet und
kosten zusätzlich `LATEENTRY.INDIVIDUAL` / `LATEENTRY.RELAY`; dokumentiert in `specs/club-entries.md` "Meldeschluss
und Nachmeldungen") und `feature/exhibition-entries` ("Außer Konkurrenz (AK)" → Admin und Verein
setzen AK bei Einzel- (`status EXH`) und Staffelmeldungen (`is_exhibition`), LENEX-Export, Vorbelegung bei der
manuellen Ergebniserfassung; AK-Rekorde aller Typen werden als ausstehend angelegt und vom Verband bestätigt —
`SwimRecord::approve()` löst dabei den Vorgänger ab; dokumentiert in `specs/club-entries.md` und `specs/records.md`) —
die
zugehörigen Open Points unten wurden entfernt. Behoben in `fix/lenex-export-clubs`: Der LENEX-Export von Meldungen
und Ergebnissen enthielt nur die Struktur, weil die Vereine nur aus `meet_club` kamen; sie werden jetzt aus Meldungen
und Ergebnissen abgeleitet (dokumentiert in `specs/lenex-import-export.md`). Umgesetzt in
`feature/meet-results-overview`:
Ergebnis-Sammelansicht je Veranstaltung mit Filtern, Erfassen ("Speichern und nächstes", Sportklasse aus dem Athleten,
automatische Punkte), Löschen einzeln oder je Disziplin und Ergebnisliste als PDF; Ergebnis-Routen jetzt nur Admin
(dokumentiert in `specs/meet-results.md`). Umgesetzt in `feature/relay-results`: Staffelergebnisse Phase 1 (Datenmodell, LENEX-Import,
Erfassung, Rekorde, PDF, Statistik). Umgesetzt in `feature/scoring-groups`:
Wertungsgruppen je Bewerb (Open Point "Meetstruktur / Wertungsgruppen", LENEX-Export mit korrekter Wertung),
dokumentiert in `specs/scoring-groups.md`. Umgesetzt in `feature/lenex-result-matching` (PR #37),
`feature/lenex-import-review` (PR #38) und `feature/relay-results-phase2` (PR #39): Abgleich mit vorhandenen
Ergebnissen beim Nachimport (Variante b), Klärungsseite mit Auswahl bestehender Vereine/Athleten, Rahmenbewerbe,
Staffeln Phase 2 (LENEX-Export, Import der Staffelmeldungen, ÖBSV-Punkte, öffentliche Seite) — vormals Punkt 5
"Staffelergebnisse Phase 2 + erneuter LENEX-Import von Altbeständen"; den Nachimport der alten Dateien macht Erik
selbst. Übrig: "Basiszeiten der Herrenstaffeln S14 prüfen" unten.

**Sicherheit — erledigt** (`fix/admin-route-guard`, 06.10.2026): LENEX-Import und alle weiteren Schreib-Routen nur
für Admins, Selbstregistrierung abgeschaltet, Routen-Audit als Test — siehe `docs/access-control.md`.

**Flash-Meldungen — erledigt** (`feature/flash-messages`, 06.10.2026): zentrale Komponente `x-flash` im Layout,
Einzel-Blöcke entfernt, Fehler ohne Feldbezug als `session('error')` — siehe `CLAUDE.md`.

**Post-Import Review-Liste — erledigt** (`feature/record-import-review`, 07.10.2026): Vereinskonflikte und
abweichende Geburtsdaten nach dem Rekordimport in einer gespeicherten Prüfliste, inkl. Bestandsprüfung — siehe
`docs/specs/records.md` "Prüfliste nach dem Import". Datenpflege offen (Erik): Verbände, die als Verein angelegt
sind (`ÖBSV`, `ÖSBV`, `SBSV`, `VVBSV`), auf Typ `VERBAND` umstellen, damit deren Rekorde nicht als Vereinskonflikt
zählen.

**Regionalrekord nach dem Verein im Ergebnis — erledigt** (`fix/regional-record-club`, 07.10.2026): Verband aus
dem Verein im Ergebnis, Kärnten-Codes `AUT.KLSV*` → `AUT.KBSV*` migriert, Altfälle als "Regionalrekord: falscher
Verband" in der Import Prüfliste.

**ÖBSV-Typen `AUT.IND` / `AUT.REL` — erledigt** (`fix/merge-national-record-types`, 07.10.2026): Import ordnet
sie `AUT`/`AUT.JR` zu; Bestand per `php artisan records:merge-national-types` (Probelauf + Bericht) zusammenführen —
siehe `docs/specs/records.md`. Ausführung auf den Daten: Erik nach Prüfung des Berichts.

**Staffel-Rekordprüfung nach Vereinszugehörigkeit am Starttag — erledigt** (`fix/relay-record-club`, 07.10.2026):
Einzelergebnisse im selben Wettkampf, sonst Vereins-History, sonst heutiger Verein (abweichend = ausstehend) — siehe
`docs/specs/records.md`. Auf Dev werden nach erneuter Rekordprüfung 7 Staffeln rekordfähig (R9, R16, R17, R25, R33,
R48, R49), 7 gemischte Staffeln bleiben es nicht.

**Staffelrekorde mit der Liste aus dem Sport Management Tool abgleichen — erledigt** (`feature/record-list-review`,
PR #47, 08.10.2026): Prüfliste "Abweichung zur Rekordliste" und "Staffelrekord ohne Verein", Staffelteam im
Rekordformular — siehe `docs/specs/records.md`. **Datenpflege (Erik):** die Datei `öbsv-relay-all-all-all-records.lxf`
erneut importieren und die Prüfliste abarbeiten (Probelauf: 5 Abweichungen, 19 Staffeln ohne Verein).

**Staffelfilter der Rekordlisten — erledigt** (`fix/relay-record-filters`, 08.10.2026): Staffelklassen je Nummer über
`S`/`SB`/`SM` zusammengefasst (Brust- und Lagenstaffeln auswählbar), Wertung "Mixed" als Filter (nur Staffeln) und als
eigener Badge statt "Damen".

**Prüfliste für Staffelrekorde — erledigt** (`fix/relay-review-checks`, 08.10.2026): "Regionalrekord: falscher
Verband" prüft bei Staffeln den Staffelverein, "Nationalität nicht AUT" die verknüpften Mitglieder (auf Dev 08.10.2026:
keine Fälle, nur 16 von 53 Staffelrekorden haben verknüpfte Mitglieder).

Kandidaten-Pool (unten je ausführlich beschrieben). Die "nach Aufwand"-Reihenfolge oben gilt nur grob — welcher
Punkt als Nächstes drankommt, entscheidet Erik:

1. "Statistik-Kacheln auf `meets/show` anklickbar machen (Drill-down mit Rücksprung)" unten
2. "Athleten-Import aus MSAccess-Datei + Athleten-Datenmodell erweitern" unten
3. "Vereins-Rollen / Berechtigungen (was Vereins-User sehen und dürfen)" unten
4. "Weitere Statistiken (sobald eine ordentliche Datenbasis vorhanden ist)" unten — Datenbasis wächst mit dem
   Nachimport der alten LENEX-Dateien (Einzel + Staffeln)
5. "Normen-Import: MQS/MET auch aus XML importieren (zusätzlich zum xlsx)" unten
6. "LENEX-Club-ID speichern (Vereine bei Splash-Rundreisen stabil wiedererkennen)" unten
7. "Mannschaftsgebühr (LENEX `TEAM`) berechnen" unten — braucht Mannschaften im Datenmodell
8. "Untereinanderstehende Tabellen einheitlich ausrichten" unten
9. "Basiszeiten der Herrenstaffeln S14 prüfen" unten (Datenprüfung)

Vor Start jedes Punkts aus Gruppe 2 zuerst die im jeweiligen Eintrag unter "Wer entscheidet" genannten Fragen mit
Erik klären, erst danach Branch anlegen/implementieren.

**Gruppe 3 — blockiert, keine Umsetzung möglich bis dahin, in dieser Reihenfolge im Blick behalten:**

1. "Barrierefreiheitserklärung — Konformitätsstand & Schlichtungsverfahren" unten — Konformitätsstand braucht eine
   echte Prüfung (aktiv einplanbar), Schlichtungsverfahren eine Vorstandsentscheidung
2. **"Impressum & Datenschutzerklärung — echter Inhalt statt Platzhalter" unten — ganz zuletzt**, da der Inhalt vom
   Vorstand noch offen ist

## LENEX-Club-ID speichern (Vereine bei Splash-Rundreisen stabil wiedererkennen)

**Seit:** `fix/club-lenex-club-id` (30.09.2026) — Weg B der Entscheidung zu `clubs.lenex_club_id`.

**Ausgangslage:** Die nie angelegte Spalte `clubs.lenex_club_id` wurde entfernt (toter Code in `Club::$fillable`,
`LenexExportService`, `LenexResolverService`-Docblock). Beim LENEX-Import wird die Club-ID aus Splash (`CLUB@clubid`,
LENEX-Standard wäre `id`) nur als Cache-Schlüssel innerhalb **eines** Imports verwendet, nicht gespeichert. Vereine
werden importübergreifend über `code`, dann über den normalisierten Namen (jeweils + Nation) wiedererkannt.

**Was fehlen könnte:** Eine gespeicherte LENEX-Club-ID als zusätzliche, stabile Matching-Stufe — sinnvoll, falls Vereine
ohne (oder mit wechselndem) `code` und mit abweichenden Namensschreibweisen importiert werden und dadurch doppelt
angelegt
oder falsch zugeordnet werden. Optional auch Export dieser ID, damit Splash beim Re-Import dieselben Vereine erkennt.

**Warum zurückgestellt:** Neues Feature statt Bugfix; nur nötig, wenn das Matching über `code`/Name in der Praxis nicht
reicht.

**Wer entscheidet:** Erik — ob es beim Import tatsächlich Fehlzuordnungen/Dubletten gibt, und ob der Export die ID für
Splash-Rundreisen mitgeben soll (`clubid` ist kein LENEX-3-Attribut an `CLUB`, nur Splash-spezifisch).

**Zum Schließen nötig:** Migration `clubs.lenex_club_id` (nullable, ggf. unique je Nation), beim Import befüllen
(`LenexResolverService::resolveClub()` + `createClub()`), als Matching-Stufe zwischen `code` und Name einbauen,
optional im Export als `clubid` ausgeben; Tests für Matching und Export.

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

## Basiszeiten der Herrenstaffeln S14 prüfen

**Seit:** `feature/relay-results-phase2` (PR #39, 06.10.2026) — ÖBSV-Punkte für Staffeln.

**Befund:** Damen- und Mixed-Staffeln stimmen mit den Punkten aus den Splash-Dateien exakt überein. Bei Herrenstaffeln
S14 rechnet Splash mit anderen Basiszeiten als unsere Basiswert-Tabelle (Version 2020-2027, Kurzbahn): 4x50 m Freistil
1:47,44 statt 1:42,62, 4x100 m Freistil 3:52,80 statt 3:50,49 (aus den Datei-Punkten zurückgerechnet). Eine
Neuberechnung ("ÖBSV-Punkte berechnen") senkt die Punkte der Herrenstaffeln daher um rund 3–13 % gegenüber der Datei.

**Wer entscheidet:** Erik — welche Werte stimmen (ÖBSV-Basiswerttabelle prüfen).

**Zum Schließen nötig:** Ggf. die Werte in der Basiswerte-Verwaltung korrigieren; sonst als bekannte Abweichung von
Splash schließen. Details in `specs/meet-results.md` "Staffelpunkte".

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

## Untereinanderstehende Tabellen einheitlich ausrichten

**Seit:** Rückmeldung Erik (06.10.2026) zu den Abschnitts-Tabellen auf `meets/show`; dort und in der
Ergebnis-Sammelansicht (`meets/results-overview`, inkl. Staffeltabelle) umgesetzt in
`feature/lenex-nation-filter`.

**Ausgangslage:** Wo mehrere Tabellen gleicher Struktur untereinander stehen (je Abschnitt, Bewerb, Wertungsgruppe,
Klasse ...), richtet sich jede Tabelle nach ihrem eigenen Inhalt — die Spalten springen von Tabelle zu Tabelle.

**Muster (wie `meets/show.blade.php` "Disziplinen", `cups/overall-ranking.blade.php`,
`public/regulations/index.blade.php`):** `table-fixed` (bei Flux bereits gesetzt) plus `w-full` und feste Breiten je
Spalte außer einer, die den Rest nimmt (`<flux:table.column class="w-24">`); lange Inhalte in festen Spalten mit
`truncate` + `title`. Für die horizontale Scroll-Breite auf schmalen Bildschirmen eine `min-w-*` am Table.

**Kandidaten** (Tabellen in Schleifen, je Datei prüfen, ob sie wirklich untereinander stehen):

- Admin: `meets/entries-overview`, `championships/selection`,
  `livewire/admin/championship-qualification-table`, `livewire/cup-club-ranking`, `qualifying-time-lists/show`,
  `qualifying-time-lists/qualifications`, `qualifying-time-lists/form`, `statistics/partials/sections`
- Öffentlich (Tailkit, nicht Flux): `public/meets/results`, `public/annual-best/index`, `public/base-times/index`,
  `public/cup-ranking/index`, `public/qualifying-times/index`, `public/records/index`
- PDF (dompdf, eigene `<colgroup>`/Breiten): `pdf/result-list`, `pdf/entry-lists/*`, `pdf/championship-*`,
  `pdf/cup-club-ranking`, `pdf/public-records`, `pdf/qualifying-times`, `pdf/wps-*`, `pdf/year-comparison`

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

## Mannschaftsgebühr (LENEX `TEAM`) berechnen

**Seit:** `feature/entry-fees` (01.10.2026), Entscheidung Erik.

**Ausgangslage:** Die Gebühr vom Typ `TEAM` (je Mannschaft, für Mannschaftsbewerbe — ein Verein kann mehrere
Mannschaften an den Start schicken) wird in `meet_fees` gespeichert, auf der Seite "Meldegelder" gepflegt und per
LENEX importiert/exportiert, aber **nicht berechnet**.

**Warum zurückgestellt:** Das Datenmodell kennt keine Mannschaften (außer Staffeln). Ohne Mannschafts-Zuordnung lässt
sich nicht zählen, wie viele Mannschaften ein Verein stellt.

**Wer entscheidet:** Erik — ob und wie Mannschaftsbewerbe abgebildet werden (eigene Mannschafts-Meldung je Verein mit
Mitgliedern? Bezug zu Staffeln?).

**Zum Schließen nötig:** Mannschaften im Datenmodell (Meldung je Mannschaft), danach im Meldegeld-Service je
Mannschaft die `TEAM`-Gebühr berechnen (auf Veranstaltungs- bzw. Abschnittsebene wie `CLUB`).

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

- **Eigene Meldungen erfassen/bearbeiten** (Einzel + Staffel des eigenen Vereins, nur bis Meldeschluss oder im
  vom Admin geöffneten Nachmelde-Fenster).
- **Eigene Athleten pflegen** (Athleten des eigenen Vereins anlegen/bearbeiten).
- **Eigene Ergebnisse einsehen** (Ergebnisse der eigenen Athleten ansehen, nicht bearbeiten).
- **Vereinsstammdaten bearbeiten** (eigene Vereinsdaten wie Name/Kontakt pflegen).

**Warum zurückgestellt:** Querschnitts-Feature über viele Controller/Policies/Views. Offene Detailfragen: Reicht die
bestehende `club_id`-Bindung als "Rolle", oder braucht es echte Rollen (mehrere Rollentypen, evtl. mehrere User pro
Verein mit unterschiedlichen Rechten)? Wie strikt ist "nur eigene" überall durchzusetzen (Policies für `Athlete`,
`Entry`, `RelayEntry`, `Result`, `Club`)? Sichtbarkeit im Menü je Rolle (viele Admin-Menüpunkte ausblenden).

**Wer entscheidet:** Erik — ob ein echtes Mehr-Rollen-Modell nötig ist oder die vier Fähigkeiten oben als fester
Vereins-User-Satz reichen; Umgang mit Meldeschluss-Sperre; ob mehrere User je Verein.

**Stand 06.10.2026:** Bis dahin gilt die Matrix in `docs/access-control.md` — Vereinsnutzer lesen, schreiben nur
eigene Meldungen; Athleten- und Vereinspflege ist vorerst Admin-Sache und soll mit diesem Punkt auf den eigenen
Verein beschränkt wieder geöffnet werden.

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

- **Ergebnisse:** erledigt in `feature/meet-results-overview` — die Kachel verlinkt (für Admins) auf die
  Ergebnis-Sammelansicht der Veranstaltung, deren Zurück-Button auf `meets/show` führt (`specs/meet-results.md`).
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
