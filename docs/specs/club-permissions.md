# Vereins-Rollen und Rechte je Modul (Entwurf)

Stand: 28.10.2026, Branch `feature/club-permissions`. **Entwurf zur Freigabe durch Erik — noch kein Code.**
Schließt den Open Point "Vereins-Rollen / Berechtigungen (was Vereins-User sehen und dürfen)".

## 1. Wunsch (Erik)

- Jeder Verein bekommt 1–3 Benutzer, die die **eigenen Daten** pflegen: den eigenen Verein (Stammdaten) und dessen
  Athleten.
- **Ergebnisse** nur abfragen, nicht ändern. **Rekorde** abfragen.
- Bei **freigegebenen Veranstaltungen** Meldungen eintragen, nur für den eigenen Verein.
- **Flexibles Usermanagement:** Erik weist Benutzern **einzeln Rechte für bestimmte Module** zu.

## 2. Bestandsaufnahme (main, 28.10.2026)

### 2.1 Rollen heute

| Merkmal                 | Wo                                               | Bedeutung                                                     |
|-------------------------|--------------------------------------------------|---------------------------------------------------------------|
| `users.is_admin` (bool) | Migration `2026_04_12_000016`, `User::isAdmin()` | Vollzugriff                                                   |
| `users.club_id` (FK)    | dieselbe Migration, `User::hasClub()`            | Vereinsnutzer; mehrere Benutzer je Verein schon heute möglich |
| keins von beiden        | —                                                | darf lesen, aber nicht melden                                 |

Es gibt **kein** Rollen- oder Rechtepaket, keine Gates außer den Policies unten. Benutzer legt nur der Admin an
(Selbstregistrierung abgeschaltet). Dev-DB (nur lesend geprüft): 1 Benutzer (Admin), 0 Vereinsnutzer, 58 Vereine,
0 offene Veranstaltungen — es gibt also **keine Bestandsnutzer zu migrieren**.

### 2.2 Absicherung heute

- **Route-Middleware `RequireAdmin`** (Alias `admin`): alle Schreib-Routen und Anlege-/Bearbeiten-/Import-Formulare.
  `AccessControlTest` prüft über alle Routen, dass jede Schreib-Route sie hat (Ausnahmen: `club-entries.*`, Export-
  Downloads, Konto-Routen).
- **`EntryPolicy`** (`manageEntries` u. a.): Admin immer; Vereinsnutzer nur mit `club_id` und
  `Meet::acceptsClubEntries()` (Meldeschluss / Nachmelde-Fenster). Zusätzlich in `ClubEntryController`:
  `authorizeMeet()` (`is_open`), `authorizeEntry()`/`authorizeRelayEntry()` (Meldung gehört zum eigenen Verein).
- **`AthletePerformanceNotePolicy`**: Leistungsnotizen nur Admin oder eigener Verein.
- **Verstreute `is_admin`-Checks:** 57 Stellen in 25 PHP-Dateien (u. a. Controller-Scopes wie "Admin alle Vereine,
  Verein nur eigener" in `EntryFeeController`, `MeetEntryListController`, `ChampionshipQualificationController`,
  `WpsClubRankingController`; `abort_unless(is_admin)` in `BaseTimeCategoryController::show`,
  `ChampionshipStandardTable`) und 29 Blade-Views (Navigation in `layouts/app.blade.php`, Schreib-Schaltflächen,
  v. a. `meets/show` mit 16 Stellen).
- **Livewire-Aktionen** (`ChampionshipStandardTable`, `BaseTimeTable`, `WpsAthleteAnalysis::saveNote`, `UserManager`)
  sind **nicht** vom Routen-Audit erfasst; sie prüfen selbst bzw. sind über die Seite davor geschützt.

### 2.3 Module (Verwaltungsbereich) und heutiger Zugriff für Vereinsnutzer

| Modul (Vorschlag)        | Routen / Bereich                                                                                                        | Vereinsnutzer heute                     |
|--------------------------|-------------------------------------------------------------------------------------------------------------------------|-----------------------------------------|
| Wettkämpfe               | `meets.*`, Abschnitte, Disziplinen, Meldegelder, Teilnehmer, Nachmelde-Fenster                                          | lesen                                   |
| Meldungen                | `club-entries.*` (eigene), `entries.*`, `relay-entries.*`, `meets.entries-overview`, Melde-/Teilnehmerlisten, Sportpass | eigene schreiben, Listen eigener Verein |
| Ergebnisse               | `results.*`, `meets.results-overview`, `meets.relay-results.*`, `meets.result-clubs`                                    | — (nur öffentliche Ergebnisseite)       |
| Berechnungen             | ÖBSV-/WPS-Punkte, Cup-Tageswertung, Rekordprüfung je Wettkampf                                                          | —                                       |
| Athleten                 | `athletes.*` inkl. Klassifizierungen, Level, Kader, Vereinswechsel, Dubletten/Zusammenführen, Team-Manager-Import       | lesen                                   |
| Vereine                  | `clubs.*`                                                                                                               | lesen                                   |
| Nationen, Klassifizierer | `nations.*`, `classifiers.*`                                                                                            | lesen                                   |
| Rekorde                  | `records.*` inkl. Import, Prüfliste, Status                                                                             | lesen, exportieren                      |
| Richtzeiten              | `qualifying-time-lists.*`, ausgeschlossene Bewerbe                                                                      | lesen (inkl. PDF)                       |
| Meisterschaften          | `championships.*` inkl. Normimport, Auswertungen                                                                        | lesen, Auswertungen eigener Verein      |
| Cup-Wertung              | Gesamt-/Vereinswertung; Stammdaten Cups, Kadertypen, Altersgruppen, Sportklassen-Gruppen                                | lesen (Stammdaten —)                    |
| WPS                      | Ranglisten, Talentbericht, Athletenanalyse, Vereinsauswertung; Import/Versionen/Faktoren                                | lesen (Vereinsauswertung eigener)       |
| Basiswerte               | `base-times.*`                                                                                                          | Kategorien/Exporte lesen                |
| Statistik                | `statistics.*`, WPS-Analyse-Einstieg                                                                                    | —                                       |
| LENEX                    | Import / Export                                                                                                         | Export                                  |
| Verwaltung               | Benutzer, Dokumente (`admin.*`)                                                                                         | —                                       |

### 2.4 Lücken für Eriks Wunsch

1. **Vereinsdaten:** `clubs` hat nur `name`, `short_name`, `code`, `nation_id`, `type`, `regional_association`,
   `swrid` — **keine Kontaktfelder** (Ansprechperson, E-Mail, Telefon, Adresse). Was soll ein Verein an sich
   selbst pflegen? Entschieden in Abschnitt 3 (F4).
2. **Athleten:** Das Formular mischt Vereins- und Verbandsdaten (Klassifizierung/Sportklasse, Status, Lizenz,
   SDMS/SWRID, Kader, Level, Vereinswechsel). Was darf der Verein? Entschieden in Abschnitt 3 (F3).
3. **Ergebnisse abfragen:** Die Ergebnisverwaltung ist heute admin-only; Vereinsnutzer sehen Ergebnisse nur
   öffentlich bzw. auf der Athleten-Detailseite. Entschieden in Abschnitt 3 (F5).
4. **Freigabe zum Melden** gibt es schon: `meets.is_open` + Meldeschluss + Nachmelde-Fenster (`EntryPolicy`).

## 3. Entscheidungen Erik (28.10.2026)

| Frage | Entscheidung                                                                                                                                 |
|-------|----------------------------------------------------------------------------------------------------------------------------------------------|
| F1    | Auch "lesen" ist je Modul steuerbar.                                                                                                         |
| F2    | Vorlagen im Code, gemerkt am Benutzer, "Vorlage neu anwenden". Welche Vorlagen genau, entscheidet sich später.                               |
| F3    | Athleten: siehe Feldregeln 4.4. Namensänderung und Ummeldung nur per **Antrag mit Freigabe durch den Admin**.                                |
| F4    | Verein: Name, Kurzname, Code (eindeutig!) und neue Kontaktdaten; dazu neu **Trainer** des Vereins.                                           |
| F5    | Der Verein sieht nur Ergebnisse der **eigenen Athleten**.                                                                                    |
| F6    | "Freigegeben" = `is_open` + Meldeschluss/Nachmelde-Fenster wie heute.                                                                        |
| F7    | Keine Obergrenze an Benutzern je Verein. **Trainer** können melden (nur melden).                                                             |
| F8    | Änderungsprotokoll ja, Zwei-Faktor-Pflicht ja (wo sinnvoll, siehe 4.7).                                                                      |
| F9    | Ein Benutzer gehört höchstens einem Verein.                                                                                                  |
| N1    | Namenspräfix gehört wie Vor-/Nachname zum Antrag.                                                                                            |
| N2    | Ummeldung beantragt der **aufnehmende** Verein.                                                                                              |
| N3    | Der Verein legt neue Athleten selbst an.                                                                                                     |
| N3b   | Geburtsdatum und Geschlecht bleiben frei änderbar.                                                                                           |
| N4    | Zusätzlich E-Mail an die Admins je Antrag und E-Mail an den Verein bei der Entscheidung.                                                     |
| N5    | Vereinskontakt: Kontaktname, Adresse, E-Mail.                                                                                                |
| N6    | Trainer-Ausbildung: Übungsleiter (ÜL), Instructor (IN), Trainer (TR) oder "ohne Ausbildung"; Aus- und Fortbildungen als Historie je Trainer. |
| N7    | Trainer-Login wird mit dem Trainer-Datensatz verknüpft.                                                                                      |
| N8    | Vereinscode eindeutig je Nation.                                                                                                             |

## 4. Rechtemodell

### 4.1 Kern

- **Admin bleibt Vollzugriff** (`is_admin`; `User::readScope()`/`writeScope()` liefern für Admins immer "alle")
  — keine Rechte-Matrix nötig, nicht aussperrbar. Bewusst **kein** globales `Gate::before`, damit bestehende
  Policies und künftige Ausnahmen für Admins (z. B. "eigenen Account nicht löschen") unberührt bleiben.
  Nur Admins verwalten Benutzer und entscheiden Anträge.
- Jeder andere Benutzer hat **je Modul zwei Einstellungen**, jeweils "kein / eigener Verein / alle":

  | Einstellung | Bedeutung                                                                            |
      |-------------|--------------------------------------------------------------------------------------|
  | Lesen       | Modul sichtbar (Menü, Listen, Detailseiten, Exporte); bei "eigener Verein" gefiltert |
  | Schreiben   | anlegen/ändern; bei "eigener Verein" nur Datensätze mit `club_id` = eigener Verein   |

  Schreiben setzt mindestens denselben Lesebereich voraus (wird beim Speichern erzwungen). Nicht jedes Modul bietet
  jeden Bereich (z. B. Nationen kein "eigener Verein", Statistik kein Schreiben); die erlaubten Werte je Modul
  stehen im Code. Das ersetzt die vier Stufen aus dem ersten Entwurf, weil F5 "lesen — nur eigener Verein"
  braucht.
- **Module:** PHP-Enum `App\Enums\Module`; Bereich: Enum `App\Enums\AccessScope` (`none`, `own`, `all`).
- **Speicherung:** Tabelle `user_module_permissions (user_id, module, read_scope, write_scope)`, eindeutig je
  `(user_id, module)`. Fehlende Zeile = kein Zugriff.
- **Eigener Verein** = `users.club_id` (genau einer, F9). Ein Bereich "eigener Verein" ohne `club_id` ist ungültig.
- **Vorlagen** im Code (`App\Enums\RoleTemplate` o. ä.); die Auswahl befüllt die Matrix, wird als
  `users.role_template` gemerkt und kann per "Vorlage neu anwenden" (einzeln oder für alle Benutzer dieser
  Vorlage) erneut übertragen werden. Startbestand, Feinschliff später:
    - **Verein** — Meldungen, Athleten, Trainer lesen + schreiben eigener Verein; Vereine lesen alle (wie heute),
      schreiben eigener Verein; Ergebnisse, Meisterschaften, WPS lesen eigener Verein (bei Meisterschaften und WPS
      heißt das wie heute: Auswertungen auf den eigenen Verein beschränkt); Wettkämpfe, Nationen, Klassifizierer,
      Rekorde, Richtzeiten, Cup-Wertung, Basiswerte, LENEX (Export) lesen alle.
    - **Trainer** — nur Meldungen schreiben eigener Verein (plus das Lesen, das das Melden braucht: Wettkämpfe).
    - **Ohne Rechte** — leere Matrix (Ausgangspunkt für Einzelvergabe).
- **Zugriff im Code:** `$user->canRead(Module::Athletes)`, `$user->canWrite(Module::Athletes)`,
  `$user->readScope(...)`; Gates `module.read` / `module.write`; Blade `@can('module.write', Module::Athletes)`.
  Datensätze mit Vereinsbezug über Policies (`AthletePolicy`, `ClubPolicy`, `CoachPolicy`, erweiterte
  `EntryPolicy`), Listen über einen Scope (`Athlete::visibleTo($user)` usw.).
- **Route-Middleware** `module:<modul>,read|write` ersetzt `RequireAdmin` an den Schreib-Gruppen und kommt an die
  lesenden Routen; reine Admin-Bereiche (Benutzer, Dokumente, Anträge entscheiden) behalten `admin`.

### 4.2 Warum eigene Umsetzung statt `spatie/laravel-permission`

- Der **Bereich** (eigener Verein vs. alle) ist dort nicht vorgesehen und müsste als eigene Rechte-Strings
  nachgebaut werden (`athletes.write.own`, `athletes.write.all`); "eigener Verein" bliebe trotzdem Policy-Code.
- Rechte sind **nur additiv** (Rolle ∪ Einzelrechte): einem einzelnen Benutzer ein Recht seiner Rolle wegnehmen
  geht nicht. Das passt schlecht zu "einzeln einstellen".
- Fünf zusätzliche Tabellen und eine weitere Abhängigkeit; die Matrix-UI müssten wir trotzdem selbst bauen.

Verworfen: fester Vereins-User-Satz (nicht flexibel genug) und verknüpfte Rollen in der DB (F2: "Vorlage neu
anwenden" reicht; später nachrüstbar, weil der Code nur `canRead()`/`canWrite()` fragt).

### 4.3 Module (Startliste)

Wettkämpfe · Meldungen · Ergebnisse · Athleten · Vereine · **Trainer (neu)** · Nationen · Klassifizierer · Rekorde ·
Richtzeiten · Meisterschaften · Cup-Wertung · Cup-Stammdaten · WPS · Basiswerte · Statistik · LENEX.
Admin-only und nicht vergebbar: Benutzer, Dokumente, Anträge entscheiden.

Umgesetzt in Phase 1 (`App\Enums\Module`), gegenüber dem ersten Entwurf zusammengefasst: **Berechnungen** (Punkte,
Cup-Tageswertung, Rekordprüfung) gehören zu "Wettkämpfe — schreiben"; **LENEX** ist ein Modul (lesen = Export,
schreiben = Import). "Eigener Verein" gibt es beim Lesen für Meldungen, Ergebnisse, Athleten, Vereine, Trainer,
Meisterschaften, WPS; beim Schreiben für Meldungen, Athleten, Vereine, Trainer. Statistik ist nur lesbar.

### 4.4 Athleten: Feldregeln bei "schreiben — eigener Verein" (F3)

| Feld / Bereich                                                                                                                                  | Verein             |
|-------------------------------------------------------------------------------------------------------------------------------------------------|--------------------|
| Vorname, Nachname, Namenspräfix (N1)                                                                                                            | nur per **Antrag** |
| Verein / Vereinseintritt (Ummeldung, Vereinshistorie)                                                                                           | nur per **Antrag** |
| SDMS ID (`license_ipc`), ÖBSV Level (`level`)                                                                                                   | — (nur Admin)      |
| Klassifizierungen, Level-Historie, Kader, Zusammenführen, Dubletten, Löschen                                                                    | — (nur Admin)      |
| aktiv, Geschlecht, Geburtsdatum, Nation, Status, nationale Lizenz, Behinderungsgruppe/-untergruppe, med. Kontrollen, Kontakt & Adresse, Notizen | frei änderbar      |

Durchgesetzt **serverseitig** (eigene Validierungsregeln für den Vereinsweg; gesperrte Felder werden ignoriert,
nicht nur ausgeblendet). Im Formular erscheinen gesperrte Felder schreibgeschützt mit dem Hinweis "Änderung per
Antrag" bzw. "nur Verband".

### 4.5 Anträge (neu)

- Tabelle `change_requests`: Art (`athlete_name`, `athlete_transfer`), Athlet, gewünschte Werte (JSON),
  Begründung, Status (offen / freigegeben / abgelehnt / zurückgezogen), gestellt von/am, entschieden von/am,
  Antwort des Admins.
- **Namensänderung:** Verein beantragt neuen Vor-/Nachnamen; bei Freigabe übernimmt das System den Namen (die
  Verknüpfungen hängen an der Athleten-ID, nicht am Namen — der Schutz zielt auf Verwechslung/Missbrauch und darauf,
  dass der LENEX-Abgleich über den Namen nicht ins Leere läuft).
- **Ummeldung (N2):** beantragt der **aufnehmende** Verein. Da er fremde Athleten nicht sieht (F5), findet er den
  Athleten nur über genaue Angaben (Vorname, Nachname, Geburtsdatum). Bei Freigabe läuft der vorhandene
  Vereinswechsel (`athletes.transfer-club`-Logik inkl. Vereinshistorie).
- **Neue Athleten (N3):** legt der Verein selbst an (eigener Verein fest), mit Dublettenwarnung; sichtbar im
  Protokoll.
- **Info (N4):** Eingangsliste "Anträge" mit Zähler im Menü **und** E-Mail an alle Admins je neuem Antrag; der
  antragstellende Benutzer bekommt bei der Entscheidung eine E-Mail. Der Verein sieht seine Anträge mit Status am
  Athleten bzw. in einer Liste "Meine Anträge".
- Ein offener Antrag je Art und Athlet; neue Anfrage ersetzt erst nach Rückziehen.

### 4.6 Verein und Trainer (F4)

- Verein (eigener) darf **Name, Kurzname, Code** und die **neuen Kontaktfelder** ändern; Nation, Typ,
  Landesverband, SWRID bleiben Verband.
- **Code eindeutig:** Heute gibt es dafür weder Validierung noch DB-Index (dev: 58 Vereine, alle mit Code, keine
  Doppelten). **Eindeutig je Nation (N8)** — Validierung in beiden Formularen plus Unique-Index
  `(nation_id, code)`.
- **Kontaktfelder neu (N5):** Kontaktname, E-Mail, Adresse (Straße, PLZ, Ort, Land).
- **Trainer neu (N6/N7):**
    - Tabelle `coaches` (`club_id`, Vorname, Nachname, E-Mail, Notizen, aktiv), Pflege unter dem Verein, Modul
      "Trainer".
    - Ausbildungsstufe: Übungsleiter (ÜL), Instructor (IN), Trainer (TR) oder "ohne Ausbildung" (Enum).
    - Historie `coach_qualifications` (`coach_id`, Art Ausbildung/Fortbildung, Stufe, Bezeichnung, Datum, Notiz);
      die aktuelle Stufe ergibt sich aus der höchsten abgeschlossenen Ausbildung, ohne Eintrag "ohne Ausbildung".
    - Login-Verknüpfung `users.coach_id` (nullable); ein Trainer-Benutzer bekommt die Vorlage "Trainer".

### 4.7 Änderungsprotokoll und Zwei-Faktor (F8)

- **Protokoll:** Tabelle `audit_logs` (Benutzer, Modell + ID, Aktion, geänderte Felder alt → neu, Zeitpunkt), per
  Trait/Observer an Athlet, Verein, Trainer, Meldung, Staffelmeldung, Antrag. Protokolliert werden **alle**
  Benutzer, auch Admins — so ist nachvollziehbar, wer was geändert hat. Anzeige als Verlauf am Athleten/Verein (nur
  Admin). Eigene Umsetzung statt `spatie/laravel-activitylog`, analog zur Entscheidung oben.
- **Zwei-Faktor:** Fortify-2FA ist schon eingebaut. Pflicht für jeden Benutzer mit mindestens einem
  Schreibrecht (und Admins): Ohne bestätigte 2FA führt jede Seite außer den Sicherheitseinstellungen dorthin.
  Dazu in der Benutzerverwaltung: 2FA-Status sehen und **2FA zurücksetzen** (Handy verloren). Benutzer nur mit
  Leserechten bleiben ohne Pflicht.

### 4.8 Auswirkungen

- **Routen:** `RequireAdmin` → `module:<modul>,write`; lesende Routen `module:<modul>,read`.
  Admin-Gruppen-Reihenfolge vor `show` bleibt.
- **Lesebereich "eigener Verein":** Athletenliste/-detail, Ergebnisse, WPS-Vereinsauswertung, Meisterschafts-
  Auswertungen, Melde-/Teilnehmerlisten filtern über `visibleTo($user)`; fremde Detailseiten → 403.
- **Ergebnisse lesend (F5):** lesende Ergebnisliste ohne Bearbeiten-Schaltflächen, gefiltert auf eigene Athleten.
- **Navigation/Views:** 29 Views mit `is_admin` → `@can(...)`; Menügruppen nur bei Lesezugriff.
- **Livewire:** jede schreibende Aktion prüft selbst (der Routen-Audit sieht sie nicht).
- **Benutzerverwaltung:** Vorlage, Matrix Modul × Lesen/Schreiben, Verein, 2FA-Status/-Reset,
  "Vorlage neu anwenden".
- **Tests:** `AccessControlTest`: jede Schreib-Route braucht `admin` **oder** `module:*,write`; datengetriebene Tests
  je Modul × Bereich; Policy-Tests "fremder Verein"; Feldregeln; Antragsablauf; Protokoll; 2FA-Pflicht.
- **Doku:** `access-control.md`, `CLAUDE.md` (Zugriffskontrolle), `open-points.md`.

## 5. Offene Fragen

Alle Fragen F1–F9 und N1–N8 sind entschieden (Abschnitt 3). Neue Detailfragen kommen mit dem Plan der jeweiligen
Phase.

## 6. Phasenplan

Jede Phase: Plan → Freigabe → Umsetzung → Tests grün + `composer lint:check` → Sign-off.

1. **Rechte-Kern ohne Verhaltensänderung:** Enums, Tabelle `user_module_permissions`, `users.role_template`,
   `User::canRead()/canWrite()/readScope()`, Gates, Middleware `module:`, Vorlagen + "neu
   anwenden" als Service. Noch nirgends verwendet.
2. **Benutzerverwaltung:** Vorlage, Matrix, Validierung, "Vorlage neu anwenden", 2FA-Status/-Reset.
3. **Routen, Navigation, Views, Livewire umstellen** auf `module:`/`@can`, `AccessControlTest` umbauen; Lese-Scope
   "eigener Verein" für Athleten und die bisherigen Vereins-Filter. Mit Vorlage "Verein" verhält sich alles wie
   heute, außer dem neuen Athleten-Filter.
4. **Änderungsprotokoll** — vor dem ersten Vereins-Schreibzugriff, damit von Anfang an protokolliert wird.
5. **Athleten des eigenen Vereins pflegen** mit Feldregeln 4.4 (ohne Anträge; Name/Verein schreibgeschützt).
6. **Anträge:** Namensänderung + Ummeldung, Admin-Eingang, Info (N4).
7. **Verein pflegen:** Kontaktfelder, Code-Eindeutigkeit (Migration + Validierung).
8. **Trainer:** Tabelle, Pflege, Vorlage "Trainer", ggf. Verknüpfung mit Benutzer.
9. **Meldungen an das Modul koppeln** (eigener Verein vs. alle/Cockpit) und **Ergebnisse lesend** (eigene Athleten).
10. **Zwei-Faktor-Pflicht** für Benutzer mit Schreibrechten.
11. **Doku**, Open Point schließen.

Phasen 1–3 sind Voraussetzung, 4 vor 5–8. Phase 1 und 2 freigegeben (Erik, 28.10.2026).

### Phase 1 im Detail (freigegeben)

- Migration `create_user_module_permissions_table` (`user_id` FK cascade, `module` string, `read_scope`,
  `write_scope` string, Unique `(user_id, module)`) und `add_role_template_to_users_table` (nullable string).
- `App\Enums\Module` (mit `label()`, `allowedReadScopes()`, `allowedWriteScopes()`), `App\Enums\AccessScope`
  (`None`, `Own`, `All`, mit `covers()`), `App\Enums\RoleTemplate` (Verein, Trainer, Ohne Rechte; `permissions()`).
- Model `UserModulePermission`; `User::modulePermissions()`, `canRead()`, `canWrite()`, `readScope()`,
  `writeScope()` (Admin → immer `All`; "eigener Verein" ohne `club_id` → `None`); Rechte je Request einmal laden.
- `final readonly class` `PermissionTemplateService`: Vorlage auf Benutzer anwenden, für alle Benutzer einer
  Vorlage neu anwenden; Validierung (Schreiben ⊆ Lesen, erlaubte Bereiche je Modul).
- Gates `module.read` / `module.write` im `AppServiceProvider` (Admin über `User::readScope()`, kein globales
  `Gate::before`).
- Middleware `RequireModuleAccess` (Alias `module`), z. B. `module:athletes,write`.
- Tests (Gruppe `club-permissions-p1`): Admin darf alles; ohne Zeile kein Zugriff; Bereich "eigener Verein" ohne
  Verein = kein Zugriff; Vorlage anwenden/neu anwenden überschreibt die Matrix; ungültige Kombinationen werden
  abgelehnt; Middleware 403/200.
- Keine Route und keine View wird in Phase 1 umgestellt — bestehende Tests bleiben unverändert grün.

### Phase 2 im Detail (freigegeben)

Ausbau des bestehenden Livewire-`Admin\UserManager` (`/admin/users`). Weiterhin nur für Admins; die Rechte wirken
erst ab Phase 3.

- **Liste:** neue Spalten "Vorlage" (Badge; "angepasst", wenn die Matrix von der gemerkten Vorlage abweicht) und
  "2FA" (aktiv/aus nach `two_factor_confirmed_at`). Filter nach Vorlage.
- **Anlegen/Bearbeiten (bestehendes Modal):** Pflichtfeld "Vorlage" (Vorbelegung: "Verein" bei gewähltem Verein,
  sonst "Ohne Rechte"; bei Admin ausgeblendet). Beim Anlegen wird die Vorlage angewendet. Beim Bearbeiten ersetzt
  eine **geänderte** Vorlage die Matrix (mit Rückfrage, falls die Matrix angepasst war); unveränderte Vorlage lässt
  die Matrix stehen.
- **Rechte-Matrix (eigener Flyout "Rechte", Button je Zeile):** 17 Module × Lesen/Schreiben, je Zelle Auswahl nur
  der im Modul erlaubten Bereiche, Hinweistext aus `Module::writeHint()`. Speichern über
  `PermissionTemplateService::setPermissions()`, Fehler je Modulzeile (`permissions.<modul>`). Buttons "Vorlage neu
  anwenden" (nur dieser Benutzer) und "Abbrechen". Für Admins nicht verfügbar ("Admin hat alle Rechte").
- **Verein entfernt, aber "eigener Verein" in der Matrix:** Speichern erlaubt, die Matrix zeigt dann einen Hinweis
  "wirkt nicht ohne Verein" (entspricht der Regel aus Phase 1).
- **"Vorlage neu anwenden" für alle:** Button im Seitenkopf → Auswahl der Vorlage, Rückfrage mit Anzahl der
  betroffenen Benutzer (davon "angepasst"), dann `reapplyTemplate()`.
- **2FA zurücksetzen:** Aktion je Benutzer mit aktiver 2FA (Rückfrage), leert `two_factor_secret`,
  `two_factor_recovery_codes`, `two_factor_confirmed_at`. Nicht für den eigenen Account (der geht über die
  Sicherheitseinstellungen).
- **Selbstschutz:** eigenen Admin-Haken nicht entfernbar (wie schon "eigenen Account nicht löschen").
- **Absicherung der Livewire-Aktionen:** Jede Aktion des `UserManager` prüft selbst `is_admin` (Livewire-Requests
  laufen nicht über die Route-Middleware der Seite).
- **Bestandsbenutzer:** Artisan-Befehl `permissions:assign-template {vorlage}` setzt die Vorlage bei allen
  Nicht-Admins **ohne** gemerkte Vorlage (`--only-with-club` für die Vorlage "Verein"), mit Trockenlauf
  `--dry-run`. Erik führt ihn auf Dev/Prod selbst aus, vor Phase 3.
- **Hilfsmethode** `PermissionTemplateService::isCustomized(User): bool` (Matrix ≠ Vorlage) für Badge und Rückfragen.
- Tests (Gruppe `club-permissions-p2`): Anlegen mit Vorlage; Vorlagenwechsel ersetzt Matrix, gleiche Vorlage
  nicht; Matrix speichern inkl. Fehlerfälle; neu anwenden einzeln/alle; 2FA-Reset; Selbstschutz; Nicht-Admin
  bekommt bei jeder Aktion 403; Artisan-Befehl inkl. `--dry-run`. Bestehende `UserManagementTest` bleibt grün.

### Phase 3 im Detail (3a freigegeben)

Ziel: Alle Prüfungen laufen über die Rechte je Modul statt über `is_admin`. Mit der Vorlage "Verein" verhält sich
alles wie heute, **außer** dass Athleten nur noch im eigenen Verein sichtbar sind (F5). Admins unverändert.
Schreiben "eigener Verein" bei Athleten, Verein und Trainern wirkt erst ab Phase 5/7/8 — bis dahin verlangen diese
Schreib-Routen den Bereich "alle".

**Umfang** (Bestand: 9 Admin-Gruppen in `routes/web.php`, 69 `is_admin`-Stellen in 28 PHP-Dateien, 83 in 29
Views, 4 Livewire-Komponenten mit Schreibaktionen) — deshalb in drei Teilschritten, jeder mit grünen Tests und
eigenem Sign-off:

**3a — Routen und Audit**

- Middleware um optionalen Bereich erweitert: `module:entries,read,all` verlangt den Bereich "alle" (für
  verbandsweite Ansichten wie das Melde-Cockpit).
- Zuordnung der Routen (`admin` bleibt nur für Benutzer, Dokumente und den Team-Manager-Import):

  | Modul                                                                                                            | lesen                                                                             | schreiben                                                                                                                                        |
    |------------------------------------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------|
  | Wettkämpfe                                                                                                       | meets index/show, Teilnehmer                                                      | Wettkampf, Abschnitte, Disziplinen, Wertungsgruppen, Gebühren, Meldeschluss wiedereröffnen, Punkte/WPS/Cup-Tageswertung berechnen, Rekordprüfung |
  | Meldungen                                                                                                        | Meldegeld, Teilnehmer-/Meldelisten; Cockpit, Gesamtübersicht, Sportpass mit `all` | club-entries (Policy wie heute); Admin-Meldeformular mit `all`                                                                                   |
  | Ergebnisse                                                                                                       | Ergebnisliste, Sammelansicht, PDF mit `all` (eigene ab Phase 9)                   | Ergebnisse, Staffelergebnisse, Ergebnis-Vereine mit `all`                                                                                        |
  | Athleten                                                                                                         | index/show (gefiltert, s. 3c)                                                     | Anlegen/Bearbeiten, Ummelden, Klassifizierung, Level, Kader, Dubletten, Zusammenführen mit `all`                                                 |
  | Vereine                                                                                                          | index/show                                                                        | Pflege mit `all`                                                                                                                                 |
  | Nationen, Klassifizierer, Richtzeiten (inkl. ausgeschlossene Bewerbe), Meisterschaften, Rekorde, Basiswerte, WPS | Ansichten, PDFs, Exporte                                                          | Pflege, Importe, Berechnungen                                                                                                                    |
  | Cup-Wertung                                                                                                      | Gesamt-, Vereins-, Tageswertung                                                   | Top-Gruppe einstufen, Gesamtwertung berechnen                                                                                                    |
  | Cup-Stammdaten                                                                                                   | —                                                                                 | Cups anlegen/bearbeiten                                                                                                                          |
  | Stammdaten                                                                                                       | —                                                                                 | Kadertypen, Altersklassen, Sportklassengruppen                                                                                                   |
  | Statistik                                                                                                        | Statistik, Jahresvergleich, Bericht, WPS-Athletenauswahl                          | —                                                                                                                                                |
  | LENEX                                                                                                            | Export (auch der POST-Download)                                                   | Import                                                                                                                                           |

- `AccessControlTest` neu: (1) jede Route hinter dem Login hat `module:` oder `admin`, außer einer kleinen
  Allowlist (Dashboard, Konto/Sicherheit, Logout, `club-entries.pick-meet`); (2) jede schreibende Route hat
  `module:…,write` oder `admin`, außer den Export-Downloads (`module:…,read`); (3) jedes in `module:` genannte Modul
  existiert. Dazu: Benutzer mit Vorlage "Verein" erreicht genau das, was ein Vereinsnutzer heute erreicht (bestehende
  Positiv-/Negativlisten), Benutzer "Ohne Rechte" nur Dashboard und Konto.
- **Testbestand:** 58 Testdateien legen Nicht-Admins an, die bisher ohne Rechte-Zeilen durchkamen. Die
  `UserFactory` bekommt `afterCreating` → Vorlage "Verein" für Nicht-Admins mit Verein; Helper mit `User::create()`
  bekommen die Vorlage ausdrücklich. Keine Änderung an Testerwartungen außer dem neuen Athleten-Filter.

- **Vorgezogen aus 3b (Erik, 29.10.2026):** Navigation im Layout nach Modulrechten; `/dashboard` leitet nach dem
  Login auf den ersten freigegebenen Bereich weiter (`User::homeRoute()`), ohne freigegebenen Bereich ein Hinweis
  statt einer 403-Seite.

**3b — Views (Schaltflächen)**

- `layouts/app.blade.php`: jede Gruppe/jeder Punkt mit `@can('module.read', …)` bzw. `module.write` statt
  `is_admin`; Gruppen ohne sichtbaren Punkt verschwinden.
- Schreib-Schaltflächen in den 29 Views (Bearbeiten, Anlegen, Import, Berechnen …) über `@can('module.write', …)`.
  Wo die Schaltfläche heute für Admins "alle Vereine" bedeutet, `readScope(...) === All`.
- Test: Navigation für "Verein", "Trainer" und "Ohne Rechte" (sichtbare/unsichtbare Punkte).

**3c — Controller, Policies, Livewire, Athleten-Filter**

- `Athlete::scopeVisibleTo(User)` (alle / eigener Verein / nichts) für Athletenliste und -suche; Detailseite eines
  fremden Athleten → 403. Vereinsseite eines fremden Vereins zeigt keine Athletenliste.
- Die 69 PHP-Stellen: "Admin sieht alle Vereine" → `readScope(Modul) === All`; "Admin darf schreiben" →
  `canWrite(Modul)`. `EntryPolicy`: Admin-Ausnahmen (Meldeschluss) → Meldungen schreiben "alle".
- Livewire-Schreibaktionen prüfen selbst: `BaseTimeTable::recalculate` (Basiswerte schreiben),
  `ChampionshipStandardTable` (Meisterschaften schreiben), WPS-Notizen (Policy bleibt, s. offene Frage).
- Tests: fremder Athlet 403/unsichtbar; Vorlage "Trainer" kann melden, aber keine Athleten sehen außer über das
  Melden; je Livewire-Aktion 403 ohne Schreibrecht.

**Entscheidungen Phase 3 (Erik, 28.10.2026)**

- P3-1: Ja, 3a/3b/3c werden einzeln abgenommen.
- P3-2: Ja, die WPS-Athletenanalyse folgt der Athleten-Sichtbarkeit (nur eigene Athleten).
- P3-3: Ja, neues Modul "Stammdaten" (`master_data`) für Kadertypen, Altersklassen, Sportklassengruppen; in keiner
  Vorlage (bisher nur Admin).
- P3-4: Ja, die Melde-Athletenauswahl hängt am Melderecht, nicht am Athletenmodul.
