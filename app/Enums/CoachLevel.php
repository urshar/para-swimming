<?php

namespace App\Enums;

/**
 * Ausbildungsstufe eines Trainers (Spec club-permissions N6). Gespeichert wird die Stufe nur an Ausbildungen
 * (coach_qualifications.level); "ohne Ausbildung" ist der Zustand ohne solchen Eintrag und wird nie gespeichert.
 */
enum CoachLevel: string
{
    case None = 'none';
    case Ul = 'UL';
    case In = 'IN';
    case Tr = 'TR';

    public function label(): string
    {
        return match ($this) {
            self::None => 'ohne Ausbildung',
            self::Ul => 'Übungsleiter (ÜL)',
            self::In => 'Instructor (IN)',
            self::Tr => 'Trainer (TR)',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::None => '–',
            self::Ul => 'ÜL',
            self::In => 'IN',
            self::Tr => 'TR',
        };
    }

    /** Rangfolge für "höchste Ausbildung" (P8-2). */
    public function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::Ul => 1,
            self::In => 2,
            self::Tr => 3,
        };
    }

    /** @return list<self> Stufen, die eine Ausbildung tragen kann. */
    public static function trained(): array
    {
        return [self::Ul, self::In, self::Tr];
    }
}
