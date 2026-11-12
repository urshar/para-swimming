<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Namenssuche über mehrere Wörter: Der Suchbegriff wird an Kommas und Leerzeichen in Teile zerlegt, jeder Teil muss in
 * mindestens einem der durchsuchten Felder vorkommen (UND über die Teile, ODER über die Felder). Damit treffen
 * "Aigner", "Aigner, M", "Aigner Matteo" und "Matteo Aigner" denselben Athleten — die Listen zeigen Namen als
 * "Nachname, Vorname", die Suche nach genau dieser Schreibweise fand vorher nichts.
 *
 * Nur Standard-SQL (LIKE), läuft auf MySQL und SQLite. Typisiert auf den Eloquent-Builder, damit applyEach()-Aufrufer
 * in der Closure Relationen (orWhereHas()) verwenden können.
 */
final class NameSearch
{
    /**
     * Suchteile ohne leere Einträge.
     *
     * @return list<string>
     */
    public static function terms(string $search): array
    {
        return array_values(array_filter(
            preg_split('/[\s,]+/u', trim($search)) ?: [],
            fn (string $term): bool => $term !== '',
        ));
    }

    /**
     * Jeder Suchteil muss in mindestens einer der Spalten vorkommen.
     *
     * @param  list<string>  $columns
     */
    public static function apply(Builder $query, string $search, array $columns): Builder
    {
        return self::applyEach($query, $search, function (Builder $q, string $term) use ($columns): void {
            foreach ($columns as $column) {
                $q->orWhere($column, 'like', '%'.$term.'%');
            }
        });
    }

    /**
     * Wie apply(), aber die Bedingung je Suchteil legt der Aufrufer fest (z. B. über Relationen). Die Closure bekommt
     * eine eigene geklammerte Where-Gruppe und darf darin mit orWhere()/orWhereHas() arbeiten.
     *
     * @param  Closure(Builder, string): void  $matchTerm
     */
    public static function applyEach(Builder $query, string $search, Closure $matchTerm): Builder
    {
        foreach (self::terms($search) as $term) {
            $query->where(function (Builder $q) use ($matchTerm, $term): void {
                $matchTerm($q, $term);
            });
        }

        return $query;
    }

    /** Für Daten im Speicher: jeder Suchteil kommt (ohne Groß-/Kleinschreibung) im Text vor. */
    public static function matches(string $haystack, string $search): bool
    {
        $haystack = mb_strtolower($haystack);

        foreach (self::terms($search) as $term) {
            if (! str_contains($haystack, mb_strtolower($term))) {
                return false;
            }
        }

        return true;
    }
}
