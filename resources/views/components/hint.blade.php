@props(['content'])

{{--
    Kurzer Feld-Hinweis als Info-Icon mit Tooltip (statt permanent sichtbarem flux:description).
    Barrierefrei: fokussierbar (tabindex=0) → Tooltip erscheint bei Hover UND Tastatur-Fokus; der
    Hinweistext steht zusätzlich im aria-label, damit Screenreader ihn direkt vorlesen (das Icon
    selbst ist über Flux' aria-hidden dekorativ). Verwendung: <flux:label>Feld <x-hint content="…"/></flux:label>
--}}
<flux:tooltip :content="$content">
    <span tabindex="0" role="img" aria-label="Hinweis: {{ $content }}"
          class="ms-1 inline-flex align-text-bottom text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 cursor-help">
        <flux:icon.information-circle class="size-4"/>
    </span>
</flux:tooltip>
