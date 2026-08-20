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

| Baustein | Art | Zweck |
|---|---|---|
| `documents`-Migration | Migration | polymorphe Dokumententabelle (§4.1) |
| `meets`-Ergänzung | Migration | `livetiming_url`, `is_published` |
| `App\Models\Document` | Model | `documentable()`, Scopes `public()`, `published()`, `forLocale()` |
| `App\Http\Middleware\SetLocale` | Middleware | Sprache aus Präfix, Cookie, Browser |
| `routes/public.php` | Routen | eigene Datei, `web.php` bleibt unberührt |
| `resources/views/layouts/public.blade.php` | Layout | Grundgerüst, von Hand geschrieben — kein Tailkit (siehe Ergebnis) |
| `resources/css/public.css`, `resources/js/public.js` | Assets | eigener Vite-Entry |
| `resources/js/theme.js` | JS | Hell/Dunkel/System, `localStorage`, Inline-Init |
| `lang/de/public.php`, `lang/en/public.php` | Übersetzung | Grundwortschatz |
| `App\Http\Controllers\Public\HomeController` | Controller | Startseite (Grundgerüst) |

**Vorab:** Tailkit ist eine Snippet-Quelle ohne Paketinstallation (§3.1.1) — ein Konfigurationskonflikt ist
ausgeschlossen.

**Ergebnis:** Anders als hier ursprünglich vorgesehen, entstand das Phase-1-Layout nicht aus den sechs
Grundbausteinen aus §3.1.3, sondern als schlichtes, von Hand geschriebenes Gerüst ohne jedes Tailkit-Markup —
committet, damit Routing und Tests auf jedem Checkout laufen, unabhängig von lokal zugelieferten Snippets und
unabhängig von der noch offenen Lizenzfrage. Die sechs Bausteine und die daraus gebaute, gestaltete Fassung folgen
als lokaler, ungetrackter Ausbauschritt, sobald sie gebraucht werden (spätestens ab Phase 2) — siehe §3.1.2 zum
Vorgehen beim Ersetzen (`git rm --cached`, sobald Tailkit-Markup einzieht).

**Tests** (`--group=public-p1`, alle grün): Sprachweiterleitung, Cookie-Vorrang, `hreflang`, Document-Scopes, `/`
führt nicht mehr in den Login.

---

## Phase 2 — Veranstaltungen

| Baustein | Art | Zweck |
|---|---|---|
| `Public\MeetController` | Controller | Liste und Detail |
| `App\Services\Public\PublicMeetService` | Service | kommend/vergangen, Jahresfilter, nur `is_published` |
| `App\Support\MeetDocumentGroup` | Wertobjekt | Dokumente je Kategorie, Sprachauflösung (§4.1) |
| `Public\DocumentDownloadController` | Controller | prüft `is_public` und `published_at`, liefert aus `Storage` |
| Views `public/meets/{index,show}` | Blade | Liste, Detail mit Dokumenten, Livetiming, Meldeschluss |

Die Sprachauflösung („zeige die passende Fassung, verlinke die andere") gehört in das Wertobjekt, nicht in die View —
sie wird in Phase 8 für Regelmente erneut gebraucht.

**Tests** (`public-p2`): unveröffentlichte Meets unsichtbar, Dokument ohne `published_at` nicht abrufbar, direkter
Pfadzugriff greift nicht, Sprachauflösung in allen drei Fällen (nur de / nur en / neutral).

---

## Phase 3 — Adminbereich Dokumente

| Baustein | Art | Zweck |
|---|---|---|
| `Admin\DocumentController` | Controller | CRUD |
| `App\Services\DocumentService` | Service | Upload, Ablage, Reihenfolge, Löschung samt Datei |
| `App\Http\Requests\StoreDocumentRequest` | Request | Validierung: Typ, Größe, Kategorie/Sprache-Kombination |
| Views `admin/documents/*` | Blade | **Flux**, wie der übrige interne Bereich |

Beim Upload eines LENEX zur Kategorie `INVITATION`: Hinweistext gemäß §4.3, `is_public` bleibt `false`.

**Tests** (`public-p3`): nur Admin, Datei landet nicht unter `public/`, Löschung entfernt die Datei, LENEX-Hinweis
erscheint.

---

## Phase 4 — Ergebnisse

| Baustein | Art | Zweck |
|---|---|---|
| `Public\MeetResultController` | Controller | **nur** je Meet, kein Athleten-Endpunkt |
| `App\Services\Public\PublicResultService` | Service | Ergebnisse je Bewerb und Klasse |
| View `public/meets/results` | Blade | Namen unverlinkt, `noindex` |

Die engste Phase der Spec. `robots.txt` und Meta-Tags gehören hierher, nicht in eine spätere Aufräumphase.

**Tests** (`public-p4`): keine Route filtert nach Athlet, `noindex` vorhanden, Ergebnisse unveröffentlichter Meets
nicht erreichbar.

---

## Phase 5 — Rekorde

| Baustein | Art | Zweck |
|---|---|---|
| `Public\RecordController` | Controller | Übersicht mit Filtern |
| `App\Support\PublicRecordFilter` | Wertobjekt | Klasse, Geschlecht, Bahn, Alter, Ebene — geteilt von Ansicht und Export |
| `App\Services\Public\PublicRecordService` | Service | Auswertung, nutzt `record_type` (`AUT`, `AUT.JR`, `AUT.<LV>`) |
| `Public\RecordExportController` | Controller | LENEX und PDF |

Das geteilte Filter-Wertobjekt folgt dem Muster von `QualificationOverviewFilter`: Zweimal ausprogrammiert liefen
Bildschirm und PDF im Bestand bereits auseinander. Export nutzt `RecordLenexExportService` und `PdfExportService`
weiter, ohne die internen Statusfilter.

**Tests** (`public-p5`): Regionalfilter je Landesverband, LENEX validiert, PDF entspricht der Bildschirmauswahl.

---

## Phase 6 — Punktetabelle und Rechner

| Baustein | Art | Zweck |
|---|---|---|
| `Public\BaseTimeTableController` | Controller | Tabelle je Version und Lage |
| `Public\PointCalculatorController` | Controller | eigene Seite, kein Dialog |
| `App\Services\PointConversionService` | Service | Zeit → Punkte **und** Punkte → Zeit |
| `resources/js/point-calculator.js` | JS | Alpine, `Alpine.data()` |

Die Rückrechnung (Schätzung, dann hundertstelweise Annäherung) existiert bereits sinngemäß in
`QualifyingTimeCalculationService`. Sie wird in den neuen Service gezogen und dort **einmal** implementiert; das
Richtzeiten-Modul nutzt sie danach mit. Zwei Fassungen derselben Iteration driften sonst auseinander.

**Tests** (`public-p6`): Hin- und Rückrechnung sind zueinander konsistent, Grenzfälle (fehlende Basiszeit,
Punktzahl 0), Richtzeiten-Modul weiterhin grün.

---

## Phase 7 — Ranglisten

| Baustein | Art | Zweck |
|---|---|---|
| `Public\CupRankingController` | Controller | Cup-Wertung je Jahr |
| `Public\QualifyingTimeController` | Controller | ÖSTM-Startberechtigung |
| `Public\AnnualBestController` | Controller | Jahresbestleistungen |
| `App\Services\AnnualBestService` | Service | siehe unten |

`AnnualBestService`: eine Zeile je Person — bester Einzelbewerb nach ÖBSV-Punkten im **Kalenderjahr**; getrennt nach
Geschlecht und Gruppen PI, VI, MI, HI, T21; **keine Staffeln**, **EXH ausgeschlossen**. Rein lesend.

Die Gruppenzuordnung (S01–S10, S11–S13, S14, S15, S21) darf nicht neu ausprogrammiert werden — vorhandene Logik aus
dem Cup-Modul bzw. `SportClassSorter` nutzen. Suchfelder filtern nur die geladene Tabelle (§2.3 Punkt 3).

**Tests** (`public-p7`): EXH bleibt draußen, Staffeln bleiben draußen, genau eine Zeile je Person, Jahresgrenzen,
Gruppenzuordnung.

---

## Phase 8 — Regelmente und Formulare

| Baustein | Art | Zweck |
|---|---|---|
| `Public\RegulationController` | Controller | gruppiert nach `category` |
| View `public/regulations/index` | Blade | Titel, Format, Größe, Veröffentlichungsdatum |

Nutzt `documents` ohne `documentable` und die Sprachauflösung aus Phase 2.

**Tests** (`public-p8`): Gruppierung, Sortierung, nur veröffentlichte Dokumente.

---

## Phase 9 — Abschluss

| Baustein | Art |
|---|---|
| Startseite ausbauen: nächste Veranstaltung, neue Rekorde, aktuelle Ergebnisse | View |
| Englische Übersetzung vollständig | `lang/en/*` |
| `/de/barrierefreiheit` | View + Inhalt |
| Barrierefreiheits-Audit über alle Seiten | Prüfung |
| `robots.txt`, Sitemap, Meta-Tags | Konfiguration |

Prüfumfang nach [accessibility.md](../accessibility.md) §Prüfung, einschließlich Screenreader-Durchsicht.

---

## Was bewusst nicht gebaut wird

| nicht enthalten | Grund |
|---|---|
| Athletenprofile | §2 |
| Personenübergreifende Ergebnissuche | §2 |
| Livetiming eingebettet | externer Dienst, eigener Status |
| Nachrichten/News-Modul | nicht angefordert |
| Öffentliches Meldewesen | bleibt im internen Bereich |
| FinSwim | entfällt gegenüber der Altseite |
