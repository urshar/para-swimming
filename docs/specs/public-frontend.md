# Spec: Öffentliches Frontend

Ein **loginfreier Bereich** für Athleten, Vereine, Angehörige, Medien und internationale Gäste. Er zeigt
Veranstaltungen mit ihren Dokumenten, Ergebnisse je Veranstaltung, Rekorde, Ranglisten, die ÖBSV-Punktetabelle samt
Rechner sowie Regelmente und Formulare.

Der bestehende, angemeldete Bereich (Meldewesen, Athletenverwaltung, Admin-Werkzeuge) bleibt unverändert. Diese Spec
beschreibt ausschließlich den zusätzlichen öffentlichen Teil und die dafür nötigen Backend-Ergänzungen.

Vorbild und Ablösung: die bisherige Seite `sport.a-timing.wien` (nur der ParaSwim-Teil; FinSwim entfällt).

## 1. Zielsetzung

- Ein Besucher findet ohne Anmeldung: Wann ist die nächste Veranstaltung, wo, bis wann kann gemeldet werden, wo ist die
  Ausschreibung, wo läuft das Livetiming, wo sind die Ergebnisse.
- Sportler sehen ihre Einordnung: Rekorde, ÖBSV-Cup-Wertung, Jahresbestleistungen.
- Vereine finden Formulare, Regelmente und die maschinenlesbaren Dateien für ihre Meldeprogramme.
- Die Seite ist zweisprachig (Deutsch/Englisch), in Hell- und Dunkeldarstellung nutzbar und erfüllt **WCAG 2.1 AA**.

## 2. Umfang der Veröffentlichung

Dieser Abschnitt ist die wichtigste fachliche Festlegung der Spec. Er begründet, **was öffentlich ist und was nicht**,
damit spätere Erweiterungen eine dokumentierte Ausgangslage vorfinden und nicht schrittweise aufweichen.

### 2.1 Ausgangslage

Eine Sportklasse (`S6`, `SB4`, `SM9`) ist eine Aussage über Art und Schweregrad einer Behinderung und damit ein
Gesundheitsdatum im Sinn von Art. 9 DSGVO. Jede öffentliche Liste, die Name und Sportklasse zusammen zeigt,
veröffentlicht dieses Datum — dauerhaft und für Suchmaschinen auffindbar.

Dass World Para Swimming Klassifizierungen international offen publiziert, ist ein Argument, aber keine
Rechtsgrundlage für den ÖBSV als eigenen Verantwortlichen.

### 2.2 Trennlinie

Die Grenze verläuft **nicht** zwischen „Ergebnisse" und „alles andere", sondern zwischen:

| veröffentlicht | nicht veröffentlicht |
|---|---|
| Kuratierte Bestenlisten: Rekorde, Cup-Wertung, Jahresbestleistungen | Die vollständige Starthistorie einer Person |
| Ergebnisse **je Veranstaltung** (Protokollcharakter) | Personenübergreifende Ergebnissuche |
| Vereinszugehörigkeit, Jahrgang, Sportklasse innerhalb dieser Listen | Athletenprofile, Kaderzugehörigkeit, Klassifizierungshistorie |

Begründung: Bestenlisten haben einen abgegrenzten Zweck (sportlicher Vergleich, Auszeichnung), betreffen eine begrenzte
Personenzahl und sind redaktionell freigegeben. Eine freie Personensuche über alle Starts aller Jahre erzeugt dagegen
faktisch ein Profil — auch ohne dass eine Profilseite existiert.

### 2.3 Verbindliche Regeln

1. Ergebnisse sind **ausschließlich** über die Veranstaltung erreichbar
   (`/veranstaltungen/{meet}/ergebnisse`). Es gibt keinen Athleten-Endpunkt und keine Route, die Ergebnisse nach Person
   filtert.
2. **Namen werden nirgends verlinkt.** Sie sind unverlinkter Text. Dies ist die Stelle, an der die Trennung
   erfahrungsgemäß aufweicht.
3. Suchfelder in Ranglisten filtern **nur die bereits geladene Tabelle** (Cup-Wertung, Jahresbestleistungen). Keine
   serverseitige Volltextsuche über Personen.
4. Ergebnis- und Ranglistenseiten tragen `noindex`; die `robots.txt` schließt `/de/veranstaltungen/*/ergebnisse` und
   die Ranglisten aus. Sonst baut die Suchmaschine die Personensuche, die hier bewusst nicht gebaut wird.
5. Öffentliche LENEX-Dateien dürfen **keine Meldungen** enthalten — siehe §4.3.
6. Eine Erweiterung dieses Umfangs (z. B. freie Ergebnissuche) setzt eine geklärte Rechtsgrundlage voraus und ist eine
   eigene Phase mit eigener Entscheidung, keine Implementierungsdetailfrage.

## 3. Technische Grundlage

### 3.1 Zwei getrennte UI-Welten

| | interner Bereich | öffentlicher Bereich |
|---|---|---|
| Komponenten | Livewire Flux (Pro) | **Tailkit-Snippets** (siehe unten) |
| Layout | `layouts/app.blade.php` | `layouts/public.blade.php` |
| CSS-Entry | `resources/css/app.css` | `resources/css/public.css` |
| JS-Entry | `resources/js/app.js` | `resources/js/public.js` |

Beide setzen auf Tailwind 4 auf, laufen aber getrennt: Im öffentlichen Layout wird **kein Flux geladen**. Grund: Flux
bringt eigenes CSS und eigene Farb-Variablen mit; eine Vermischung führt zu schwer auffindbaren Überschreibungen.

### 3.1.1 Tailkit ist eine Snippet-Quelle, kein Paket

Die Lizenz ist ein Online-Zugang: Markup wird je Baustein aus dem Tailkit-Katalog kopiert. Es gibt **keine
Paketinstallation, keine Tailkit-Konfiguration und keine Abhängigkeit im Build**. Daraus folgt:

- Ein Konflikt zwischen Tailkit- und Projekt-Tailwind-Konfiguration ist ausgeschlossen — der ursprünglich
  vorgesehene blockierende Prüfschritt entfällt.
- Kopiertes Markup wird zu **Projektcode**: Es wird angepasst, umbenannt und gepflegt wie eigener Code. Es gibt kein
  Update von außen.
- Snippets sind **Ausgangspunkt, nicht Ergebnis**. Vor Übernahme sind sie gegen
  [accessibility.md](../accessibility.md) zu prüfen und dort anzupassen, wo sie die Anforderungen nicht erfüllen.

**Lizenzrechtlich zu klären:** ob kopiertes Tailkit-Markup in einem öffentlichen Repository liegen darf. Das Repo ist
derzeit `public`. Bis das geklärt ist, keine Snippets committen.

### 3.1.2 Beschaffung über den Tailkit-MCP-Server

Ein MCP-Server ersetzt das manuelle Kopieren aus der Tailkit-Weboberfläche. Vier Werkzeuge stehen zur Verfügung:

- `browse_catalog` — Katalog durchsteigen: Paket → Kategorie → Unterkategorie → Komponenten.
- `search_components` — Volltextsuche über Beschreibungen.
- `get_component_suggestions` — kuratierte Sets je Seitentyp; für eine Verbandsseite nur bedingt treffend.
- `get_component_code` — liefert Code zu einem oder mehreren (max. 10) Bezeichnern, wahlweise als `html`, `react`,
  `vue` oder `alpine`.

**Format:** Standardmäßig `alpine`. Die `html`-Fassung enthält bei interaktiven Bausteinen (Menüs, Dialoge,
Akkordeons) nur Kommentare, die das nötige Skriptverhalten beschreiben — kein Code. Die `alpine`-Fassung liefert
dieselbe Auszeichnung bereits mit `x-data`/`x-show`/`x-transition`/`x-bind` verdrahtet. Bei rein statischen Bausteinen
(Footer, Karten-Raster) sind beide Fassungen identisch. Da der öffentliche Bereich ohnehin Alpine.js lädt, spart das
Nacharbeit; die Alpine-Logik wird beim Einbau wie gewohnt nach `Alpine.data()` ausgelagert (§Alpine-Doppelinitialisierung
in CLAUDE.md).

**Ablauf je Baustein:**

1. Bezeichner finden über `search_components` oder `browse_catalog`.
2. Code holen: `get_component_code(identifier, tech="alpine")`.
3. Ablegen unter `docs/snippets/<sprechender-name>.html`, mit Kopfkommentar, der auf den Bezeichner verweist
   (`<!-- Tailkit: m-s-main-headers-01 "Simple" -->`), damit ein späteres erneutes Abrufen oder ein Abgleich mit einer
   aktualisierten Katalogversion nachvollziehbar bleibt.
4. Ab hier unverändert: Rohmaterial, gegen [accessibility.md](../accessibility.md) geprüft und dort angepasst, wo es
   die Anforderungen nicht erfüllt. Die Dateien sind **Rohmaterial**, keine Quelle, aus der gebaut wird — die
   tatsächliche Umsetzung liegt in `resources/views/public/`. Nach dem Umbau einer Ansicht darf das Snippet stehen
   bleiben oder entfallen.

**Solange die Lizenzfrage offen ist, liegt `docs/snippets/` von Beginn an in der `.gitignore`** — nicht erst bei
negativem Ausgang. Das betrifft nicht nur die Rohfassungen: Auch jede View, die aus einem Snippet entsteht (allen
voran `layouts/public.blade.php`), ist Tailkit-abgeleitetes Markup und bleibt bis zur Klärung ungetrackt. Fällt die
Prüfung positiv aus, werden beide Ausschlüsse entfernt und der bis dahin lokal entstandene Bestand nachträglich
committet.

### 3.1.3 Grundbausteine für Phase 1

Diese sechs kehren auf nahezu jeder Seite wieder und werden zu Beginn zusammengetragen; die späteren Seiten sind
überwiegend Varianten davon:

1. Seitenkopf mit Navigation (mit Untermenü, entsprechend dem bisherigen ParaSwim-Menü)
2. Fußzeile
3. Datentabelle mit Filterleiste
4. Karten-Raster (Startseite)
5. Formularelemente (Punkterechner, Filter)
6. Hell/Dunkel-Umschalter, sofern vorhanden — sonst eigene Umsetzung

**Auswirkung auf den Ablauf:** Phase 2 und die folgenden Phasen können nicht am Stück durchlaufen. Je Seite wird das
passende Snippet zugeliefert, bevor die View entsteht. Das ist beim Zuschnitt der Phasen einzuplanen.

### 3.2 Routen und Sprache

- Präfix je Sprache: `/de/...` und `/en/...`, gesetzt über eine `SetLocale`-Middleware.
- `/` erkennt die Browsersprache und leitet weiter; die Wahl wird in einem Cookie gemerkt.
- Jede Seite trägt `hreflang`-Verweise auf ihre andere Sprachfassung.
- **Wegfall der bisherigen Weiterleitung:** `Route::redirect('/', '/meets')` entfällt. `/meets` bleibt intern und
  authentifiziert; die öffentliche Veranstaltungsliste ist eine eigene Route.

Alle öffentlichen Routen in einer eigenen Datei `routes/public.php`, registriert in `bootstrap/app.php`. Damit bleibt
`routes/web.php` unangetastet — dort sind in der Vergangenheit bereits Routen verlorengegangen.

### 3.3 Was übersetzt wird

| übersetzt | nicht übersetzt |
|---|---|
| Oberfläche, Navigation, Spaltenköpfe, Hinweistexte | **Veranstaltungsnamen** (Eigennamen, bleiben in Landessprache) |
| Statische Seitentexte (Punktetabellen-Erläuterung) | Vereinsnamen, Ortsnamen |
| Dokumenttitel (über `documents.locale`) | Sportklassen, Bewerbsbezeichnungen (S6, 50m Freistil) |

Bewerbsbezeichnungen bekommen Sprachvarianten über `lang/`-Dateien („Freistil" / „Freestyle"), da sie generiert werden.

### 3.4 Hell- und Dunkeldarstellung

- Umschalter mit drei Zuständen: Hell / Dunkel / Systemeinstellung.
- Speicherung in `localStorage`, Anwendung über ein Inline-Skript **vor** dem ersten Rendern (sonst kurzes Aufblitzen
  der hellen Darstellung).
- **Beide** Modi müssen die Kontrastwerte aus §7 erfüllen. Dunkeldarstellungen fallen bei Kontrastprüfungen häufiger
  durch als helle — insbesondere bei Statusfarben und Badges.

## 4. Datenmodell-Ergänzungen

### 4.1 Neue Tabelle `documents`

Polymorph, damit dieselbe Tabelle Veranstaltungsdokumente **und** Regelmente/Formulare trägt.

| Spalte | Typ | Bemerkung |
|---|---|---|
| `documentable_type`, `documentable_id` | nullable | `Meet` oder leer (allgemeine Dokumente) |
| `category` | enum | `INVITATION`, `START_LIST`, `RESULTS`, `REGULATION`, `FORM` |
| `title` | string | Anzeigetext |
| `locale` | string, nullable | `de`, `en`, `null` = sprachneutral |
| `path` | string | Speicherort |
| `mime_type`, `size_bytes` | | für die Linkbeschriftung (§7) |
| `is_public` | boolean, Default `false` | siehe §4.3 |
| `published_at` | datetime, nullable | `null` = Entwurf, nicht sichtbar |
| `sort_order` | integer | manuelle Reihenfolge |

Anzeigelogik je Sprache: Existiert das Dokument in der aktiven Sprache, wird es gezeigt und die andere Fassung daneben
verlinkt. Existiert nur eine sprachneutrale Fassung, wird diese in beiden Sprachen gezeigt.

### 4.2 Neue Felder auf `meets`

| Feld | Typ | Zweck |
|---|---|---|
| `livetiming_url` | string, nullable | externer Swimify-Link, je Veranstaltung individuell |
| `is_published` | boolean, Default `false` | steuert die öffentliche Sichtbarkeit |

`is_published` mit Default `false` ist bewusst gewählt: Andernfalls wären mit der Migration schlagartig alle
Bestandsdatensätze öffentlich, einschließlich Testdaten und noch nicht freigegebener Veranstaltungen.

### 4.3 LENEX-Dateien: `is_public`

Bei `RESULTS` ist ein öffentliches LENEX unproblematisch — die Ergebnisse stehen ohnehin auf der Veranstaltungsseite.

Bei `INVITATION` gilt: Öffentlich ausgespielt wird **nur die Wettkampfstruktur** (Bewerbe, Zeitplan, Bahnlänge), wie
Vereine sie in ihr Meldeprogramm laden. Ein LENEX **mit** Meldungen enthält Name, Jahrgang, Sportklasse und Meldezeit
aller gemeldeten Personen und wäre maschinenlesbar genau die Personendatei, die §2 ausschließt.

Deshalb trägt jedes Dokument `is_public`. Beim Upload eines LENEX zur Kategorie `INVITATION` weist der Adminbereich
ausdrücklich darauf hin; der Default ist `false`.

## 5. Seitenstruktur

| Seite | Route (de) | Quelle | Backend |
|---|---|---|---|
| Startseite | `/de` | kommende Meets, neue Rekorde | Flag ✗ |
| Veranstaltungen | `/de/veranstaltungen` | `meets` | ✓ |
| Veranstaltung | `/de/veranstaltungen/{meet}` | + Dokumente, Livetiming, Meldeschluss | Doks ✗ |
| Ergebnisse | `/de/veranstaltungen/{meet}/ergebnisse` | `results` | Ansicht ✗ |
| Rekorde | `/de/rekorde` | `swim_records` | ✓ |
| Rekord-Download | `/de/rekorde/export` | `RecordExportController` | öffentl. Variante ✗ |
| Punktetabelle | `/de/punktetabelle` | `base_times` | ✓ |
| Punkterechner | `/de/punkterechner` | `WorldAquaticsPointsService` | Rückrechnung ✗ |
| ÖBSV Cup | `/de/cup/{jahr}` | Cup-Wertung | ✓ |
| Startberechtigung | `/de/startberechtigung` | `QualifyingTimeList` | ✓ |
| Jahresbestleistungen | `/de/bestleistungen/{jahr}` | `results` | Service ✗ |
| Regelmente & Formulare | `/de/regelmente` | `documents` | ✗ |

### 5.1 Veranstaltungen

Zwei Blöcke wie bisher: **kommend** und **vergangen**, je zwölf Monate, mit Jahresfilter für ältere Jahrgänge. Spalten:
Datum, Name, Ort, Meldeschluss, Dokumente.

Die alte Icon-Spalte (I/S/L/R) wird ersetzt: Statt vier stummer Symbole trägt jedes Dokument einen sprechenden Link
(„Ausschreibung, PDF, 240 kB"). Die Checkbox-Spalte der alten Seite entfällt ersatzlos.

Der Livetiming-Link führt nach extern (`rel="noopener"`, als externer Link gekennzeichnet). Kein „läuft gerade"-Status
— den zeigt Swimify selbst.

### 5.2 Rekorde

Filter: Sportklasse, Geschlecht, Bahnlänge (SC/LC), Altersklasse (offen/Jugend) und **Rekordebene**. Letztere nutzt die
vorhandenen `record_type`-Werte: `AUT` (national), `AUT.JR`, sowie je Landesverband `AUT.<LV>` und `AUT.<LV>.JR`. Die
Ableitung existiert bereits über `Club::regional_record_type`.

Download als **LENEX und PDF** — der `RecordExportController` leistet das bereits; für den öffentlichen Bereich
entsteht eine schlankere Variante ohne die internen Statusfilter.

### 5.3 Punktetabelle und Rechner

Die Tabelle zeigt die Basiszeiten je Version, gegliedert nach Lage, mit Damen/Herren nebeneinander — wie bisher.

Der **Rechner** ist eine eigene Seite, nicht ein Dialogfenster. Zwei getrennte Modi:

- **Zeit → Punkte:** direkte Anwendung von `P = 1000 · (B/T)³`, vorhanden in `WorldAquaticsPointsService`.
- **Punkte → Zeit:** inverse Formel als erste Schätzung, dann hundertstelweise verkürzen, bis die Rückrechnung die
  Ausgangspunktzahl ergibt. Diese Iteration ist neu zu bauen; dieselbe Logik existiert bereits in
  `QualifyingTimeCalculationService` und wird dorthin ausgelagert oder von dort übernommen.

Der alte Dialog hatte neun Eingabefelder und drei parallele Ergebnisfelder (Damen/Herren/Mixed). Stattdessen: Geschlecht
als Auswahl, ein Ergebnis. Ein Dialogfenster mit neun Feldern ist für Tastatur- und Screenreader-Bedienung die
schlechtere Form.

### 5.4 Jahresbestleistungen

Zweck: Auszeichnung der besten Schwimmer einer Saison. Reihung nach **ÖBSV-Punkten**. Eine Saison ist immer ein
**Kalenderjahr**.

- **Eine Zeile je Person** — ihr bestes Einzelergebnis über alle Bewerbe hinweg (die Lage mit der höchsten Punktzahl).
- Getrennt nach Geschlecht und den Gruppen **PI, VI, MI, HI, T21**, entsprechend den Sportklassenbereichen
  S01–S10, S11–S13, S14, S15, S21.
- **Keine Staffeln.**
- **EXH-Ergebnisse ausgeschlossen.** Damit bleibt die projektweite Regel erhalten: EXH zählt für Richtzeiten und
  Rekorde, nicht für Punkteranglisten.
- Neuer Service `AnnualBestService`, rein lesend, nichts persistiert — wie im Statistik- und Qualifikationsmodul.

## 6. Adminbereich

Der interne Bereich bekommt eine Dokumentenverwaltung (Flux, wie der übrige interne Bereich):

- Upload je Veranstaltung, mit Kategorie, Sprache, Sichtbarkeit und Reihenfolge.
- Getrennte Verwaltung für Regelmente und Formulare.
- Sichtbarkeitsschalter `is_published` an der Veranstaltung.
- Beim Upload eines LENEX zur Kategorie `INVITATION`: Hinweis auf §4.3.

Speicherung über `Storage`; Auslieferung über einen Controller, der `is_public` und `published_at` prüft — **nicht**
als direkt erreichbare Datei unter `public/`. Andernfalls wäre jedes Dokument über seine URL erreichbar, auch das noch
nicht freigegebene.

## 7. Barrierefreiheit

Verbindlich nach [accessibility.md](../accessibility.md) — dort projektweit geregelt, weil die Anforderungen auch den
internen Bereich und jede künftige View betreffen.

Für den öffentlichen Bereich besonders relevant:

- Die Tailkit-Komponenten sind vor Übernahme einzeln auf Tastaturbedienung und ARIA zu prüfen (accessibility.md
  §Komponentenbibliotheken).
- Die breiten Tabellen (Punktetabelle, Rekorde) brauchen zweistufige Kopfzeilen mit `headers`/`id` sowie eine
  alternative Darstellung unter 320px.
- Beide Darstellungsmodi müssen die Kontrastwerte erfüllen.
- Eine **Erklärung zur Barrierefreiheit** unter `/de/barrierefreiheit` ist Teil von Phase 9.

Geprüft wird je Phase, nicht am Ende.

## 8. Phasenplan

| Phase | Inhalt |
|---|---|
| **1** | Fundament: `documents`-Tabelle, Meet-Felder, `routes/public.php`, Layout, Vite-Entry, Sprach-Middleware, Dark/Light, Startseite (Grundgerüst) |
| **2** | Veranstaltungen: Liste, Detail, Dokumente, Livetiming, Meldeschluss |
| **3** | Adminbereich: Dokumentenverwaltung, Upload, Sichtbarkeit |
| **4** | Ergebnisse je Veranstaltung |
| **5** | Rekorde inkl. Regionalebenen und LENEX-/PDF-Download |
| **6** | Punktetabelle und Rechner (inkl. Rückrechnung) |
| **7** | Cup-Wertung, Startberechtigung, Jahresbestleistungen |
| **8** | Regelmente & Formulare |
| **9** | Startseite ausbauen, Barrierefreiheits-Audit, englische Übersetzung vollständig |

Die konkreten Bausteine je Phase — Controller, Services, Wertobjekte, Views, Testgruppen — stehen in
[public-frontend-modules.md](public-frontend-modules.md).

Jede Phase: Plan → Freigabe → Umsetzung → Tests grün → Sign-off. Test-Gruppen mit Suffix `public-pN`.

## 9. Offene Punkte

- **Tailkit-Lizenz** (§3.1.1) — darf kopiertes Markup in einem öffentlichen Repository liegen?
- **Rechtsgrundlage** für eine spätere Ausweitung auf freie Ergebnissuche (§2.3 Punkt 6).
- **Englische Fassungen** der Regelmente: liegen nicht für alle Dokumente vor; Verhalten bei Fehlen ist in §4.1
  geregelt, die redaktionelle Lücke bleibt.
- **Bestandsdokumente**: Übernahme der Dateien von der Altseite — manuell oder per Skript?
