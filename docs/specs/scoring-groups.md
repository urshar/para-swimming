# Spec: Wertungsgruppen

Wertungsgruppen legen je Bewerb fest, welche Ergebnisse gemeinsam gewertet werden (LENEX `AGEGROUP`). Typischer Fall:
Damen und Herren schwimmen gemeinsam (Bewerb "A"), werden aber getrennt und in zusammengefassten Klassen gewertet,
z. B. bei den ÖSTM 2025 in 50 m Freistil "Damen ÖSTM: S01 - S08", "Damen ÖM: S09 - S10", "Herren ÖSTM: S01 - S10".

| Teil    | Ort                                                                                       |
|---------|-------------------------------------------------------------------------------------------|
| Modell  | `App\Models\ScoringGroup` (Tabelle `scoring_groups`), `SwimEvent::scoringGroups()`        |
| Wertung | `App\Services\ScoringGroupService::rankedGroups()`                                        |
| Pflege  | Bewerbsformular (`swim-events/form`, Alpine `scoringGroupsEditor`), `SwimEventController` |
| Import  | `LenexParserService::importScoringGroups()`                                               |
| Export  | `LenexExportService::buildScoringGroupAgeGroups()`                                        |

## Datenmodell

`scoring_groups`: `swim_event_id`, `name`, `gender` (M/F/X, A = alle), `sport_classes` (Nummern, kommagetrennt;
leer = alle Klassen; die Kategorie S/SB/SM ergibt sich aus der Lage, bei Staffeln die Staffelklasse 14/20/34/49),
`age_min`/`age_max` (optional, Jahrgangsalter), `title` (`OSTM` = Österr. Staatsmeisterschaft, `OM` = Österr.
Meisterschaft, leer = ohne Titel), `sort_order`, `lenex_agegroup_id`.

`swim_events.sport_classes` (Grundlage der Meldeberechtigung) wird beim Speichern der Gruppen auf die Vereinigung
ihrer Klassen gesetzt (`SwimEvent::syncSportClassesFromGroups()`); umfasst eine Gruppe alle Klassen, bleibt es leer.

## Wertung

- **Zuordnung wird berechnet, nicht gespeichert.** Ein Einzelergebnis gehört in jede Gruppe, zu der Geschlecht,
  Sportklasse (Ergebnisfeld) und Jahrgangsalter des Athleten (Wettkampfjahr − Geburtsjahr) passen; ein
  Staffelergebnis über Wertung (M/F/X) und Staffelklasse. Mehrfachwertung ist gewollt (z. B. Klassen- und
  Jugendwertung). Ändert man eine Gruppe, stimmt die Wertung sofort.
- Unbekanntes Alter passt nur in Gruppen ohne Altersgrenze; eine unbekannte Klasse nur in Gruppen für alle Klassen.
- Passt ein Ergebnis in keine Gruppe, steht es unter **"Ohne Wertungsgruppe"**.
- **Ohne Gruppen** (Altbestand): Rückfall je Geschlecht und Sportklasse bzw. Staffelklasse ("Herren – S9").
- **Platz mit Wertungsgruppen nach Punkten** (`results.points`, ÖBSV-Punkte = World-Aquatics-Formel), höchste
  zuerst, gleiche Punkte = gleicher Platz. Nur so sind zusammengefasste Klassen (S01–S08) vergleichbar; an den
  ÖSTM 2025 nachgerechnet ergibt das exakt die offiziellen Plätze. Ergebnisse mit Zeit, aber ohne Punkte bekommen
  keinen Platz und werden je Gruppe gezählt (`missingPoints`): Sammelansicht und PDF weisen darauf hin, der Button
  "ÖBSV-Punkte berechnen" in der Sammelansicht rechnet die Punkte der Veranstaltung neu.
- **Platz ohne Wertungsgruppen** (Rückfall je Klasse) nach der Zeit, gleiche Zeit = gleicher Platz.
- AK (EXH) steht ohne Platz unter den Gewerteten, danach DSQ/DNF/DNS usw.

**Gespeicherter Platz:** `results.place` bzw. `relay_results.place` wird nach jeder Änderung an den Ergebnissen eines
Bewerbs auf den berechneten Platz gesetzt (`ScoringGroupService::syncPlaces()`): Erfassen, Bearbeiten und Löschen von
Einzel- und Staffelergebnissen, Punkte-Neuberechnung der Veranstaltung, Speichern und Übernehmen von Wertungsgruppen.
Liegt ein Ergebnis in mehreren Gruppen, gilt der Platz der ersten. So zeigen Athletenseite, globale Ergebnisliste,
Detailseite, Qualifikation und LENEX-Export dieselbe Wertung. Die Formulare zeigen den Platz je Gruppe nur an
(`placementsOf()`), er wird nicht mehr von Hand eingetragen. Der LENEX-Import übernimmt weiterhin die offiziellen
Plätze aus den RANKINGS.

Genutzt von Sammelansicht (`meets/results-overview`, Filter blenden nur Zeilen aus, der Platz gilt in der ganzen
Gruppe), PDF-Ergebnisliste (`MeetResultListService`), öffentlicher Ergebnisseite (`PublicResultService`,
Überschrift aus übersetztem Geschlecht und Gruppenname) und LENEX-Export.

## Pflege

- Im Bewerbsformular: Zeilen mit Name, Geschlecht, Klassen, Alter ab/bis, Titel; Speichern ersetzt die Gruppen.
  Ungültige Gruppen verhindern das Speichern des Bewerbs.
- "Wertungsgruppen auf andere Bewerbe übernehmen" (Route `events.scoring-groups.copy`, nur Admin): ersetzt die
  Gruppen aller Bewerbe derselben Veranstaltung mit gleicher Art (Einzel/Staffel) und Klassenkategorie (S:
  Freistil/Rücken/Delfin, SB: Brust, SM: Lagen).
- Gespeicherte Vorlagen (z. B. "ÖSTM-Standard") sind bewusst nicht umgesetzt; bei Bedarf als späterer Schritt.

## LENEX

- **Import:** je AGEGROUP eine Gruppe (über `agegroupid` wiedererkannt, manuell angelegte bleiben stehen). Klassen aus
  `handicap`, Geschlecht aus `gender` (fehlt = A), Alter aus `agemin`/`agemax` (-1 = ohne), Titel aus dem Namen (
  "ÖSTM…", "ÖM…").
- **Export:** je Gruppe eine AGEGROUP mit `name`, `gender`, `handicap`, `agemin`/`agemax`; beim Ergebnisexport mit
  `RANKINGS` der Einzelergebnisse (place -1 für nicht gewertete). Staffel-Ranglisten folgen mit dem Export der
  Staffelergebnisse (Phase 2). Bewerbe ohne Gruppen: wie bisher je Sportklasse eine AGEGROUP.

## Tests

`tests/Feature/ScoringGroupsTest.php`.

## Plätze aus der Ergebnisdatei (internationale Veranstaltungen)

Ist an der Veranstaltung `keep_file_places` gesetzt (automatisch beim LENEX-Import mit Nationenfilter, sonst im
Veranstaltungsformular unter "Punkteberechnung"), rechnet `ScoringGroupService` die Plätze nicht neu: `rankedGroups`
übernimmt den gespeicherten `place` (sortiert danach, ohne Platz nach Status und Zeit, kein "ohne Punkte"-Hinweis),
und `syncPlaces` lässt die gespeicherten Plätze unangetastet. Damit bleibt z. B. der EM-Platz in Sammelansicht, PDF,
öffentlicher Seite und LENEX-Export erhalten, auch nach "ÖBSV-Punkte berechnen".
