# Deployment: netcup Webhosting (Plesk)

Ziel: `https://paraswim.at` auf einem netcup-Webhosting-Paket mit Plesk und SSH. Der Server hat PHP und MySQL, aber
**kein Node.js und keinen dauerhaften Prozess-Manager** — daraus ergeben sich drei Besonderheiten:

- Die Frontend-Assets (`public/build`) werden **lokal gebaut** und hochgeladen (`scripts/upload-build.ps1`).
- Die Queue (WPS-Punkte, Benachrichtigungen) läuft über den **Scheduler**: ein einziger Plesk-Cronjob ruft jede
  Minute `schedule:run` auf, der startet `queue:work --stop-when-empty` (`routes/console.php`).
- SSH läuft in einer **chroot-Umgebung**: `/` ist das Webspace-Verzeichnis (zugleich `$HOME` und Startordner), darin
  liegt je Domain ein eigener Ordner. PHP wird mit vollem Pfad aufgerufen (`/usr/local/php84/bin/php`), Composer
  bringen wir als eigene `composer.phar` mit.

Aufbau auf dem Server (andere Domains des Webspace bleiben unberührt):

```
/                        Webspace = $HOME = SSH-Startordner
├── tools/composer.phar
├── .ssh/                Deploy-Key für GitHub
└── paraswim.at/
    ├── app/             Git-Checkout des Projekts
    │   ├── .env         nur auf dem Server
    │   ├── auth.json    Flux-Pro-Zugang, nur auf dem Server
    │   └── public/      ← Dokumentstamm
    └── httpdocs/        bisheriger Dokumentstamm, ungenutzt
```

Der Dokumentstamm zeigt auf `paraswim.at/app/public`, damit `.env`, `storage`, `vendor` und der Quellcode **nicht**
über das Web erreichbar sind. Lässt er sich nicht umstellen, siehe die Symlink-Variante in Schritt 5.

## 1. Plesk vorbereiten (einmalig)

Alles unter **Websites & Domains → paraswim.at**:

1. **PHP:** "PHP" → Version **8.4** (FPM). Für PHP ab 7.3 verlangt netcup unter **Hosting & DNS → Apache & nginx →
   nginx-Einstellungen** den **Proxy-Modus** — aktiviert lassen bzw. einschalten. Limits so hoch setzen, wie der
   Tarif erlaubt (bei netcup an den Tarif gebunden): `memory_limit` möglichst 512M, `upload_max_filesize` und
   `post_max_size` 64M, `max_execution_time` das Maximum (180 s reichen). Die LENEX-Importe und PDF-Berichte
   brauchen mehr als die Standardwerte. Erweiterungen prüfen: `pdo_mysql`, `mbstring`, `xml`/`dom`, `zip`, `gd`,
   `intl`, `fileinfo`, `bcmath` (per `php -m`, siehe Schritt 2).
2. **Datenbank:** "Datenbanken" → neue MySQL-Datenbank mit eigenem Benutzer anlegen. Name, Benutzer, Passwort und
   **Datenbankserver** notieren (für die `.env`). Bei netcup ist das ein eigener Server mit interner IP, nicht
   `localhost`. Der Benutzer muss **dieser** Datenbank zugeordnet sein, und seine Zugriffskontrolle muss Verbindungen
   vom Webserver erlauben (sonst "Access denied for user ...@<IP>", obwohl das Passwort stimmt).
3. **SSH:** "Hosting & DNS → Hosting-Einstellungen" bzw. "Webhosting-Zugang" → SSH-Zugriff auf `/bin/bash (chrooted)`.
   Eigenen öffentlichen SSH-Schlüssel hinterlegen (oder mit Passwort anmelden).
4. **E-Mail:** Postfach `noreply@paraswim.at` anlegen (für Passwort-Reset und Benachrichtigungen). Mailserver und
   Port stehen in Plesk bei den Einstellungen des Postfachs.
5. **SSL:** "SSL/TLS-Zertifikate" → Let's Encrypt für `paraswim.at` und `www.paraswim.at`. Danach in den
   Hosting-Einstellungen "Permanente SEO-sichere 301-Weiterleitung von HTTP zu HTTPS" aktivieren.

Den **Dokumentstamm** erst in Schritt 5 umstellen, wenn der Code da ist.

## 2. Server per SSH einrichten (einmalig)

```bash
ssh <ssh-benutzer>@<ssh-host>
```

PHP-Version und Werkzeuge prüfen:

netcup legt die PHP-Versionen unter `/usr/local/phpXY/bin/php` ab (ein bloßes `php` nimmt die Version aus
`/conf/phpversion`, das muss nicht 8.4 sein — deshalb immer den vollen Pfad):

```bash
pwd
/usr/local/php84/bin/php -v
/usr/local/php84/bin/php -m
/usr/local/php84/bin/php -i | grep -E "memory_limit|max_execution_time"
git --version
```

`pwd` sollte `/` zeigen (siehe Aufbau oben). Laut netcup hat PHP in der Shell teils ein niedrigeres
`memory_limit` als im Browser — die Befehle unten heben es deshalb bei Bedarf mit `-d memory_limit=...` an.
Zur Abkürzung für diese Sitzung:

```bash
PHP=/usr/local/php84/bin/php
```

Composer installieren. Im chroot ist `$HOME` der Startordner `/` — `~/bin` wäre damit das schreibgeschützte
System-`/bin`, deshalb ein eigener Ordner `~/tools`. (Eine ältere `/composer.phar` aus anderen Projekten kann
liegen bleiben, wird hier aber nicht verwendet.)

```bash
mkdir -p ~/tools
$PHP -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
$PHP composer-setup.php --install-dir=$HOME/tools --filename=composer.phar
rm composer-setup.php
```

## 3. Code holen (einmalig)

Das Repository ist privat. Der Server bekommt einen eigenen **Deploy-Key** (nur Lesezugriff):

`~/.ssh` gibt es auf dem Webspace eventuell schon. Vorher prüfen, ob dort bereits ein `Host github.com`-Eintrag steht
(`cat ~/.ssh/config`) — dann den Eintrag unten nicht anhängen, sondern den vorhandenen anpassen, sonst gewinnt der
erste.

```bash
mkdir -p ~/.ssh && chmod 700 ~/.ssh
ssh-keygen -t ed25519 -f ~/.ssh/github_deploy -N ""
cat ~/.ssh/github_deploy.pub
printf "Host github.com\n  IdentityFile ~/.ssh/github_deploy\n  IdentitiesOnly yes\n" >> ~/.ssh/config
chmod 600 ~/.ssh/config
```

Den ausgegebenen öffentlichen Schlüssel auf GitHub unter **urshar/para-swimming → Settings → Deploy keys → Add
deploy key** eintragen (ohne "Allow write access"). Steht `ssh-keygen` im chroot nicht zur Verfügung, den Schlüssel
lokal erzeugen und beide Dateien per `scp` nach `~/.ssh/` kopieren.

Nach `paraswim.at/app` klonen:

```bash
cd /paraswim.at
git clone git@github.com:urshar/para-swimming.git app
```

Wurde das Projekt schon nach `httpdocs` geklont, stattdessen umbenennen: `mv httpdocs app`.

Alternative ohne Deploy-Key-Handarbeit: die Plesk-Erweiterung **Git** (Websites & Domains → Git) mit Ziel
`paraswim.at/app`; Plesk zeigt dort den einzutragenden Schlüssel selbst an. Die Schritte ab hier bleiben gleich.

## 4. Abhängigkeiten und Konfiguration (einmalig)

Vom lokalen Rechner (PowerShell, im Projektordner) den Flux-Pro-Zugang hochladen:

```powershell
scp auth.json <ssh-benutzer>@<ssh-host>:paraswim.at/app/auth.json
```

Auf dem Server:

```bash
cd /paraswim.at/app
$PHP -d memory_limit=1G ~/tools/composer.phar install --no-dev --optimize-autoloader --no-interaction
cp .env.production.example .env
nano .env        # bzw. vi — Datenbank, Mail, APP_URL eintragen
chmod 600 .env auth.json
```

`APP_KEY` siehe Abschnitt "APP_KEY und Zwei-Faktor" unten — entweder neu erzeugen oder den Dev-Schlüssel übernehmen.
Neu erzeugen:

```bash
$PHP artisan key:generate
```

## 5. Dokumentstamm umstellen und Assets hochladen (einmalig)

Den Dokumentstamm der Domain `paraswim.at` von `paraswim.at/httpdocs` auf **`paraswim.at/app/public`** setzen
(Plesk: Hosting-Einstellungen der Domain).

Falls sich der Dokumentstamm nicht ändern lässt: `httpdocs` durch einen **relativen** Symlink ersetzen. Relativ,
weil der Webserver außerhalb des chroot andere absolute Pfade sieht (`/var/www/vhosts/...`) als die SSH-Sitzung:

```bash
cd /paraswim.at
[ -d httpdocs ] && [ ! -L httpdocs ] && mv httpdocs httpdocs.plesk-default
ln -s app/public httpdocs
ls -la /paraswim.at        # httpdocs -> app/public
```

Lokal (PowerShell, im Projektordner) die Assets bauen und hochladen. `-Target` ist der SSH-Zugang aus dem netcup CCP
(Webhosting → SSH): Benutzer **und** Server, z. B. `hosting123456@hosting123456.abc12.netcup.net`. Das Skript prüft
zuerst die Verbindung und baut erst dann:

```powershell
.\scripts\upload-build.ps1 -Target "<ssh-benutzer>@<ssh-server>"
```

## 6. Datenbank anlegen und Daten übernehmen (einmalig)

Auf dem Server das Schema anlegen:

```bash
cd /paraswim.at/app
$PHP artisan migrate --force
```

Lokal einen **frischen** Export ziehen (nach der letzten Datenpflege auf dev) und hochladen:

```powershell
php artisan db:export-seed
ssh <ssh-benutzer>@<ssh-host> "mkdir -p paraswim.at/app/database/seeders/data"
scp -r database/seeders/data/snapshot <ssh-benutzer>@<ssh-host>:paraswim.at/app/database/seeders/data/
```

Auf dem Server einspielen und den Export sofort wieder löschen (Personen- und Gesundheitsdaten, Passwort-Hashes):

```bash
$PHP -d memory_limit=512M artisan db:seed --class=ProductionDataSeeder --force
rm -rf database/seeders/data/snapshot
```

Den `DatabaseSeeder` auf prod **nicht** zusätzlich ausführen — der Export enthält die Stammdaten bereits. Der
Seeder bricht ab, wenn eine Tabelle nicht leer ist oder das Schema nicht zum Export passt.

Den lokalen Export danach ebenfalls löschen (`database/seeders/data/snapshot`).

## 7. Abschluss (einmalig)

Den Storage-Link von Hand als **relativen** Symlink anlegen. `artisan storage:link` würde einen absoluten Pfad aus
Sicht des chroot schreiben, den der Webserver nicht findet, und `--relative` braucht das Paket `symfony/filesystem`,
das ohne Dev-Abhängigkeiten fehlt:

```bash
cd /paraswim.at/app/public
ln -s ../storage/app/public storage
ls -la storage        # storage -> ../storage/app/public
cd /paraswim.at/app
$PHP artisan optimize
```

**Cronjob:** im netcup CCP unter Produkte → Webhosting → "Plesk Panel Links" → **Geplante Aufgaben** → "Aufgabe
hinzufügen":

- Typ: "Befehl ausführen"
- Befehl: `/usr/local/php84/bin/php /paraswim.at/app/artisan schedule:run`
- Ausführen: jede Minute (`* * * * *`)
- Benachrichtigung: "Nur bei Fehlern"

Danach in der Aufgabenliste "Jetzt ausführen" klicken: Erscheint keine Fehlermeldung (bzw. "No scheduled commands
are ready to run" oder die `queue:work`-Zeile), stimmt der Pfad. Bei "Could not open input file" läuft der Cronjob
nicht im chroot — dann den vollen Serverpfad verwenden, `/var/www/vhosts/<webspace>/paraswim.at/app/artisan`
(`<webspace>` zeigt Plesk unter "Hosting-Einstellungen" im Pfad des Dokumentstamms).

## 8. Prüfen nach dem Go-live

- `https://paraswim.at/up` → "Application up"
- Öffentliche Seiten (Rekorde, Cup, Bestenlisten) zeigen die Daten von dev.
- Anmelden, Zwei-Faktor einrichten bzw. bestätigen.
- "Passwort vergessen" → kommt die Mail an? (prüft die Mail-Konfiguration)
- Eine WPS-Punkteberechnung starten → nach spätestens einer Minute erledigt (prüft Cronjob und Queue).
- Einen PDF-Bericht erzeugen (prüft dompdf und `memory_limit`).
- `storage/logs/laravel-*.log` auf Fehler prüfen.

## APP_KEY und Zwei-Faktor

Die 2FA-Geheimnisse der Benutzer sind mit dem `APP_KEY` verschlüsselt. Zwei Wege:

- **Neuer Schlüssel (empfohlen):** `key:generate` wie oben. Die übernommenen 2FA-Einstellungen sind damit nicht
  mehr lesbar und müssen zurückgesetzt werden — die betroffenen Benutzer richten 2FA beim nächsten Login neu ein
  (`AUTH_TWO_FACTOR_REQUIRED=true` erzwingt das für Schreibrechte):

  ```bash
  $PHP artisan tinker --execute="App\Models\User::whereNotNull('two_factor_secret')->update(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null]);"
  ```

- **Dev-Schlüssel übernehmen:** den `APP_KEY` aus der lokalen `.env` in die Server-`.env` kopieren. 2FA bleibt
  gültig, aber prod und dev teilen sich dann einen Schlüssel.

## Updates einspielen

1. Änderungen auf `main` mergen und pushen.
2. Falls sich CSS/JS geändert haben, lokal: `.\scripts\upload-build.ps1 -Target "<ssh-benutzer>@<ssh-host>"`
3. Auf dem Server:

   ```bash
   cd /paraswim.at/app && bash scripts/deploy.sh
   ```

`scripts/deploy.sh` schaltet die Seite in den Wartungsmodus, holt den Code (`git pull --ff-only`), installiert die
Abhängigkeiten, führt neue Migrationen aus, baut die Caches neu und schaltet die Seite wieder frei — auch wenn ein
Schritt fehlschlägt.

**Wichtig:** Migrationen auf prod nie bearbeiten oder zusammenfassen, sobald sie dort gelaufen sind — Änderungen am
Schema immer als neue Migration.

## Was nicht übertragen wird

- **Dateien aus `storage/app`:** Die alten Import-/Export-Archive bleiben lokal. Hochgeladene Dokumente gibt es zum
  Go-live keine (Tabelle `documents` leer).
- **Laufzeitdaten:** Sessions, Cache, Jobs und Passwort-Reset-Tokens entstehen auf prod neu.
