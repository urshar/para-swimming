# Spec: Ergebnisse einer Veranstaltung (Sammelansicht)

Admin-Arbeitsfläche, um die Einzel- und Staffelergebnisse **einer** Veranstaltung zu erfassen, zu bearbeiten und zu
löschen. Gegenstück zu "Alle Meldungen" ([club-entries.md](club-entries.md)), aber für Ergebnisse.

| Teil       | Ort                                                                                                                                              |
|------------|--------------------------------------------------------------------------------------------------------------------------------------------------|
| Controller | `App\Http\Controllers\MeetResultsOverviewController` (Liste, Löschen je Disziplin)                                                               |
| View       | `resources/views/meets/results-overview.blade.php`                                                                                               |
| Erfassen   | `ResultController::create()` / `store()` (Formular `results/form.blade.php`)                                                                     |
| Routen     | `meets.results-overview` (GET `meets/{meet}/results`), `meets.results-overview.destroy-event` (DELETE `meets/{meet}/events/{swimEvent}/results`) |

Nur Admin (Route-Middleware `RequireAdmin`). Erreichbar über den Button "Ergebnisse" und die Kachel "Ergebnisse"
auf `meets/show`.

## Liste

- Gruppiert nach Disziplin (Abschnitt, dann Bewerbsnummer), darin nach Wertungsgruppen mit Platz je Gruppe
  ([scoring-groups.md](scoring-groups.md)); Vereins- und Athletenfilter blenden nur Zeilen aus. Früher: platzierte Ergebnisse nach
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
- **Staffelpunkte:** Für Staffelergebnisse rechnet dieselbe Formel über die Wertung der Mannschaft (Damen/Herren/Mixed
  → Basiswert-Kategorie) und die Staffelklasse (S14, S15, S20, S21, S34, S49) → `relay_results.points` — beim
  Erfassen (eingetragener Wert bleibt) und bei "ÖBSV-Punkte berechnen"; WPS gibt es für Staffeln nicht. Beim
  LENEX-Import bleiben die Punkte aus der Datei. Die Basiswert-Tabelle ist maßgeblich; weichen die Datei-Punkte ab,
  zuerst prüfen, ob die richtige Basiswert-Version eingespielt ist (Oktober 2026: Herrenstaffeln S14 auf Dev aus einer
  noch nicht gültigen Tabelle, gültig ist MM-2021).

## Ergebnisliste (PDF)

Button "Ergebnisliste (PDF)" in der Sammelansicht (Route `meets.results-overview.pdf`, View
`pdf/result-list.blade.php`, Aufbereitung `MeetResultListService`). Ein gesetzter Disziplin-Filter wird übernommen.

- Je Bewerb, darin je **Wertungsgruppe** mit eigener Platzierung (siehe [scoring-groups.md](scoring-groups.md); ohne
  Gruppen je Geschlecht und Sportklasse).
- Platz wird berechnet, nicht aus `results.place` übernommen: mit Wertungsgruppen nach Punkten, ohne nach der Zeit
  (siehe [scoring-groups.md](scoring-groups.md)). AK (EXH) folgt ohne Platz, danach DSQ, DNF, DNS usw.
- Spalten: Platz, Name, Jahrgang, Verein, Sportklasse, Zeit, Punkte, WPS, Status/Rekordkürzel, mit Spaltenköpfen und
  Fußnote zu den Punkten.

## Punktespalten

Sammelansicht und PDF zeigen "Punkte" (ÖBSV-Punkte, World-Aquatics-Formel, `results.points`) und "WPS"
(`results.wps_points`) in eigenen Spalten. Eine Spalte erscheint, wenn das System für die Veranstaltung aktiviert ist
oder schon Werte vorliegen (`MeetResultsOverviewController::pointColumns()`). Geschätzte WPS-Punkte (abgeleitete
Kurzbahn-Parameter, nicht offiziell; `Result::hasEstimatedWpsPoints()`) tragen ein `*`, wie in der globalen
Ergebnisliste.

## Staffelergebnisse

Eigene Tabellen `relay_results`, `relay_result_members`, `relay_result_splits` (siehe
[data-model.md](../data-model.md)); Import aus LENEX siehe [lenex-import-export.md](lenex-import-export.md).

- **Sammelansicht:** Staffelbewerbe zeigen ihre Staffelergebnisse (Partial `meets/_relay-results-table`): Platz,
  Staffel (Verein mit Mannschaftsnummer ab 2 bzw. eigener Name), Wertung und Staffelklasse, Schwimmer, Zeit, Punkte,
  Rekorde, Status, Herkunft. Sortiert zuerst nach Wertung, weil die Plätze je Wertung gelten. Vereinsfilter auf den
  Staffelverein, Athletensuche auf die Schwimmer. "Alle löschen" je Disziplin löscht auch Staffelergebnisse.
- **Erfassen** (`RelayResultController`, View `relay-results/form`, Alpine `relayResultForm`): Bewerb, Verein,
  Mannschaftsnummer, eigener Name, Schwimmer je Position (so viele, wie der Bewerb hat), Zeit, Status, Staffelklasse,
  Platz, Punkte, Kommentar.
  - Schwimmer werden aus der Staffelmeldung desselben Vereins und Bewerbs vorbelegt; jeder Athlet ist wählbar (auch
    vereinsfremd). Ein Athlet darf nur einmal vorkommen.
  - **Wertung** leer = aus den Schwimmern (`RelayResult::genderFromMembers()`): nur Frauen = Damen, gleich viele Frauen
    und Männer = Mixed, sonst Herren (auch 3 + 1 und 1 + 3).
  - **Staffelklasse** leer = aus den S-Klassen der Schwimmer (`RelayClassValidator`), nur bei voller Besetzung.
  - "Speichern und nächstes" wie bei Einzelergebnissen. Zwischenzeiten werden manuell (noch) nicht erfasst; importierte
    bleiben beim Bearbeiten erhalten.
- **Ergebnisliste (PDF) und Sammelansicht:** je Staffelbewerb nach Wertungsgruppen (ohne Gruppen je Wertung und
  Staffelklasse, "Herren – S14"), Platz aus der Zeit wie bei Einzelergebnissen, im PDF darunter die Schwimmer mit
  Jahrgang.
- **Rekorde:** siehe [records.md](records.md) "Staffelrekorde"; Herrenstaffeln mit Damenbeteiligung und Staffeln mit
  vereinsfremden Schwimmern stellen keinen Rekord auf.
- **Phase 2** (`feature/relay-results-phase2`): LENEX-Export der Staffelergebnisse, Import der Staffel**meldungen**
  (siehe [lenex-import-export.md](lenex-import-export.md)), ÖBSV-Punkte für Staffeln (oben) und Staffeln auf der
  öffentlichen Ergebnisseite (`PublicResultService`, gleiche Wertungsgruppen; Spalten Staffel, Schwimmer mit
  Jahrgang, Staffelklasse, Zeit, Punkte, Rekord; Schwimmer unverlinkt wie bei Einzelergebnissen). Eine Veranstaltung
  nur mit Staffelergebnissen zeigt den Ergebnis-Link ebenfalls. Ein Staffelbewerb mit Staffelmeldungen oder
  -ergebnissen lässt sich nicht löschen (sonst würden sie per Kaskade mitgelöscht).

## Kennzahlen-Kacheln auf `meets/show` und Teilnehmerseite

Seit `feature/meet-stat-tiles` (08.10.2026) führt jede Kachel zu den Daten dahinter; "Zurück" auf der Zielseite führt
wieder auf `meets/show`:

| Kachel           | Admin                                                    | Vereinsnutzer                               |
|------------------|----------------------------------------------------------|---------------------------------------------|
| Disziplinen      | Anker `#disziplinen` auf derselben Seite                 | gleich                                      |
| Einzelmeldungen  | `meets.entries-overview`                                 | `club-entries.index` (nur mit Verein)       |
| Staffelmeldungen | `meets.entries-overview#staffelmeldungen`                | `club-entries.relay.index` (nur mit Verein) |
| Ergebnisse       | `meets.results-overview`                                 | kein Link (Sammelansicht ist Admin-Bereich) |
| Teilnehmer       | `meets.participants` (Ansicht Athleten)                  | gleich                                      |
| Clubs            | `meets.participants?ansicht=vereine`                     | gleich                                      |

**Teilnehmerseite** (`MeetParticipantsController`, `meets/{meet}/participants`, nur lesend, alle Angemeldeten):
- **Athleten:** dieselben wie die Kachel (`Meet::participantIds()` — Einzel- oder Staffelmeldung oder Ergebnis), mit
  Jahrgang, Sportklasse und Zählern (Einzelmeldungen, Staffeln, Ergebnisse). Verein = der Verein bei dieser
  Veranstaltung (Meldung, sonst Ergebnis, sonst Staffelmeldung, sonst Stammverein). Filter Verein und Namenssuche.
- **Vereine:** dieselben wie die Kachel (`entryClubIds()` + `resultClubIds()`, ohne die reine `meet_club`-Zuordnung)
  mit Athleten, Einzel-, Staffelmeldungen und Ergebnissen (Einzel + Staffel); Klick filtert die Athleten-Ansicht.

## Zugriff

Alle Ergebnis-Verwaltungsrouten (`results.*`, `meets.results.*`, `meets.relay-results.*`/`relay-results.*`, Sammelansicht,
PDF, Löschen je Disziplin) liegen
hinter `RequireAdmin`. Die öffentliche Ergebnisseite (`routes/public.php`) ist davon nicht betroffen.

## Tests

`tests/Feature/MeetResultsOverviewTest.php`, `tests/Feature/ResultEntryAutomationTest.php` (Sportklasse, Punkte,
Ergebnisliste, Zugriffsschutz), `tests/Feature/RelayResultsTest.php` (Staffeln: Wertungsregel, LENEX-Import nach dem
Muster der ÖSTM 2025, Rekorde, Erfassen, Sammelansicht, PDF), Rücksprung beim Erfassen zusätzlich in
`tests/Feature/ContextBackNavigationTest.php`. Kacheln und Teilnehmerseite: `tests/Feature/MeetStatTilesTest.php`.
