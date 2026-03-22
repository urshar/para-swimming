<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\AthleteSportClass;
use App\Models\Club;
use App\Models\Nation;
use Illuminate\Support\Facades\DB;
use SimpleXMLElement;

/**
 * LenexResolverService
 *
 * Löst beim LENEX-Import Clubs, Athleten und Events auf:
 *   - Existiert bereits → bestehenden Datensatz verwenden
 *   - Nicht gefunden   → zur manuellen Bestätigung vormerken
 *
 * Matching-Priorität Clubs:
 *   1. code + nation_id
 *   2. lenex_club_id + nation_id
 *   3. name (normalisiert) + nation_id
 *
 * Matching-Priorität Athleten:
 *   1. license
 *   2. license_ipc (SDMS ID)
 *   3. lenex_athlete_id + club_id
 *   4. last_name + first_name + birth_date + gender + nation_id
 */
class LenexResolverService
{
    /** lenex_club_id → App Club ID */
    private array $clubCache = [];

    /** lenex_athlete_id → App Athlete ID */
    private array $athleteCache = [];

    /** lenex_event_id → App SwimEvent ID */
    private array $eventCache = [];

    /** Unbekannte Clubs die manuell aufgelöst werden müssen */
    private array $unresolvedClubs = [];

    /** Unbekannte Athleten die manuell aufgelöst werden müssen */
    private array $unresolvedAthletes = [];

    // ── Clubs ─────────────────────────────────────────────────────────────────

    public function resolveClub(SimpleXMLElement $clubXml, int $nationId): ?Club
    {
        $lenexId = (string) ($clubXml['id'] ?? '');
        $code = (string) ($clubXml['code'] ?? '');
        $name = (string) ($clubXml['name'] ?? '');

        if ($lenexId && isset($this->clubCache[$lenexId])) {
            return Club::find($this->clubCache[$lenexId]);
        }

        $club = null;

        // 1. code + nation_id
        if ($code && $nationId) {
            $club = Club::where('code', $code)
                ->where('nation_id', $nationId)
                ->whereNull('deleted_at')
                ->first();
        }

        // 2. lenex_club_id + nation_id
        if (! $club && $lenexId && $nationId) {
            $club = Club::where('lenex_club_id', $lenexId)
                ->where('nation_id', $nationId)
                ->whereNull('deleted_at')
                ->first();
        }

        // 3. normalisierter Name + nation_id
        if (! $club && $name && $nationId) {
            $club = Club::where(DB::raw('LOWER(TRIM(name))'), '=', mb_strtolower(trim($name)))
                ->where('nation_id', $nationId)
                ->whereNull('deleted_at')
                ->first();
        }

        if ($club) {
            if ($lenexId && ! $club->lenex_club_id) {
                $club->update(['lenex_club_id' => $lenexId]);
            }
            if ($lenexId) {
                $this->clubCache[$lenexId] = $club->id;
            }

            return $club;
        }

        // Nicht gefunden → vormerken
        $this->unresolvedClubs[] = [
            'lenex_id' => $lenexId,
            'code' => $code,
            'name' => $name,
            'nation_id' => $nationId,
            'nation_code' => Nation::find($nationId)?->code ?? '',
        ];

        return null;
    }

    public function createClub(array $data): Club
    {
        $club = Club::create([
            'name' => $data['name'],
            'short_name' => $data['short_name'] ?? null,
            'code' => $data['code'] ?? null,
            'nation_id' => $data['nation_id'],
            'type' => $data['type'] ?? 'CLUB',
            'lenex_club_id' => $data['lenex_club_id'] ?? null,
        ]);

        if ($data['lenex_club_id'] ?? null) {
            $this->clubCache[$data['lenex_club_id']] = $club->id;
        }

        return $club;
    }

    // ── Athleten ──────────────────────────────────────────────────────────────

    public function resolveAthlete(
        SimpleXMLElement $athleteXml,
        int $clubId,
        int $nationId
    ): ?Athlete {
        $lenexId = (string) ($athleteXml['athleteid'] ?? '');
        $license = (string) ($athleteXml['license'] ?? '');
        $licenseIpc = (string) ($athleteXml['license_ipc'] ?? '');
        $lastName = (string) ($athleteXml['lastname'] ?? '');
        $firstName = (string) ($athleteXml['firstname'] ?? '');
        $birthDate = (string) ($athleteXml['birthdate'] ?? '');
        $gender = (string) ($athleteXml['gender'] ?? '');

        if ($lenexId && isset($this->athleteCache[$lenexId])) {
            return Athlete::find($this->athleteCache[$lenexId]);
        }

        $athlete = null;

        // 1. Lizenznummer
        if ($license) {
            $athlete = Athlete::where('license', $license)->whereNull('deleted_at')->first();
        }

        // 2. SDMS ID (IPC Lizenznummer)
        if (! $athlete && $licenseIpc) {
            $athlete = Athlete::where('license_ipc', $licenseIpc)->whereNull('deleted_at')->first();
        }

        // 3. lenex_athlete_id + club_id
        if (! $athlete && $lenexId && $clubId) {
            $athlete = Athlete::where('lenex_athlete_id', $lenexId)
                ->where('club_id', $clubId)
                ->whereNull('deleted_at')
                ->first();
        }

        // 4. Name + Geburtsdatum + Geschlecht + Nation
        if (! $athlete && $lastName && $firstName && $birthDate) {
            $athlete = Athlete::where(DB::raw('LOWER(last_name)'), '=', mb_strtolower($lastName))
                ->where(DB::raw('LOWER(first_name)'), '=', mb_strtolower($firstName))
                ->where('birth_date', $birthDate)
                ->where('gender', $gender)
                ->where('nation_id', $nationId)
                ->whereNull('deleted_at')
                ->first();
        }

        if ($athlete) {
            if ($lenexId && ! $athlete->lenex_athlete_id) {
                $athlete->update(['lenex_athlete_id' => $lenexId]);
            }
            if ($lenexId) {
                $this->athleteCache[$lenexId] = $athlete->id;
            }

            // Sport-Klassen aus HANDICAP aktualisieren, falls vorhanden
            if (isset($athleteXml->HANDICAP)) {
                $this->syncHandicap($athlete, $athleteXml->HANDICAP);
            }

            return $athlete;
        }

        // Nicht gefunden → vormerken
        $this->unresolvedAthletes[] = [
            'lenex_id' => $lenexId,
            'license' => $license,
            'license_ipc' => $licenseIpc,
            'last_name' => $lastName,
            'first_name' => $firstName,
            'birth_date' => $birthDate,
            'gender' => $gender,
            'nation_id' => $nationId,
            'club_id' => $clubId,
            'sport_class' => isset($athleteXml->HANDICAP)
                ? $this->extractPrimarySportClass($athleteXml->HANDICAP)
                : null,
        ];

        return null;
    }

    // ── Cache-Methoden ────────────────────────────────────────────────────────

    public function createAthlete(array $data): Athlete
    {
        $athlete = Athlete::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'birth_date' => $data['birth_date'] ?? null,
            'gender' => $data['gender'] ?? 'M',
            'nation_id' => $data['nation_id'],
            'club_id' => $data['club_id'] ?? null,
            'license' => $data['license'] ?? null,
            'license_ipc' => $data['license_ipc'] ?? null,
            'lenex_athlete_id' => $data['lenex_athlete_id'] ?? null,
        ]);

        if ($data['lenex_athlete_id'] ?? null) {
            $this->athleteCache[$data['lenex_athlete_id']] = $athlete->id;
        }

        return $athlete;
    }

    public function addToEventCache(string $lenexEventId, int $swimEventId): void
    {
        $this->eventCache[$lenexEventId] = $swimEventId;
    }

    public function getEventIdFromCache(string $lenexEventId): ?int
    {
        return $this->eventCache[$lenexEventId] ?? null;
    }

    public function addToClubCache(string $lenexId, int $clubId): void
    {
        $this->clubCache[$lenexId] = $clubId;
    }

    // ── Unaufgelöste Einträge ─────────────────────────────────────────────────

    public function addToAthleteCache(string $lenexId, int $athleteId): void
    {
        $this->athleteCache[$lenexId] = $athleteId;
    }

    public function getClubIdFromCache(string $lenexId): ?int
    {
        return $this->clubCache[$lenexId] ?? null;
    }

    public function getAthleteIdFromCache(string $lenexId): ?int
    {
        return $this->athleteCache[$lenexId] ?? null;
    }

    public function getUnresolvedClubs(): array
    {
        return $this->unresolvedClubs;
    }

    // ── HANDICAP / Sport-Klassen ──────────────────────────────────────────────

    public function getUnresolvedAthletes(): array
    {
        return $this->unresolvedAthletes;
    }

    public function hasUnresolved(): bool
    {
        return ! empty($this->unresolvedClubs) || ! empty($this->unresolvedAthletes);
    }

    public function unresolvedCount(): int
    {
        return count($this->unresolvedClubs) + count($this->unresolvedAthletes);
    }

    private function syncHandicap(Athlete $athlete, SimpleXMLElement $handicapXml): void
    {
        $mapping = [
            'S' => ['lenex_attr' => 'free', 'status_attr' => 'freestatus'],
            'SB' => ['lenex_attr' => 'breast', 'status_attr' => 'breaststatus'],
            'SM' => ['lenex_attr' => 'medley', 'status_attr' => 'medleystatus'],
        ];

        foreach ($mapping as $category => $attrs) {
            $classNumber = (string) ($handicapXml[$attrs['lenex_attr']] ?? '');
            $classNumber = trim($classNumber);
            if ($classNumber === '' || $classNumber === '0') {
                continue;
            }

            $status = $this->mapHandicapStatus((string) ($handicapXml[$attrs['status_attr']] ?? ''));

            AthleteSportClass::updateOrCreate(
                ['athlete_id' => $athlete->id, 'category' => $category],
                [
                    'class_number' => $classNumber,
                    'sport_class' => $category.$classNumber,
                    'status' => $status,
                ]
            );
        }
    }

    // ── Event Cache ───────────────────────────────────────────────────────────

    private function mapHandicapStatus(string $status): ?string
    {
        $valid = ['NATIONAL', 'NEW', 'REVIEW', 'OBSERVATION', 'CONFIRMED'];
        $upper = strtoupper(trim($status));

        return in_array($upper, $valid) ? $upper : null;
    }

    private function extractPrimarySportClass(SimpleXMLElement $handicapXml): ?string
    {
        $free = (string) ($handicapXml['free'] ?? '');

        return $free ? 'S'.$free : null;
    }
}
