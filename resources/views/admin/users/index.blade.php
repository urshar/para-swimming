{{-- resources/views/admin/users/index.blade.php --}}
{{-- Livewire-View für UserManager-Component --}}

<div>
    {{-- Livewire-Aktionen rendern nur diese Komponente neu, nicht das Layout: die Meldungen (session()->now)
         zeigt deshalb die Komponente selbst an, nicht das x-flash im Layout. --}}
    <x-flash/>

    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Benutzerverwaltung</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
                Benutzer anlegen, Vereine zuweisen, Rechte je Modul und Admin-Rechte vergeben.
            </p>
        </div>
        <div class="flex gap-2">
            <flux:button wire:click="openReapply" variant="ghost" icon="arrow-path">
                Vorlage neu anwenden
            </flux:button>
            <flux:button wire:click="openCreate" variant="primary" icon="plus">
                Neuer Benutzer
            </flux:button>
        </div>
    </div>

    {{-- Suche und Filter --}}
    <div class="mb-4 flex flex-wrap gap-3">
        <flux:input
            wire:model.live.debounce.300ms="search"
            placeholder="Name oder E-Mail suchen…"
            icon="magnifying-glass"
            class="max-w-sm"
            aria-label="Name oder E-Mail suchen"
        />
        <div class="w-56">
            <flux:select variant="listbox" wire:model.live="templateFilter" aria-label="Nach Vorlage filtern">
                <flux:select.option value="{{ $filterAll }}">Alle Vorlagen</flux:select.option>
                @foreach($templates as $template)
                    <flux:select.option value="{{ $template->value }}">{{ $template->label() }}</flux:select.option>
                @endforeach
                <flux:select.option value="{{ $filterNone }}">Ohne Vorlage</flux:select.option>
            </flux:select>
        </div>
    </div>

    {{-- Tabelle --}}
    <div
        class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden p-4 [--flux-bleed:1rem]">
        <flux:table bleed>
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>E-Mail</flux:table.column>
                <flux:table.column>Verein</flux:table.column>
                <flux:table.column>Rolle</flux:table.column>
                <flux:table.column>Vorlage</flux:table.column>
                <flux:table.column>2FA</flux:table.column>
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
                            @elseif($user->coach)
                                <flux:badge color="zinc" size="sm" title="{{ $user->coach->display_name }}">Trainer</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">Verein</flux:badge>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if($user->is_admin)
                                <span class="text-zinc-400 text-sm">–</span>
                            @elseif($user->role_template)
                                <span
                                    class="text-sm text-zinc-700 dark:text-zinc-300">{{ $user->role_template->label() }}</span>
                                @if(in_array($user->id, $this->customizedIds, true))
                                    <flux:badge color="sky" size="sm" class="ml-1">angepasst</flux:badge>
                                @endif
                            @else
                                <flux:badge color="red" size="sm">keine</flux:badge>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if($user->two_factor_confirmed_at)
                                <flux:badge color="green" size="sm">aktiv</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">aus</flux:badge>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="text-zinc-400 text-sm">
                            {{ $user->created_at->format('d.m.Y') }}
                        </flux:table.cell>

                        <flux:table.cell>
                            <div class="flex gap-1 justify-end">
                                @unless($user->is_admin)
                                    <flux:button
                                        wire:click="openPermissions({{ $user->id }})"
                                        size="sm" variant="ghost" icon="key"
                                        aria-label="Rechte von {{ $user->name }}">
                                    </flux:button>
                                @endunless

                                <flux:button
                                    wire:click="openEdit({{ $user->id }})"
                                    size="sm" variant="ghost" icon="pencil"
                                    aria-label="{{ $user->name }} bearbeiten">
                                </flux:button>

                                @if($user->two_factor_confirmed_at && $user->id !== auth()->id())
                                    <flux:button
                                        wire:click="resetTwoFactor({{ $user->id }})"
                                        wire:confirm="Zwei-Faktor-Anmeldung von '{{ $user->name }}' zurücksetzen? Die Person muss sie danach neu einrichten."
                                        size="sm" variant="ghost" icon="shield-exclamation"
                                        aria-label="Zwei-Faktor-Anmeldung von {{ $user->name }} zurücksetzen">
                                    </flux:button>
                                @endif

                                @if($user->id !== auth()->id())
                                    <flux:button
                                        wire:click="delete({{ $user->id }})"
                                        wire:confirm="Benutzer '{{ $user->name }}' wirklich löschen?"
                                        size="sm" variant="ghost" icon="trash"
                                        class="text-red-500 hover:text-red-700"
                                        aria-label="{{ $user->name }} löschen">
                                    </flux:button>
                                @endif
                            </div>
                        </flux:table.cell>

                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="8" class="text-center text-zinc-400 py-8">
                            Keine Benutzer gefunden.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <div class="mt-4">
        {{ $this->users->links() }}
    </div>

    {{-- ── Modal: Benutzer anlegen / bearbeiten ─────────────────────────────── --}}
    <flux:modal wire:model="showModal" class="max-w-lg">
        <div class="p-6">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-5">
                {{ $isEditing ? 'Benutzer bearbeiten' : 'Neuer Benutzer' }}
            </h2>

            <div class="space-y-4">

                {{-- Name --}}
                <flux:field>
                    <flux:label>Name<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                    <flux:input
                        wire:model="name"
                        placeholder="Vor- und Nachname"
                        autocomplete="off"
                    />
                    <flux:error name="name"/>
                </flux:field>

                {{-- E-Mail --}}
                <flux:field>
                    <flux:label>E-Mail<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
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
                        @if($isEditing)
                            Passwort (leer lassen = unverändert)
                        @else
                            Passwort<span class="text-red-500 dark:text-red-400 ms-1">*</span>
                        @endif
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
                                (alle Rechte, kein Meldeschluss, alle Vereine sichtbar)
                            </span>
                        </label>
                    </div>
                    <flux:error name="is_admin"/>
                </flux:field>

                {{-- Verein und Vorlage (werden ausgeblendet wenn Admin) --}}
                @unless($is_admin)
                    <flux:field>
                        <flux:label>Verein</flux:label>
                        <flux:select variant="listbox" searchable wire:model.live="club_id"
                                     placeholder="— Kein Verein —" clearable>
                            @foreach($this->clubs as $club)
                                <flux:select.option value="{{ $club->id }}">
                                    {{ $club->short_name ?: $club->name }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="club_id"/>
                        <flux:description>
                            "Eigener Verein" in den Rechten bezieht sich auf diesen Verein.
                        </flux:description>
                    </flux:field>

                    {{-- Trainer-Login (Spec club-permissions N7, P8-4): nur Trainer des gewählten Vereins.
                         wire:key je Verein, damit die Optionen beim Vereinswechsel neu aufgebaut werden. --}}
                    @if($club_id !== null)
                        <flux:field wire:key="coach-select-{{ $club_id }}">
                            <flux:label>Trainer</flux:label>
                            <flux:select variant="listbox" searchable wire:model.live="coach_id"
                                         placeholder="— Kein Trainer —" clearable>
                                @foreach($this->coaches as $coach)
                                    <flux:select.option value="{{ $coach->id }}">{{ $coach->display_name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:error name="coach_id"/>
                            <flux:description>
                                Verknüpft das Login mit einem Trainer des Vereins; beim Anlegen wird die Vorlage "Trainer" vorgeschlagen.
                            </flux:description>
                        </flux:field>
                    @endif

                    <flux:field>
                        <flux:label>Vorlage<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                        <flux:select variant="listbox" wire:model.live="role_template">
                            @foreach($templates as $template)
                                <flux:select.option
                                    value="{{ $template->value }}">{{ $template->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="role_template"/>
                        <flux:description>
                            {{ $isEditing ? 'Eine geänderte Vorlage ersetzt die Rechte des Benutzers.' : 'Befüllt die Rechte; einzeln anpassen über "Rechte".' }}
                        </flux:description>
                    </flux:field>

                    @if($this->templateWillOverwrite)
                        <flux:callout variant="warning" icon="exclamation-triangle">
                            <flux:callout.text>
                                Die Rechte dieses Benutzers wurden einzeln angepasst. Mit der neuen Vorlage gehen diese
                                Anpassungen verloren.
                            </flux:callout.text>
                        </flux:callout>
                    @endif
                @endunless

            </div>

            {{-- Buttons --}}
            <div class="flex gap-3 mt-6">
                @if($this->templateWillOverwrite)
                    <flux:button wire:click="save" variant="primary"
                                 wire:confirm="Angepasste Rechte durch die neue Vorlage ersetzen?">
                        Speichern
                    </flux:button>
                @else
                    <flux:button wire:click="save" variant="primary">
                        {{ $isEditing ? 'Speichern' : 'Anlegen' }}
                    </flux:button>
                @endif
                <flux:button wire:click="closeModal" variant="ghost">
                    Abbrechen
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- ── Flyout: Rechte je Modul ──────────────────────────────────────────── --}}
    <flux:modal wire:model="showPermissions" variant="flyout" class="md:w-176">
        @include('admin.users.partials.permissions-matrix', ['permissionsUser' => $this->permissionsUser])
    </flux:modal>

    {{-- ── Modal: Vorlage für alle neu anwenden ─────────────────────────────── --}}
    <flux:modal wire:model="showReapply" class="max-w-md">
        <div class="p-6 space-y-4">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Vorlage neu anwenden</h2>

            <flux:field>
                <flux:label>Vorlage</flux:label>
                <flux:select variant="listbox" wire:model.live="reapplyTemplate">
                    @foreach($templates as $template)
                        <flux:select.option value="{{ $template->value }}">{{ $template->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="reapplyTemplate"/>
            </flux:field>

            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                Betrifft {{ $this->reapplyStats['total'] }} Benutzer (ohne Admins),
                davon {{ $this->reapplyStats['customized'] }} mit einzeln angepassten Rechten — diese Anpassungen
                gehen verloren.
            </p>

            <div class="flex gap-3">
                <flux:button wire:click="confirmReapply" variant="primary"
                             wire:confirm="Vorlage jetzt auf alle betroffenen Benutzer neu anwenden?">
                    Neu anwenden
                </flux:button>
                <flux:button wire:click="$set('showReapply', false)" variant="ghost">
                    Abbrechen
                </flux:button>
            </div>
        </div>
    </flux:modal>

</div>
