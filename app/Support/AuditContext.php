<?php

namespace App\Support;

use App\Models\AuditLog;
use Closure;

/**
 * Laufzeitkontext des Änderungsprotokolls je Request bzw. Befehl (scoped im Container): Quelle und Angabe (z. B.
 * Import mit Dateiname) und ob Model-Events gerade nicht protokolliert werden, weil der Aufrufer selbst einen
 * zusammenfassenden Eintrag schreibt (Vereinswechsel).
 */
final class AuditContext
{
    private ?string $source = null;

    private ?string $detail = null;

    private bool $muted = false;

    /**
     * Führt $callback mit der angegebenen Quelle aus; danach gilt wieder die vorherige.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function during(string $source, ?string $detail, Closure $callback): mixed
    {
        [$previousSource, $previousDetail] = [$this->source, $this->detail];
        [$this->source, $this->detail] = [$source, $detail];

        try {
            return $callback();
        } finally {
            [$this->source, $this->detail] = [$previousSource, $previousDetail];
        }
    }

    /**
     * Führt $callback aus, ohne Model-Events zu protokollieren.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function muted(Closure $callback): mixed
    {
        $previous = $this->muted;
        $this->muted = true;

        try {
            return $callback();
        } finally {
            $this->muted = $previous;
        }
    }

    public function isMuted(): bool
    {
        return $this->muted;
    }

    /** Ohne gesetzte Quelle: angemeldeter Benutzer oder Web-Request → web, sonst Konsole. */
    public function source(): string
    {
        if ($this->source !== null) {
            return $this->source;
        }

        return auth()->check() || ! app()->runningInConsole() ? AuditLog::SOURCE_WEB : AuditLog::SOURCE_CONSOLE;
    }

    public function detail(): ?string
    {
        return $this->detail;
    }
}
