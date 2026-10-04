# Spec: Ergebnisse einer Veranstaltung (Sammelansicht)

Admin-Arbeitsfläche, um die Einzelergebnisse **einer** Veranstaltung zu erfassen, zu bearbeiten und zu löschen.
Gegenstück zu "Alle Meldungen" ([club-entries.md](club-entries.md)), aber für Ergebnisse. Staffelergebnisse gibt es
im Datenmodell noch nicht (Open Point "Staffel-Ergebnisse importieren"), die Seite zeigt nur Einzelbewerbe.

| Teil       | Ort                                                                                                                                              |
|------------|--------------------------------------------------------------------------------------------------------------------------------------------------|
| Controller | `App\Http\Controllers\MeetResultsOverviewController` (Liste, Löschen je Disziplin)                                                               |
| View       | `resources/views/meets/results-overview.blade.php`                                                                                               |
| Erfassen   | `ResultController::create()` / `store()` (Formular `results/form.blade.php`)                                                                     |
| Routen     | `meets.results-overview` (GET `meets/{meet}/results`), `meets.results-overview.destroy-event` (DELETE `meets/{meet}/events/{swimEvent}/results`) |

Nur Admin (Route-Middleware `RequireAdmin`). Erreichbar über den Button "Ergebnisse" und die Kachel "Ergebnisse"
auf `meets/show`.

## Liste

- Gruppiert nach Disziplin (Abschnitt, dann Bewerbsnummer). Innerhalb einer Disziplin: platzierte Ergebnisse nach
  Platz, dann unplatzierte nach Zeit, Ergebnisse ohne Zeit (DNS, DSQ ...) am Ende.
- Spalten: Platz, Athlet, Verein, Klasse, Zeit, Punkte (+ WPS), Rekord-Kennzeichen, Status (AK als violettes Badge),
  Herkunft ("LENEX" bei `lenex_result_id`, sonst "manuell"), Aktionen Ansehen/Bearbeiten/Löschen.
- Filter Disziplin, Verein (nur Vereine mit Ergebnissen plus `meet_club`) und Athletensuche; greifen sofort
  (`indexFilters`). Ein Disziplin- oder Vereinsfilter, der nicht zu dieser Veranstaltung gehört, wird ignoriert.
- "Rekorde prüfen" und (bei aktiviertem WPS) "WPS-Punkte berechnen" stehen auch hier; beide kehren zur aufrufenden
  Seite zurück, das Prüfergebnis der Rekorde wird hier wie auf `meets/show` angezeigt.

## Löschen aller Ergebnisse einer Disziplin

Papierkorb im Disziplin-Kopf, z. B. nach einem fehlerhaften LENEX-Import. Löscht **alle** Ergebnisse der Disziplin
samt Splits, auch die durch einen Vereins- oder Namensfilter ausgeblendeten. Die Rückfrage nennt deshalb die
ungefilterte Anzahl und weist auf ausgeblendete Ergebnisse hin. Eine Disziplin einer anderen Veranstaltung ergibt 404.

## Rücksprünge

Die Route merkt sich ihre URL im Bereich `results` (`remember.list:results`), den sie mit der globalen
Ergebnisliste `results.index` teilt. Damit führen Detailseite, Bearbeiten und Einzel-Löschen automatisch dorthin
zurück, wo man herkam, inklusive Filter.

- **Erfassen:** Zurück/Abbrechen und "Ergebnis anlegen" führen zur Sammelansicht dieser Veranstaltung
  (`MeetResultsOverviewController::backUrl()`: die gemerkte URL nur, wenn sie die Sammelansicht dieses Meets ist,
  sonst die ungefilterte).
- **"Speichern und nächstes":** speichert und öffnet das Formular neu mit derselben Disziplin (`?swim_event_id=`), mit
  Erfolgsmeldung.
- Der "+"-Button im Disziplin-Kopf öffnet das Formular mit dieser Disziplin vorausgewählt.

## Erfassen: Sportklasse und Punkte

- **Sportklasse:** Bleibt das Feld leer, wird beim Anlegen und Bearbeiten die Klasse des Athleten zur Lage des
  Bewerbs übernommen (Brust → SB, Lagen → SM, sonst S; `ClubEntryService::resolveSportClass()`, wie bei den
  Meldungen). Eine eingetragene Klasse bleibt.
- **Punkte** (`ResultPointsService`): Beim Speichern werden die Punkte der Systeme berechnet, die im
  Veranstaltungsformular unter "Punkteberechnung" aktiviert sind. World Aquatics (1000 × (B/T)³ mit der
  Basiswert-Version des Wettkampfdatums) sind die ÖBSV-Punkte, die auch der ÖBSV Cup verwendet → `results.points`;
  WPS → `results.wps_points`. Ein eingetragener Punktewert gilt als manuell und bleibt; beim Bearbeiten gilt nur ein
  **geänderter** Wert als manuell (das Feld ist mit dem gespeicherten Wert vorbelegt), sonst wird neu gerechnet, z. B.
  nach einer korrigierten Zeit. Kann ein aktiviertes System nicht rechnen (keine Zeit, keine Basiswert-Version ...),
  nennt die Erfolgsmeldung den Grund.

## Ergebnisliste (PDF)

Button "Ergebnisliste (PDF)" in der Sammelansicht (Route `meets.results-overview.pdf`, View
`pdf/result-list.blade.php`, Aufbereitung `MeetResultListService`). Ein gesetzter Disziplin-Filter wird übernommen.

- Je Disziplin, darin je **Wertungsgruppe** mit eigener Platzierung. Vorläufig ist die Wertungsgruppe die Sportklasse
  des Ergebnisses (S vor SB vor SM, numerisch; ohne Klasse am Ende). Die echten Wertungsgruppen sind noch nicht
  abgebildet (Open Point "Meetstruktur / Wertungsgruppen"); dann ändert sich nur
  `MeetResultListService::groupKey()`/`groupLabel()`.
- Platz wird aus der Zeit berechnet (gleiche Zeit = gleicher Platz), nicht aus `results.place`. Gewertet werden
  Ergebnisse ohne Status mit Zeit; AK (EXH) folgt mit Zeit ohne Platz, danach DSQ, DNF, DNS usw.
- Spalten: Platz, Name, Jahrgang, Verein, Sportklasse, Zeit, Punkte, WPS, Status/Rekordkürzel, mit Spaltenköpfen und
  Fußnote zu den Punkten.

## Punktespalten

Sammelansicht und PDF zeigen "Punkte" (ÖBSV-Punkte, World-Aquatics-Formel, `results.points`) und "WPS"
(`results.wps_points`) in eigenen Spalten. Eine Spalte erscheint, wenn das System für die Veranstaltung aktiviert ist
oder schon Werte vorliegen (`MeetResultsOverviewController::pointColumns()`). Geschätzte WPS-Punkte (abgeleitete
Kurzbahn-Parameter, nicht offiziell; `Result::hasEstimatedWpsPoints()`) tragen ein `*`, wie in der globalen
Ergebnisliste.

## Zugriff

Alle Ergebnis-Verwaltungsrouten (`results.*`, `meets.results.*`, Sammelansicht, PDF, Löschen je Disziplin) liegen
hinter `RequireAdmin`. Die öffentliche Ergebnisseite (`routes/public.php`) ist davon nicht betroffen.

## Tests

`tests/Feature/MeetResultsOverviewTest.php`, `tests/Feature/ResultEntryAutomationTest.php` (Sportklasse, Punkte,
Ergebnisliste, Zugriffsschutz), Rücksprung beim Erfassen zusätzlich in `tests/Feature/ContextBackNavigationTest.php`.
