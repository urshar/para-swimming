# Spec: Wertungsgruppen nach Landesverband und Level

Erweiterung von [scoring-groups.md](scoring-groups.md). Bisher entscheiden Geschlecht, Sportklasse und Alter, wer in
eine Wertungsgruppe gehört. Bei Landesmeisterschaften reicht das nicht: Bei den LM Wien dürfen nur Vereine des WBSV in
der LM-Wertung stehen, alle anderen gehören in die Gäste-Wertung. LENEX hat dafür kein Attribut, deshalb kommen zwei
neue Angaben je Wertungsgruppe dazu: **Landesverband** und **Level**.

## Ausgangslage (Testfall)

"Wiener Integration Swim Meeting & LM Wien" (2018, dev Meet #30): je Bewerb neun Gruppen, sieben "LM Wien …" (Klassen,
Jugend bis 18) und zwei "Gäste …", alle nur über Klassen und Alter unterschieden. Dadurch steht jedes Ergebnis zugleich
in einer LM- und einer Gäste-Gruppe; in der LM-Wertung erscheinen Vereine aus SBSV, TBSV, KBSV, OOEBSV und NOEVSV, in
der Gäste-Wertung die Wiener Vereine.

## Entscheidungen (mit Erik geklärt)

| Frage         | Entscheidung                                                                                                           |
|---------------|------------------------------------------------------------------------------------------------------------------------|
| Landesverband | Ein oder mehrere Landesverbände (`Club::REGIONAL_ASSOCIATIONS`) mit Modus **"nur diese"** oder **"alle außer diesen"** |
| Level         | Athleten-Level (`athletes.level`, LENEX `ATHLETE level`); Werte variabel (heute A/B/T), nicht fest vorgegeben          |
| Auswirkung    | Wie Klasse und Alter: nur die Gruppenzuordnung, Plätze innerhalb der Gruppe neu                                        |
| Pflege        | Bewerbsformular, Sammelaktion für die ganze Veranstaltung, LENEX-Import (Name und `levels`)                            |

## Datenmodell

Neue Spalten in `scoring_groups` (alle optional, leer = keine Einschränkung, Altbestand bleibt unverändert):

- `regional_associations` — Verbandscodes, kommagetrennt (z. B. `WBSV`).
- `regional_mode` — `only` ("nur diese") oder `except` ("alle außer diesen"); nur gesetzt, wenn Verbände angegeben sind.
- `levels` — Level, kommagetrennt (z. B. `B` oder `A,B`; ein Level darf Leerzeichen enthalten, getrennt wird nur an
  Komma/Semikolon, `ScoringGroup::parseLevels()`). Verglichen wird ohne Rücksicht auf Groß-/Kleinschreibung.

## Zuordnung

`ScoringGroup::matches()` bekommt zwei weitere Merkmale; `ScoringGroupService::traits()` liefert sie mit:

- **Landesverband des Vereins im Ergebnis** (`results.club_id` bzw. `relay_results.club_id`, also der Verein zum
  Startzeitpunkt, nicht der aktuelle Verein des Athleten).
    - "nur diese": passt, wenn der Verband des Vereins in der Liste steht. Verein ohne Landesverband (Ausland, nicht
      zugeordnet) passt nicht.
    - "alle außer diesen": passt, wenn der Verband nicht in der Liste steht; Vereine ohne Landesverband und ausländische
      Vereine passen also (Gäste).
- **Level des Athleten zum Wettkampfdatum**: letzter Eintrag der Level-Historie mit `changed_at` ≤ Wettkampfbeginn,
  sonst das aktuelle `athletes.level`. (Die Historie auf dev beginnt erst im Oktober 2026, für Altbestand gilt also
  praktisch das aktuelle Level.) Athlet ohne Level passt nur in Gruppen ohne Level-Einschränkung — wie unbekanntes
  Alter.
- **Staffeln**: Landesverband über den Verein der Staffel. Level greift bei Staffeln nicht (eine Staffel hat kein
  Level); eine Staffelgruppe mit Level-Einschränkung bekommt keine Ergebnisse, das Formular lässt das Feld bei
  Staffeln deshalb weg.

Wer durch die neuen Angaben aus einer Gruppe fällt, landet wie bisher in einer anderen passenden Gruppe oder unter
"Ohne Wertungsgruppe". Die Plätze werden in jeder Gruppe nur unter den Zugehörigen vergeben (1, 2, 3 ohne Lücken),
`syncPlaces()` schreibt sie wie gehabt in `results.place`/`relay_results.place` (erste passende Gruppe zählt). Damit
gilt die Einschränkung automatisch überall, wo Wertungsgruppen genutzt werden: Sammelansicht, Formularanzeige,
PDF-Ergebnisliste, öffentliche Ergebnisseite, LENEX-Export, Athletenseite und Qualifikation (über den gespeicherten
Platz). **Punkte, Cup, Rekorde und Statistiken bleiben unberührt.** Bei `keep_file_places` gilt die Zuordnung,
die Plätze kommen weiter aus der Datei.

## Pflege

- **Bewerbsformular** (`swim-events/form`, Alpine `scoringGroupsEditor`): je Gruppe zusätzlich Landesverbände
  (Flux-Checkbox-Gruppe, Variante "pills") mit Modus "nur diese"/"alle außer diesen" (Flux-Select) und Level
  (Checkbox-Gruppe, nur bei Einzelbewerben). Zur Auswahl stehen die vergebenen Level (`Athlete::knownLevels()`:
  aktuelles Level und Level-Historie) plus die Level vorhandener Gruppen (`ScoringGroup::levelOptions()`).
  Validierung: bekannte Verbandscodes, Modus Pflicht, sobald Verbände gewählt sind; Level frei (keine feste Liste).
- **"Wertungsgruppen auf andere Bewerbe übernehmen"** kopiert die neuen Angaben mit.
- **Sammelaktion an der Veranstaltung** (nur Admin, Seite "Wertungsgruppen einschränken", Knopf in der
  Ergebnis-Sammelansicht, `MeetScoringGroupRestrictionsController`) — Namensanfang (z. B. "LM Wien" oder "Gäste"), Landesverbände + Modus, Level; setzt die
  Angaben an allen Wertungsgruppen der Veranstaltung, deren Name so beginnt (Groß-/Kleinschreibung egal), mit Vorschau
  der Anzahl betroffener Gruppen. Danach werden die Plätze aller betroffenen Bewerbe neu berechnet.
- In der Gruppenanzeige (Bewerbsformular, Sammelansicht-Überschrift unverändert) wird die Einschränkung als kurzer
  Hinweis gezeigt, z. B. "nur WBSV" bzw. "ohne WBSV", "Level B".

## LENEX

- **Import, Landesverband aus dem Namen** (analog zum Titel "ÖSTM"/"ÖM"), nur wenn die Gruppe beim Import neu
  angelegt wird oder noch keine Angabe hat (manuell gesetzte Werte bleiben):
    - "LM <Bundesland> …" (Bundesland laut `Club::REGIONAL_ASSOCIATION_STATES`, auch übliche Kurzformen wie "Wr.",
      "NÖ", "OÖ", "Stmk.") → nur dieser Verband.
    - "Gäste …" → alle außer den Verbänden der LM-Gruppen desselben Bewerbs; gibt es keine, bleibt sie offen.
- **Import, Level aus der Datei**: AGEGROUP `levels` (Liste) bzw. `levelmin`/`levelmax` (Bereich, auf die
  vergebenen Level abgebildet). Werte werden übernommen, wie sie in der Datei stehen. (Attribute bei der Umsetzung
  gegen die LENEX-3.0-Spezifikation prüfen.)
- **Export**: `levels` an der AGEGROUP; der Landesverband hat kein LENEX-Attribut und wird nicht exportiert (die
  RANKINGS enthalten aber bereits nur die richtigen Ergebnisse).

## Phasen

1. **Datenmodell und Wertung** — Migration, Modell (`matches()`, Hilfen für Anzeige), `ScoringGroupService`
   (Verband aus dem Ergebnisverein, Level zum Wettkampfdatum), Tests für Einzel/Staffel, "nur"/"außer", ohne
   Verband, Level und Historie, Platzvergabe nach dem Herausfiltern, `syncPlaces`.
2. **Pflege** — Felder im Bewerbsformular, Validierung, Übernehmen auf andere Bewerbe, Sammelaktion an der
   Veranstaltung (Route in der `RequireAdmin`-Gruppe), Hinweis in der Anzeige; Tests und Browserprüfung.
3. **LENEX** — Import aus Namen und `levels`, Export `levels`; Tests; Doku (`scoring-groups.md`, `data-model.md`).
   Abnahme am Testfall: Erik setzt auf dev die Angaben per Sammelaktion (oder importiert neu), danach zeigt die
   LM-Wertung nur WBSV-Vereine und die Gäste-Wertung nur die anderen.

## Tests

- `tests/Feature/ScoringGroupRestrictionsTest.php` — Zuordnung und Plätze (Gruppe `scoring-group-restrictions-p1`).
- `tests/Feature/ScoringGroupRestrictionsMaintenanceTest.php` — Bewerbsformular, Übernehmen, Sammelseite, Anzeige
  (`scoring-group-restrictions-p2`).
- `tests/Feature/ScoringGroupRestrictionsLenexTest.php` — Namenserkennung, Import, Export (`scoring-group-restrictions-p3`).

## Hinweis zum Import

Die Plätze kommen beim LENEX-Import weiterhin aus den RANKINGS der Datei (siehe
[scoring-groups.md](scoring-groups.md)); stimmen diese nicht mit der neuen Einschränkung überein (etwa wenn die Datei
auch Gäste in der LM-Wertung führt), rechnet "ÖBSV-Punkte berechnen" oder die Sammelaktion
"Wertungsgruppen einschränken" die gespeicherten Plätze neu. Sammelansicht, PDF und öffentliche Seite zeigen die
Wertung ohnehin berechnet.
