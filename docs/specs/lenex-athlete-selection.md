# Spec: LENEX-Import nur ausgewählter Athleten

Status: umgesetzt (22.10.2026, `feature/lenex-athlete-selection`). Die laufende Beschreibung steht in
[lenex-import-export.md](lenex-import-export.md) unter "Athletenauswahl"; dieses Dokument hält Ziel und Entscheidungen
fest. Erweitert um Treffer über Name + Jahrgang und "Weitere Schwimmer" für Para-Schwimmer ohne Sportklasse bei
Fremdvereinen: [lenex-athlete-selection-other-swimmers.md](lenex-athlete-selection-other-swimmers.md). Die Aussage
unten "Schwimmer ohne Treffer und ohne Para-Hinweis werden nicht angezeigt" gilt seitdem nicht mehr.

Abweichung von der Planung: Ein neu angelegter Kandidat bekommt den Verein aus der Datei nur, wenn es ihn schon gibt.
Sonst hat er keinen Heimverein, und seine Ergebnisse werden als "Athlet ohne Verein" übersprungen (wie beim
Nationenfilter); der Auswahlschritt weist darauf hin.

## Ziel

Ergebnisdateien fremder Veranstaltungen enthalten überwiegend Schwimmer, die für den ÖBSV keine Rolle spielen. Ein
vollständiger Import würde die Datenbank mit Athleten, Vereinen und Starts füllen, die nie gebraucht werden.
Typische Fälle:

- **Internationale Meisterschaften** (WM, EM, World Series): einige österreichische Para-Schwimmer in einem großen
  internationalen Feld.
- **Österreichische Veranstaltungen außerhalb des ÖBSV** (z. B. OSV-Meetings, Landesmeisterschaften der
  Schwimmverbände): Para-Schwimmer starten im offenen Feld zwischen vielen nicht behinderten Schwimmern aus
  österreichischen Vereinen.

Der vorhandene Nationenfilter (`only_nation`) hilft nur im ersten Fall, und auch dort nur grob: Er importiert alle
Schwimmer der Nation, auch unbekannte. Im zweiten Fall greift er gar nicht (alle Schwimmer sind AUT), und ohne Filter
kämen alle Vereine und Athleten in die Datenbank.

**Lösung:** ein zusätzlicher Importumfang "Nur ausgewählte Athleten". Die Datei wird vorab mit der Datenbank
abgeglichen; der Admin sieht die gefundenen Athleten und entscheidet je Athlet, ob dessen Ergebnisse importiert
werden. Alles andere bleibt draußen.

## Entscheidungen (Erik, 22.10.2026)

1. **Plätze:** im Auswahlschritt wählbar: "Plätze aus der Datei" oder "unter den importierten Schwimmern berechnen".
   Vorbelegung: Veranstaltung im Ausland → aus der Datei, in Österreich → berechnen.
2. **Wertung:** Die Ergebnisse zählen normal (Rekorde, Bestzeiten, Richtzeit-Qualifikation, Statistik), **außer für
   den Cup**.
3. **Staffeln** werden in diesem Modus nicht importiert.

## Ablauf

Erweiterung des bestehenden mehrstufigen Imports (siehe "HTTP-Ablauf" in
[lenex-import-export.md](lenex-import-export.md)):

1. Upload wie bisher.
2. **Wettkampf zuordnen** (`confirm-meet`): Bei Ergebnisdateien wird die Auswahl "Nur Schwimmer dieser Nation
   importieren" zu einer Auswahl **Importumfang**:
   - "Alle" (bisheriges `ALL`),
   - "Nur Nation …" (bisheriger Nationenfilter, je Nation der Datei eine Option),
   - **"Nur ausgewählte Athleten"** (neu, Sentinel-Wert `SELECTED`, siehe CLAUDE.md zu leeren Option-Werten).

   Bei Melde- und Strukturdateien bleibt die Auswahl wie bisher; den neuen Umfang gibt es nur für Ergebnisdateien.
3. **Athleten auswählen** (neu, `lenex.import.select-athletes`): siehe unten. Absenden startet den Import.
4. Klärungsseite (`review`) nur für Athleten, die man ausdrücklich neu anlegen will (siehe "Kandidaten ohne
   Treffer"). Unbekannte Vereine kommen in diesem Modus nie auf die Klärungsseite.
5. Rückmeldung wie bisher, ergänzt um "x Athleten ausgewählt, y Ergebnisse importiert".

Die Abfrage der Rahmenbewerbe (`resolveEvents`) entfällt in diesem Modus: Fremde Veranstaltungen haben keine
ÖBSV-Rahmenbewerbe, und ihre Bewerbe haben oft keine Para-Klassen (sonst würde jeder Bewerb abgefragt).

## Auswahlschritt

### Abgleich

Für jeden Schwimmer der Datei (`CLUB > ATHLETES > ATHLETE`) wird ohne Schreibzugriff ein bestehender Athlet gesucht,
mit denselben Regeln wie `LenexResolverService::resolveAthlete()`:

1. Lizenznummer (`license`),
2. SDMS-ID (`license_ipc`, nicht "0"),
3. Nachname + Vorname + Geburtsdatum + Geschlecht + Nation.

`resolveAthlete()` hat Nebenwirkungen (Cache, HANDICAP-Abgleich, Vormerken für die Klärungsseite). Die Suchlogik wird
deshalb in eine reine Methode ausgelagert (z. B. `LenexResolverService::findAthlete(): ?array{athlete, matchedBy}`),
die `resolveAthlete()` intern weiterverwendet. So ergeben Vorschau und Import garantiert dieselben Treffer.

Die Nation für Regel 3 kommt wie beim Import aus dem Athleten (`nation`), sonst aus dem Verein.

### Anzeige

**Tabelle "In der Datenbank gefunden"**, standardmäßig alle angehakt:

| Spalte         | Inhalt                                                                    |
|----------------|---------------------------------------------------------------------------|
| Auswahl        | Checkbox                                                                  |
| Athlet         | Name aus der Datenbank (verlinkt), dahinter Geburtsjahr                   |
| Laut Datei     | Name, Verein und Nation laut Datei (nur wenn abweichend hervorgehoben)    |
| Starts         | Anzahl Einzelergebnisse in der Datei, als Liste der Bewerbe im Tooltip    |
| Treffer über   | Lizenz / SDMS-ID / Name + Geburtsdatum                                    |

Treffer über Name + Geburtsdatum werden dezent markiert: Sie sind korrekt, aber am ehesten eine Verwechslung wert.

**Abschnitt "Kandidaten ohne Treffer"** (eingeklappt, nicht angehakt): Schwimmer, die nicht gefunden wurden, aber
einen **Para-Hinweis** in der Datei haben: ein `HANDICAP`-Element oder ein Start in einem Bewerb mit `AGEGROUP
handicap`. Ohne diese Einschränkung wäre die Liste bei einem OSV-Meeting mehrere hundert Zeilen lang. Ausgewählte
Kandidaten gehen auf die bestehende Klärungsseite für Athleten (neu anlegen oder bestehendem zuordnen).
Schwimmer ohne Treffer und ohne Para-Hinweis werden nicht angezeigt.

**Einstellungen** unter der Tabelle:

- **Plätze:** Radio "Aus der Datei übernehmen" / "Unter den importierten Schwimmern berechnen". Vorbelegung nach der
  Nation der Veranstaltung (≠ AUT → Datei). Setzt `meets.keep_file_places`
  ("Plätze nicht neu berechnen", seit `fix/manual-places`).
- **Cup-Hinweis:** "Die Veranstaltung wird keinem Cup zugeordnet." Ist die gewählte bestehende Veranstaltung einem
  Cup zugeordnet, steht hier stattdessen ein Warnhinweis (siehe "Cup").

Ohne einen einzigen Treffer und ohne Kandidaten zeigt die Seite "Keine bekannten Athleten in der Datei" und bietet
nur "Abbrechen" an.

### Sitzung

Die Sitzung hält nur Arrays (keine Modelle, siehe CLAUDE.md): die LENEX-`athleteid`s der ausgewählten Treffer samt
zugeordneter Athleten-ID, die `athleteid`s der Kandidaten zum Neuanlegen und die Platz-Einstellung. Das Formular
schickt nur die Auswahl (`athletes[]` mit `athleteid`, `candidates[]`, `places`).

## Import

Neue Option von `LenexParserService::import()`: `only_athletes` (Liste von LENEX-`athleteid`s), analog zu
`only_nation`. Mit dieser Option:

- **Athleten:** Nur ausgewählte Schwimmer werden aufgelöst und importiert. Ausgewählte Treffer werden dem Resolver
  vorab zugeordnet (`assignAthlete()`), damit Vorschau und Import nicht auseinanderlaufen können. Alle anderen werden
  übergangen, ohne Vormerken für die Klärungsseite.
- **Bewerbe:** Nur Bewerbe, in denen ausgewählte Schwimmer ein Ergebnis haben (Muster `collectNationEventIds`).
  Fehlende Bewerbe werden als gewertet angelegt, ohne Rahmenbewerb-Abfrage.
- **Wertungsgruppen:** Die `AGEGROUP`s der Datei werden **nicht** als Wertungsgruppen übernommen. Bei fremden
  Veranstaltungen sind das meist Jahrgangsklassen ("Jg. 2008-2010") ohne Bezug zur Para-Wertung. Die Wertung fällt
  auf "je Geschlecht und Sportklasse" zurück (siehe [scoring-groups.md](scoring-groups.md)). Die Plätze aus der Datei
  (`RANKINGS`) werden trotzdem gelesen, damit "Plätze aus der Datei" funktioniert.
- **Sportklasse des Ergebnisses:** Fehlt sie in der Datei (typisch bei Nicht-Para-Veranstaltungen), wird sie wie bei
  der manuellen Erfassung aus dem Athleten zur Lage des Bewerbs abgeleitet
  (`ClubEntryService::resolveSportClass()`). Bei der Umsetzung prüfen, ob `importResult()` das heute schon tut.
- **Vereine:** Ein Verein der Datei wird nur gesucht, nie zur Klärung vorgemerkt (`recordUnresolved: false`). Wird
  er gefunden, geht das Ergebnis an ihn, sonst an den Heimverein des Athleten (wie beim Nationenfilter). Fehlt beides,
  wird das Ergebnis gezählt übersprungen ("Athlet ohne Verein").
- **Staffeln:** werden nicht importiert, weder Staffelergebnisse noch -meldungen.
- **Gastvereine:** keine `foreign_starts`. Die Statistik-Starts fremder Vereine entfallen in diesem Modus bewusst.
- **HANDICAP-Abgleich und Klassen-Hinweise** wie bisher, aber nur für ausgewählte Athleten.
- **Wiederholter Import:** Der bestehende Ergebnis-Abgleich (`findExistingResult`) gilt unverändert. Ein zweiter Lauf
  mit einer größeren Auswahl ergänzt Ergebnisse; Ergebnisse von abgewählten Athleten aus einem früheren Lauf bleiben
  stehen (kein Löschen).

`only_nation` und `only_athletes` schließen sich aus.

## Cup

Der Cup wertet nur Veranstaltungen mit `meets.cup_id` (`Cup::meets()`). Ein neues Feld ist deshalb nicht nötig:

- Eine durch den Import neu angelegte Veranstaltung bekommt keinen Cup (heute schon so).
- Ist eine gewählte bestehende Veranstaltung einem Cup zugeordnet, warnt der Auswahlschritt. Der Import läuft
  trotzdem, die Zuordnung bleibt Sache des Admins im Veranstaltungsformular.

Entschieden (Erik, 22.10.2026): Ein fehlender Cup reicht als Kennzeichen, kein neues Feld und kein eigener Hinweis
im Veranstaltungsformular.

## Umsetzung in Teilschritten

Jeder Schritt mit eigenen Tests, Freigabe nach jedem Schritt:

1. **Abgleich ohne Nebenwirkungen:** `findAthlete()` aus `resolveAthlete()` herauslösen, Verhalten von
   `resolveAthlete()` unverändert (bestehende Import-Tests müssen grün bleiben). Dazu ein Vorschau-Service
   (z. B. `LenexAthleteSelectionService::preview(string $path): array`) mit Treffern und Kandidaten.
2. **Parser-Option `only_athletes`** mit allen Regeln aus "Import", getestet an kleinen LENEX-Fixtures (OSV-artige
   Datei ohne Para-Klassen, WM-artige Datei mit mehreren Nationen, Staffeln in der Datei).
3. **Oberfläche:** Importumfang auf `confirm-meet`, Auswahlschritt, Platz-Einstellung, Cup-Hinweis, Durchreichen an
   die bestehende Klärungsseite für Kandidaten. Live-Prüfung im Browser.
4. **Doku:** Abschnitt in [lenex-import-export.md](lenex-import-export.md) ergänzen, Routen- und Testliste
   aktualisieren.

## Tests (Gruppe `lenex-athlete-selection`)

- Vorschau: Treffer über Lizenz, SDMS-ID, Name + Geburtsdatum; kein Treffer bei abweichender Nation; Kandidaten nur
  mit Para-Hinweis; Vorschau schreibt nichts in die Datenbank (Anzahl Athleten/Vereine/Klassen unverändert).
- Import: nur ausgewählte Athleten haben Ergebnisse; keine neuen Vereine, keine neuen Athleten außer bestätigten
  Kandidaten; keine Staffeln, keine `foreign_starts`; nur Bewerbe mit ausgewählten Startern; keine Wertungsgruppen
  aus der Datei.
- Plätze: "aus der Datei" setzt `keep_file_places` und übernimmt RANKINGS; "berechnen" ergibt Plätze unter den
  importierten Schwimmern.
- Ergebnis ohne Verein laut Datei geht an den Heimverein.
- Wiederholter Import mit erweiterter Auswahl verdoppelt nichts.
- Zugriff: alle neuen Routen nur für Admins (`AccessControlTest`).
