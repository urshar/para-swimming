# Spec: Öffentliches Frontend — Module und Bausteine

Ergänzung zu [public-frontend.md](public-frontend.md). Dort steht das **Was und Warum**, hier das **Woraus**: die
konkreten Klassen, Views und Tests je Phase.

Die Aufstellung ist bewusst grob — sie legt Zuschnitt und Verantwortlichkeiten fest, nicht die Signaturen. Details
entstehen in der Planungsrunde der jeweiligen Phase.

Durchgängig gilt [architecture.md](../architecture.md): dünne Controller, Logik in `final readonly class`-Services,
Wertobjekte statt assoziativer Arrays, nichts wird persistiert, was sich berechnen lässt.

---

## Phase 1 — Fundament — **abgeschlossen**

Ohne diese Phase kann keine andere beginnen.

| Baustein                                             | Art         | Zweck                                                                                               |
|------------------------------------------------------|-------------|-----------------------------------------------------------------------------------------------------|
| `documents`-Migration                                | Migration   | polymorphe Dokumententabelle (§4.1)                                                                 |
| `meets`-Ergänzung                                    | Migration   | `livetiming_url`, `is_published`                                                                    |
| `App\Models\Document`                                | Model       | `documentable()`, Scopes `public()`, `published()`, `forLocale()`                                   |
| `App\Http\Middleware\SetLocale`                      | Middleware  | Sprache aus Präfix, Cookie, Browser                                                                 |
| `routes/public.php`                                  | Routen      | eigene Datei, `web.php` bleibt unberührt                                                            |
| `resources/views/layouts/public.blade.php`           | Layout      | Grundgerüst (siehe Ergebnis; seit Klärung der Tailkit-Lizenz mit echtem Tailkit-Markup nachgezogen) |
| `resources/css/public.css`, `resources/js/public.js` | Assets      | eigener Vite-Entry                                                                                  |
| `resources/js/theme.js`                              | JS          | Hell/Dunkel/System, `localStorage`, Inline-Init                                                     |
| `lang/de/public.php`, `lang/en/public.php`           | Übersetzung | Grundwortschatz                                                                                     |
| `App\Http\Controllers\Public\HomeController`         | Controller  | Startseite (Grundgerüst)                                                                            |

**Vorab:** Tailkit ist eine Snippet-Quelle ohne Paketinstallation (§3.1.1) — ein Konfigurationskonflikt ist
ausgeschlossen.

**Ergebnis:** Anders als hier ursprünglich vorgesehen, entstand das Phase-1-Layout zunächst nicht aus den sechs
Grundbausteinen aus §3.1.3, sondern als schlichtes, von Hand geschriebenes Gerüst ohne jedes Tailkit-Markup — committet,
damit Routing und Tests auf jedem Check-out liefen, unabhängig von lokal zugelieferten Snippets und unabhängig von der
zu diesem Zeitpunkt noch offenen Lizenzfrage. Mit deren Klärung (§3.1.1) wurde das Layout anschließend mit den echten
Tailkit-Bausteinen (Kopfzeile, Fußzeile) nachgezogen und committet.

**Tests** (`--group=public-p1`, alle grün): Sprachweiterleitung, Cookie-Vorrang, `hreflang`, Document-Scopes, `/`
führt nicht mehr in den Login.

---

## Phase 2 — Veranstaltungen — **abgeschlossen**

| Baustein                                  | Art        | Zweck                                                           |
|-------------------------------------------|------------|-----------------------------------------------------------------|
| `Public\MeetController`                   | Controller | Liste, Archiv und Detail                                        |
| `App\Services\Public\PublicMeetService`   | Service    | kommend/vergangen (je 10), Archiv nach Jahr, nur `is_published` |
| `App\Support\MeetDocumentGroup`           | Wertobjekt | Dokumente je Kategorie, Sprachauflösung (§4.1)                  |
| `Public\DocumentDownloadController`       | Controller | prüft `is_public` und `published_at`, liefert aus `Storage`     |
| Views `public/meets/{index,archive,show}` | Blade      | Liste, Archiv, Detail mit Dokumenten, Livetiming, Meldeschluss  |

Die Sprachauflösung ("zeige die passende Fassung, verlinke die andere") gehört in das Wertobjekt, nicht in die View —
sie wird in Phase 8 für Regelmente erneut gebraucht.

**Ergebnis:** Die Liste zeigt die nächsten und die letzten 10 veröffentlichten Veranstaltungen (statt eines reinen
Jahresfilters); eine eigene, nach Jahr gruppierte Archiv-Seite deckt den vollständigen Rückblick ab (Entscheidung aus
der Phase-2-Planungsrunde). Kopf- und Fußzeile wurden im Zug dieser Phase mit den echten Tailkit-Bausteinen nachgezogen
(siehe Ergebnisvermerk Phase 1).

**Tests** (`public-p2`, alle grün): unveröffentlichte Meets unsichtbar (Liste, Archiv, Detail), Dokument ohne
`published_at` nicht abrufbar, Dokument eines unveröffentlichten Meets nicht abrufbar, direkter Pfadzugriff greift
nicht, Sprachauflösung in allen drei Fällen (nur de / nur en / neutral), `PublicMeetService` (kommend/vergangen/Archiv).
Zusätzlich manuell im Browser gegen `/de/veranstaltungen`, `/veranstaltungen/archiv` und Detailseiten (de/en) geprüft.

---

## Phase 3 — Adminbereich Dokumente — **abgeschlossen**

| Baustein                                 | Art        | Zweck                                                                         |
|------------------------------------------|------------|-------------------------------------------------------------------------------|
| `Admin\DocumentController`               | Controller | CRUD                                                                          |
| `App\Services\DocumentService`           | Service    | Upload, Ablage, Reihenfolge, Löschung samt Datei                              |
| `App\Http\Requests\StoreDocumentRequest` | Request    | Validierung: Typ, Größe, Kategorie/Sprache-Kombination                        |
| Views `admin/documents/*`                | Blade      | **Flux**, wie der übrige interne Bereich                                      |
| `App\Http\Controllers\MeetController`    | Controller | ergänzt: Felder `is_published`, `livetiming_url` im bestehenden Meet-Formular |

Beim Upload eines LENEX zur Kategorie `INVITATION`: Hinweistext gemäß §4.3, `is_public` bleibt `false`.

`is_published` und `livetiming_url` existieren seit Phase 1 (Migration `add_public_frontend_columns_to_meets_table`)
nur als Spalten, ohne Bedienoberfläche — bislang ist kein einziges Bestands-Meet veröffentlicht (Default `false`
gemäß Migrations-Kommentar), die öffentliche Veranstaltungsliste bleibt bis dahin leer. §4.4 verortet den
Sichtbarkeitsschalter im internen Adminbereich; da beide Felder am selben, bereits bestehenden Meet-Formular hängen,
wird der Schalter zusammen mit `livetiming_url` dort ergänzt statt in einem eigenen Baustein.

**Ergebnis:** `App\Http\Requests\StoreDocumentRequest` wurde nicht angelegt — im gesamten restlichen Projekt läuft
Validierung durchgängig inline über `$request->validate()`, keine einzige `FormRequest`-Klasse existiert; diesem Muster
gefolgt, statt es hier neu einzuführen. Zwei Einstiege auf denselben Baustein statt eines einzelnen: Dokumente mit
Veranstaltungsbezug über `admin/meets/{meet}/documents`, Regelmente & Formulare ohne Bezug über `admin/documents`
(`documentable = null`) — letzteres bereits jetzt, damit für Phase 8 Daten vorhanden sind, die selbst keinen eigenen
Adminbaustein bekommt. Zusätzlich ein "Sprachvariante zu"-Feld im Formular: `category`+`sort_order` ist laut §4.1 der
Paarungsschlüssel für die Sprachauflösung, von Hand zwei übereinstimmende `sort_order`-Werte zu pflegen wäre
fehleranfällig — die Auswahl einer bestehenden Fassung übernimmt deren Wert automatisch, serverseitig auf dieselbe
Zuordnung eingeschränkt.

Nebenbei zwei vorbestehende, unabhängig von dieser Phase gefundene Fehler behoben: `is_published`/`livetiming_url`
ließen sich vor der Admin-Prüfung bereits über einen rohen Request setzen (dieselbe Lücke, die `cup_id`/
`qualifying_time_list_id` schon abgesichert hatten, jetzt auch hier); `entries_deadline` fehlte in
`MeetController::validateMeet()`s Regelliste und wurde dadurch nie gespeichert, obwohl das Formularfeld längst
existierte.

**Tests** (`public-p3`, alle grün): nur Admin, Datei landet nicht unter `public/`, Löschung entfernt die Datei,
Sprachvarianten-Feld setzt `sort_order` korrekt (inkl. Schutz gegen fremde Meets), Datei-Ersetzen/-Behalten beim
Bearbeiten, `is_published`/`livetiming_url` im Meet-Formular editierbar und wirken sich auf die öffentliche Liste aus,
Nicht-Admins können beide Felder nicht über einen rohen Request setzen, `entries_deadline`-Regression.

---

## Phase 4 — Ergebnisse — **abgeschlossen**

| Baustein                                  | Art        | Zweck                                   |
|-------------------------------------------|------------|-----------------------------------------|
| `Public\MeetResultController`             | Controller | **nur** je Meet, kein Athleten-Endpunkt |
| `App\Services\Public\PublicResultService` | Service    | Ergebnisse je Bewerb und Klasse         |
| View `public/meets/results`               | Blade      | Namen unverlinkt, `noindex`             |

Die engste Phase der Spec. `robots.txt` und Meta-Tags gehören hierher, nicht in eine spätere Aufräumphase.

**Ergebnis:** Relais-Ergebnisse existieren im Datenmodell gar nicht (`results.athlete_id` ist eine Pflicht-FK, es gibt
kein `RelayResult`-Modell — `RelayEntry`/`RelayEntryMember`/`RelayTeamMember` decken nur Meldungen ab), Phase 4 deckt
damit automatisch nur Einzelergebnisse ab, keine bewusste Einschränkung. Gruppierung nach Bewerb dann Sportklasse (das
Ergebnis-Feld `sport_class`, nicht die Athlet-Stammdaten — kann laut LENEX abweichen), Sortierung über einen
zusammengesetzten `sprintf()`-Schlüssel (CLAUDE.md-Konvention) statt `sortBy()` mit Closure-Array: gültige Zeiten nach
Platz/Zeit, DNS/DNF/DSQ/SICK/WDR ans Ende. Die Zeit-/Status-Spalte spiegelt `Result::getFormattedSwimTimeAttribute()`:
Zeit hat Vorrang vor dem Status, EXH-Ergebnisse haben trotzdem eine reelle Zeit und bleiben damit mit dieser sichtbar,
statt hinter dem Status zu verschwinden —, nur wenn tatsächlich keine Zeit erfasst ist, zeigt die Spalte den
(lokalisierten) Status. Punkte-/WPS-Punkte-Spalten werden nur eingeblendet, wenn für die jeweilige Klasse tatsächlich
Werte vorliegen — das Projekt kennt beide Punktesysteme parallel, fest auf eines zu verdrahten wäre falsch. Rekorde als
ausgeschriebene Bezeichnung statt Kürzel-Badge mit `title=`: `title=` wird von Screenreadern nicht zuverlässig
vorgelesen (derselbe Fund wie beim Flaggen-Fix in Phase 2, siehe `components/flag.blade.php`). `@yield('robots')` als
neuer Hook in `layouts/public.blade.php`, von der Ergebnis-View über `@section('robots', 'noindex, nofollow')`
gesetzt; `robots.txt` bekam zusätzlich `Disallow: /*/veranstaltungen/*/ergebnisse`. Link "Ergebnisse ansehen" auf der
Meet-Detailseite nur, wenn für dieses Meet überhaupt Ergebnisse vorliegen (`PublicResultService::hasResults()`).

**Tests** (`public-p4`, alle grün): unveröffentlichte Meets → 404, `noindex`-Meta vorhanden, `robots.txt` sperrt die
Route, Ergebnislink auf der Detailseite erscheint nur mit vorhandenen Ergebnissen, Name und Verein stehen in keinem
`<a>`-Tag, Gruppierung nach Bewerb/Klasse, Sortierung (Platz, DNS/DNF/DSQ ans Ende, EXH bleibt sichtbar), Punkte-Spalte
nur bei vorhandenen Werten, EXH zeigt die reelle Zeit statt des Status, fehlende Zeit zeigt den lokalisierten Status.

---

## Phase 5 — Rekorde — **abgeschlossen**

| Baustein                                  | Art        | Zweck                                                                   |
|-------------------------------------------|------------|-------------------------------------------------------------------------|
| `Public\RecordController`                 | Controller | Übersicht mit Filtern                                                   |
| `App\Support\PublicRecordFilter`          | Wertobjekt | Klasse, Geschlecht, Bahn, Alter, Ebene — geteilt von Ansicht und Export |
| `App\Services\Public\PublicRecordService` | Service    | Auswertung, nutzt `record_type` (`AUT`, `AUT.JR`, `AUT.<LV>`)           |
| `Public\RecordExportController`           | Controller | LENEX und PDF                                                           |

Das geteilte Filter-Wertobjekt folgt dem Muster von `QualificationOverviewFilter`: Zweimal ausprogrammiert liefen
Bildschirm und PDF im Bestand bereits auseinander. Export nutzt `RecordLenexExportService` und `PdfExportService`
weiter, ohne die internen Statusfilter.

**Ergebnis:** Nur österreichische Rekorde (`AUT`, `AUT.JR`, `AUT.<LV>`, `AUT.<LV>.JR`) — international (WR/ER/OR) ist
außerhalb des öffentlichen Umfangs, §5.2 nennt dafür nur die AUT-Werte. Rekordebene (national/Landesverband) und
Altersklasse (offen/Jugend) sind zwei getrennte Filterachsen in `PublicRecordFilter`, die sich erst zu
`recordType()` zusammensetzen. Anders als der interne `RecordController` zeigt die öffentliche Liste nur
`record_status = APPROVED` — `PENDING` (Nationalität unklar), `INVALID` und `TARGETTIME` sind nicht öffentlich reif
(Planungsentscheidung, keine Vorgabe aus §5.2). Staffelrekorde sind enthalten, mit den Namen der Staffelmitglieder
(`RelayTeamMember`) genauso unverlinkt wie Einzelnamen (§2.3) — anders als Phase 4, wo Staffeln allein aus einer
Datenmodell-Lücke fehlten (kein `RelayResult`), existieren Staffel-`SwimRecord`s tatsächlich. Ungepaginiert: ein
Rekordbrett ist ein Nachschlagewerk, keine Feed-Liste (Planungsentscheidung Phase 5) — das weicht vom ursprünglich in
`docs/accessibility.md` vorgesehenen Bahnlänge-über-Bewerb-Matrix-Layout ab; die dortige Beispielangabe wurde
entsprechend korrigiert (siehe dort). Der Sportklassen-Filter ist ein `<select>` mit den in der gewählten Rekordebene
tatsächlich vorkommenden Klassen (`PublicRecordService::availableSportClasses()`) statt Freitext — und zeigt nur die
Klassifizierungsnummer ("9"), nicht den vollen `sport_class`-Code: `sport_class` trägt die Lage mit (S9/SB9/SM9 für
dieselbe Klassifizierung in Freistil/Brust/Lagen), Nutzer denken aber in "Klasse 9". Die Auswahl "9"
filtert serverseitig gegen alle drei Varianten dieser Nummer (`PublicRecordService::forFilter()`), nicht gegen einen
einzelnen `sport_class`-Wert — ursprünglich falsch gebaut (S/SB/SM als eigene Dropdown-Einträge) und in der Review-Runde
korrigiert.

Ursprünglich eine einzige flache Tabelle, sortiert nach Sportklasse zuerst und Schwimmart zuletzt (alphabetisch) — laut
Rückmeldung unübersichtlich, weil die fünf Lagen dadurch quer durch die Liste verstreut waren. Umgebaut auf
`PublicRecordService::groupByStroke()`: eine Tabelle je Schwimmart, in Verbandsreihenfolge Freistil → Rücken → Brust →
Schmetterling → Lagen (dieselbe Reihenfolge wie `QualifyingTimeListController::groupByStroke()`, hier dupliziert statt
geteilt — im Bestand existiert keine gemeinsame Stroke-Order-Stelle). Der Sortierschlüssel setzt Schwimmart vor Distanz
vor Sportklasse vor Geschlecht; `groupBy()` erhält die Eingabereihenfolge der Gruppen, sodass die Gruppenüberschriften
ohne zweite Sortierung in derselben Reihenfolge erscheinen. Die "Disziplin"-Spalte entfiel zu Gunsten einer
"Distanz"-Spalte — die Schwimmart steht jetzt in der Gruppenüberschrift, ihre Wiederholung in jeder Zeile war redundant.
PDF-Export zieht dieselbe Gruppierung (eigener `<h2>` + eigene `<table>` je Schwimmart, wie
`qualifying-times.blade.php`), damit Bildschirm und PDF gleich aussehen.

Der interne `RecordExportController` liefert entgegen der ursprünglichen Annahme in dieser Tabelle **nur** LENEX, kein
PDF — der PDF-Export existierte im gesamten Projekt noch nicht und wurde hier neu gebaut
(`resources/views/pdf/public-records.blade.php`, nach dem bestehenden Muster wie `wps-ranking.blade.php`, aber — anders
als die rein internen PDF-Views — locale-bewusst über `public.records.*`). Der LENEX-Export übernimmt
`RecordLenexExportService::build()` unverändert und kennt dadurch keine Sportklassen-Eingrenzung (der Service filtert
nur nach `record_type`, Bahn und Geschlecht) — eine LENEX-Rekorddatei ist als vollständige Bestenliste einer Ebene fürs
Meldeprogramm gedacht, keine Ein-Klassen-Auswahl, die Einschränkung ist also unschädlich. Der PDF-Export nutzt
stattdessen `PublicRecordService::forFilter()` direkt und respektiert damit den vollständigen Filter, einschließlich
Sportklasse.

**Tests** (`public-p5`, alle grün): Regionalfilter je Landesverband, Jugend-Umschalter wechselt `record_type`, nur
`APPROVED`+aktuell sichtbar (PENDING/INVALID/History/TARGETTIME nicht), Staffelrekorde mit Mitgliedsnamen, Namen
generell nie in `<a>`-Tags, `PublicRecordService::availableSportClasses()` grenzt auf den record_type ein,
Sportklassen-Filter grenzt serverseitig ein (S/SB/SM einer Nummer gemeinsam), `groupByStroke()` liefert die
Verbandsreihenfolge Frei/Rücken/Brust/Fly/Lagen, Schwimmart-Überschriften erscheinen in dieser Reihenfolge auf der
Seite, LENEX-Export strukturell gültig (Well-formed-XML,
`RECORDLIST[@type]`), LENEX-Export bewusst ohne Sportklassen-Eingrenzung, PDF-Export liefert `application/pdf` und
respektiert die Sportklasse, `PublicRecordFilter::fromQuery()` fällt bei unbekannten Werten auf den Standard zurück,
Vereins-Kurzname wird bevorzugt angezeigt, Ort erscheint mit Flagge, nur wenn `meet_nation_id` gesetzt ist (sonst reiner
Text). Zusätzlich `tests/Unit/RecordCheckerServiceTest.php`: `meet_nation_id` wird vom `Meet::nation_id` des
zugrundeliegenden Ergebnisses übernommen.

Nachträglich zwei Rückmeldungen aus der Review-Runde umgesetzt: Erstens zeigte `SwimRecord::record_club_name` bei
vorhandenem Kurznamen bisher trotzdem den vollen Vereinsnamen (`name` vor `short_name`) — umgestellt auf
`Club::display_name` (`short_name ?? name`), dasselbe Muster wie schon bei den Ergebnissen in Phase 4
(`Result::club->display_name`). Zweitens fehlte eine "Ort"-Spalte.

Erster Anlauf dafür nutzte `SwimRecord::nation` als Flaggen-Nation — dieselbe Relation, die auch der bestehende
LENEX-Export für `MEETINFO[nation]` heranzieht. Beim Testen stellte sich heraus, dass das für jede einzige Zeile
dieselbe AUT-Flagge zeigte: `nation_id` trägt in diesem Bestand nicht das Austragungsland, sondern die Nation, für die
der Rekord zählt (`RecordImportService::import()` setzt sie hart auf `AUT`) — im öffentlichen, auf Österreich
beschränkten Rekordbrett zwangsläufig immer derselbe Wert. Auf Nutzerrückfrage neues Feld angelegt, statt die Flagge zu
entfernen: `swim_records.meet_nation_id` (Migration
`2026_08_22_100001_add_meet_nation_id_to_swim_records_table.php`, nullable FK auf `nations`,
`SwimRecord::meetNation()`), getrennt von `nation()`. Österreichische Rekorde werden regelmäßig im Ausland aufgestellt
(WM, EM, Paralympics) — ein Feld, das tatsächlich variiert, macht die Flagge erst sinnvoll.

Befüllung an zwei Stellen: `RecordCheckerService` (Rekorde aus im System erfassten `Result`/`Meet`) übernimmt
`$result->meet?->nation_id` direkt — zuverlässigste Quelle, da `Meet` bereits ein strukturiertes Gastgeberland trägt
(dieselbe Relation, die Phase 4 schon für die Flagge auf den Veranstaltungsseiten nutzt). `RecordImportService`
(LENEX-Import historischer Rekorde) liest zusätzlich das `MEETINFO@nation`-Attribut aus der LENEX-Datei. Manuell im
Adminbereich angelegte Rekorde (`RecordController::storeManual()`) bekommen ein drittes, optionales Auswahlfeld
"Austragungsland" im Formular (`records/form.blade.php`), analog zum bestehenden `nation_id`-Feld — ohne dieses bleiben
sie ohne Flagge, wie jeder Rekord ohne erfasstes `meet_nation_id` (kein Backfill für Altbestand).

Bildschirm zeigt `meet_city` mit vorangestellter Flagge (`<x-flag>`, wie schon bei den Veranstaltungsseiten), sofern
`meetNation` gesetzt ist — sonst bleibt der Ort als reiner Text stehen, keine Platzhalter-Flagge. Im PDF (dompdf, keine
externe Flag-Icons-CDN) steht der Nationscode stattdessen als Text in Klammern hinter dem Ort (`Ort (AUT)`), wie auch
sonst im internen Bereich Nationscodes textuell dargestellt werden (`records/show.blade.php`, dort ebenfalls um
`meetNation` ergänzt). Um die Tabelle dabei nicht zu überladen: keine zusätzliche Spaltenbreite verschwendet, die Zelle
bleibt kompakt (kleine Flagge + kurzer Ortsname, `whitespace-nowrap`), und der ohnehin vorhandene
`overflow-x-auto`-Container fängt schmale Viewports ab.

**Bewusst nicht gemacht:** `RecordLenexExportService` (LENEX-Export, `MEETINFO@nation`) weiterhin unverändert auf
`nation`/`athlete.nation` — dieselbe Ungenauigkeit besteht dort also fort. Der Export ist als vollständige,
maschinenlesbare Bestenliste fürs Meldeprogramm gedacht, keine menschliche Ortsanzeige; eine Umstellung dort würde einen
unabhängig getesteten, nicht auf Phase 5 beschränkten Service anfassen und war nicht Teil dieser Rückmeldung.

---

## Phase 6 — Punktetabelle und Rechner

| Baustein                              | Art        | Zweck                               |
|---------------------------------------|------------|-------------------------------------|
| `Public\BaseTimeTableController`      | Controller | Tabelle je Version und Lage         |
| `Public\PointCalculatorController`    | Controller | eigene Seite, kein Dialog           |
| `App\Services\PointConversionService` | Service    | Zeit → Punkte **und** Punkte → Zeit |
| `resources/js/point-calculator.js`    | JS         | Alpine, `Alpine.data()`             |

Die Rückrechnung (Schätzung, dann hundertstelweise Annäherung) existiert bereits sinngemäß in
`QualifyingTimeCalculationService`. Sie wird in den neuen Service gezogen und dort **einmal** implementiert; das
Richtzeiten-Modul nutzt sie danach mit. Zwei Fassungen derselben Iteration driften sonst auseinander.

**Tests** (`public-p6`): Hin- und Rückrechnung sind zueinander konsistent, Grenzfälle (fehlende Basiszeit, Punktzahl 0),
Richtzeiten-Modul weiterhin grün.

---

## Phase 7 — Ranglisten

| Baustein                          | Art        | Zweck                  |
|-----------------------------------|------------|------------------------|
| `Public\CupRankingController`     | Controller | Cup-Wertung je Jahr    |
| `Public\QualifyingTimeController` | Controller | ÖSTM-Startberechtigung |
| `Public\AnnualBestController`     | Controller | Jahresbestleistungen   |
| `App\Services\AnnualBestService`  | Service    | siehe unten            |

`AnnualBestService`: eine Zeile je Person — bester Einzelbewerb nach ÖBSV-Punkten im **Kalenderjahr**; getrennt nach
Geschlecht und Gruppen PI, VI, MI, HI, T21; **keine Staffeln**, **EXH ausgeschlossen**. Rein lesend.

Die Gruppenzuordnung (S01–S10, S11–S13, S14, S15, S21) darf nicht neu ausprogrammiert werden — vorhandene Logik aus dem
Cup-Modul bzw. `SportClassSorter` nutzen. Suchfelder filtern nur die geladene Tabelle (§2.3 Punkt 3).

**Tests** (`public-p7`): EXH bleibt draußen, Staffeln bleiben draußen, genau eine Zeile je Person, Jahresgrenzen,
Gruppenzuordnung.

---

## Phase 8 — Regelmente und Formulare

| Baustein                        | Art        | Zweck                                        |
|---------------------------------|------------|----------------------------------------------|
| `Public\RegulationController`   | Controller | gruppiert nach `category`                    |
| View `public/regulations/index` | Blade      | Titel, Format, Größe, Veröffentlichungsdatum |

Nutzt `documents` ohne `documentable` und die Sprachauflösung aus Phase 2.

**Tests** (`public-p8`): Gruppierung, Sortierung, nur veröffentlichte Dokumente.

---

## Phase 9 — Abschluss

| Baustein                                                                      | Art           |
|-------------------------------------------------------------------------------|---------------|
| Startseite ausbauen: nächste Veranstaltung, neue Rekorde, aktuelle Ergebnisse | View          |
| Englische Übersetzung vollständig                                             | `lang/en/*`   |
| `/de/barrierefreiheit`                                                        | View + Inhalt |
| Barrierefreiheits-Audit über alle Seiten                                      | Prüfung       |
| `robots.txt`, Sitemap, Meta-Tags                                              | Konfiguration |

Prüfumfang nach [accessibility.md](../accessibility.md) §Prüfung, einschließlich Screenreader-Durchsicht.

---

## Was bewusst nicht gebaut wird

| nicht enthalten                     | Grund                           |
|-------------------------------------|---------------------------------|
| Athletenprofile                     | §2                              |
| Personenübergreifende Ergebnissuche | §2                              |
| Livetiming eingebettet              | externer Dienst, eigener Status |
| Nachrichten/News-Modul              | nicht angefordert               |
| Öffentliches Meldewesen             | bleibt im internen Bereich      |
| FinSwim                             | entfällt gegenüber der Altseite |
