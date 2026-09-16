@props([
    'gender' => null,
    'class' => '',
])

@php
    // Einheitliche Geschlechts-Darstellung (Symbol + Farbe), wiederverwendet für Athleten- und
    // Event-Geschlecht in club-entries/index(-relay).blade.php. Farbschema wie bereits in
    // meets/show.blade.php für Event-Geschlecht-Badges etabliert (M=blue, F=pink, sonst zinc).
    [$symbol, $color, $title] = match($gender) {
        'M' => ['♂', 'text-blue-500 dark:text-blue-400', 'Männer'],
        'F' => ['♀', 'text-pink-500 dark:text-pink-400', 'Frauen'],
        'X', 'MX' => ['⚥', 'text-violet-500 dark:text-violet-400', 'Mixed'],
        default => ['⚥', 'text-zinc-400 dark:text-zinc-500', 'Offen'],
    };
@endphp

<span class="{{ $color }} {{ $class }}" title="{{ $title }}" role="img" aria-label="{{ $title }}">{{ $symbol }}</span>
