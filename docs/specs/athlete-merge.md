# Spec: Athleten zusammenführen (Dubletten)

Dieselbe Person kann mehrfach als Athlet angelegt sein: Der Team-Manager-Import brachte Lizenz O-2030 dreimal
(vertauschter Name, Tippfehler), LENEX- und Rekordimporte legen bei abweichender Schreibweise neue Athleten an. Löschen
geht nicht mehr, sobald Ergebnisse oder Meldungen dranhängen. Deshalb: zwei Athleten zu einem zusammenführen — einer
bleibt, der andere wird aufgelöst und danach **endgültig gelöscht**. Umgesetzt in `feature/athlete-merge` (Oktober
2026).

## Entscheidungen (mit Erik geklärt)

| Frage                                            | Entscheidung                                                                                                           |
|--------------------------------------------------|------------------------------------------------------------------------------------------------------------------------|
| Stammdaten bei Abweichung                        | Feld für Feld wählen; vorbelegt der Wert des behaltenen Athleten, bei leerem Wert der des anderen, Notizen "beide"     |
| Sportklassen                                     | je Kategorie mit abweichender Klasse Pflichtauswahl (ohne Vorbelegung); fehlende Kategorien werden übernommen          |
| Ausnahme-Codes                                   | Vereinigung beider Seiten, Duplikate entfallen                                                                         |
| Ergebnis/Meldung im selben Bewerb                | **blockiert** — erst von Hand bereinigen; ebenso beide in derselben Staffel (Ergebnis, Meldung, Rekord-Staffelteam)    |
| Histories (Verein, Level, Kader, Klassifikation) | alles übernehmen, exakte Duplikate entfallen                                                                           |
| Verein                                           | aus der Vereins-History (jüngster offener Eintrag), sonst wählbares Stammdatenfeld (fast kein Athlet hat eine History) |
| Rekorde                                          | nur umhängen, die Rekordkette hängt nicht am Athleten; doppelte Rekorde (gleiche Kategorie und Zeit) als Hinweis       |
| Cup-Wertungen, Qualifikationen                   | neu berechnen (Häkchen, vorbelegt), in derselben Transaktion                                                           |
| Aufgelöster Athlet                               | hart löschen; Protokoll als Zeile in `laravel.log`, keine eigene Tabelle                                               |
| Dublettenvorschläge                              | eigene Seite mit "kein Duplikat"-Markierung                                                                            |

## Was am Athleten hängt (18 Tabellen mit `athlete_id`)

`results`, `relay_result_members`, `entries`, `relay_entry_members`, `swim_records`, `relay_team_members`,
`athlete_sport_classes`, `athlete_exceptions`, `athlete_classifications` (deren `athlete_classification_exceptions`
wandern mit), `athlete_club_history`, `athlete_level_history`, `athlete_kader_memberships`, `athlete_performance_notes`,
`import_review_items`, `cup_top_group_classifications`, `cup_daily_results`, `cup_overall_results`, `qualifications`
(`AthleteMergeService::TABLES`). Dazu Athleten-IDs ohne Fremdschlüssel im JSON offener Prüflisten-Einträge
(`details.list.athlete_id`, `details.list.relay_members[].db_id`). `athlete_duplicate_dismissals` verweist ebenfalls auf
Athleten, entfällt aber per Cascade (Markierungen des aufgelösten Athleten wandern nicht mit).

Wichtig: `athletes` hat Soft Deletes — die `cascadeOnDelete`-Regeln greifen nur beim harten Löschen. Vor
`forceDelete()` prüft der Service deshalb, dass keine der 18 Tabellen mehr auf den aufgelösten Athleten verweist.

## Ablauf (`AthleteMergeService`)

- `analyze($keep, $drop)`: abweichende Stammdaten mit Vorschlag, `club_from_history`, Anzahl je Tabelle, blockierende
  Kollisionen, Sportklassen zur Auswahl, Hinweise (überlappende Vereinszeiträume, doppelte Rekorde), betroffene Cups
  (`cup_ids`) und Richtzeitenlisten (`qualifying_time_list_ids`).
- `merge($keep, $drop, $fieldChoices, $sportClassChoices, $recalculate)` in einer Transaktion:
    - Stammdaten aus der Auswahl, Sportklassen nach Auswahl, Ausnahme-Codes vereinigen.
    - Histories, Cup-Wertungen und Qualifikationen umhängen, außer der behaltene hat schon eine gleiche Zeile (exaktes
      Duplikat bzw. gleicher Unique-Schlüssel) — dann entfällt die des aufgelösten.
    - Übrige Tabellen umhängen, JSON der Prüfliste umschreiben.
    - Mehrere offene Vereinseinträge: der jüngste bleibt aktiv, ältere enden am Tag vor dessen Beginn. `club_id` und
      `level` danach aus der History (sonst gewählter bzw. vorhandener Wert).
    - Umgehängte Ergebnisse und Kaderzugehörigkeiten bekommen ein neues `updated_at`, damit `CupStalenessService` die
      Wertungen als veraltet meldet, wenn ohne Neuberechnung zusammengeführt wird.
    - `forceDelete()` des aufgelösten Athleten, danach optional die Neuberechnung.
    - Rückgabe `{moved, recalculation}`; beides landet auch im Log.

## Neuberechnung (`AthleteMergeRecalculationService`)

Je betroffenem Cup nur die Stufen, die schon einmal berechnet wurden, in der Reihenfolge der Kette: Topgruppe →
Tageswertung je Cup-Wettkampf → Gesamtwertung. Eine nie berechnete Wertung legt das Zusammenführen nicht an. Je
betroffener Richtzeitenliste `QualificationDeterminationService::calculateForList()`; ein gemeldeter Fehler (z. B. kein
Zeitraum-Beginn) landet im Bericht und als Flash-Meldung "error", bricht aber nichts ab. Eine Liste ohne Zeitraum-Ende
wird dabei bis heute gerechnet (Hinweis in der Vorschau). Achtung: Jede Neuberechnung ersetzt den ganzen Snapshot des
Cups bzw. der Liste, damit fließen auch andere seither geänderte Daten ein.

## Oberfläche (admin-only)

- **Athletenseite** → "Zusammenführen" → `athletes.merge.create`: durchsuchbare Auswahl der Dublette (Name,
  Geburtsdatum, Verein, Lizenz, ID).
- **Vorschau** `athletes.merge.preview` (`athletes/{keep}/merge/{drop}`): Bleibt / Wird aufgelöst, "Andersherum"
  tauscht die Richtung; roter Block bei Blockaden (Button gesperrt), gelber Block für Hinweise; Stammdaten mit
  Radio-Auswahl (nur abweichende, Zähler der übereinstimmenden), Sportklassen, Daten je Tabelle, Häkchen zur
  Neuberechnung; Rückfrage per `confirm()`.
- **Zusammenführen** `athletes.merge.store`: danach zur Athletenseite des behaltenen Athleten, Flash-Meldung mit den
  übernommenen Datensätzen und den neu berechneten Wertungen.
- **Dubletten** `athletes.duplicates.index` (Button in der Athletenliste): siehe unten. Von dort geöffnet
  (`from=duplicates`), führen Zurück, Abbrechen, Tausch und die Weiterleitung nach dem Zusammenführen zur gemerkten
  Liste (`ListUrl`, Bereich `athletes.duplicates`).

## Dublettenvorschläge (`AthleteDuplicateFinder`)

Paarweiser Vergleich aller Athleten in PHP (Namen normalisiert wie auf der LENEX-Klärungsseite,
`ImportSuggestionService::normalizeName()`):

- **stark:** gleiche Lizenz, SDMS ID oder swimrankings-ID (nur Werte mit Ziffer — Platzhalter wie "W-" zählen nicht);
  gleicher Name und Geburtsdatum; vertauschter Vor- und Nachname.
- **mittel:** gleicher Name, anderes oder fehlendes Geburtsdatum.
- **schwach:** gleiches Geburtsdatum und Geschlecht und ein gemeinsamer Namensteil — trifft Tippfehler und
  Namenswechsel, aber auch Geschwister (Zwillinge, Drillinge).

Vorbelegt bleibt der Athlet mit mehr Daten (Ergebnisse + Rekorde + Meldungen), bei Gleichstand die kleinere ID.
"Kein Duplikat" speichert das Paar in `athlete_duplicate_dismissals` (kleinere ID zuerst, mit Admin); markierte Paare
sind ausgeblendet, "Ausgeblendete anzeigen" zeigt sie samt "Markierung zurücknehmen". Die Markierungen kommen beim
Umzug dev → prod (`team-data:export`) nicht mit.

Stand dev (28.10.2026): 18 Vorschläge — 4 stark (O-2030 dreifach, Heinrich/Jordis), 4 mittel (Drehmann, Groger,
Kopfer, Muhrhofer), 10 schwach (Teuchmann, mögliche Namenswechsel, Geschwister). Der in `open-points.md` genannte
"Athlet 733" existiert auf der heutigen dev-DB nicht mehr (neu aufgebaut); nach den Merkmalen (S15, ohne Lizenz, vom
Import angelegt) ist es heute #717 Muhrhofer Katrin, Dublette von #576.

## Tests

`tests/Feature/AthleteMergeTest.php` (`athlete-merge-p1`, je Tabelle, Kollisionen, Auswahl, Rollback, Log),
`AthleteMergeRecalculationTest.php` (`athlete-merge-p2`), `AthleteMergeUiTest.php` (`athlete-merge-p3`),
`AthleteDuplicatesTest.php` (`athlete-merge-p4`).
