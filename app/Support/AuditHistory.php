<?php

namespace App\Support;

use App\Models\Athlete;
use App\Models\AuditLog;
use App\Models\ChangeRequest;
use App\Models\Club;
use App\Models\Entry;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\RelayEntry;
use App\Models\SwimEvent;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Bereitet Einträge des Änderungsprotokolls für den Verlauf auf den Detailseiten von Athlet und Verein auf (Spec
 * club-permissions §4.7): deutsche Feldnamen, Fremdschlüssel als Name, Datum, Zeit und Ja/Nein lesbar.
 */
final class AuditHistory
{
    /** So viele Einträge zeigt der Verlauf, bis "Mehr anzeigen" gewählt wird. */
    public const int LIMIT = 50;

    private const array LABELS = [
        'club_id' => 'Verein',
        'nation_id' => 'Nation',
        'first_name' => 'Vorname',
        'last_name' => 'Nachname',
        'name_prefix' => 'Namenspräfix',
        'birth_date' => 'Geburtsdatum',
        'gender' => 'Geschlecht',
        'license' => 'Nationale Lizenz',
        'license_ipc' => 'SDMS ID',
        'status' => 'Status',
        'disability_group' => 'Behinderungsgruppe',
        'disability_subgroup' => 'Behinderungsuntergruppe',
        'swrid' => 'SWRID',
        'is_active' => 'Aktiv',
        'notes' => 'Notizen',
        'email' => 'E-Mail',
        'phone' => 'Telefon',
        'address_street' => 'Straße',
        'address_city' => 'Ort',
        'address_zip' => 'PLZ',
        'address_country' => 'Land',
        'level' => 'ÖBSV Level',
        'last_medical_check_at' => 'Letzte med. Kontrolle',
        'next_medical_check_at' => 'Nächste med. Kontrolle',
        'name' => 'Name',
        'short_name' => 'Kurzname',
        'code' => 'Code',
        'type' => 'Typ',
        'regional_association' => 'Landesverband',
        'meet_id' => 'Wettkampf',
        'swim_event_id' => 'Bewerb',
        'athlete_id' => 'Athlet',
        'entry_time' => 'Meldezeit',
        'entry_time_code' => 'Zeitcode',
        'entry_course' => 'Bahnlänge',
        'is_late_entry' => 'Nachmeldung',
        'sport_class' => 'Sportklasse',
        'heat' => 'Lauf',
        'lane' => 'Bahn',
        'relay_number' => 'Staffelnummer',
        'relay_class' => 'Staffelklasse',
        'is_exhibition' => 'Außer Konkurrenz',
        'members' => 'Mitglieder',
        'merged_athlete' => 'Zusammengeführt mit',
        'joined_at' => 'Vereinseintritt',
        'from_club_id' => 'Bisheriger Verein',
        'values' => 'Gewünschte Werte',
        'reason' => 'Begründung',
        'response' => 'Antwort',
    ];

    /** Schlüssel in ChangeRequest::$values → Bezeichnung. */
    private const array VALUE_LABELS = [
        'first_name' => 'Vorname',
        'last_name' => 'Nachname',
        'name_prefix' => 'Namenspräfix',
        'joined_at' => 'Eintritt',
    ];

    private const array BOOLEANS = ['is_active', 'is_late_entry', 'is_exhibition'];

    private const array DATES = ['birth_date', 'last_medical_check_at', 'next_medical_check_at', 'joined_at'];

    private const array REFERENCES = ['club_id', 'from_club_id', 'nation_id', 'meet_id', 'swim_event_id', 'athlete_id'];

    private const array EVENTS = [
        'created' => 'angelegt',
        'updated' => 'geändert',
        'deleted' => 'gelöscht',
        'restored' => 'wiederhergestellt',
        'merged' => 'zusammengeführt',
        'transferred' => 'Vereinswechsel',
    ];

    private const array SUBJECTS = [
        Athlete::class => 'Athlet',
        Club::class => 'Verein',
        Entry::class => 'Meldung',
        RelayEntry::class => 'Staffelmeldung',
        ChangeRequest::class => 'Antrag',
    ];

    /**
     * @param  Builder<AuditLog>  $query  z. B. AuditLog::forAthlete($athlete)
     * @return array{entries: list<array{at: string, user: string, source: string|null, event: string, subject: string, changes: list<array{label: string, old: string, new: string}>}>, has_more: bool}
     */
    public static function build(Builder $query, bool $all): array
    {
        $logs = $query->orderByDesc('created_at')->orderByDesc('id')
            ->when(! $all, fn (Builder $q) => $q->limit(self::LIMIT + 1))
            ->get();
        $hasMore = ! $all && $logs->count() > self::LIMIT;
        $logs = $all ? $logs : $logs->take(self::LIMIT);

        $contexts = self::entryContexts($logs);
        $names = self::referenceNames($logs, $contexts);

        $entries = $logs->map(fn (AuditLog $log): array => [
            'at' => $log->created_at?->format('d.m.Y H:i') ?? '—',
            'user' => $log->user_name ?? 'System',
            'source' => $log->source === AuditLog::SOURCE_IMPORT ? ($log->source_detail ?? 'Import') : null,
            'event' => self::EVENTS[$log->event] ?? $log->event,
            'subject' => self::subject($log, $contexts, $names),
            'changes' => collect($log->changes)
                ->map(fn (array $change, string $field): array => [
                    'label' => self::LABELS[$field] ?? $field,
                    'old' => self::value($field, $change[0] ?? null, $names),
                    'new' => self::value($field, $change[1] ?? null, $names),
                ])
                ->values()
                ->all(),
        ]);

        return ['entries' => $entries->values()->all(), 'has_more' => $hasMore];
    }

    /**
     * Wettkampf und Bewerb je Meldung bzw. Staffelmeldung: aus dem Datensatz, bei gelöschten aus den Änderungen.
     *
     * @param  Collection<int, AuditLog>  $logs
     * @return array<string, array{meet_id: int|null, swim_event_id: int|null}> Schlüssel "Klasse#ID"
     */
    private static function entryContexts(Collection $logs): array
    {
        $contexts = [];

        foreach ([Entry::class => Entry::query(), RelayEntry::class => RelayEntry::query()] as $class => $query) {
            $ids = $logs->where('auditable_type', $class)->pluck('auditable_id')->unique();
            $rows = $ids->isEmpty() ? collect() : $query->whereIn('id', $ids)->get(['id', 'meet_id', 'swim_event_id'])->keyBy('id');

            foreach ($logs->where('auditable_type', $class) as $log) {
                $row = $rows->get($log->auditable_id);
                $contexts[$class.'#'.$log->auditable_id] ??= [
                    'meet_id' => $row?->meet_id ?? self::changedValue($log, 'meet_id'),
                    'swim_event_id' => $row?->swim_event_id ?? self::changedValue($log, 'swim_event_id'),
                ];
            }
        }

        return $contexts;
    }

    private static function changedValue(AuditLog $log, string $field): ?int
    {
        $change = $log->changes[$field] ?? null;
        $value = $change[1] ?? $change[0] ?? null;

        return $value === null ? null : (int) $value;
    }

    /**
     * Namen aller verwiesenen Datensätze, je Feld gebündelt geladen (auch gelöschte Vereine und Athleten).
     *
     * @param  Collection<int, AuditLog>  $logs
     * @param  array<string, array{meet_id: int|null, swim_event_id: int|null}>  $contexts
     * @return array<string, array<int, string>>
     */
    private static function referenceNames(Collection $logs, array $contexts): array
    {
        $ids = array_fill_keys(self::REFERENCES, []);

        foreach ($logs as $log) {
            foreach (array_intersect_key($log->changes, $ids) as $field => $change) {
                foreach ([$change[0] ?? null, $change[1] ?? null] as $value) {
                    if ($value !== null && $value !== '') {
                        $ids[$field][(int) $value] = true;
                    }
                }
            }
        }
        foreach ($contexts as $context) {
            foreach ($context as $field => $value) {
                if ($value !== null) {
                    $ids[$field][$value] = true;
                }
            }
        }

        $load = fn (string $field, Closure $query): array => $ids[$field] === [] ? [] : $query(array_keys($ids[$field]));
        $clubs = fn (array $keys) => Club::withTrashed()->whereIn('id', $keys)->get()
            ->mapWithKeys(fn (Club $club) => [$club->id => $club->display_name])->all();

        return [
            'club_id' => $load('club_id', $clubs),
            'from_club_id' => $load('from_club_id', $clubs),
            'athlete_id' => $load('athlete_id', fn (array $keys) => Athlete::withTrashed()->whereIn('id', $keys)->get()
                ->mapWithKeys(fn (Athlete $athlete) => [$athlete->id => $athlete->last_name.' '.$athlete->first_name])->all()),
            'nation_id' => $load('nation_id', fn (array $keys) => Nation::whereIn('id', $keys)->pluck('name_de', 'id')->all()),
            'meet_id' => $load('meet_id', fn (array $keys) => Meet::withTrashed()->whereIn('id', $keys)->pluck('name', 'id')->all()),
            'swim_event_id' => $load('swim_event_id', fn (array $keys) => SwimEvent::with('strokeType')->whereIn('id', $keys)->get()
                ->mapWithKeys(fn (SwimEvent $event) => [$event->id => trim($event->display_name)])->all()),
        ];
    }

    /**
     * @param  array<string, array{meet_id: int|null, swim_event_id: int|null}>  $contexts
     * @param  array<string, array<int, string>>  $names
     */
    private static function subject(AuditLog $log, array $contexts, array $names): string
    {
        $label = self::SUBJECTS[$log->auditable_type] ?? class_basename($log->auditable_type);
        $context = $contexts[$log->auditable_type.'#'.$log->auditable_id] ?? null;

        if ($context === null) {
            return $label;
        }

        $parts = array_filter([
            $names['swim_event_id'][$context['swim_event_id']] ?? null,
            $names['meet_id'][$context['meet_id']] ?? null,
        ]);

        return $parts === [] ? $label : $label.': '.implode(', ', $parts);
    }

    /** @param  array<string, array<int, string>>  $names */
    private static function value(string $field, mixed $value, array $names): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (in_array($field, self::BOOLEANS, true)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Ja' : 'Nein';
        }
        if (in_array($field, self::DATES, true)) {
            try {
                return Carbon::parse((string) $value)->format('d.m.Y');
            } catch (Throwable) {
                return (string) $value;
            }
        }
        if ($field === 'entry_time' && is_numeric($value)) {
            return TimeParser::display((int) $value);
        }
        if ($field === 'type' && isset(ChangeRequest::TYPE_LABELS[$value])) {
            return ChangeRequest::TYPE_LABELS[$value];
        }
        if ($field === 'status' && isset(ChangeRequest::STATUS_LABELS[$value])) {
            return ChangeRequest::STATUS_LABELS[$value];
        }
        if ($field === 'values') {
            $decoded = is_string($value) ? json_decode($value, true) : $value;
            if (is_array($decoded)) {
                return collect($decoded)->filter(fn ($v) => $v !== null && $v !== '')
                    ->map(fn ($v, $key) => (self::VALUE_LABELS[$key] ?? $key).': '
                        .($key === 'joined_at' ? self::value('joined_at', $v, $names) : $v))
                    ->implode(', ');
            }
        }
        if ($field === 'gender') {
            return match ($value) {
                'M' => 'Herr',
                'F' => 'Dame',
                default => (string) $value,
            };
        }
        if (in_array($field, self::REFERENCES, true)) {
            return $names[$field][(int) $value] ?? '#'.$value;
        }

        return is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
