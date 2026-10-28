<?php

namespace App\Enums;

/**
 * Module des Verwaltungsbereichs, für die einem Benutzer Lese- und Schreibrechte zugewiesen werden (Spec
 * club-permissions §4.3). Benutzer, Dokumente und das Entscheiden von Anträgen sind kein Modul, sondern bleiben
 * Admin-Sache.
 *
 * Der Wert ist zugleich der Parameter der Route-Middleware (z. B. "module:athletes,write").
 */
enum Module: string
{
    case Meets = 'meets';
    case Entries = 'entries';
    case Results = 'results';
    case Athletes = 'athletes';
    case Clubs = 'clubs';
    case Coaches = 'coaches';
    case Nations = 'nations';
    case Classifiers = 'classifiers';
    case Records = 'records';
    case QualifyingTimes = 'qualifying_times';
    case Championships = 'championships';
    case Cup = 'cup';
    case CupSettings = 'cup_settings';
    case Wps = 'wps';
    case BaseTimes = 'base_times';
    case Statistics = 'statistics';
    case Lenex = 'lenex';

    public function label(): string
    {
        return match ($this) {
            self::Meets => 'Wettkämpfe',
            self::Entries => 'Meldungen',
            self::Results => 'Ergebnisse',
            self::Athletes => 'Athleten',
            self::Clubs => 'Vereine',
            self::Coaches => 'Trainer',
            self::Nations => 'Nationen',
            self::Classifiers => 'Klassifizierer',
            self::Records => 'Rekorde',
            self::QualifyingTimes => 'Richtzeiten',
            self::Championships => 'Meisterschaften',
            self::Cup => 'Cup-Wertung',
            self::CupSettings => 'Cup-Stammdaten',
            self::Wps => 'WPS-Auswertungen',
            self::BaseTimes => 'Basiswerte',
            self::Statistics => 'Statistik',
            self::Lenex => 'LENEX',
        };
    }

    /** Was "schreiben" in diesem Modul umfasst, wo es vom Namen abweicht (Hinweis für die Benutzerverwaltung). */
    public function writeHint(): ?string
    {
        return match ($this) {
            self::Meets => 'inkl. Berechnungen (Punkte, Cup-Tageswertung, Rekordprüfung)',
            self::Lenex => 'lesen = Export, schreiben = Import',
            default => null,
        };
    }

    /**
     * Erlaubte Lesebereiche. "Eigener Verein" nur, wo die Daten einen Vereinsbezug haben.
     *
     * @return list<AccessScope>
     */
    public function allowedReadScopes(): array
    {
        return match ($this) {
            self::Entries, self::Results, self::Athletes, self::Clubs, self::Coaches, self::Championships,
            self::Wps => [AccessScope::None, AccessScope::Own, AccessScope::All],
            default => [AccessScope::None, AccessScope::All],
        };
    }

    /**
     * Erlaubte Schreibbereiche. Statistik ist rein lesend.
     *
     * @return list<AccessScope>
     */
    public function allowedWriteScopes(): array
    {
        return match ($this) {
            self::Entries, self::Athletes, self::Clubs, self::Coaches => [
                AccessScope::None, AccessScope::Own, AccessScope::All,
            ],
            self::Statistics => [AccessScope::None],
            default => [AccessScope::None, AccessScope::All],
        };
    }

    /** Erlaubte Kombination? Beide Bereiche zulässig und Schreiben nicht weiter als Lesen. */
    public function allows(AccessScope $read, AccessScope $write): bool
    {
        return in_array($read, $this->allowedReadScopes(), true)
            && in_array($write, $this->allowedWriteScopes(), true)
            && $read->covers($write);
    }
}
