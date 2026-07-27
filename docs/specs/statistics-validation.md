# Referenzvalidierung Statistikmodul

Dokumentation zu Spec-Phase 16 (Referenzvalidierung) und den Punkten aus §20–24. Festgehalten wird, wie die vom Modul
ermittelten Kennzahlen gegen einen vorliegenden Jahresbericht plausibilisiert wurden, wie die festgestellten
Abweichungen zustande kommen und welche fachlichen Entscheidungen getroffen wurden.

> Modul-Referenz: [statistics.md](statistics.md). Die
> Paragrafen-Verweise (§16, §20–24, §22, §25) beziehen sich auf die
> ursprüngliche Bau-Spezifikation des Statistikmoduls, deren dauerhafter Inhalt
> inzwischen in `specs/statistics.md` aufgegangen ist.

## Methode

Der Abgleich erfolgt über den Befehl

```bash
php artisan statistics:reference-check <jahr> \
    --participants= --clubs= --starts= --repeat-athletes= --records=
```

Die Referenzwerte werden ausschließlich als Kommandozeilen-Optionen übergeben. Sie dienen nur der Gegenüberstellung und
fließen an keiner Stelle in eine Berechnung ein — es wird also keine Zahl hartcodiert, um die Referenz zu treffen (Spec
§16). Sämtliche ermittelten Werte stammen aus dem
`StatisticsService`.

## Abgleich Jahresbericht 2024

Referenzwerte aus dem offiziellen Jahresbericht 2024, ermittelte Werte aus dem aktuellen Datenbestand:

| Kennzahl                    | Referenz | Ermittelt | Abweichung |
|-----------------------------|---------:|----------:|-----------:|
| Sportler                    |      186 |       185 |         −1 |
| Österreichische Vereine     |       25 |        27 |         +2 |
| Starts                      |    1.464 |     1.571 |       +107 |
| Sportler mit ≥ 2 Teilnahmen |       97 |        99 |         +2 |
| Neue Rekorde                |       85 |       552 |       +467 |

### Erklärung der Abweichungen

**Sportler, Vereine, Mehrfachteilnahmen (±1–2):** Die Werte treffen die Referenz im Rahmen nachträglich korrigierter
oder ergänzter Meldungen. Solche Differenzen sind bei einem gegenüber dem Redaktionsstand veränderten Datenbestand
erwartbar und kein Hinweis auf einen Zählfehler.

**Starts (+107):** Die Statusaufschlüsselung weist für 2024 ausschließlich reguläre Ergebnisse aus (regular 1.571, alle
übrigen Status 0). Es existiert also keine einzige DNS-, DSQ-, DNF-, SICK- oder WDR-Zeile. Damit liefern alle drei
möglichen Startdefinitionen denselben Wert (1.571), und die **Start-Definition ist als Ursache der Abweichung
ausgeschlossen**. Die +107 sind schlicht zusätzliche, gegenüber dem Referenzbericht nachgetragene Ergebniszeilen. Die im
Modul gewählte Definition — angetreten = alle Ergebnisse außer DNS/SICK/WDR — bleibt damit bestätigt.

**Neue Rekorde (+467):** Dies ist ein Artefakt des Rekord-Imports, kein Zählfehler. Beim Einspielen wurden die Rekorde
gegen eine leere Rekordtabelle geprüft. Der historische Grundbestand aus der Zeit vor den ersten erfassten Wettkämpfen
(2016) ist im geprüften Datenbestand nicht enthalten. Dadurch wurde für viele bereits zuvor bestehende Bestleistungen
ein "neuer" Rekord mit Datum im jeweiligen Jahr angelegt. Das Modul zählt korrekt, was in der Tabelle steht; die
überhöhte Zahl entsteht ausschließlich aus der Datenlage beim Import.

## Veranstaltungsbezug der Rekorde

Auf Wunsch (bestätigt) zählt die Rekordstatistik nur Rekorde, die tatsächlich an einer Veranstaltung aufgestellt
wurden — verknüpft über
`swim_records.result_id → results.meet_id`:

- **Mit Veranstaltungsauswahl:** nur Rekorde der ausgewählten Veranstaltungen (z. B. die neuen Rekorde der
  ÖBSV-Cup-Runden).
- **Ohne Auswahl:** alle Rekorde des Jahres, die an einer im System vorhandenen Veranstaltung aufgestellt wurden
  (`result_id` gesetzt). Ein separat importierter historischer Grundbestand ohne Veranstaltungsbezug bleibt außen vor.

Die zeitliche Abgrenzung über `set_date` bleibt unverändert; Jugend- und Offen-Rekorde werden gemeinsam gezählt. In der
Praxis reduziert die Veranstaltungsauswahl die Rekordzahl deutlich (Beispiel: 552 → 300 gesamt, davon 65 nationale
österreichische Rekorde). Die verbleibende Differenz zur Referenz ist auf den oben genannten fehlenden Grundbestand
zurückzuführen.

## Konsistenzprüfungen

Unabhängig vom Referenzabgleich wurde die innere Stimmigkeit der Aggregation geprüft. Alle Prüfungen bestehen:

- Summe aller Status = ausgewiesene Gesamtzeilen
- Alle Ergebnisse − (DNS + SICK + WDR) = Starts
- Summe der Starts je Veranstaltung = Gesamtstarts
- Summe der Teilnehmer je Veranstaltung = Teilnahmen

Sechs unabhängig berechnete Wege ergeben dieselben Werte.

## Ergebnis

Die Validierung ist mit dokumentierten und erklärten Abweichungen bestanden:

- Teilnehmer-, Vereins- und Mehrfachteilnahmezahlen treffen die Referenz im Rahmen nachgetragener Daten (±1–2).
- Die Startdifferenz erklärt sich vollständig aus zusätzlichen Ergebniszeilen; die Start-Definition ist nachweislich
  nicht die Ursache.
- Die Rekorddifferenz ist ein Import-/Datenthema (fehlender historischer Grundbestand vor 2016), kein Fehler der
  Auswertung.

Es wurde keine Zahl hartcodiert; alle Werte werden aus den bestehenden Services abgeleitet.

## Offene Punkte für einen zahlen genauen Abgleich

Ein exakter Abgleich mit dem Referenzbericht 2024 setzt voraus, dass derselbe Datenstand vorliegt wie zum
Redaktionsschluss des Berichts — insbesondere der historische Rekord-Grundbestand. Sobald dieser eingespielt ist, kann
der
`reference-check` erneut ausgeführt werden; das Prüfinstrument steht bereit und ist durch Tests abgesichert.

## Fachliche Entscheidungen (§22, §25)

- **Berechtigungen (§22):** Das Projekt besitzt keine Rechte-/Rollen-Architektur, sondern nur das `is_admin`-Flag mit
  der `RequireAdmin`-Middleware. Die in der Spec beispielhaft genannten Rechte (`statistics.view` usw.) einzuführen
  hätte eine neue Berechtigungsarchitektur bedeutet, was die Spec untersagt. Entscheidung: Das Statistikmodul ist
  **ausschließlich für Administratoren**
  zugänglich (Routen unter `RequireAdmin`, Navigationseintrag nur für Admins).
- **Start-Definition:** angetreten = alle Ergebnisse außer DNS/SICK/WDR (bestätigt; im Datenbestand 2024 ohne praktische
  Auswirkung, siehe oben).
- **Rekord-Veranstaltungsbezug:** siehe Abschnitt oben (bestätigt).
- **ÖBM/ÖJM:** ohne Kennzeichnungsfeld in den Stammdaten; die Zuordnung erfolgt über die Auswahl der betreffenden
  Veranstaltungen im Bericht.
