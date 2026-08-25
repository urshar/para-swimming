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
| `App\Support\DocumentLocaleGroup`         | Wertobjekt | Dokumente je Kategorie, Sprachauflösung (§4.1)                  |
| `Public\DocumentDownloadController`       | Controller | prüft `is_public` und `published_at`, liefert aus `Storage`     |
| Views `public/meets/{index,archive,show}` | Blade      | Liste, Archiv, Detail mit Dokumenten, Livetiming, Meldeschluss  |

Die Sprachauflösung ("zeige die passende Fassung, verlinke die andere") gehört in das Wertobjekt, nicht in die View —
sie wird in Phase 8 für Regelmente erneut gebraucht. Dafür in Phase 8 von `MeetDocumentGroup` auf
`DocumentLocaleGroup` umbenannt und um `forDocuments()` verallgemeinert (nimmt eine bereits gefilterte Collection statt
einer `Meet`-Beziehung entgegen); `forMeet()` bleibt als schmaler Wrapper bestehen, siehe dort.

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

## Phase 6 — Punktetabelle und Rechner — **abgeschlossen**

| Baustein                              | Art        | Zweck                               |
|---------------------------------------|------------|-------------------------------------|
| `Public\BaseTimeTableController`      | Controller | Tabelle je Version und Lage         |
| `Public\PointCalculatorController`    | Controller | eigene Seite, kein Dialog           |
| `App\Services\PointConversionService` | Service    | Zeit → Punkte **und** Punkte → Zeit |
| `resources/js/base-time-tabs.js`      | JS         | Reiternavigation, Alpine.data()     |
| `resources/js/point-calculator.js`    | JS         | Feld-Umschaltung, Alpine.data()     |

Die Rückrechnung (Schätzung, dann hundertstelweise Annäherung) existiert bereits sinngemäß in
`QualifyingTimeCalculationService`. Ursprünglich hier vorgesehen: in den neuen Service ziehen und dort **einmal**
implementieren, das Richtzeiten-Modul danach mitnutzen lassen. Auf Nutzerrückfrage bewusst **nicht** umgesetzt: Das
Richtzeiten-Modul rechnet weiterhin mit seiner eigenen, direkten Umkehrformel ohne Annäherungs-Iteration — bereits
ausgeliefertes, getestetes Verhalten eines phasen-fremden Moduls sollte nicht als Nebeneffekt dieser Phase kippen.
`PointConversionService::pointsToTime()` ist eine eigenständige, neue Implementierung derselben mathematischen Idee.

**Ergebnis:** `PointConversionService` ist bewusst von `WorldAquaticsPointsService` getrennt (nicht dessen
`resolvePoints()` wiederverwendet) — Letzteres ist an ein konkretes `Result`/`Meet` gebunden (Kurs kommt vom Meet,
Geschlecht vom Athleten/Bewerb), der öffentliche Rechner braucht dieselbe Formel aber ganz ohne Ergebnis-Kontext, nur
aus Bahn/Geschlecht/Bewerb/Sportklasse/Zeit direkt gewählt. Beide Services landen bei derselben Basiswert-Auflösung
(`base_time_version_id` + `base_time_category_id` + `base_time_discipline_id` + `base_time_sport_class_id`), aber
getrennt implementiert, um `WorldAquaticsPointsService` nicht anzufassen.

Nur die aktuell gültige Basiswert-Version (`BaseTimeVersion::validOn(heute)`) — weder Punktetabelle noch Rechner haben
ein Versions-Auswahlfeld (Planungsentscheidung Phase 6, historische Versionen sind kein öffentliches Bedürfnis). Nur
Einzelbewerbe (`relay_count = 1`) — dieselbe Einschränkung wie im bestehenden Richtzeiten-Modul
(`QualifyingTimeCalculationService`), Staffeln sind aus der Basiswert-Perspektive kein sinnvoller
Rechner-Anwendungsfall. Die Verbandsreihenfolge (Frei/Rücken/Brust/Fly/Lagen) ist ein drittes Mal dupliziert
(`PointConversionService::STROKE_ORDER`, nach `PublicRecordService` und `QualifyingTimeListController`) — diesmal
`public` statt `private`, damit `PointCalculatorController` sie fürs Bewerbs-Dropdown mitnutzt, statt sie ein viertes
Mal zu kopieren.

Punktetabelle: je Lage eine eigene Matrix-Tabelle (Zeilen = Sportklasse, zweistufige Kopfzeile Geschlecht über Bewerb
mit `headers`/`id`, wie in accessibility.md für die Punktetabelle vorgeschrieben — anders als bei den Rekorden in Phase
5 ist das hier keine stale Doku-Angabe, sondern zutreffend). Reiternavigation zwischen den Lagen (`role="tablist"`,
Pfeiltasten, `aria-selected`, accessibility.md) progressiv verbessert: Ohne JS sind alle fünf Lagen-Panels als normaler
Fließtext sichtbar, die Tab-"Buttons" sind echte Sprunglinks (`<a href="#panel-…">"`); mit JS blendet
`base-time-tabs.js` auf ein sichtbares Panel um und ergänzt volle ARIA-Semantik. Unter dem `sm`-Breakpoint ersetzt eine
Sportklassen-Auswahl mit Einzelansicht (Distanz → Zeit Herren/Damen als Liste) die Matrix (accessibility.md "Reflow" —
dort exemplarisch an 320px festgemacht, hier über Tailwinds `sm`-Breakpoint als praktikable Umsetzung, nicht als exakte
Pixelgrenze).

Punkterechner: ein GET-Formular statt AJAX/Dialog — funktioniert dadurch auch ohne JS über einen echten Seitenaufruf
(§5.3: neun Felder in einem Dialog sind für Tastatur-/Screenreader-Bedienung die schlechtere Form, hier sind es vier
plus Zeit/Punkte). Die Richtung (Zeit→Punkte / Punkte→Zeit) ist eine Radio-group, keine Tabs — anders als bei der
Punktetabelle handelt es sich um eine Ein-aus-zwei-Auswahl innerhalb *eines* Formulars, nicht um parallele
Inhaltsbereiche. Ohne JS stehen Zeit- und Punkte-Feld beide sichtbar untereinander (funktional redundant, aber korrekt:
nur das zur gewählten Richtung passende Feld wird serverseitig ausgewertet); `point-calculator.js` blendet mit JS das
jeweils nicht passende Feld aus. Fehlermeldungen sind serverseitig kurze Codes (`PointConversionService`-Rückgabe wie
`no_base_time`, `invalid_time`) statt Freitext — sonst hätte der Service fest verdrahtetes Deutsch geliefert, das auf
der englischen Seite falsch gestanden wäre; der Controller übersetzt über `public.point_calculator.errors.*`.

Ein echter Sortier-Fehler wurde vor dem Testen mit Echt-daten übersehen und erst im manuellen Abgleich gegen die
Dev-Datenbank auffällig: `sortBy()` mit einem Array aus Ein-Parameter-Extraktor-Closures sortierte Bewerbe innerhalb
einer Lage nicht nach Distanz (CLAUDE.md warnt explizit vor "sortBy () mit Closure-Array" — genau dieser Fallstrick,
selbst begangen). `sortBy([...])` erwartet Zwei-Parameter-Komparatoren, keine Schlüssel-Extraktoren; korrigiert auf
zusammengesetzte `sprintf()`-Sortierschlüssel (`PointConversionService::buildTable()` und
`PointCalculatorController::index()`).

**Tests** (`public-p6`, alle grün): Zeit→Punkte→Zeit- und Punkte→Zeit→Punkte-Rundtrip konsistent, die Rückrechnung
erreicht mindestens die Zielpunktzahl, Grenzfälle (kein Basiswert für die Kombination, `TYPE_NOT_APPLICABLE`, Punktzahl
0), Punktetabelle zeigt nur `validOn(heute)`, zweistufige Kopfzeilen korrekt mit `headers`/`id` verdrahtet, mobile
Sportklassen-Einzelansicht zeigt denselben Wert wie die Matrix, Punkterechner-Endpunkt für beide Richtungen, übersetzte
Fehlermeldung ohne Basiswert, leeres Formular ohne Filterparameter zeigt weder Ergebnis noch Fehler. Zusätzlich
`tests/Unit/RecordCheckerServiceTest.php`-Nachbarschaft unberührt, `QualifyingTimeCalculationService`-Tests weiterhin
grün (unverändert, siehe oben).

---

### Nachträglich: zweiter Rechner (WPS/Gompertz) + Review-Runde

Rückmeldung nach dem ersten Abnahme-Durchlauf: Zwei Punkterechner sind nötig, nicht einer — der bisherige rechnet mit
den ÖBSV-Basiswerten und der World-Aquatics-Formel (LCM **und** SCM); die offizielle WPS-Tabelle (Gompertz-Formel)
ist ein eigenes, zweites Punktesystem mit eigener Datengrundlage und existiert nur für LCM. Dieselbe Trennung wie schon
in Phase 4 dokumentiert ("das Projekt kennt beide Punktesysteme parallel") — hier erstmals auch öffentlich zugänglich
gemacht.

**`Public\WpsPointCalculatorController`** (`/de/wps-punkterechner`) — dieselbe Bauweise wie
`PointCalculatorController` (GET-Formular, kein `validate()`, `pointCalculator.js` fürs Feld-Umschalten), aber:

- Nur `LCM`, kein Bahn-Feld — die WPS-Tabelle hat keine Kurzbahnwerte.
- Sportklassen als reine Nummer (1–14, 21) wie beim Rekorde-Filter (Phase 5) — die Kategorie (`S`/`SB`/`SM`) wird aus
  der gewählten Lage abgeleitet (`WpsPointCalculator::STROKE_CATEGORY_MAP`, hier dupliziert, da `private const`).
- Bewerbsliste kommt direkt aus `WpsPointParameter` (keine eigene Bewerbs-Stammtabelle wie bei den Basiswerten) — nur
  Kombinationen, für die tatsächlich ein Parametersatz existiert.
- Version über `WpsPointVersionResolver::resolveForDate()` — bereits bestehend, für genau diesen
  "kein Meet/Result vorhanden"-Fall gebaut (wps-qualification §5.3), hier wiederverwendet statt neu gebaut.
- Zeit→Punkte nutzt `WpsPointCalculator::pointsForTime()` — ebenfalls bereits bestehend und für denselben Zweck gebaut,
  unverändert übernommen.
- Punkte→Zeit gab es noch nicht: neue Methode `WpsPointCalculator::timeForPoints()`, als Geschwistermethode neben
  `pointsForTime()` ergänzt (keine bestehende Methode geändert). Geschlossene Umkehrung der Gompertz-Funktion
  (`p = c / (b − ln(−ln(q/a)))`) als Schätzung, danach dieselbe hundertstelweise Annäherung wie bei
  `PointConversionService::pointsToTime()`, weil `gompertz()` abrundet statt rundet und die Schätzung daher systematisch
  leicht zu schnell sein kann.

**Weitere Rückmeldungen aus derselben Runde:**

- **IMask griff nicht.** `resources/js/public.js` importierte `imask` nie und setzte `window.IMask` nicht — anders als
  `app.js` für den internen Bereich. `x-init="IMask($el, …)"` ist ein Inline-Ausdruck (ein String, den Alpine zur
  Laufzeit auswertet) und sieht deshalb keine Modulimporte, nur globale Bezeichner. Ohne `window.IMask = IMask`
  blieb das Zeitfeld unmaskiert — deckt sich genau mit der Beobachtung "wenn die Trennzeichen nicht eingegeben wurden,
  macht er gar nichts". Nachgezogen wie in `app.js`.
- **Keine Fehlermeldung bei ungültiger Zeit** (z. B. "99:99.99"). Zwei Ursachen: Erstens nutzte der Controller
  `$request->validate()`, das bei einem Formatfehler auf die vorherige URL zurückspringt — bei einem
  selbst-einreichenden GET-Formular sieht das wie "die Seite tut gar nichts" aus, ohne sichtbare Fehlermeldung.
  Umgestellt auf denselben Query-Parameter-Ansatz wie `PublicRecordFilter`: unbekannte/leere Werte fallen still auf
  einen Standard zurück, eine tatsächlich versuchte, aber ungültige Berechnung zeigt einen Fehlertext in derselben
  Antwort (200, kein Redirect). Zweitens prüfte das Zeitformat nur `\d{2}` für Sekunden/Hundertstel — "99:99.99" bestand
  die Prüfung rein formal. Regex verschärft auf `[0-5]\d` für den Sekundenanteil. Beide Punkterechner-Controller
  betroffen und behoben.
- **`sortBy()`/`resolveBaseTime()`-Rückgaben als Tupel-Arrays** ersetzt durch Wertobjekte
  (`App\Support\PointCalculationResult`, `App\Support\BaseTimeLookupResult`) statt `[$wert, $fehler] = …`
  -Destrukturierung (CLAUDE.md: "Wertobjekte statt assoziativer Arrays"; PhpStorm meldete an den Aufrufstellen
  zusätzlich
  "Potentially polymorphic call").
- **Inline-Objektliteral als `x-data`** (`x-data="{ mobileClass: '…' }"`) in der Punktetabelle ausgelagert nach
  `resources/js/base-time-mobile-class.js` (`baseTimeMobileClass()`) — ein bei Statement-Position stehendes `{`
  ist als reines JS nicht eindeutig als Objektliteral erkennbar und erzeugte IDE-Rauschen; CLAUDE.md verlangt ohnehin
  ausgelagerte Alpine-Logik.
- **Punktetabelle: ÖBSV-Bezeichnung/Version fehlte.** `$version->display_name` jetzt sichtbar auf allen drei Seiten
  (Punktetabelle, ÖBSV-Rechner, WPS-Rechner) — bei zwei parallelen Punktesystemen ist sonst unklar, welche Version
  gerade gilt bzw. welches System überhaupt gerade angezeigt wird. Aus demselben Grund tragen alle drei
  Seitenüberschriften jetzt "ÖBSV-" bzw. "WPS-" als Präfix statt nur "Punktetabelle"/"Punkterechner" — vorher, mit nur
  einem Rechner, war das nicht nötig.
- **Herren/Damen in der Punktetabelle schwer auseinanderzuhalten.** Trennlinie (`border-l-2`) zwischen den beiden
  Spaltenblöcken statt zweier separater Tabellen — bleibt eine einzige, mit `headers`/`id` verdrahtete Tabelle
  (accessibility.md), zwei Tabellen hätten das doppelt und redundant gemacht.
- **Sportklassen sollten aufsteigend erscheinen** (1, 2, 3, … statt der im Bestand hinterlegten `sort_order`, die der
  alten, absteigenden Excel-Quelle folgt). `PointConversionService::classNumber()` extrahiert die Nummer aus dem Code
  und sortiert numerisch aufsteigend — betrifft die Punktetabelle **und** das Sportklassen-Dropdown des ÖBSV-Rechners
  (dieselbe Methode, `public static`, damit nicht zweimal gebaut).
- **Namenskollision beim Ausliefern:** Der Navigationseintrag "WPS-Punkterechner" ließ ein bestehender Phase-4-Test
  (`assertDontSee('WPS-Punkte')` auf der Ergebnisseite) fehlschlagen — die Navigation steht auf jeder Seite, auch dort.
  Umbenannt auf "WPS-Rechner" (Navigation only, die Rechner-Seite selbst heißt weiterhin
  "WPS-Punkterechner").

**Tests ergänzt:** `WpsPointCalculator::timeForPoints()` gegen den offiziellen Referenzwert (S2/57,00s/939 Punkte, aus
der bestehenden WPS-Testsuite) im Rundtrip mit `pointsForTime()`, WPS-Rechner-Endpunkt für beide Richtungen, unbekannter
Bewerb zeigt Fehler statt falscher Berechnung, Versionsanzeige auf der Rechner-Seite.

### Nachträglich: Kopfzeile — Untermenü "Punkte"

Rückmeldung: Mit drei Punktesystem-Zielen (Punktetabelle, ÖBSV-Rechner, WPS-Rechner) neben Start/Veranstaltungen/
Rekorde wurde die Desktop-Kopfzeile zu lang. Zusammengefasst in einem Untermenü "Punkte"
(`resources/js/nav-dropdown.js`, `Alpine.data('navDropdown', …)`) — als WAI-ARIA- **Disclosure**, nicht
`role="menu"`: Die drei Einträge sind Navigationsziele, keine Befehle, das schwerere "Menu Button"-Muster (volle
`role="menu"`/`role="menuitem"`-Semantik) wäre hier unpassend. Accessibility.md verlangt für Dropdowns/Menüs explizit
Pfeiltasten, Escape, `aria-expanded`, Fokusrückgabe — alle vier umgesetzt. Progressiv verbessert wie die
Reiternavigation der Punktetabelle: Ohne JS ist der Auslöser ein normaler Link auf die Punktetabelle, das Panel steht
offen im Fließtext, alle drei Ziele bleiben erreichbar; mit JS wird daraus ein einklappbares Menü. Dabei eine
vorbestehende Lücke gefunden und behoben: `[x-cloak] { display: none !important; }` fehlte im gesamten öffentlichen
Bereich, das mobile Nav-Panel trug `x-cloak` also folgenlos (`resources/css/public.css`) — bewusst **nicht** auf dem
neuen Punkte-Panel verwendet, dessen Panel ja ohne JS sichtbar bleiben soll. Die Kopfzeile im mobilen Slide-out-Panel
bleibt unverändert eine flache Liste — dort ist "zu lang" kein Problem (vertikales Scrollen, kein horizontaler
Platzmangel).

### Nachträglich: PhpStorm-Nachschärfung nach der Rückmeldungs-Runde

- **Duplizierter Code** in beiden Rechner-Controllern (`PointCalculatorController`, `WpsPointCalculatorController`):
  Die Extraktion von Ergebnis und Fehlercode aus dem Berechnungsergebnis war in den Zweigen "Zeit→Punkte" und
  "Punkte→Zeit" fast identisch dupliziert. Zusammengeführt auf eine gemeinsame Nachverarbeitung nach der Verzweigung,
  statt in jedem Zweig einzeln.
- **"Potentially polymorphic call" an den `$discipline`-Aufrufstellen** in beiden Controllern: PhpStorm verliert die
  Typinformation von `$discipline` durch die Collection-Kette (`->filter()->sortBy()->values()->firstWhere()`/
  `->first()`), obwohl das Ergebnis stets `BaseTimeDiscipline`/`WpsPointParameter` oder `null` ist. Behoben mit
  `/** @var ?Type $discipline */`-Hinweisen direkt an der Zuweisung — dieselbe Ursache wie die "Potentially polymorphic
  call"-Funde zuvor bei `PointConversionService`, nur diesmal an der Verbrauchsstelle statt an der Erzeugungsstelle.
- **Dieselbe Ursache tiefer verfolgt für die Blade-Views:** `PointConversionService::buildTable()` gab bislang anonyme
  `(object) [...]`-Strukturen zurück (`stroke`/`disciplines`/`rows`, `sportClass`/`cells`) — PhpStorm kann
  `@return`-Array-Shape-Annotationen für solche Objekte nicht auflösen, jeder nachgelagerte Zugriff (`$group->stroke`,
  `$row->sportClass->code`, …) in `base-times/index.blade.php` erschien deshalb als
  "Potentially polymorphic call". Ersetzt durch zwei echte Wertobjekte, `App\Support\BaseTimeStrokeGroup` und
  `App\Support\BaseTimeSportClassRow` (CLAUDE.md: "Wertobjekte statt assoziativer Arrays") — behebt die Ursache an einer
  Stelle, statt an jeder Verbrauchsstelle einzeln nachzubessern.
- **`@php … @endphp`-Blöcke mit `@var`-Docblocks** am Kopf aller drei betroffenen Blade-Dateien ergänzt
  (`base-times/index`, `point-calculator/index`, `wps-point-calculator/index`) — dasselbe, bereits im Bestand genutzte
  Muster wie `statistics/partials/sections.blade.php`. Blade-Views haben ohne solche Hinweise keinerlei Typinformation
  zu den vom Controller übergebenen Variablen, jede `@foreach`-Schleife darüber (`$discipline`,
  `$row`, …) galt PhpStorm deshalb als untypisiert.
- **`x-data="fn('{{ $wert }}')"`-Muster ersetzt** (`baseTimeTabs`, `baseTimeMobileClass`, `pointCalculator` — Letzteres
  auf beiden Rechner-Seiten): Ein per Blade in ein Alpine-Inline-Attribut interpolierter Wert innerhalb eines
  JS-String-Literals wurde von PhpStorms JS-Analyse nicht zuverlässig erkannt ("Missing import statement",
  "Expression statement is not assignment or call"). Umgestellt auf argumentlose `x-data="fn()"` plus ein normales
  `data-*`-Attribut, das die Komponente in ihrem `init()`-Hook über `this.$el.dataset` liest — dieselbe Information,
  ohne Blade-Interpolation innerhalb eines JS-Strings.
- **Bewusst nicht weiter verfolgt:** Vereinzelte "Element is not exported"-Funde beim Aufruf global über
  `Alpine.data()` registrierter Komponenten (`baseTimeTabs()`, `pointCalculator()`, `navDropdown()` …) sowie
  "'with' statement" auf `x-on:`/`x-bind:`-Ausdrücken — beides scheint eine strukturelle Eigenschaft von PhpStorms
  Alpine-Unterstützung zu sein (Komponenten werden aus Blade-Sicht nie "importiert", Alpine wertet Ausdrücke intern über
  `with()` aus) und träfe im selben Umfang auch auf `mobileNav()`/`theme()` im Layout zu, die nach demselben, bereits
  vor Phase 6 etablierten Muster gebaut sind.

Volle Suite nach dieser Runde weiterhin grün (1337/1337), alle Rechenergebnisse gegen die Dev-Datenbank erneut
verifiziert (unverändert gegenüber vor der Refaktorierung).

### Nachträglich: zweite PhpStorm-Runde

- **"Statement has empty body"** in beiden Rechner-Controllern: Die vorherige Fassung nutzte einen leeren
  `if (! $hasAttempt) { /* Kommentar */ }`-Zweig, um die Deduplizierung der letzten Runde ohne zusätzliche Einrückung
  unterzubringen. Zurückgebaut auf ein umschließendes `if ($hasAttempt) { … }` — eine Ebene mehr Einrückung, aber kein
  leerer Zweig.
- **"Duplicated code fragment (6 lines long)"** — diesmal nicht innerhalb einer Datei, sondern zwischen den beiden
  Controllern: `$mode`/`course`/`gender` wurden in beiden identisch über
  `in_array($request->query(...), self::X, true) ? … : $default` gelesen. Extrahiert nach
  `App\Support\QueryParam::pick()` (dasselbe "unbekannt fällt still zurück"-Prinzip wie `PublicRecordFilter`), von
  beiden Controllern genutzt statt zweimal ausprogrammiert.

Erneut gegen die Dev-Datenbank verifiziert (1000/626 Punkte, dieselben Werte wie zuvor), volle Suite grün (1337/1337).

### Nachträglich: Punktetabelle-Vorgabe und WPS-Sportklassenliste (Rückmeldung)

- **Punktetabelle: Vorgabe SCM statt LCM.** `BaseTimeTableController::index()` fällt jetzt ohne `?course=`-Parameter auf
  SCM (25m) statt LCM (50m) zurück (Rückmeldung: SCM ist die relevantere Vorgabe). Drei Tests in
  `PublicFrontendPhase6Test`, die den Endpunkt bisher ohne `course` aufriefen und sich auf LCM-Testdaten verließen,
  fordern seither explizit `course=LCM` an — sie prüfen Versions-Scoping/Kopfzeilen/mobile Ansicht, nicht die
  Bahn-Vorgabe selbst, daher unverändert in der Sache.
- **Punktetabelle: zweite Trennlinie neben der Sportklassen-Spalte.** Zusätzlich zur bestehenden Trennlinie zwischen
  Herren- und Damen-Spaltenblock nun auch eine (`border-r-2`) zwischen der Sportklassen-Spalte und dem Herren-Block — in
  der Kopfzeile (`corner-{code}`) und je Zeile (`row-{code}-{id}`, Rückmeldung: "sieht besser aus").
- **WPS-Punkterechner: Sportklassen-Liste wie beim ÖBSV-Rechner.** Die Optionen zeigten bisher nur die nackte Zahl (`1`,
  `2`, … `21`), der ÖBSV-Punkterechner dagegen den vollen Code (`S1`, `S2`, … `S21`) — dieselbe Uneinheitlichkeit wie
  bei den zwei Rechnern generell (Rückmeldung). Angeglichen: die Optionen zeigen jetzt `S{{ $number }}` als reines
  Label; der `<option value>` bleibt die nackte Zahl, der Controller leitet die tatsächliche WPS-Kategorie (S/SB/SM)
  weiterhin aus dem gewählten Bewerb ab (`STROKE_CATEGORY_MAP`) — unverändert in der Berechnung, nur die Anzeige zieht
  mit dem ÖBSV-Rechner gleich.

Erneut verifiziert: Pint grün, `--group=public-p6` 19/19, volle Suite 1337/1337, live gegen die Dev-Datenbank (Vorgabe
SCM sichtbar, `S1`…`S21` im WPS-Rechner, Trennlinie im HTML vorhanden).

---

## Phase 7 — Ranglisten — **abgeschlossen**

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

### Umsetzung

Zwei Extraktionen aus dem internen Bereich, jeweils verhaltensgleich (bestehende interne Testsuiten bleiben grün), bevor
die neuen Controller geschrieben wurden:

- `App\Support\DisabilityGroupGrouper` (`byGroupThenStroke()`/`byStroke()`) — aus
  `QualifyingTimeListController::groupByDisabilityGroupAndStroke()`/`groupByStroke()` gezogen (dort ursprünglich
  `private`), damit `Public\QualifyingTimeController` dieselbe Gliederung (Behinderungsgruppe → Bewerb) nutzt statt sie
  zu duplizieren.
- `App\Support\SportRankAssigner` (`assign()`) — aus `OverallRankingService::assignRanks()` gezogen (Sportwertung:
  gleiche Punkte = gleicher Rang, nächster Rang überspringt), damit `AnnualBestService` dieselbe Tie-Break-Logik nutzt.

**Cup-Wertung** (`Public\CupRankingController`, `/cup/{jahr?}`): dünner Wrapper um das bestehende
`OverallRankingService` (`brackets()`/`rankedBracket()`), keine eigene Berechnung. Kein Neu-berechnen-Button und keine
Runden-Aufschlüsselung wie im internen Bereich (admin-/Nachvollziehbarkeits-Werkzeug, keine öffentliche Anforderung),
kein PDF-Export (keine eigene Route dafür in der Spec-Routentabelle). Jahresauswahl direkt auf der Seite statt einer
eigenen Indexseite — die Spec-Routentabelle listet nur eine Route. `{jahr}` ist optional: ohne Angabe (Nav-Link) wird
das aktuellste Cup-Jahr mit vorhandener Wertung angezeigt.

**Startberechtigung** (`Public\QualifyingTimeController`, `/startberechtigung`): zeigt die `Qualification`-Zeilen
(Snapshot) der aktuell **aktiven** Richtzeitenliste (`QualifyingTimeList::is_active`), kein Jahres-Parameter — es gibt
immer nur eine aktuelle Startberechtigung. Eigenständige Neuimplementierung statt Wiederverwendung von
`QualifyingTimeListController::filteredQualifications()`: bewusst **ohne** dessen Namenssuche — §2.3 Punkt 3 verbietet
serverseitige Volltextsuche über Personen projektweit, nicht nur bei Cup-Wertung/Jahresbestleistungen. Neuer
`App\Support\PublicQualificationFilter` (nach dem Muster von `PublicRecordFilter`) mit nur geschlossenen Auswahlfeldern
(Bewerb, Geschlecht, Sportklasse, Verein); unbekannte Werte fallen still auf "kein Filter" zurück.

**Jahresbestleistungen** (`Public\AnnualBestController` + `AnnualBestService`, `/bestleistungen/{jahr?}`): Filterung der
Ergebnisse mit `whereNull('status')` (dasselbe "nur reguläre Ergebnisse"-Muster wie
`QualificationDeterminationService` — schließt EXH und alle anderen Sonderstatus in einem Schritt aus) plus
`relay_count = 1` und `whereBetween('start_date', [...])` fürs Kalenderjahr (portabel, kein `YEAR()`). Pro Athlet wird
serverseitig in PHP das punktbeste Ergebnis über alle Bewerbe hinweg ausgewählt, dann per `SportClassGroupMember` der
Behinderungsgruppe zugeordnet.

**Suchfelder** (Cup-Wertung, Jahresbestleistungen): neue `resources/js/table-search.js`
(`Alpine.data('tableSearch', …)`) filtert ausschließlich die bereits geladene Tabelle — Zeilen tragen ihren
durchsuchbaren Text (Name + Verein, kleingeschrieben) in `data-search`, damit keine Blade-Werte in JS-Stringliterale
eingebettet werden (Namen mit Apostroph wären sonst ein Escaping-Risiko). Ohne JavaScript bleibt das Suchfeld
wirkungslos, alle Zeilen stehen einfach in der Tabelle.

**Datenschutz**: alle drei Seiten zeigen Name + Sportklasse zusammen → `@section('robots', 'noindex, nofollow')` wie bei
den Ergebnissen (Phase 4), plus `Disallow`-Einträge in `public/robots.txt`. Namen bleiben überall unverlinkter Text —
anders als die jeweiligen internen Pendants, die auf `athletes.show` verlinken.

**Navigation**: analog zu "Punkte" aus Phase 6 ein zweites Untermenü "Ranglisten" (dieselbe `navDropdown()`-Komponente,
zweite Instanz), sonst wäre die Kopfzeile mit drei weiteren Top-Level-Links wieder zu lang geworden.

Verifiziert: Pint grün, `--group=public-p7` 16/16, volle Suite 1353/1353, live gegen die Dev-Datenbank (echte
Cup-/Startberechtigungs-/Jahresbestleistungs-Daten rendern korrekt, `noindex` gesetzt, keine Namenslinks, `robots.txt`
greift). Die clientseitige Suchfeld-Filterung und das Auf-/Zuklappen der Untermenüs ließen sich im Browser-Werkzeug
dieser Umgebung nicht interaktiv nachstellen — derselbe, bereits aus Phase 6 bekannte Sandbox-Umstand (Vite-Dev-Server
für den Browser-Tab nicht erreichbar, wohl aber per curl); das ausgelieferte JS-Bundle wurde stattdessen per curl gegen
den Vite-Dev-Server geprüft (kompiliert fehlerfrei, enthält die erwarteten Alpine-Registrierungen).

### Nachträglich: Runden-Aufschlüsselung, Tab-Trennung, kombinierte Jahr-/Suchen-Zeile (Rückmeldung)

Rückmeldung zur Cup-Wertung/Jahresbestleistungen: die Punkte der einzelnen Runden fehlten, zu viele Tabellen standen
untereinander, Jahresauswahl und Suchfeld sollten in einer Zeile stehen (Jahr links, Suchen rechts).

- **Runden-Aufschlüsselung** (nur Cup-Wertung — Jahresbestleistungen/Startberechtigung kennen keine Runden):
  `OverallRankingService::cupMeets()`/`attachRoundBreakdown()` — bisher `private` auf
  `CupOverallRankingController` — wurden in den Service gezogen (verhaltensgleich, interne Testsuite bleibt grün:
  79/79 über alle `cup-wertung-*`- und `cup-club-ranking-p3`-Gruppen), damit `Public\CupRankingController` dieselbe
  Runden-je-Meet-Spalte samt Grün/fett-Markierung der tatsächlich gezählten besten Runden zeigt wie der interne
  Bereich — jetzt aus einer einzigen Quelle statt dupliziert.
- **Tab-Trennung**: neue generische `resources/js/tab-panels.js` (`Alpine.data('tabPanels', …)`) — dieselbe
  Reiter-Interaktionslogik wie `base-time-tabs.js` (Punktetabelle, Phase 6), hier mit generischen Tab-Keys statt
  Lage-Codes, damit sie auch für Wertungskategorien (Cup-Wertung: Geschlecht × Sportklassengruppe × Altersgruppe;
  Jahresbestleistungen: Geschlecht × Behinderungsgruppe) wiederverwendbar ist, statt für die Punktetabelle eine Kopie zu
  pflegen. Ersetzt die vorherige Liste untereinander stehender Tabellen auf beiden Seiten. (Für die Cup-Wertung
  inzwischen durch die Dropdown-/Checkbox-Filterung ersetzt, siehe nächster Abschnitt — Jahresbestleistungen nutzt
  weiterhin diese Reiter, da dort keine Altersgruppen-Dimension die Tab-Zahl verdoppelt.)
- **Kombinierte Zeile**: Jahresauswahl-`<form>` und Suchfeld stehen jetzt in einer `flex justify-between`-Zeile (Jahr
  links, Suchen rechts) statt zweier getrennter Zeilen.

**Nebenbei gefundener und behobener Bug (Routing):** Beim Verifizieren gegen die Dev-Datenbank fiel auf, dass
`/de/bestleistungen/2025` mit echten Daten eine leere Seite zeigte, obwohl `AnnualBestService::forYear(2025)` direkt
aufgerufen Daten liefert. Ursache: `Public\CupRankingController::index()`/`Public\AnnualBestController::index()`
deklarierten `?string $jahr = null` als eigenes Methodenargument — unter der `{locale}`-Präfixgruppe (die selbst kein
Methodenargument ist) bindet Laravel Nicht-Klassen-Routenparameter aber positionsbasiert statt namensbasiert
(`RouteDependencyResolverTrait::resolveMethodDependencies`), sodass `$jahr` den `locale`-Wert (`'de'`) statt der
Jahreszahl bekam. Der bestehende Fallback ("unbekanntes/kein Jahr → aktuellstes bzw. laufendes Jahr") verschleierte den
Fehler in den bisherigen Tests vollständig, weil `(int) 'de'` (= 0) zufällig auf denselben Fallback-Wert lief wie die
Testdaten. Fix: `$jahr = $request->route('jahr')` statt Methodenparameter (kein Bindungsproblem, da direkt nach Namen
aus der Routen-Parameterliste gelesen). Siehe CLAUDE.md-Fallstricke. Zwei neue Regressionstests (`public-p7`, je zwei
unterschiedliche Jahre mit Daten, explizit das ältere angefordert) stellen sicher, dass der Fehler nicht durch einen
Fallback maskiert werden kann.

Verifiziert: Pint grün, `--group=public-p7` 18/18 (2 neue Regressionstests), volle Suite 1355/1355, live gegen die
Dev-Datenbank (`/de/cup/2025` zeigt 18 Wertungskategorien samt Runden-Spalten und Grün/fett-Markierung,
`/de/bestleistungen/2025` zeigt 8 Tabs — beide vorher durch den Routing-Bug leer).

### Nachträglich (2): Cup-Wertung als Dropdown-Filter statt Reiter, Select-Icon site-wide (Rückmeldung)

Rückmeldung zu den 18 Reitern der Cup-Wertung: "das schaut nicht optimal aus … besser ein Dropdown für die
Behindertenklasse, eines für Geschlecht und eine Checkbox für Jugend". Zusätzlich: das Pfeil-Icon der `<select>`-Felder
sitzt sehr knapp am Rand — Nachfrage, ob das ein Tailkit-Snippet ist.

**Select-Icon (site-wide, alle ~20 `<select>` im öffentlichen Bereich):** ja, es ist exakt Tailkits
`a-c-form-elements-03` — das Snippet selbst liefert aber gar kein Icon mit, es reserviert mit `pr-10` nur Platz dafür;
ohne `appearance-none` rendert der Browser seinen eigenen, ungestylten Pfeil direkt am Rand, unabhängig vom reservierten
Platz. Tailkit hat eine Variante mit echtem Chevron (`a-c-select-menus-01`), die ist aber ein komplett JS-gesteuertes
Listbox-Widget ganz ohne natives `<select>` — passt nicht zum hier durchgehend verwendeten Muster
"natives `<select onchange="…submit()">` plus `<noscript>`-Fallback". Stattdessen neue
`resources/views/components/select-chevron.blade.php` (`<x-select-chevron/>`, dasselbe Pfeil-Icon wie das
Untermenü-Chevron im Kopfbereich) plus `appearance-none` auf jedem `<select>` — überall im öffentlichen Bereich
angewendet (Rekorde, Punktetabelle, Punkterechner, WPS-Rechner, Startberechtigung, Jahresbestleistungen, Cup-Wertung).

**Cup-Wertung — Filter statt Reiter:** `Public\CupRankingController` liefert weiterhin alle Wertungskategorien
serverseitig (unverändert), die View berechnet zusätzlich `$groupOptions`/`$genderOptions` (aus den tatsächlich
vorhandenen Brackets abgeleitet, keine feste Liste) und eine kompakte `$filterKeys`-Liste
(Gruppe/Geschlecht/Jugend-Tripel je Bracket, nur für die "keine Daten"-Prüfung im JS). Neue
`resources/js/cup-ranking-filter.js` (`Alpine.data('cupRankingFilter', …)`): Gruppen- und Geschlechts-`<select>` plus
Jugend-`<checkbox>` steuern, welche der weiterhin serverseitig gerenderten Bracket-Sektionen sichtbar ist (`x-show`
über `data-group-id`/`data-gender`/`data-jugend`-Attribute, analog zu `table-search.js`s `data-search`-Muster). Wechselt
die Gruppe, wird eine ungültig gewordene Geschlechts-Auswahl automatisch auf eine vorhandene Kombination nachgezogen
(kaskadierendes Auswahlfeld) — die Jugend-Checkbox dagegen bewusst nicht: Ein expliziter Klick auf eine Kombination ohne
Daten zeigt die "keine Wertung vorhanden"-Meldung statt still andere Inhalte anzuzeigen. Ersetzt die Reiterleiste aus
dem vorherigen Abschnitt für die Cup-Wertung; `tab-panels.js` bleibt für die Jahresbestleistungen im Einsatz (siehe
oben).

Verifiziert: Pint grün, `--group=public-p7` 18/18, volle Suite 1355/1355; live gegen die Dev-Datenbank per curl geprüft
(Gruppen-/Geschlechts-`<select>` mit den korrekten 6 bzw. 3 Optionen und dem richtigen Default vorbelegt, 18
`data-group-id`/`data-gender`/`data-jugend`-Sektionen, kompilierter Vite-Modulcode enthält die erwarteten
Alpine-Registrierungen) — dieselbe Browser-Sandbox-Einschränkung wie in den vorherigen Phasen verhindert eine
interaktive Prüfung der Dropdown-Kaskadierung in diesem Environment.

### Nachträglich (3): Tabelle fehlte, Filter reagierten nicht; Filterzeile zusammengeführt (Rückmeldung)

Rückmeldung: "Bei der ÖBSV Cup Wertung fehlt die Tabelle. Bei der Wahl einer Klasse oder Geschlecht tut sich gar
nichts." — echter Funktionsfehler, kein Verhalten wie beabsichtigt.

**Ursache:** `x-data="cupRankingFilter(@json($filterKeys))"` stand in einem mit doppelten Anführungszeichen begrenzten
HTML-Attribut. `@json()` ist Laravels roher `json_encode()` mit den HTML-sicheren HEX-Flags — die escapen nur
Sonderzeichen *innerhalb* von JSON-String-Werten, nicht die strukturellen Anführungszeichen, die JSON für Objekte/Arrays
selbst braucht (`{"groupId":6,...}`). Das erste strukturelle `"` im JSON hat das HTML-Attribut also mitten im Ausdruck
beendet — der Browser hat alles danach als zusätzliche, bedeutungslose Attribute auf demselben `<div>` gelesen. `x-data`
enthielt dadurch nur den unvollständigen, syntaktisch ungültigen Rest
`cupRankingFilter([{`, Alpine konnte die Komponente nie initialisieren — `groupId`/`gender`/`jugend`/`isVisible()`
blieben undefiniert. Deshalb blieb jede Wertungskategorie-Sektion ohne sichtbaren Zustand (Tabelle "fehlte") und die
`x-model`-gebundenen Dropdowns/die Checkbox hatten keinerlei Wirkung. Nie in diesem Environment aufgefallen, weil die
Browser-Sandbox den Vite-Dev-Server nicht erreicht (siehe oben) — nur die kompilierte, syntaktisch valide JS-Datei und
das server-gerenderte HTML wurden per curl geprüft, beides sah für sich genommen unauffällig aus; erst ein echter
Browser zeigt das kaputte Attribut. **Fix:** einfache statt doppelte Anführungszeichen um das
`x-data`-Attribut (`x-data='cupRankingFilter(@json($filterKeys))'`) — genau das von Laravels eigener Doku für
`@json()` empfohlene Muster. `$filterKeys` enthält ohnehin nur Zahlen, feste Kürzel (`M`/`F`/`combined`) und Booleans,
also keine Apostrophe, die das einfach-Anführungszeichen-Attribut ihrerseits brechen könnten.

**Filterzeile zusammengeführt:** Jahr-, Klassen- und Geschlechts-`<select>` sowie die Jugend-Checkbox stehen jetzt alle
in einer gemeinsamen `flex`-Zeile, Suchen weiterhin rechts daneben (`justify-between`) — vorher stand die Jahresauswahl
in einer eigenen Zeile über den drei neuen Filtern.

Verifiziert: Pint grün, `--group=public-p7` 18/18, volle Suite 1355/1355; die korrigierte `x-data`-Ausgabe wurde per
curl gegenkontrolliert (jetzt ein einziges, vollständiges, gültiges Attribut mit dem kompletten JSON statt
abgeschnitten). Eine echte interaktive Bestätigung im Browser bleibt durch dieselbe Sandbox-Einschränkung verwehrt —
dies ist also die Art Fehler, die grundsätzlich nur ein echter Browser zuverlässig aufdeckt, weshalb hier besonders
sorgfältig zusätzlich per Hand durch die HTML-Attribut-Grammatik nachvollzogen wurde.

### Nachträglich (4): Jahresbestleistungen auf dasselbe Filter-Muster wie die Cup-Wertung (Rückmeldung)

Rückmeldung: "Passe die Jahresbestleistungen Filter so an wie die ÖBSV Cup Wertung." `cup-ranking-filter.js` wurde dafür
zu `resources/js/ranking-filter.js` verallgemeinert (`Alpine.data('rankingFilter', …)`, ersetzt sowohl
`cup-ranking-filter.js` als auch das bisherige `tab-panels.js` — Letzteres war nach der Cup-Wertungs-Umstellung bereits
nur noch bei den Jahresbestleistungen im Einsatz und wurde komplett entfernt, kein anderer Verwender mehr). Ob die
Jugend-Dimension existiert, leitet die Komponente aus den übergebenen `keys` ab (`'jugend' in keys[0]`) —
Jahresbestleistungen liefern nur Gruppe/Geschlecht, keine Altersgruppen, daher ohne dritten Filter/Checkbox.

`public/annual-best/index` bekommt dieselbe Struktur wie die Cup-Wertung: Jahr-, Klassen- und Geschlechts-`<select>`
in einer gemeinsamen Zeile, Suchen rechts daneben; Wertungskategorie-Sektionen (jetzt Geschlecht × Behinderungsgruppe
statt Reiter) über `data-group-id`/`data-gender`-Attribute geschaltet, analog zur Cup-Wertung.

Eine Besonderheit gegenüber der Cup-Wertung: Manche Jahresbestleistungs-Buckets haben keine zugeordnete
Behinderungsgruppe (Sportklasse nicht in `SportClassGroupMember` gepflegt, Anzeige "—"). Dafür braucht die Gruppen-Id
ein nicht-numerisches Kürzel (`"none"`) statt einer Zahl — deshalb wurde `groupId` in `ranking-filter.js`
durchgehend (auch bei der Cup-Wertung) von `x-model.number` auf einen einfachen String-Vergleich umgestellt
(`$filterKeys`/`data-group-id` liefern jetzt beide Seiten als `(string) $id`), sonst hätte Alpines `.number`-Modifier
`"none"` zu `NaN` gemacht.

Verifiziert: Pint grün, `--group=public-p7` 18/18, volle Suite 1355/1355; live gegen die Dev-Datenbank per curl geprüft
(`/de/bestleistungen/2025`: `x-data='rankingFilter(...)'` vollständig und gültig, 8
`data-group-id`/`data-gender`-Sektionen, Gruppen-/Geschlechts-`<select>` mit den richtigen Optionen; `/de/cup/2025`
weiterhin unverändert korrekt mit den jetzt string-wertigen `groupId`s). Dieselbe Sandbox-Einschränkung verhindert eine
interaktive Klick-Prüfung in diesem Environment.

### Nachträglich (5): Sammel-Optionen "Alle Klassen"/"Damen & Herren" (Rückmeldung)

Rückmeldung: "Beim Klasse Filter fehlt mir noch alle Klassengruppen zusammen und beim Geschlecht Filter Damen und Herren
zusammen." Beide Dropdowns filterten bis dahin ausschließlich auf eine exakte Gruppe/ein exaktes Geschlecht — es gab
keine Möglichkeit, absichtlich mehrere Sektionen gleichzeitig zu sehen.

`ranking-filter.js` bekommt zwei Sammel-Werte, die absichtlich *nicht* einschränken, statt eine weitere konkrete
Kombination zu sein:

- `groupId === 'all'` ("Alle Klassen", neue Option, immer an erster Stelle): `isVisible()` prüft die Gruppe dann gar
  nicht mehr — alle Sektionen jeder Klasse werden gezeigt (weiterhin eingeschränkt durch Geschlecht/Jugend).
- `gender === 'combined'` ("Damen & Herren", jetzt immer im Dropdown, nicht mehr nur bei Cup-Gruppen mit echter
  gemeinsamer Wertung): deckt zwei serverseitig unterschiedliche Fälle einheitlich ab — bei Gruppen mit
  `Cup::isGenderCombined()` gibt es ohnehin nur eine Sektion (gender=null), die wird gezeigt; bei Gruppen mit getrennter
  Damen-/Herren-Wertung (und bei den Jahresbestleistungen, die *nie* eine echte gemeinsame Wertung kennen) zeigt
  dieselbe Option stattdessen beide Sektionen gleichzeitig. Kein dritter Optionswert nötig, weil sich beide Fälle für
  Nutzer:innen gleich anfühlen sollen: "nicht nach Geschlecht eingrenzen".

Die Kaskadierung (Gruppenwechsel korrigiert eine ungültig gewordene *konkrete* Geschlechtsauswahl) überspringt die
beiden Sammel-Werte, weil sie per definitionem nie ungültig werden können, solange für den Rest der Auswahl überhaupt
Daten existieren. Betroffen: `ranking-filter.js` (neue `matchingKeys()`-Hilfsmethode, von `syncJugend()`/`hasMatch`
gemeinsam genutzt), `public/cup-ranking/index` und `public/annual-best/index` (je eine zusätzliche `<option>` bzw. ein
zusätzlicher, nicht aus den Daten abgeleiteter `null`-Eintrag in `$genderOptions`), neue Lang-Keys
`filter.group_all` (beide Seiten) und `gender.combined` (Jahresbestleistungen — bei der Cup-Wertung gab es den Schlüssel
bereits).

Verifiziert: Pint grün, `--group=public-p7` 18/18, volle Suite 1355/1355; live gegen die Dev-Datenbank per curl geprüft
(beide Seiten zeigen "Alle Klassen" als erste Gruppen-Option und "Damen & Herren" im Geschlechts-Dropdown, kompilierter
Vite-Modulcode enthält `matchingKeys`/die neue Sammel-Logik fehlerfrei). Dieselbe Sandbox-Einschränkung verhindert eine
interaktive Klick-Prüfung, ob das Umschalten auf "Alle Klassen"/"Damen & Herren" tatsächlich mehrere Sektionen
gleichzeitig einblendet.

### Nachträglich (6): Sammel-Optionen sind eine echte Neu-Wertung, keine Mehrfachanzeige (Rückmeldung)

Klarstellung zur vorherigen Rückmeldung: "Bei allen Klassen habe ich mich nicht klar ausgedrückt. Ich meinte das alle
gemeinsam über die Punkte gewertet werden. Bei Damen und Herren werden Geschlechter unabhängig sie über die Punkte
gewertet." Nachträglich (5) hatte "Alle Klassen"/"Damen & Herren" als reine Anzeige-Wildcards umgesetzt (mehrere
bestehende Tabellen gleichzeitig sichtbar) — gemeint war stattdessen eine echte **eine** Tabelle, in der alle
betroffenen Zeilen gemeinsam nach Punkten sortiert und neu gerankt werden (SportRankAssigner), nicht nur gleichzeitig
angezeigt.

Das ist möglich, ohne die zugrunde liegenden Punkte neu zu berechnen: ÖBSV-/WPS-Punkte sind klassen- und
geschlechtsübergreifend vergleichbar (genau dafür wurden sie eingeführt) und hängen nicht davon ab, in welcher
Wertungskategorie/welchem "Bucket" ein Ergebnis für die Rangvergabe steht — nur die Rang- *Konkurrenz* ändert sich. Ein
nachträgliches Zusammenlegen bereits berechneter Punkte-Zeilen und Neuvergeben des Rangs ist deshalb gleichwertig zu
einer von vornherein gemeinsam gewerteten Berechnung. Das entspricht exakt dem bereits bestehenden Mechanismus hinter
`Cup::isGenderCombined()` (Top-Gruppe u. Ä.): `CupOverallResult`-Zeilen tragen immer das echte Geschlecht der
Athletin/des Athleten, "gemeinsame Wertung" bedeutet nur, beim Ranking nicht nach Geschlecht zu filtern.

**Cup-Wertung:** `OverallRankingService::rankedAcrossGroups()` (neu) — wie `rankedBracket()`, aber ohne
Sportklassengruppen-Filter, für "Alle Klassen". Für "Damen & Herren" genügt der *bestehende* `rankedBracket($cupId,
gender: null, …)` — `null` bedeutete dort schon immer "kein Geschlechtsfilter", nicht "im Datensatz gespeichertes NULL"
(so berechnet sich auch die bereits existierende echte gemeinsame Wertung von `Cup::isGenderCombined()`).
`Public\CupRankingController::resolveBrackets()` baut jetzt zusätzlich zu den echten Wertungskategorien:
`mergedGenderBrackets()` (je Sportklassengruppe + Altersgruppe, sofern nicht schon echt gemeinsam gewertet) und
`mergedGroupBrackets()` (je Altersgruppe × Geschlecht inkl. der Geschlecht- *und*-Klassen-übergreifenden
Gesamtwertung) — 18 echte + 9 zusammengelegte Geschlechts- + 6 zusammengelegte Klassen-Sektionen im aktuellen
Dev-Datensatz für 2025. `group: null` steht dabei für "Alle Klassen" (Gegenstück zum schon vorhandenen `gender:
null` für "Damen & Herren"). Zeilen einer klassenübergreifenden Sektion bekommen eine zusätzliche Klasse-Spalte, Zeilen
einer geschlechtsübergreifenden Sektion eine zusätzliche Geschlecht-Spalte (sonst ginge das nur noch aus der
Sektions-Überschrift hervor).

**Jahresbestleistungen:** `AnnualBestService::forYear()` bleibt unverändert (Tests!). Zwei neue Methoden
`mergedGenderBuckets()`/`mergedGroupBuckets()` legen dieselben bereits ermittelten Bestergebnisse
(`bestResultPerAthlete()`, privat, jetzt dreifach statt einfach abgefragt — bei dieser Datenmenge unkritisch)
gruppen- bzw. geschlechtsübergreifend zusammen und ranken neu. `Public\AnnualBestController::index()` hängt beide
Ergebnislisten an `forYear()` an. Die Sportklasse steht hier ohnehin schon als eigene Spalte je Zeile (Rekord aus Phase
5 übernommen), nur eine zusätzliche Geschlecht-Spalte für zusammengelegte Sektionen war nötig.

`ranking-filter.js` ist dadurch wieder einfacher geworden: "all"/"combined" sind jetzt ganz normale, serverseitig
bereits fertig berechnete Werte auf ganz normalen, vollständigen Sektionen — kein Sonderfall mehr im JS nötig, die
vorherige `matchingKeys()`-Wildcard-Logik aus Nachträglich (5) wurde zurückgebaut auf einen einfachen
Gleichheitsvergleich (wie vor (5)).

Verifiziert: Pint grün, `--group=public-p7` 18/18, volle Suite 1355/1355, außerdem die vollen internen
`cup-wertung-*`/`cup-club-ranking-p3`-Gruppen (79/79 — `brackets()`/`rankedBracket()` unverändert, nur um eine neue
Methode ergänzt); live gegen die Dev-Datenbank per curl geprüft — beide Seiten zeigen die erwarteten zusätzlichen
Sektionen ("Damen & Herren — Alle Klassen — Offen" bzw. "Damen & Herren — Alle Klassen") mit korrekt neu vergebenem Rang
über die zusammengelegten Zeilen und den passenden Zusatzspalten. Dieselbe Sandbox-Einschränkung verhindert eine
interaktive Klick-Prüfung des Dropdown-Wechsels in diesem Environment.

### Nachträglich (7): Startberechtigung — Filterzeile, Richtzeit-Anzeige, Behinderungsgruppe-Filter (Rückmeldung)

Rückmeldung mit vier farblich markierten Punkten zur Startberechtigungsseite:

- **Versatz beim Geschlecht-Filter / Button-Größe:** drei Anläufe, bis es saß.
    1. Das Filter-`<form>` stand auf `items-end`, wie die Rekorde/Cup-Wertung/Jahresbestleistungen auch — dort passt
       das, weil jedes Element ein Label über dem Auswahlfeld hat. Der label-lose "Filtern"-Button in derselben Zeile
       bekommt dadurch eine andere optische Höhe/Unterkante. Erster Versuch: `items-center` statt `items-end` —
       zentriert zwar alle Spalten zueinander, aber bezogen auf die GESAMTE Spaltenhöhe inklusive des für den Button
       unsichtbaren Platzes, den ein Label einnehmen würde; der Button landete dadurch optisch oberhalb der
       Auswahlfelder (Rückmeldung:
       "schaut aus, als wäre er verrutscht").
    2. Zurück auf `items-end`, dafür eine unsichtbare Label-Attrappe (`aria-hidden`, `.invisible`, exakt wie ein echtes
       Label groß) über dem Button — seine Spalte hat dadurch dieselbe Struktur/Höhe wie die übrigen, `items-end`
       richtet Button und Auswahlfelder auf gleicher Höhe aus. Blieb aber unterschiedlich groß: Der Button hatte keinen
       `border`
       und kein `leading-6`, die Auswahlfelder aber schon — die reine Box-Höhe (Border + Padding + Zeilenhöhe) stimmte
       also trotz korrekter Ausrichtung nicht überein (Rückmeldung: "vertikale Zentrierung mit dem Input Feld, oder mach
       den Button gleich groß").
    3. `border border-transparent` (unsichtbar, aber raumgreifend) + `leading-6` auf den Button ergänzt — jetzt exakt
       dieselbe Box-Höhe wie die Auswahlfelder (2px Border + 16px Padding + 24px Zeilenhöhe beidseitig). Die beiden
       versteckten Felder (`stroke_type_id`/`distance`) standen bisher verschachtelt im Bewerb-Feld-Block (einzige
       strukturelle Abweichung von den übrigen, sonst identisch aufgebauten Feld-Blöcken) — jetzt als eigene, direkte
       Formular-Kinder ans Ende verschoben, damit alle Feld-Blöcke exakt denselben Aufbau haben. (Bewusst nicht auf
       `public/records/index` übertragen — dessen Filterzeile hat zusätzlich eine Jugend-Checkbox mit einer bereits fein
       abgestimmten `pb-2`-Kompensation für `items-end`; ein Wechsel bräuchte dort eine eigene Prüfung, ob diese
       Kompensation dann noch passt oder entfernt werden müsste — nicht Teil dieser Rückmeldung.)
- **Richtzeit der gefilterten Sportklasse:** `QualifyingTimeController::referenceTimes()` (neu) lädt die
  `QualifyingTime`-Zeile (n) der aktiven Liste für die gewählte Sportklasse (optional zusätzlich nach Geschlecht
  eingegrenzt, falls auch gesetzt) und gruppiert sie nach Bewerb. Erscheint nur, wenn eine Sportklasse gewählt ist —
  ohne diese Eingrenzung gäbe es je Bewerb bis zu 21 verschiedene Richtzeiten, keine sinnvolle Einzelanzeige. Ohne
  Geschlechtsfilter stehen beide Richtzeiten nebeneinander ("Richtzeit: Herren 00:52.31, Damen 01:00.76"), rechts in der
  Tabellenbeschriftung neben der Bewerbs-Überschrift.
- **Neuer Behinderungsgruppe-Filter:** `PublicQualificationFilter` bekommt ein zusätzliches
  `sportClassGroupId`-Feld, unabhängig von der einzelnen Sportklasse (Rückmeldung: "wenn alle Sportklassen gewählt ist,
  dass man sich nur die Sportklassengruppen ebenfalls ansehen kann, so wie bei der Jahresbestleistung die Klasse") —
  dieselbe `SportClassGroupMember`-Zuordnungstabelle, die `DisabilityGroupGrouper` für die Sektionen ohnehin schon
  nutzt. Beide Filter wirken unabhängig; eine widersprüchliche Kombination liefert schlicht keine Treffer statt eines
  Fehlers.

Verifiziert: Pint grün, `--group=public-p7` 18/18 sowie `--group=qualifying-time-lists-p1` 18/18 (internes Pendant
unverändert), volle Suite 1355/1355; live gegen die Dev-Datenbank per curl geprüft — Behinderungsgruppe-Filter grenzt
korrekt auf eine Sektion ein, Richtzeit-Anzeige zeigt bei `sport_class=S9` "Herren 00:52.31, Damen 01:00.76"
und bei zusätzlichem `gender=M` nur noch "Herren 00:52.31", `items-center` und die ans Formularende verschobenen
versteckten Felder sind im gerenderten HTML bestätigt. Dieselbe Sandbox-Einschränkung verhindert eine visuelle
Bestätigung der Ausrichtung in diesem Environment.

---

## Phase 8 — Regelmente und Formulare — **abgeschlossen**

| Baustein                        | Art        | Zweck                                        |
|---------------------------------|------------|----------------------------------------------|
| `Public\RegulationController`   | Controller | gruppiert nach `category`                    |
| View `public/regulations/index` | Blade      | Titel, Format, Größe, Veröffentlichungsdatum |

Nutzt `documents` ohne `documentable` und die Sprachauflösung aus Phase 2.

**Ergebnis:** Bewusst auf die Kategorien REGULATION und FORM eingeschränkt statt "gruppiert nach allen vorkommenden
category-Werten" — das Admin-Formular für Dokumente ohne Veranstaltungsbezug (Phase 3) erlaubt zwar dieselben fünf
Kategorien wie das Veranstaltungsformular (beide teilen dieselbe Validierung), aber INVITATION/START_LIST/RESULTS
ergeben ohne Meet-Bezug fachlich keinen Sinn. Anzeigereihenfolge (REGULATION vor FORM, passend zum Seitentitel
"Reglemente & Formulare") ist in `RegulationController::CATEGORY_ORDER` fest in PHP verdrahtet statt per
`orderBy('category')` — ein `enum`-Feld sortiert in MySQL nach Deklarationsreihenfolge, in SQLite (Testsuite) dagegen
alphabetisch als Text (CLAUDE.md: jede Query muss auf beiden Datenbanken laufen).

Keine Filter, keine Suche: eine Handvoll Dokumente ist als schlichte, nach Kategorie zweigeteilte Liste übersichtlicher
als eine Tabelle mit Steuerelementen (ähnlich der Punktetabelle in Phase 6) — anders als bei Rekorden oder
Startberechtigung, wo Dutzende Zeilen eine Eingrenzung brauchen.

Die Sprachauflösung/den Linktext-Aufbau ("Titel (Format, Größe)", Verlinkung der anderen Sprachfassung) direkt aus Phase
2 übernommen, dafür `App\Support\MeetDocumentGroup` auf `App\Support\DocumentLocaleGroup` umbenannt und um eine
generische `forDocuments(Collection $documents, string $locale)` erweitert, die eine bereits gefilterte Collection statt
einer `Meet`-Beziehung entgegennimmt — `forMeet()` bleibt als schmaler Wrapper für die unveränderten Aufrufstellen
(`Public\MeetController`, `public/meets/_table`) bestehen. Vermeidet eine zweite Kopie derselben Gruppierungs-/
Paarungslogik. Nebenbei einen Tippfehler in der Kategorie-Übersetzung behoben: `public.documents.category.REGULATION`
hieß "Regelment" statt "Reglement" (DE) — fällt auf dieser neuen Seite als Abschnittsüberschrift zum ersten Mal sichtbar
ins Gewicht.

Kein `noindex` (anders als die Ranglisten-Seiten aus Phase 7): Reglemente und Formulare sind dauerhaft gültiger,
öffentlich relevanter Inhalt, kein personenbezogener Snapshot — `robots.txt` bleibt unverändert.

**Tests** (`public-p8`, alle grün): nur öffentliche, veröffentlichte Dokumente ohne `documentable` sichtbar,
Veranstaltungsdokumente (`documentable` gesetzt) erscheinen hier nicht, meet-typische Kategorien (z. B. INVITATION)
bleiben ausgeblendet, Gruppierung Reglemente vor Formularen, Sprachauflösung bei zwei vorhandenen Fassungen, Download
ganz ohne Veranstaltungsbezug, Seite erscheint in der Hauptnavigation.

---

## Phase 9 — Abschluss — **abgeschlossen**

| Baustein                                                                      | Art           |
|-------------------------------------------------------------------------------|---------------|
| Startseite ausbauen: nächste Veranstaltung, neue Rekorde, aktuelle Ergebnisse | View          |
| Englische Übersetzung vollständig                                             | `lang/en/*`   |
| `/de/barrierefreiheit`                                                        | View + Inhalt |
| Barrierefreiheits-Audit über alle Seiten                                      | Prüfung       |
| `robots.txt`, Sitemap, Meta-Tags                                              | Konfiguration |

Prüfumfang nach [accessibility.md](../accessibility.md) §Prüfung, einschließlich Screenreader-Durchsicht.

**Ergebnis:** Startseite mit drei Kacheln (Tailkit a-c-cards-02 "Simple in Grid",
`docs/snippets/card-grid.html` — bis hierhin ungenutzt): nächste veröffentlichte Veranstaltung
(`PublicMeetService::upcoming(1)`), neue nationale Rekorde (neue Methode
`PublicRecordService::recent()`, bewusst nicht über `PublicRecordFilter`/`forFilter()` — die Startseite hat keinen
Filterzustand und soll immer die nationale Ebene zeigen, kein zuletzt gewähltes Level), aktuelle Ergebnisse als
Teaser-Link auf die letzte vergangene Veranstaltung **mit** tatsächlich erfassten Ergebnissen
(`HomeController::latestMeetWithResults()`, nicht zwangsläufig die chronologisch letzte — eine veröffentlichte
Veranstaltung kann noch ohne Ergebnisse dastehen). Bewusst keine einzelnen Ergebniszeilen auf der Startseite: welche
Zeilen dort
"hervorzuheben" wären, ist willkürlich und nicht spezifiziert.

Englische Übersetzung war bereits vollständig (`lang/de/public.php` und `lang/en/public.php` beim Start dieser Phase
strukturell 1:1 deckungsgleich, keine leeren Werte) — hier gab es nichts zu tun.

`/de/barrierefreiheit` (`Public\AccessibilityStatementController`) zeigt bewusst nur die Kontaktmöglichkeit
(`schwimmen@obsv.at`, Rückmeldung). Konformitätsstand und Schlichtungsverfahren fehlen absichtlich — dafür bräuchte es
zuerst eine echte Prüfung bzw. eine Entscheidung des Verbands, keine Selbstauskunft ohne Grundlage. Als offener Punkt in
[`docs/open-points.md`](../open-points.md) festgehalten, statt es stillschweigend zu vergessen oder mit Platzhaltertext
zu füllen. Verlinkt aus der Fußzeile, nicht dem Hauptmenü (wie
"Impressum"/"Datenschutz" auf den meisten Websites üblich) — die Fußzeile bekam dafür zusätzlich zur Copyright-Zeile
diesen einen echten Link.

Der Barrierefreiheits- **Audit** selbst (axe DevTools, Tastaturdurchlauf, Kontrastprüfung, 200-%-Zoom,
Screenreader-Durchsicht) ist laut `accessibility.md` §Prüfung eine **manuelle**
Prüfung — in dieser Umgebung ohne echten Browser mit axe/Screenreader nicht durchführbar. Stattdessen eine
Templates-Durchsicht der neuen Phase-9-Seiten gegen die dokumentierten Konventionen: `home.blade.php`s drei Kachel-Links
("Details ansehen" / "Ergebnisse ansehen")
waren aus dem Zusammenhang gerissen (z. B. in einer Screenreader-Linkliste) zu unspezifisch —
`aria-label` mit dem jeweiligen Veranstaltungsnamen ergänzt (sinngemäß dieselbe Regel wie bei Dokumentlinks: "Art,
Format und Größe im Linktext", hier: "welche Veranstaltung"). Der Kontraststichprobe blieb auf bereits im Bestand
verwendete Klassen (`text-gray-500/600`,
`bg-gray-50`/`dark:bg-gray-700/50`) beschränkt — keine neuen Farbwerte eingeführt. Ein echter Tastaturdurchlauf und eine
Screenreader-Durchsicht bleiben offen (siehe `docs/open-points.md`? — nein, bewusst **nicht** dort eingetragen: das ist
eine wiederkehrende Aufgabe vor jedem Livegang, kein einmalig nachzuholender Punkt an dieser einen Phase).

`robots.txt` war bis zu dieser Phase eine statische Datei unter `public/robots.txt` — umgestellt auf
`Public\RobotsController` (Route statt Datei), damit die neue `Sitemap:`-Zeile eine echte absolute URL trägt (`url()`,
löst gegen `APP_URL` auf); eine statische Datei kennt die aktuelle Umgebung (Dev- vs. Produktionsdomain) nicht, und das
Sitemap-Protokoll verlangt ohnehin eine absolute Angabe. Die alte Datei musste dafür entfernt werden — sonst liefert der
Webserver sie weiter direkt aus, ohne Laravel je zu erreichen. `Public\SitemapController` (`/sitemap.xml`, kein
Sprachpräfix, eine Datei für beide Sprachen) listet dieselben Seiten, die auch in der Navigation stehen, plus eine URL
je veröffentlichter Veranstaltung — bewusst **nicht** die per `robots.txt`
gesperrten Seiten (Cup-Wertung, Startberechtigung, Jahresbestleistungen, Ergebnislisten): eine Sitemap, die auf
gesperrte Seiten verweist, wäre widersprüchlich.

Meta-Description: neue `@section('description', ...)`-Konvention analog zu `title`/`robots`, mit Fallback auf
`public.meta.default_description` über `$__env->yieldContent('description')` statt eines `@hasSection`/`@yield`-Paars —
jede Seite bekommt dadurch eine Beschreibung, auch ohne eigene. Sieben Seiten mit ohnehin vorhandenem Intro-Absatz
(`public.*.intro`, z. B.
`public.regulations.intro`) setzen exakt diesen Text auch als Meta-Description, statt eine zweite, nur geringfügig
abweichende Formulierung zu pflegen. Veranstaltungsdetail bekam eine dynamische, mit `:name`/`:date`/`:city`
-Platzhaltern zusammengesetzte Beschreibung statt eines statischen Satzes.

**Tests** (`public-p9`, alle grün): Startseiten-Leerzustände ohne Daten, nächste veröffentlichte Veranstaltung erscheint
(unveröffentlichte nicht), neue Rekorde zeigen nur die nationale Ebene, Ergebnis-Teaser zeigt die letzte Veranstaltung
**mit** Ergebnissen statt der chronologisch letzten, Barrierefreiheitsseite mit Kontaktadresse, seiteneigene und
Fallback-Meta-Description, Sitemap enthält die erwarteten statischen und dynamischen URLs je Sprache, Sitemap lässt
gesperrte Seiten aus, `robots.txt` nennt die Sitemap-URL. Zusätzlich zwei bestehende Tests (Phase 4, Phase 7)
angepasst, die bislang direkt die jetzt entfernte statische `public/robots.txt` gelesen hatten — lesen jetzt über
`$this->get('/robots.txt')`.

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
