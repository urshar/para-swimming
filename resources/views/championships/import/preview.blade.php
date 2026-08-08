@extends('layouts.app')

@section('title', 'Vorschau Normimport')

@section('content')
    @php
        use App\Support\TimeParser;
        use Illuminate\Support\Carbon;

        $kennzahlen = [
            'Normen' => $preview->counts['rows'],
            'Bewerbe' => $preview->counts['events'],
            'Männer' => $preview->counts['male'],
            'Frauen' => $preview->counts['female'],
            'mit MET' => $preview->counts['with_met'],
        ];
    @endphp

    <div class="max-w-4xl">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 mb-2">Vorschau</h1>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-6">{{ $championship->display_name }}</p>

        @if($preview->title)
            <p class="mb-4 text-xs text-zinc-500 dark:text-zinc-400 whitespace-pre-line">{{ $preview->title }}</p>
        @endif

        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-6">
            @foreach($kennzahlen as $label => $wert)
                <div class="p-3 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl">
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $label }}</div>
                    <div class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ $wert }}</div>
                </div>
            @endforeach
        </div>

        @if($preview->suggestedPeriod)
            <div
                class="mb-4 p-4 bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-xl text-sm text-blue-700 dark:text-blue-400">
                Die Titelzeile nennt den Qualifikationszeitraum
                {{ Carbon::parse($preview->suggestedPeriod['start'])->format('d.m.Y') }} bis
                {{ Carbon::parse($preview->suggestedPeriod['end'])->format('d.m.Y') }}.
                Er wird <strong>nicht</strong> übernommen — bei der Meisterschaft ist
                {{ $championship->qualification_start->format('d.m.Y') }} bis
                {{ $championship->qualification_end->format('d.m.Y') }} hinterlegt.
                Weichen die Angaben ab, bitte zuerst die Meisterschaft bearbeiten.
            </div>
        @endif

        @if($preview->errorCount() > 0)
            <div
                class="mb-4 p-4 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800 rounded-xl">
                <p class="text-sm font-medium text-red-700 dark:text-red-400 mb-2">
                    {{ $preview->errorCount() }} Fehler — es wird nichts importiert.
                </p>
                <ul class="text-xs text-red-700 dark:text-red-400 space-y-1 list-disc list-inside">
                    @foreach($preview->errors as $fehler)
                        <li>{{ $fehler }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($preview->warningCount() > 0)
            <div
                class="mb-4 p-4 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-xl">
                <p class="text-sm font-medium text-amber-700 dark:text-amber-400 mb-2">Hinweise</p>
                <ul class="text-xs text-amber-700 dark:text-amber-400 space-y-1 list-disc list-inside">
                    @foreach($preview->warnings as $hinweis)
                        <li>{{ $hinweis }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($preview->rowCount() > 0)
            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden mb-4">
                <flux:table class="[&_td:first-child]:ps-4 [&_th:first-child]:ps-4">
                    <flux:table.columns>
                        <flux:table.column>Bewerb</flux:table.column>
                        <flux:table.column>Klasse</flux:table.column>
                        <flux:table.column>MQS</flux:table.column>
                        <flux:table.column>MET</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach(array_slice($preview->rows, 0, 25) as $zeile)
                            <flux:table.row>
                                <flux:table.cell>{{ $zeile['event_label'] }}</flux:table.cell>
                                <flux:table.cell class="font-mono text-xs">
                                    {{ $zeile['sport_class'] }} {{ $zeile['gender'] === 'M' ? 'm' : 'w' }}
                                </flux:table.cell>
                                <flux:table.cell class="font-mono text-xs">
                                    {{ $zeile['mqs_centiseconds'] === null ? '–' : TimeParser::display($zeile['mqs_centiseconds']) }}
                                </flux:table.cell>
                                <flux:table.cell class="font-mono text-xs">
                                    {{ $zeile['met_centiseconds'] === null ? '–' : TimeParser::display($zeile['met_centiseconds']) }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>

            @if($preview->rowCount() > 25)
                <p class="mb-6 text-xs text-zinc-500 dark:text-zinc-400">
                    Gezeigt werden die ersten 25 von {{ $preview->rowCount() }} Normen.
                </p>
            @endif
        @endif

        <div class="flex gap-3">
            @if($preview->isValid())
                <form method="POST" action="{{ route('championships.import.run', $championship) }}">
                    @csrf
                    <flux:button type="submit" variant="primary">
                        {{ $preview->rowCount() }} Normen importieren
                    </flux:button>
                </form>
            @endif
            <flux:button href="{{ route('championships.import', $championship) }}" variant="ghost">
                Andere Datei wählen
            </flux:button>
        </div>
    </div>
@endsection
