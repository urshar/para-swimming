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
`age_min`/`age_max` (optional, Jahrgangsalter, bei Staffeln Summe der Alter), `title` (`OSTM` = Österr.
Staatsmeisterschaft, `OM` = Österr.
Meisterschaft, leer = ohne Titel), `sort_order`, `lenex_agegroup_id`.

Dazu die Einschränkung nach **Landesverband** des Ergebnisvereins (`regional_associations` + `regional_mode`, "nur
diese" / "alle außer diesen", z. B. LM-Wertung nur WBSV, Gäste ohne WBSV) und **Athleten-Level** (`levels`) — eigene
Spec: [scoring-group-restrictions.md](scoring-group-restrictions.md) (Zuordnung, Pflege im Bewerbsformular und auf
der Seite "Wertungsgruppen einschränken", LENEX).

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
  Ungültige Gruppen verhindern das Speichern des Bewerbs. Eine neue Gruppe übernimmt das gewählte Geschlecht des
  Bewerbs (Damen/Herren), sonst "Alle".
- "Wertungsgruppen auf andere Bewerbe übernehmen" (Route `events.scoring-groups.copy`, `module:meets,write`): ersetzt die
  Gruppen aller Bewerbe derselben Veranstaltung mit gleicher Art (Einzel/Staffel) und Klassenkategorie (S:
  Freistil/Rücken/Delfin, SB: Brust, SM: Lagen). Ist der Zielbewerb Damen bzw. Herren, bekommen die Gruppen dessen
  Geschlecht; aus einem offenen/Mixed-Bewerb werden dabei nur Gruppen des passenden Geschlechts und "Alle"
  übernommen. Offene/Mixed-Zielbewerbe übernehmen die Gruppen unverändert.
- Die Disziplinliste der Veranstaltung zeigt die Klassen zusammengefasst je Behinderungsgruppe mit dem Präfix der
  Lage (`S1-S10, S11-S13, S14`; `SwimEvent::sport_classes_display`); Staffeln zeigen den Rohwert.
- Gespeicherte Vorlagen (z. B. "ÖSTM-Standard") sind bewusst nicht umgesetzt; bei Bedarf als späterer Schritt.

## Reihenfolge und einzelne Gruppe kopieren

Tests: `tests/Feature/ScoringGroupOrderCopyTest.php` (Gruppe `scoring-group-order-copy-p1`).

**Reihenfolge:**

- Im Bewerbsformular bekommt jede Gruppe die Knöpfe "nach oben" und "nach unten" (per Tastatur bedienbar, keine
  zusätzliche Bibliothek). Gespeichert wird mit dem Bewerb über `sort_order`, das schon aus der Zeilenfolge gesetzt
  wird.
- Die Zeilen im Alpine-Editor (`scoring-groups-editor.js`) bekommen einen festen internen Schlüssel, damit die Eingaben
  beim Verschieben an der richtigen Gruppe bleiben.
- **Die Reihenfolge wirkt auf gespeicherte Plätze:** Liegt ein Ergebnis in mehreren Gruppen, wird der Platz der ersten
  Gruppe gespeichert (`syncPlaces()`). Beim Speichern werden die Plätze neu berechnet, und ein Hinweis im Formular
  erklärt diesen Effekt. Die Regel selbst bleibt unverändert.

**Einzelne Gruppe kopieren:**

- Jede gespeicherte Gruppe bekommt einen Knopf "Kopieren", der einen Dialog mit den Zielbewerben derselben Veranstaltung
  öffnet; "Alle mit gleicher Klassenkategorie" hakt die passenden Bewerbe auf einmal an (Route `scoring-groups.copy`,
  `SwimEventController::copySingleScoringGroup()`, Alpine `scoringGroupCopyDialog`). Recht: `module:meets,write`, wie das
  Übernehmen aller Gruppen. Gibt es keinen möglichen Zielbewerb, fehlen Knopf und Dialog.
- **Zur Auswahl** stehen alle Bewerbe gleicher Art (Einzel oder Staffel), auch über S, SB und SM hinweg. Die Kategorie
  steht in der Liste dabei; die Klassen-Nummern werden unverändert übernommen und sind bei SB und SM eventuell
  anzupassen.
- **Im Ziel** wird die Gruppe hinten angefügt, vorhandene Gruppen bleiben bestehen (anders als beim Übernehmen aller
  Gruppen, das ersetzt).
- **Gibt es im Ziel schon eine Gruppe mit gleichem Namen und Geschlecht,** wird das Ziel übersprungen und gemeldet.
- **Kopiert** werden alle Einstellungen außer der LENEX-ID. Beim Geschlecht gilt dieselbe Regel wie beim Übernehmen aller
  Gruppen (`groupsForTarget()`): Ein Damen- oder Herrenbewerb gibt der Gruppe sein Geschlecht, eine Herrengruppe aus einem
  offenen Bewerb wird nicht in einen Damenbewerb kopiert.
- **Danach** werden Klassen und Plätze der Zielbewerbe neu berechnet, und eine Meldung nennt kopierte und übersprungene
  Bewerbe.
- **Kopiert wird der gespeicherte Stand.** Neue, noch nicht gespeicherte Zeilen haben keinen Kopierknopf. Der Dialog weist
  darauf hin, auch darauf, dass nicht gespeicherte Änderungen im Formular beim Kopieren verloren gehen (die Seite wird neu
  geladen).
- **Meldeberechtigung:** Ein Bewerb ohne Gruppen ist für alle Klassen offen; nach dem Kopieren einer Gruppe gelten dort nur
  noch deren Klassen, bis weitere Gruppen dazukommen (`syncSportClassesFromGroups()`). Der Dialog warnt bei solchen
  Zielbewerben.

## Medaillenwertung

> **Status: Datenmodell umgesetzt, Schalter im Formular folgt.** Details in [meet-entry-summary.md](meet-entry-summary.md).

Je Gruppe die Einstellung "Medaillenwertung" ja/nein (`awards_medals`), z. B. nein beim ÖBSV Cup, wo die Tageswertung
zählt. Sie wirkt nur auf die Medaillenaufstellung der Melde-Kennzahlen; Plätze, Punkte und Cup-Wertung bleiben unberührt.
Dazu kommen die Titel `OJM` und `OBM`. "Wertungsgruppen auf andere Bewerbe übernehmen" und das Kopieren einer einzelnen
Gruppe übernehmen die Einstellung mit. Das Bewerbsformular schickt den Wert bis zum sichtbaren Schalter verdeckt mit,
damit er beim Speichern erhalten bleibt; neue Gruppen bekommen ja.

## LENEX

- **Import:** je AGEGROUP eine Gruppe (über `agegroupid` wiedererkannt, manuell angelegte bleiben stehen). Klassen aus
  `handicap`, Geschlecht aus `gender` (fehlt = A), Alter aus `agemin`/`agemax` (-1 = ohne), Titel aus dem Namen (
  "ÖSTM…", "ÖM…", "ÖJM…", "ÖBM…", `ScoringGroup::titleFromName()`). Medaillenwertung bei neuen Gruppen ja, wenn ein
  Titel erkannt wird; bei bestehenden bleibt sie unverändert.
- **Export:** je Gruppe eine AGEGROUP mit `name`, `gender`, `handicap`, `agemin`/`agemax`; beim Ergebnisexport mit
  `RANKINGS` der Einzelergebnisse (place -1 für nicht gewertete). Staffel-Ranglisten folgen mit dem Export der
  Staffelergebnisse (Phase 2). Bewerbe ohne Gruppen: wie bisher je Sportklasse eine AGEGROUP.

## Tests

`tests/Feature/ScoringGroupsTest.php`.

## Plätze nicht neu berechnen (internationale Veranstaltungen)

Ist an der Veranstaltung `keep_file_places` gesetzt ("Plätze nicht neu berechnen": automatisch beim LENEX-Import mit
Nationenfilter, bei der Athletenauswahl je nach Einstellung, beim Anlegen einer Veranstaltung im Ausland vorbelegt,
sonst im Veranstaltungsformular unter "Punkteberechnung"), rechnet `ScoringGroupService` die Plätze nicht neu. Der
Platz kommt dann aus der Ergebnisdatei oder wird im Einzel- bzw. Staffelergebnisformular eingetragen (Eingabefeld
statt Anzeige, Partial `results/_place-field.blade.php`; ohne die Einstellung wird ein mitgeschickter Platz
ignoriert). `rankedGroups`
übernimmt den gespeicherten `place` (sortiert danach, ohne Platz nach Status und Zeit, kein "ohne Punkte"-Hinweis),
und `syncPlaces` lässt die gespeicherten Plätze unangetastet. Damit bleibt z. B. der EM-Platz in Sammelansicht, PDF,
öffentlicher Seite und LENEX-Export erhalten, auch nach "ÖBSV-Punkte berechnen".
