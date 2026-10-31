<?php

namespace App\Support;

use App\Enums\Module;
use App\Models\User;

/**
 * Feldregeln für Athleten bei "schreiben — eigener Verein" (Spec club-permissions §4.4, Phase 5): Name und Verein
 * ändert ein Verein nur per Antrag, SDMS ID und ÖBSV Level nur der Verband. Gesperrte Felder werden serverseitig
 * ignoriert (nicht validiert, nicht gespeichert) und im Formular schreibgeschützt mit Hinweis angezeigt. Beim
 * Anlegen sind die Namensfelder frei, der Verein ist fest der eigene.
 *
 * Wer Athleten "alle" schreiben darf (Admins, Verband), hat keine Sperren.
 */
final readonly class AthleteFieldRules
{
    public const string HINT_REQUEST = 'Änderung per Antrag';

    public const string HINT_FEDERATION = 'nur Verband';

    public const string HINT_OWN_CLUB = 'eigener Verein';

    private const array BY_REQUEST = ['first_name', 'last_name', 'name_prefix'];

    private const array FEDERATION_ONLY = ['license_ipc', 'level'];

    public function __construct(private bool $restricted) {}

    public static function for(User $user): self
    {
        return new self(! $user->canWriteAll(Module::Athletes));
    }

    public function restricted(): bool
    {
        return $this->restricted;
    }

    /** @return array<string, string> Gesperrtes Feld → Hinweis im Formular */
    public function locked(bool $creating): array
    {
        if (! $this->restricted) {
            return [];
        }

        $locked = array_fill_keys(self::FEDERATION_ONLY, self::HINT_FEDERATION);
        $locked['club_id'] = $creating ? self::HINT_OWN_CLUB : self::HINT_REQUEST;

        return $creating ? $locked : array_fill_keys(self::BY_REQUEST, self::HINT_REQUEST) + $locked;
    }
}
