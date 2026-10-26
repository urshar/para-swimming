# Spec: Athletenauswahl — Para-Schwimmer ohne Sportklasse bei Fremdvereinen

Status: Entwurf (26.10.2026, `feature/lenex-selection-other-swimmers`). Erweitert
[lenex-athlete-selection.md](lenex-athlete-selection.md); dort beschriebene Regeln gelten unverändert, sofern hier
nichts anderes steht.

## Ausgangslage

Para-Schwimmer starten bei Veranstaltungen außerhalb des ÖBSV teils für einen anderen, nicht-paralympischen Verein
(z. B. "SC Diana Wien", "OSV Schwimmverein"), dort ohne Sportklasse. Ihre Leistungen sind gültig und sollen importiert
werden. In der Athletenauswahl ("Nur ausgewählte Athleten") erscheinen sie heute nicht.

**Ursache** (geprüft an "Lagen Cup 2019 Runde 1", LSV Wien, 205 Schwimmer — Vorschau: 0 Treffer, 0 Kandidaten,
obwohl Janina Falk und Jakob Schumacher (beide WAT, S14) in der Datei stehen):

1. **Lizenz:** Die Datei enthält die OSV-Lizenznummer (z. B. `43909`), nicht die ÖBSV-Lizenz (`W-1886`) → kein Treffer.
2. **SDMS-ID:** fehlt in OSV-Dateien.
3. **Name + Geburtsdatum:** Die Datei kennt nur den Jahrgang (`2003-01-01`), die Datenbank das genaue Datum
   (`2003-02-05`) → kein Treffer.
4. **Kandidat:** braucht einen Para-Hinweis (`HANDICAP` oder Bewerb mit Klassen) — fehlt bei OSV-Veranstaltungen.

Die fehlende Sportklasse ist also nicht die eigentliche Ursache, sondern verhindert nur den Kandidaten-Weg.

Der **Import** selbst kommt mit diesen Schwimmern schon zurecht, sobald sie ausgewählt sind: Treffer werden vorab
zugeordnet (`assignAthlete()`), die Sportklasse kommt aus den Stammdaten zur Lage des Bewerbs
(`ClubEntryService::resolveSportClass()`), Stammdaten (Lizenz, Geburtsdatum) werden nicht überschrieben.

## Lösung

### A. Treffer über "Name + Jahrgang"

Neue Trefferart in der Vorschau (`LenexResolverService::MATCH_NAME_YEAR`, Anzeige "Name + Jahrgang"), geprüft nur, wenn
`findAthlete()` nichts findet:

- Nachname + Vorname (ohne Groß-/Kleinschreibung, wie Regel 3) + Geschlecht + Nation stimmen,
- das **Geburtsjahr** stimmt (Datumsbereich 01.01.–31.12., kein `YEAR()`, siehe CLAUDE.md), das genaue Datum nicht,
- genau **ein** Athlet passt — bei mehreren kein Treffer (der Schwimmer bleibt für "Weitere Schwimmer").

Solche Treffer stehen in der Tabelle "In der Datenbank gefunden", **nicht angehakt**, Badge gelb wie "Name +
Geburtsdatum". Beim Absenden gelten sie wie jeder andere Treffer (Athleten-ID aus dem Angebot in der Sitzung).

Nur Vorschau: `findAthlete()` und `resolveAthlete()` (normaler Import) bleiben unverändert. Ein Jahrgangs-Treffer
wirkt nur, weil die Auswahl ihn dem Import vorab zuordnet.

### B. Abschnitt "Weitere Schwimmer" mit Nation- und Vereinsfilter

Für Schwimmer, die weder Treffer noch Kandidat sind (anderer Name, mehrdeutiger Jahrgang, noch nicht in der
Datenbank):

- Eingeklappter Abschnitt "Weitere Schwimmer (n)" unter den Kandidaten, n = alle übrigen Schwimmer mit Einzelergebnis.
- Zwei Filter: **Nation** und **Verein** (Werte aus der Datei, Verein abhängig von der Nation). Solange kein Verein
  gewählt ist, bleibt die Liste leer ("Bitte einen Verein wählen") — bei einem OSV-Meeting wären es sonst hunderte
  Zeilen. Gefiltert wird im Browser (Alpine-Komponente in eigener `.js`, `Alpine.data()`), die Seite lädt nicht neu.
- Je Zeile: Checkbox, Name, Geburtsdatum bzw. Jahrgang, Verein, Nation, Bewerbe — wie bei den Kandidaten.
- Angehakte Schwimmer werden wie Kandidaten behandelt (`only_athletes` mit `null`) und kommen auf die bestehende
  Klärungsseite: **bestehendem Athleten zuordnen** oder neu anlegen. Angehakte, aber gerade ausgefilterte Schwimmer
  bleiben angehakt und werden mitgeschickt; die Zahl der angehakten steht neben der Überschrift.
- Sitzung: Das Angebot bekommt eine dritte Liste `others` (LENEX-`athleteid`s); beim Absenden zählen nur angebotene.

Hinweistext wie bei den Kandidaten: Ein neu angelegter Athlet bekommt den Datei-Verein nur, wenn es ihn schon gibt,
sonst werden seine Ergebnisse als "Athlet ohne Verein" übersprungen. Der Normalfall (bekannter Para-Schwimmer) ist das
Zuordnen auf der Klärungsseite.

Keine gespeicherte Liste "bekannter Fremdvereine" — kann später ergänzt werden, falls die Filter nicht reichen.

## Entscheidungen (Erik, 26.10.2026)

1. A und B wie oben, Jahrgangs-Treffer **nicht** vorausgewählt.
2. **Ergebnis-Regeln wie im bestehenden Auswahlmodus:**
   - Verein am Ergebnis: Datei-Verein, wenn er in der Datenbank existiert, sonst Heimverein des Athleten.
   - Sportklasse: aus der Datei, sonst aus den Stammdaten zur Lage des Bewerbs; hat der Athlet keine, bleibt das
     Ergebnis ohne Klasse.
   - Wertung: Rekorde, Bestzeiten, Richtzeit-Qualifikation, Statistik ja; Cup nein.
3. Nation muss beim Jahrgangs-Treffer übereinstimmen (wie bei Regel 3), gegen Verwechslungen.

## Phasen

Jede Phase mit eigenen Tests (Gruppe `lenex-athlete-selection-others`), `composer lint:check`,
`vendor/bin/pest --parallel` grün, Freigabe nach jeder Phase:

1. **Jahrgangs-Treffer (A):** Konstante `MATCH_NAME_YEAR`, Suche in `LenexAthleteSelectionService` (bzw. reine Methode
   im Resolver), Rückgabefeld `preselect` je Treffer; View: Badge-Beschriftung und nicht angehakte Checkbox.
   Tests: Treffer über Jahrgang bei OSV-artiger Datei (Datum `01.01.`, fremde Lizenz, keine Klassen); kein Treffer bei
   anderem Jahr, anderer Nation, anderem Geschlecht oder zwei passenden Athleten; Regel 1-3 haben Vorrang; normaler
   Import (`resolveAthlete()`) findet den Schwimmer weiterhin nicht; Import mit ausgewähltem Jahrgangs-Treffer legt
   Ergebnisse mit Sportklasse aus den Stammdaten und Heimverein an, ändert keine Stammdaten.
2. **Weitere Schwimmer (B):** Vorschau liefert `others`, Sitzung/Absenden, Abschnitt mit Nation-/Vereinsfilter
   (Alpine), Weitergabe an die Klärungsseite. Tests: `others` enthält genau die übrigen Schwimmer mit
   Einzelergebnis; nur angebotene werden übernommen; ausgewählter Schwimmer landet auf der Klärungsseite und nach
   Zuordnung mit Ergebnissen in der Datenbank. Live-Prüfung im Browser (nur lesend) mit der Lagen-Cup-Datei.
3. **Doku:** Abschnitt "Athletenauswahl" in [lenex-import-export.md](lenex-import-export.md) ergänzen, Status hier auf
   "umgesetzt", Testliste aktualisieren.
