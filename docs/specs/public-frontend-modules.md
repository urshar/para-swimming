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
  Herren- und Damen-Spaltenblock nun auch eine (`border-r-2`) zwischen der Sportklassen-Spalte und dem Herren-Block —
  in der Kopfzeile (`corner-{code}`) und je Zeile (`row-{code}-{id}`, Rückmeldung: "sieht besser aus").
- **WPS-Punkterechner: Sportklassen-Liste wie beim ÖBSV-Rechner.** Die Optionen zeigten bisher nur die nackte Zahl
  (`1`, `2`, … `21`), der ÖBSV-Punkterechner dagegen den vollen Code (`S1`, `S2`, … `S21`) — dieselbe Uneinheitlichkeit
  wie bei den zwei Rechnern generell (Rückmeldung). Angeglichen: die Optionen zeigen jetzt `S{{ $number }}` als reines
  Label; der `<option value>` bleibt die nackte Zahl, der Controller leitet die tatsächliche WPS-Kategorie (S/SB/SM)
  weiterhin aus dem gewählten Bewerb ab (`STROKE_CATEGORY_MAP`) — unverändert in der Berechnung, nur die Anzeige zieht
  mit dem ÖBSV-Rechner gleich.

Erneut verifiziert: Pint grün, `--group=public-p6` 19/19, volle Suite 1337/1337, live gegen die Dev-Datenbank (Vorgabe
SCM sichtbar, `S1`…`S21` im WPS-Rechner, Trennlinie im HTML vorhanden).

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
