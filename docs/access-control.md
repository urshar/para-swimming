# Zugriffskontrolle

Stand: Sicherheits-Audit 06.10.2026 (`fix/admin-route-guard`). Ergänzt `CLAUDE.md` und den Open Point
"Vereins-Rollen / Berechtigungen" (das künftige, feinere Rollenmodell).

## Grundsatz

- **Öffentlich** (ohne Login): `routes/public.php` — Veranstaltungen, Ergebnisse, Rekorde, Punktetabelle usw.
- **Angemeldete Nicht-Admins (Vereinsnutzer)** dürfen im Verwaltungsbereich **lesen**; schreiben nur ihre **eigenen
  Meldungen** (`club-entries.*`, abgesichert über `EntryPolicy`: eigener Verein, Meldeschluss bzw. Nachmelde-Fenster).
- **Alles Schreibende** (Anlegen, Bearbeiten, Löschen, Importe, Berechnungen) und alle Anlege-/Bearbeiten-/
  Import-Formulare liegen hinter `RequireAdmin` (Route-Middleware, Alias `admin`).
- **Keine Selbstregistrierung**: `Features::registration()` ist in `config/fortify.php` abgeschaltet; Benutzer legt
  der Admin in der Benutzerverwaltung (`admin.users.*`) an.

## Rechte-Matrix (Verwaltungsbereich)

| Bereich                                                                 | Vereinsnutzer                                          | Admin                                   |
|-------------------------------------------------------------------------|--------------------------------------------------------|-----------------------------------------|
| Wettkämpfe (Liste, Detail, Cup-Tageswertung ansehen)                    | lesen                                                  | alles                                   |
| Wettkampf, Abschnitte, Disziplinen, Meldegelder pflegen                 | —                                                      | ✓                                      |
| ÖBSV- und WPS-Punkte berechnen, Rekorde prüfen, Cup-Wertungen berechnen | —                                                      | ✓                                      |
| Eigene Einzel- und Staffelmeldungen                                     | ✓ (eigener Verein, Fristen)                           | ✓                                      |
| Meldungs-Cockpit, Meldungen aller Vereine, Ergebnisverwaltung           | —                                                      | ✓                                      |
| Meldelisten / Meldegeld (PDF/Excel)                                     | eigener Verein                                         | alle                                    |
| Athleten, Vereine, Nationen, Klassifizierer                             | lesen                                                  | alles                                   |
| Rekorde                                                                 | lesen, exportieren                                     | alles inkl. Import                      |
| Richtzeiten / Qualifikationen                                           | lesen (inkl. PDF)                                      | alles                                   |
| Meisterschaften                                                         | lesen, Auswertungen (eigener Verein, siehe Controller) | alles inkl. Normimport                  |
| Cup-Gesamt- und Vereinswertung                                          | lesen                                                  | alles                                   |
| WPS-Auswertungen                                                        | lesen (Vereinsauswertung: eigener Verein)              | alles inkl. Import, Versionen, Faktoren |
| Statistik                                                               | —                                                      | ✓                                      |
| Basiswerte                                                              | Kategorien/Exporte lesen                               | alles inkl. Import                      |
| LENEX                                                                   | Export                                                 | Import + Export                         |
| Kadertypen, Altersgruppen, Sportklassen-Gruppen, Cups (Stammdaten)      | —                                                      | ✓                                      |
| Benutzer, Dokumente (`admin.*`)                                         | —                                                      | ✓                                      |

Schreib-Schaltflächen in den lesbaren Ansichten (Anlegen, Bearbeiten, Löschen, Importieren, Berechnen) sind für
Nicht-Admins ausgeblendet (`@if(auth()->user()?->is_admin)`), ebenso die Menüpunkte "Ergebnisse", "Rekorde →
Importieren", "Rekorde → Import Prüfliste" und "LENEX-Import".

## Absicherung neuer Routen

- `tests/Feature/AccessControlTest.php` prüft **alle** Routen hinter dem Login: Jede schreibende Route (nicht
  GET/HEAD) und jedes Anlege-/Bearbeiten-/Import-Formular braucht `RequireAdmin` — außer der kurzen Liste bewusst
  offener Routen (`allowedForClubUsers_acc`: Vereinsmeldungen, Export-Downloads, Konto-Routen). Eine neue,
  ungeschützte Schreib-Route lässt diesen Test sofort fehlschlagen.
- **Reihenfolge bei aufgeteilten Ressourcen:** Werden Lese- und Admin-Routen einer Ressource getrennt registriert,
  muss die Admin-Gruppe (mit `create`) **vor** der lesenden `show`-Route stehen — sonst bindet Laravel das Wort
  "create" als `{athlete}`/`{club}` und liefert 404. Der Test "erreicht als Admin alle Anlege-Formulare" deckt das ab.
