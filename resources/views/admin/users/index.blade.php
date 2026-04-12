@php use App\Livewire\Admin\UserManager; @endphp
{{-- resources/views/admin/users/index.blade.php --}}
{{-- Livewire-View für UserManager-Component --}}

<?php /** @var UserManager $this */ ?>

<div>
    {{-- Flash Messages --}}
    @if(session('success'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
             class="mb-4 p-4 bg-green-50 dark:bg-green-950/30 border border-green-200 dark:border-green-800 rounded-xl text-sm text-green-700 dark:text-green-300">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div
            class="mb-4 p-4 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-800 rounded-xl text-sm text-red-700 dark:text-red-300">
            {{ session('error') }}
        </div>
    @endif

    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Benutzerverwaltung</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
                Benutzer anlegen, Vereine zuweisen und Admin-Rechte vergeben.
            </p>
        </div>
        <flux:button wire:click="openCreate" variant="primary" icon="plus">
            Neuer Benutzer
        </flux:button>
    </div>

    {{-- Suche --}}
    <div class="mb-4">
        <flux:input
            wire:model.live.debounce.300ms="search"
            placeholder="Name oder E-Mail suchen…"
            icon="magnifying-glass"
            class="max-w-sm"
        />
    </div>

    {{-- Tabelle --}}
    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>E-Mail</flux:table.column>
                <flux:table.column>Verein</flux:table.column>
                <flux:table.column>Rolle</flux:table.column>
                <flux:table.column>Erstellt</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse($this->users as $user)
                    <flux:table.row wire:key="user-{{ $user->id }}">

                        <flux:table.cell class="font-medium text-zinc-900 dark:text-zinc-100">
                            {{ $user->name }}
                            @if($user->id === auth()->id())
                                <span class="text-xs text-zinc-400 ml-1">(Sie)</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="text-zinc-500 text-sm">
                            {{ $user->email }}
                        </flux:table.cell>

                        <flux:table.cell>
                            @if($user->club)
                                <span class="text-sm text-zinc-700 dark:text-zinc-300">
                                    {{ $user->club->short_name ?? $user->club->name }}
                                </span>
                            @else
                                <span class="text-zinc-400 text-sm">–</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if($user->is_admin)
                                <flux:badge color="amber" size="sm">Admin</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">Verein</flux:badge>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="text-zinc-400 text-sm">
                            {{ $user->created_at->format('d.m.Y') }}
                        </flux:table.cell>

                        <flux:table.cell>
                            <div class="flex gap-1 justify-end">
                                <flux:button
                                    wire:click="openEdit({{ $user->id }})"
                                    size="sm" variant="ghost" icon="pencil">
                                </flux:button>

                                @if($user->id !== auth()->id())
                                    <flux:button
                                        wire:click="delete({{ $user->id }})"
                                        wire:confirm="Benutzer '{{ $user->name }}' wirklich löschen?"
                                        size="sm" variant="ghost" icon="trash"
                                        class="text-red-500 hover:text-red-700">
                                    </flux:button>
                                @endif
                            </div>
                        </flux:table.cell>

                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6" class="text-center text-zinc-400 py-8">
                            Keine Benutzer gefunden.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        {{-- Pagination --}}
        @if($this->users->hasPages())
            <div class="px-4 py-3 border-t border-zinc-100 dark:border-zinc-700">
                {{ $this->users->links() }}
            </div>
        @endif
    </div>

    {{-- ── Modal: Benutzer anlegen / bearbeiten ────────────────────────────── --}}
    <flux:modal wire:model="showModal" class="max-w-lg">
        <div class="p-6">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-5">
                {{ $isEditing ? 'Benutzer bearbeiten' : 'Neuer Benutzer' }}
            </h2>

            <div class="space-y-4">

                {{-- Name --}}
                <flux:field>
                    <flux:label>Name *</flux:label>
                    <flux:input
                        wire:model="name"
                        placeholder="Vor- und Nachname"
                        autocomplete="off"
                    />
                    <flux:error name="name"/>
                </flux:field>

                {{-- E-Mail --}}
                <flux:field>
                    <flux:label>E-Mail *</flux:label>
                    <flux:input
                        wire:model="email"
                        type="email"
                        placeholder="name@beispiel.at"
                        autocomplete="off"
                    />
                    <flux:error name="email"/>
                </flux:field>

                {{-- Passwort --}}
                <flux:field>
                    <flux:label>
                        Passwort {{ $isEditing ? '(leer lassen = unverändert)' : '*' }}
                    </flux:label>
                    <flux:input
                        wire:model="password"
                        type="password"
                        placeholder="{{ $isEditing ? 'Neues Passwort eingeben…' : 'Passwort' }}"
                        autocomplete="new-password"
                    />
                    <flux:error name="password"/>
                </flux:field>

                {{-- Admin-Schalter --}}
                <flux:field>
                    <div class="flex items-center gap-3">
                        <flux:checkbox wire:model.live="is_admin" id="is_admin"/>
                        <label for="is_admin"
                               class="text-sm font-medium text-zinc-700 dark:text-zinc-300 cursor-pointer">
                            Administrator
                            <span class="text-zinc-400 font-normal ml-1">
                                (kein Meldeschluss, alle Vereine sichtbar)
                            </span>
                        </label>
                    </div>
                </flux:field>

                {{-- Verein (wird ausgeblendet wenn Admin) --}}
                @unless($is_admin)
                    <flux:field>
                        <flux:label>Verein</flux:label>
                        <flux:select wire:model="club_id">
                            <option value="">— Kein Verein —</option>
                            @foreach($this->clubs as $club)
                                <option value="{{ $club->id }}">
                                    {{ $club->name }}
                                    @if($club->short_name && $club->short_name !== $club->name)
                                        ({{ $club->short_name }})
                                    @endif
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:error name="club_id"/>
                        <flux:description>
                            Ein Vereinsbenutzer sieht nur Athleten und Meldungen seines Vereins.
                        </flux:description>
                    </flux:field>
                @endunless

            </div>

            {{-- Buttons --}}
            <div class="flex gap-3 mt-6">
                <flux:button wire:click="save" variant="primary">
                    {{ $isEditing ? 'Speichern' : 'Anlegen' }}
                </flux:button>
                <flux:button wire:click="closeModal" variant="ghost">
                    Abbrechen
                </flux:button>
            </div>
        </div>
    </flux:modal>

</div>
