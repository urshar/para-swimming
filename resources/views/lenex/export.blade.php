@extends('layouts.app')

@section('title', 'LENEX Export')

@section('content')
    <div class="max-w-xl">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 mb-6">LENEX Export</h1>
        <form method="POST" action="{{ route('lenex.export.download') }}">
            @csrf
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-5">
                <flux:field>
                    <flux:label>Wettkampf *</flux:label>
                    <flux:select name="meet_id" required>
                        <option value="">Bitte wählen…</option>
                        @foreach($meets as $meet)
                            <option value="{{ $meet->id }}">{{ $meet->name }} ({{ $meet->start_date->format('Y') }})
                            </option>
                        @endforeach
                    </flux:select>
                    <flux:error name="meet_id"/>
                </flux:field>
                <flux:field>
                    <flux:label>Export-Typ *</flux:label>
                    <div class="space-y-2 mt-1">
                        @foreach(['structure' => ['Struktur', 'Nur Meet, Sessions und Disziplinen'], 'entries' => ['Meldungen', '+ Vereine, Athleten und Meldungen'], 'results' => ['Ergebnisse', '+ Ergebnisse und Splitzeiten']] as $val => [$label, $desc])
                            <label
                                class="flex items-start gap-3 p-3 rounded-lg border border-zinc-200 dark:border-zinc-700 cursor-pointer hover:border-blue-400 transition-colors has-checked:border-blue-500 has-checked:bg-blue-50 dark:has-checked:bg-blue-950/20">
                                <input type="radio" name="export_type" value="{{ $val }}" class="mt-0.5 text-blue-600"
                                       @if($val === 'results') checked @endif>
                                <div>
                                    <div class="font-medium text-sm text-zinc-900 dark:text-zinc-100">{{ $label }}</div>
                                    <div class="text-xs text-zinc-400 mt-0.5">{{ $desc }}</div>
                                </div>
                            </label>
                        @endforeach
                    </div>
                </flux:field>
            </div>
            <flux:button type="submit" variant="primary" icon="arrow-down-tray" class="mt-6">
                LENEX Datei herunterladen
            </flux:button>
        </form>
    </div>
@endsection
