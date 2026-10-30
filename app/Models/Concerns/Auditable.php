<?php

namespace App\Models\Concerns;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Protokolliert Anlegen, Ändern, Löschen und Wiederherstellen eines Modells im Änderungsprotokoll (Spec
 * club-permissions §4.7). Nur geänderte Felder; Zeitstempel und die Felder aus auditExcluded() bleiben außen vor.
 *
 * Für den Verlauf liefert das Modell über auditAthleteId()/auditClubId() den zugehörigen Athleten bzw. Verein;
 * Standard sind die Spalten athlete_id und club_id.
 *
 * @mixin Model
 */
trait Auditable
{
    private const array AUDIT_ALWAYS_EXCLUDED = ['created_at', 'updated_at', 'deleted_at'];

    public static function bootAuditable(): void
    {
        static::created(fn (self $model) => $model->writeAudit('created', array_map(
            fn (mixed $value): array => [null, $value],
            array_filter($model->auditableAttributes($model->getAttributes()), fn (mixed $value): bool => $value !== null),
        )));

        static::updated(function (self $model) {
            $changes = [];
            foreach (array_keys($model->auditableAttributes($model->getChanges())) as $key) {
                $changes[$key] = [$model->getRawOriginal($key), $model->getAttributes()[$key] ?? null];
            }

            $model->writeAudit('updated', $changes);
        });

        static::deleted(fn (self $model) => $model->writeAudit('deleted', array_map(
            fn (mixed $value): array => [$value, null],
            array_filter($model->auditableAttributes($model->getRawOriginal()), fn (mixed $value): bool => $value !== null),
        )));

        if (method_exists(static::class, 'restored')) {
            static::restored(fn (self $model) => $model->writeAudit('restored', []));
        }
    }

    public function auditAthleteId(): ?int
    {
        return $this->getAttribute('athlete_id');
    }

    public function auditClubId(): ?int
    {
        return $this->getAttribute('club_id');
    }

    /** @return list<string> Weitere Felder, die nicht protokolliert werden. */
    protected function auditExcluded(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function auditableAttributes(array $attributes): array
    {
        return array_diff_key($attributes, array_flip([...self::AUDIT_ALWAYS_EXCLUDED, ...$this->auditExcluded(), $this->getKeyName()]));
    }

    /** @param  array<string, array{0: mixed, 1: mixed}>  $changes */
    private function writeAudit(string $event, array $changes): void
    {
        app(AuditLogger::class)->recordModelEvent($this, $event, $changes);
    }
}
