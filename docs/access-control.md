# Zugriffskontrolle

Stand: 31.10.2026, nach `feature/club-permissions` (Phasen 1–11). Die Entscheidungen und ihre Begründung stehen in
[specs/club-permissions.md](specs/club-permissions.md); diese Seite beschreibt, was gilt und wie man es im Code
einhält.

## Grundsatz

- **Öffentlich** (ohne Login): `routes/public.php` — Veranstaltungen, Ergebnisse, Rekorde, Punktetabelle usw.
- **Admins** (`users.is_admin`) haben im Verwaltungsbereich alles; `User::readScope()`/`writeScope()` liefern für sie
  immer "alle". Nur Admins verwalten Benutzer und Dokumente, entscheiden Anträge und führen den Team-Manager-Import
  aus (Route-Middleware `admin`).
- **Alle anderen** haben **je Modul** zwei Einstellungen, Lesen und Schreiben, jeweils **kein / eigener Verein /
  alle** (Tabelle `user_module_permissions`, fehlende Zeile = kein Zugriff). "Eigener Verein" ist `users.club_id`;
  ohne Verein zählt der Bereich als kein Zugriff. Schreiben reicht nie weiter als Lesen.
- **Vorlagen** (`App\Enums\RoleTemplate`) befüllen die Matrix; der Admin kann sie je Benutzer anpassen und eine
  geänderte Vorlage per "Vorlage neu anwenden" übertragen (einzeln oder für alle Benutzer der Vorlage).
- **Zwei-Faktor-Pflicht** für Admins und jeden Benutzer mit mindestens einem Schreibrecht (siehe unten).
- **Keine Selbstregistrierung**: `Features::registration()` ist in `config/fortify.php` nicht aktiviert; Benutzer legt
  der Admin in der Benutzerverwaltung (`admin.users.*`) an.

## Module und Vorlagen

Module: `App\Enums\Module` (der Wert ist zugleich der Parameter der Middleware). "Eigener Verein" gibt es nur, wo die
Daten einen Vereinsbezug haben (`allowedReadScopes()`/`allowedWriteScopes()`).

| Modul                                                                          | erlaubt Lesen      | erlaubt Schreiben  | Vorlage "Verein"                                                      | Vorlage "Trainer"                |
|--------------------------------------------------------------------------------|--------------------|--------------------|-----------------------------------------------------------------------|----------------------------------|
| Wettkämpfe                                                                     | kein, alle         | kein, alle         | lesen alle                                                            | lesen alle                       |
| Meldungen                                                                      | kein, eigene, alle | kein, eigene, alle | lesen + schreiben eigener Verein                                      | lesen + schreiben eigener Verein |
| Ergebnisse                                                                     | kein, eigene, alle | kein, alle         | lesen eigener Verein                                                  | —                                |
| Athleten                                                                       | kein, eigene, alle | kein, eigene, alle | lesen + schreiben eigener Verein                                      | —                                |
| Vereine                                                                        | kein, eigene, alle | kein, eigene, alle | lesen alle, schreiben eigener Verein                                  | —                                |
| Trainer                                                                        | kein, eigene, alle | kein, eigene, alle | lesen + schreiben eigener Verein                                      | —                                |
| Nationen, Klassifizierer, Rekorde, Richtzeiten, Cup-Wertung, Basiswerte, LENEX | kein, alle         | kein, alle         | lesen alle                                                            | —                                |
| Meisterschaften, WPS-Auswertungen                                              | kein, eigene, alle | kein, alle         | lesen eigener Verein (Auswertungen auf den eigenen Verein beschränkt) | —                                |
| Cup-Stammdaten, Stammdaten                                                     | kein, alle         | kein, alle         | —                                                                     | —                                |
| Statistik                                                                      | kein, alle         | kein               | —                                                                     | —                                |

Die Vorlage "Ohne Rechte" ist leer. Was "schreiben" umfasst, wo es vom Namen abweicht (`Module::writeHint()`):
Wettkämpfe schreiben schließt die Berechnungen ein (Punkte, Cup-Tageswertung, Rekordprüfung); LENEX lesen = Export,
schreiben = Import.

## Regeln je Datensatz

Listen filtern über einen Scope, einzelne Datensätze prüft eine Policy (registriert in `AppServiceProvider`).

| Daten      | Liste (Scope)                                     | Datensatz (Policy)                           | "Eigener Verein" heißt                                                                                            |
|------------|---------------------------------------------------|----------------------------------------------|-------------------------------------------------------------------------------------------------------------------|
| Athleten   | `Athlete::visibleTo()`                            | `AthletePolicy` (view, update)               | Athlet gehört aktuell zum Verein                                                                                  |
| Vereine    | —                                                 | `ClubPolicy` (update, viewContact)           | der eigene Verein; Kontaktdaten nur Admin und eigener Verein                                                      |
| Trainer    | `Coach::visibleTo()`                              | `CoachPolicy` (view, create, update, delete) | Trainer gehört zum Verein; Löschen nur ohne verknüpften Benutzer                                                  |
| Meldungen  | Controller-Filter je Verein                       | `EntryPolicy` (manageEntries)                | Meldung des eigenen Vereins, Wettkampf freigegeben (`is_open`) und kein Meldeschluss bzw. Nachmelde-Fenster offen |
| Ergebnisse | `Result::visibleTo()`, `RelayResult::visibleTo()` | `ResultPolicy` (view)                        | Athlet gehört aktuell zum Verein; Staffel des eigenen Vereins                                                     |
| Anträge    | —                                                 | `ChangeRequestPolicy`                        | vom eigenen Verein gestellt                                                                                       |

- Fremde Detailseiten liefern 403; Links auf nicht sichtbare Datensätze zeigen nur den Text.
- Die Ergebnis-Sammelansicht und ihr PDF zeigen bei "eigener Verein" nur die eigenen Zeilen mit dem Platz im ganzen
  Feld, ohne Gaststarts.
- Die Übersicht der eigenen Meldungen braucht nur Leserecht und ist auch bei geschlossenen Wettkämpfen offen;
  Melden selbst regelt die `EntryPolicy`. Meldungen schreiben "alle" darf auch nach Meldeschluss.

## Feldregeln und Anträge

- **Athleten** mit "schreiben — eigener Verein" (`App\Support\AthleteFieldRules`): Name und Verein nur per **Antrag**;
  SDMS ID, ÖBSV Level, Klassifizierungen, Level-Historie, Kader, Zusammenführen, Dubletten und Löschen nur Admin; der
  Rest ist frei. Gesperrte Felder werden serverseitig aus der Validierung genommen (nicht nur ausgeblendet) und im
  Formular schreibgeschützt mit Hinweis angezeigt (`<x-locked-field-hint>`).
- **Vereine** mit "schreiben — eigener Verein" (`App\Support\ClubFieldRules`): Nation, Typ, Landesverband und SWRID
  bleiben Verband; Name, Kurzname, Code (eindeutig je Nation) und Kontaktdaten sind frei.
- **Anträge** (`change_requests`): Namensänderung und Ummeldung (vom aufnehmenden Verein); entscheidet der Admin unter
  "Anträge", Info per E-Mail an Admins und Antragsteller.

## Änderungsprotokoll

Trait `App\Models\Concerns\Auditable` an Athlet, Verein, Trainer, Aus-/Fortbildung, Meldung, Staffelmeldung und
Antrag schreibt `audit_logs` (wer, was, alt → neu, wann) für **alle** Benutzer, auch Admins. Den Verlauf am Athleten
bzw. Verein sehen nur Admins (`App\Support\AuditHistory`). Änderungen an Benutzern und Rechten werden noch nicht
protokolliert (Open Point).

## Zwei-Faktor-Pflicht

- `User::requiresTwoFactor()`: Admins und jeder mit mindestens einem Schreibrecht; reine Leser nicht.
- Middleware `App\Http\Middleware\EnsureTwoFactorEnabled` (an der `web`-Gruppe und als persistente
  Livewire-Middleware): Ohne bestätigte 2FA führt jede Seite hinter dem Login auf die Sicherheitsseite, JSON-Anfragen
  bekommen 403. Frei bleiben Sicherheitsseite, Passwortbestätigung, die Fortify-2FA-Routen und das Abmelden.
- Bei Pflicht ist Deaktivieren gesperrt (Seite und Fortify-Route). Handy verloren: Der Admin setzt die 2FA in der
  Benutzerverwaltung zurück, im Notfall `php artisan users:reset-two-factor {email}`.
- Schalter `AUTH_TWO_FACTOR_REQUIRED` (`config/auth.php`, Standard an); in der Testsuite aus (`phpunit.xml`), die
  2FA-Tests schalten ihn selbst ein.

## Absicherung neuer Routen und Views

- **Routen:** Jede Route hinter dem Login bekommt `module:<modul>,read|write` (optional `,all` für den Bereich
  "alle", z. B. verbandsweite Ansichten) oder `admin`. Die Middleware prüft nur das Modul; ob der einzelne Datensatz
  zum eigenen Verein gehört, prüft der Controller über Policy bzw. Scope.
- **`tests/Feature/AccessControlTest.php`** prüft über alle Routen: (1) jede Route hinter dem Login hat `module:` oder
  `admin`, außer den Konto-Routen; (2) jede schreibende Route (nicht GET/HEAD) und jedes Anlege-, Bearbeiten- und
  Import-Formular hat `module:…,write` oder `admin`, außer den Export-Downloads (`module:…,read`); (3) jedes in
  `module:` genannte Modul existiert. Eine neue, ungeschützte Route lässt den Test sofort fehlschlagen.
- **Reihenfolge bei aufgeteilten Ressourcen:** Die Schreib-Gruppe (mit `create`) steht **vor** der lesenden
  `show`-Route — sonst bindet Laravel das Wort "create" als `{athlete}`/`{club}` und liefert 404.
- **Views:** Schreib-Schaltflächen über `@can('module.write', Module::X)` bzw. die Policy (`@can('update', $club)`),
  verbandsweite Ansichten über `canReadAll(...)`; nie über `is_admin`. Menüpunkte und -gruppen nur bei Leserecht.
- **Livewire:** Jede schreibende Aktion prüft selbst (`canWrite(...)` oder Policy) — Livewire-Requests laufen nicht
  über die Route-Middleware der Seite (Ausnahme: persistente Middleware wie die 2FA-Pflicht).
- **Neue Konto-Routen** (Sicherheit, 2FA), die ohne eingerichtete 2FA erreichbar sein müssen, in
  `EnsureTwoFactorEnabled::ALLOWED_ROUTES` aufnehmen.
