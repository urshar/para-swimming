<?php

namespace App\Enums;

/**
 * Bereich eines Lese- oder Schreibrechts (Spec club-permissions §4.1): kein Zugriff, nur Datensätze des eigenen
 * Vereins (users.club_id) oder alle.
 */
enum AccessScope: string
{
    case None = 'none';
    case Own = 'own';
    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::None => 'kein Zugriff',
            self::Own => 'eigener Verein',
            self::All => 'alle',
        };
    }

    /** Umfasst dieser Bereich mindestens den anderen? (All ⊇ Own ⊇ None) */
    public function covers(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::Own => 1,
            self::All => 2,
        };
    }
}
