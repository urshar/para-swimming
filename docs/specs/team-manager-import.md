# Spec: Übernahme aus dem Splash Team Manager

Einmaliger Umzug der Vereins- und Athletendaten aus der `Team.mdb` des Splash Team Managers (Erik), samt der dafür
nötigen Erweiterungen am Athleten-Datenmodell. Ursprünglich als offener Punkt "Athleten-Import aus MSAccess-Datei +
Athleten-Datenmodell erweitern" geführt (seit 20.09.2026), umgesetzt in drei Phasen (Oktober 2026).

## Phase 1 — Datenmodell (`feature/team-manager-import`, PR #55)

Neu an `athletes`: Behinderungsgruppe (`disability_group`: PI/VI/MI/HI/T21) mit PI-Untergruppe
(`disability_subgroup`: A/C/R, nur für die Statistik), medizinische Kontrolle (`last_medical_check_at`,
`next_medical_check_at`). `athlete_classifications.classified_at` darf leer sein, weil übernommene Klassifizierungen
nicht immer ein Datum haben. Siehe `data-model.md` "Stammdaten".

## Phase 2 — Import (`feature/team-manager-import`, PR #55; Korrekturen PR #57, #59)

Admin-Seite "Team Manager übernehmen" (Athletenliste) mit Vorschau. Liest die `.mdb` direkt über PDO_ODBC
(`AccessTeamManagerSource`, nur Windows mit Access-Treiber → läuft auf dev). Klassifizierer-Namen ohne Treffer müssen
in der Vorschau zugeordnet oder übergangen werden. Vereine werden zuerst über den exakten Code zugeordnet (PR #57); ein
leerer Klassifizierungsstatus gilt als bestätigt, S15 ist national (PR #59). Feld-Mapping und Entscheidungen in
`app/Services/TeamManagerImportService.php`.

Nicht übernommen: englische Namen (Verein und Athlet), Geburtsort, Fax, Funktionen, Mitgliedsbeitrag, WWW, E-Mail
alternativ, Lizenz "gültig bis", Bundesland der Adresse.

Auf dev gelaufen (Team-Manager-Athleten mit Anlagedatum 15.10.2026). Dubletten aus der Datei (z. B. Lizenz O-2030
dreimal) lassen sich seit `feature/athlete-merge` zusammenführen (`specs/athlete-merge.md`).

## Phase 3 — Umzug dev → prod (`feature/team-data-seeder`, PR #60)

Auf dev `php artisan team-data:export` (Vereine, Athleten samt Sportklassen/Level-/Vereinshistorie/Ausnahmen,
Klassifizierer, Klassifizierungshistorie, Rekorde samt Splits und Staffelmitgliedern) schreibt JSON nach
`database/seeders/data/team/` (von Git ausgeschlossen — Personen- und Gesundheitsdaten, von Hand sicher übertragen
und danach löschen). Auf prod nach den übrigen Seedern `php artisan db:seed --class=TeamDataSeeder`: nur in eine DB
ohne Vereine/Athleten, IDs bleiben erhalten, Nationen, Ausnahme-Codes und Lagen werden über den Code umgeschlüsselt,
Klassifizierer über die ID abgeglichen (`App\Services\TeamDataTransferService`). Prüflisten-Einträge kommen nicht mit
(Erik).

Der Export verweigert, solange Rekorde an Wettkampfergebnissen oder Klassen-Hinweise an Wettkämpfen hängen — er wurde
für einen Stand **ohne Wettkämpfe** gebaut (damals hieß es: Testwettkämpfe vorher löschen).

> **Überholt durch den historischen Import:** Seit dem Import der Ergebnisdateien 2012–2017 liegen auf dev
> Wettkämpfe und Ergebnisse, die mit nach prod sollen. Dafür reicht dieser Export nicht — siehe offenen Punkt
> "Umzug dev → prod mit Wettkämpfen und Ergebnissen" in `open-points.md`.
