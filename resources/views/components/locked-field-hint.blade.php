@props(['field', 'locked'])
@php
    /** @var string $field */
    /** @var array<string, string> $locked */
@endphp
{{-- Hinweis unter einem für den Benutzer gesperrten Feld (Spec club-permissions §4.4, App\Support\AthleteFieldRules).
     Gesperrte Felder ignoriert der Server; der Hinweis erklärt, warum das Feld schreibgeschützt ist. --}}
@isset($locked[$field])
    <flux:description class="mt-1! flex items-center gap-1">
        <flux:icon.lock-closed variant="micro" class="shrink-0"/>
        {{ $locked[$field] }}
    </flux:description>
@endisset
