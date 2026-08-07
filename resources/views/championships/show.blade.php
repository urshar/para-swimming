@extends('layouts.app')

@section('title', $championship->display_name)

@section('content')
    <div class="max-w-6xl">
        <div class="flex items-start justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                    {{ $championship->name }}
                </h1>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Qualifikationszeitraum
                    {{ $championship->qualification_start->format('d.m.Y') }}
                    bis {{ $championship->qualification_end->format('d.m.Y') }}
                    · Normen auf {{ $championship->course }}
                    @if($championship->source)
                        · Quelle: {{ $championship->source }}
                    @endif
                </p>
            </div>
            <div class="flex gap-2">
                <flux:button href="{{ route('championships.index') }}" variant="ghost" size="sm">
                    Zur Übersicht
                </flux:button>
                @if(auth()->user()?->is_admin)
                    <flux:button href="{{ route('championships.edit', $championship) }}"
                                 variant="ghost" size="sm" icon="pencil"/>
                @endif
            </div>
        </div>

        @if(session('success'))
            <div
                class="mb-4 p-4 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800 rounded-xl text-sm text-green-700 dark:text-green-400">
                {{ session('success') }}
            </div>
        @endif

        @if($championship->notes)
            <div
                class="mb-4 p-4 bg-zinc-50 dark:bg-zinc-900/40 border border-zinc-200 dark:border-zinc-700 rounded-xl text-sm text-zinc-600 dark:text-zinc-400 whitespace-pre-line">{{ $championship->notes }}</div>
        @endif

        @if(auth()->user()?->is_admin && $copySources->isNotEmpty())
            <div
                class="mb-6 p-4 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl">
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 mb-3">
                    Normen aus einer anderen Meisterschaft übernehmen
                </h2>
                <form method="POST" action="{{ route('championships.copy-from', $championship) }}"
                      class="flex flex-wrap items-end gap-3">
                    @csrf
                    <flux:field class="w-72">
                        <flux:label>Quelle</flux:label>
                        <flux:select name="source_id">
                            @foreach($copySources as $quelle)
                                <option value="{{ $quelle->id }}">
                                    {{ $quelle->display_name }} ({{ $quelle->standards_count }} Normen)
                                </option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                    <flux:button type="submit" variant="filled" size="sm" icon="document-duplicate">
                        Übernehmen
                    </flux:button>
                </form>
                @error('source_id')
                <p class="mt-2 text-xs text-red-500">{{ $message }}</p>
                @enderror
                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                    Übernommen werden nur MQS und MET. Die ÖBSV-Verschärfung hängt an der
                    Startplatzlage der jeweiligen Meisterschaft und wird deshalb nicht mitkopiert.
                    Bereits vorhandene Zeilen bleiben unverändert.
                </p>
            </div>
        @endif

        @livewire('admin.championship-standard-table', ['championship' => $championship])
    </div>
@endsection
