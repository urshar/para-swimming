{{-- Zentrale Flash-Meldungen für alle Seiten im Layout "layouts.app" (siehe CLAUDE.md):
     session('success') als Status, session('error') als Fehler ohne Feldbezug.
     Fehler mit Feldbezug (withErrors) zeigen die Formulare selbst über flux:error.
     Kein automatisches Ausblenden (docs/accessibility.md), nur ein Schließen-Knopf. --}}
@foreach(['success' => ['success', 'check-circle', 'status'], 'error' => ['danger', 'exclamation-triangle', 'alert']] as $key => [$variant, $icon, $role])
    @if(session($key))
        {{-- x-data ist nötig, sonst wertet Alpine das x-on:click am Schließen-Knopf nicht aus. --}}
        <flux:callout :variant="$variant" :icon="$icon" role="{{ $role }}" class="mb-4" data-flash="{{ $key }}"
                      x-data="{ open: true }" x-show="open">
            <flux:callout.text>{{ session($key) }}</flux:callout.text>
            <x-slot name="controls">
                <flux:button icon="x-mark" variant="ghost" size="sm" aria-label="{{ __('Meldung schließen') }}"
                             x-on:click="open = false"/>
            </x-slot>
        </flux:callout>
    @endif
@endforeach
