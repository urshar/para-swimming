<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Setzt die Zwei-Faktor-Anmeldung eines Benutzers zurück (Spec club-permissions P10-4) — für den Notfall, wenn kein
 * anderer Admin das in der Benutzerverwaltung tun kann (z. B. letzter Admin ohne Handy und ohne Wiederherstellungscode).
 * Bei Pflicht richtet der Benutzer die 2FA beim nächsten Seitenaufruf neu ein.
 *
 *   php artisan users:reset-two-factor admin@example.org
 */
class ResetTwoFactorCommand extends Command
{
    protected $signature = 'users:reset-two-factor {email : E-Mail-Adresse des Benutzers}';

    protected $description = 'Setzt die Zwei-Faktor-Anmeldung eines Benutzers zurück';

    public function handle(): int
    {
        $user = User::where('email', (string) $this->argument('email'))->first();

        if ($user === null) {
            $this->error('Kein Benutzer mit dieser E-Mail-Adresse.');

            return self::FAILURE;
        }

        $user->resetTwoFactor();
        $this->info('Zwei-Faktor-Anmeldung von '.$user->name.' zurückgesetzt.');

        return self::SUCCESS;
    }
}
