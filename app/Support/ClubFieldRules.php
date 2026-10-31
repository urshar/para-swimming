<?php

namespace App\Support;

use App\Enums\Module;
use App\Models\User;

/**
 * Feldregeln für Vereine bei "schreiben — eigener Verein" (Spec club-permissions §4.6, Phase 7): Name, Kurzname,
 * Code und Kontaktdaten pflegt der Verein selbst, Nation, Typ, Landesverband und SWRID nur der Verband. Gesperrte Felder
 * werden serverseitig ignoriert und im Formular schreibgeschützt mit Hinweis angezeigt (wie AthleteFieldRules).
 *
 * Wer Vereine "alle" schreiben darf (Admins, Verband), hat keine Sperren.
 */
final readonly class ClubFieldRules
{
    private const array FEDERATION_ONLY = ['nation_id', 'type', 'regional_association', 'swrid'];

    public function __construct(private bool $restricted) {}

    public static function for(User $user): self
    {
        return new self(! $user->canWriteAll(Module::Clubs));
    }

    /** @return array<string, string> Gesperrtes Feld → Hinweis im Formular */
    public function locked(): array
    {
        return $this->restricted ? array_fill_keys(self::FEDERATION_ONLY, AthleteFieldRules::HINT_FEDERATION) : [];
    }
}
