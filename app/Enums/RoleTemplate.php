<?php

namespace App\Enums;

/**
 * Rechte-Vorlagen (Spec club-permissions §4.1): befüllen die Rechte-Matrix eines Benutzers als Ausgangspunkt. Die
 * Vorlage wird am Benutzer gemerkt, damit eine geänderte Vorlage per "Vorlage neu anwenden" übertragen werden kann.
 * Nicht aufgeführte Module = kein Zugriff.
 */
enum RoleTemplate: string
{
    case Club = 'club';
    case Coach = 'coach';
    case Empty = 'empty';

    public function label(): string
    {
        return match ($this) {
            self::Club => 'Verein',
            self::Coach => 'Trainer (nur melden)',
            self::Empty => 'Ohne Rechte',
        };
    }

    /**
     * Lese- und Schreibbereich je Modul, Schlüssel = Module-Wert.
     *
     * @return array<string, array{read: AccessScope, write: AccessScope}>
     */
    public function permissions(): array
    {
        $own = AccessScope::Own;
        $all = AccessScope::All;
        $none = AccessScope::None;

        return match ($this) {
            self::Club => [
                Module::Meets->value => ['read' => $all, 'write' => $none],
                Module::Entries->value => ['read' => $own, 'write' => $own],
                Module::Results->value => ['read' => $own, 'write' => $none],
                Module::Athletes->value => ['read' => $own, 'write' => $own],
                Module::Clubs->value => ['read' => $all, 'write' => $own],
                Module::Coaches->value => ['read' => $own, 'write' => $own],
                Module::Nations->value => ['read' => $all, 'write' => $none],
                Module::Classifiers->value => ['read' => $all, 'write' => $none],
                Module::Records->value => ['read' => $all, 'write' => $none],
                Module::QualifyingTimes->value => ['read' => $all, 'write' => $none],
                Module::Championships->value => ['read' => $own, 'write' => $none],
                Module::Cup->value => ['read' => $all, 'write' => $none],
                Module::Wps->value => ['read' => $own, 'write' => $none],
                Module::BaseTimes->value => ['read' => $all, 'write' => $none],
                Module::Lenex->value => ['read' => $all, 'write' => $none],
            ],
            self::Coach => [
                Module::Meets->value => ['read' => $all, 'write' => $none],
                Module::Entries->value => ['read' => $own, 'write' => $own],
            ],
            self::Empty => [],
        };
    }
}
