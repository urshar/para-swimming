{{-- Rechte-Matrix eines Benutzers im Flyout der Benutzerverwaltung (Spec club-permissions Phase 2). --}}
{{-- Erwartet: $permissionsUser (?User), $matrix (Livewire-Property), $modules (Module::cases()). --}}

@php
    $usesOwn = collect($matrix)->contains(fn (array $s) => $s['read'] === 'own' || $s['write'] === 'own');
@endphp

<div class="space-y-5">
    @if($permissionsUser)
        <div>
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Rechte: {{ $permissionsUser->name }}</h2>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
                Vorlage: {{ $permissionsUser->role_template?->label() ?? 'keine' }}
                · Verein: {{ $permissionsUser->club?->short_name ?? $permissionsUser->club?->name ?? 'keiner' }}
            </p>
        </div>

        @if($usesOwn && $permissionsUser->club_id === null)
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.text>
                    "Eigener Verein" wirkt nicht, solange dem Benutzer kein Verein zugeordnet ist.
                </flux:callout.text>
            </flux:callout>
        @endif

        <table class="w-full text-sm">
            <thead>
            <tr class="text-left text-zinc-500 dark:text-zinc-400">
                <th scope="col" class="py-2 pr-3 font-medium">Modul</th>
                <th scope="col" class="py-2 pr-3 font-medium w-40">Lesen</th>
                <th scope="col" class="py-2 font-medium w-40">Schreiben</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
            @foreach($modules as $module)
                <tr wire:key="perm-{{ $module->value }}">
                    <th scope="row" class="py-2 pr-3 text-left font-normal align-top">
                        <span class="text-zinc-900 dark:text-zinc-100">{{ $module->label() }}</span>
                        @if($module->writeHint())
                            <span class="block text-xs text-zinc-400">{{ $module->writeHint() }}</span>
                        @endif
                        @error('permissions.'.$module->value)
                        <span class="block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>
                        @enderror
                    </th>
                    <td class="py-2 pr-3 align-top">
                        <flux:select variant="listbox" size="sm" wire:model="matrix.{{ $module->value }}.read"
                                     aria-label="{{ $module->label() }} lesen">
                            @foreach($module->allowedReadScopes() as $scope)
                                <flux:select.option value="{{ $scope->value }}">{{ $scope->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </td>
                    <td class="py-2 align-top">
                        @if(count($module->allowedWriteScopes()) === 1)
                            <span class="text-zinc-400">–</span>
                        @else
                            <flux:select variant="listbox" size="sm" wire:model="matrix.{{ $module->value }}.write"
                                         aria-label="{{ $module->label() }} schreiben">
                                @foreach($module->allowedWriteScopes() as $scope)
                                    <flux:select.option value="{{ $scope->value }}">{{ $scope->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <p class="text-xs text-zinc-500 dark:text-zinc-400">
            Schreiben darf nicht weiter reichen als Lesen. Wirksam ab der Umstellung der Bereiche auf Rechte je Modul.
        </p>

        <div class="flex flex-wrap gap-3">
            <flux:button wire:click="savePermissions" variant="primary">Speichern</flux:button>
            @if($permissionsUser->role_template)
                <flux:button wire:click="reapplyForPermissionsUser" variant="ghost" icon="arrow-path"
                             wire:confirm="Rechte auf die Vorlage zurücksetzen? Einzelne Anpassungen gehen verloren.">
                    Vorlage neu anwenden
                </flux:button>
            @endif
            <flux:button wire:click="closePermissions" variant="ghost">Abbrechen</flux:button>
        </div>
    @endif
</div>
