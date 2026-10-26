<?php

namespace App\Rules;

use App\Models\ScoringGroup;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Kommagetrennte Code-Liste ("WBSV, SBSV"), deren Einträge alle bekannt sein müssen — für die Landesverbände der
 * Wertungsgruppen. Zerlegt wie ScoringGroup::parseCodes() (Groß-/Kleinschreibung egal).
 */
final readonly class CodeList implements ValidationRule
{
    /** @param  list<string>  $allowed */
    public function __construct(private array $allowed) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $unknown = array_diff(ScoringGroup::parseCodes((string) $value), $this->allowed);
        if ($unknown !== []) {
            $fail('Unbekannt: '.implode(', ', $unknown).'.');
        }
    }
}
