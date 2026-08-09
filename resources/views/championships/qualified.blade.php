@extends('layouts.app')

@section('title', 'Qualifikanten')

@section('content')
    @php
        use App\Support\TimeParser;use Illuminate\Support\Carbon;
    @endphp

    <div class="max-w-5xl">
        <div class="flex items-start justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Qualifikanten</h1>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ $championship->display_name }} ·
                    Qualifikationszeitraum {{ $championship->qualification_start->format('d.m.Y') }}
                    bis {{ $championship->qualification_end->format('d.m.Y') }}
                </p>
            </div>
            <div class="flex gap-2">
                <flux:button href="{{ route('championships.development', $championship) }}"
                             variant="ghost" size="sm">Förderansicht
                </flux:button>
                <flux:button href="{{ route('championships.show', $championship) }}"
                             variant="ghost" size="sm">Normen
                </flux:button>
            </div>
        </div>

        <div
            class="mb-6 p-4 bg-zinc-50 dark:bg-zinc-900/40 border border-zinc-200 dark:border-zinc-700 rounded-xl text-sm text-zinc-600 dark:text-zinc-400">
            Diese Liste enthält ausschließlich Qualifikationsnachweise: reale Zeiten auf
            {{ $championship->course }}, geschwommen im Qualifikationszeitraum bei einem von World Para
            Swimming sanktionierten Wettkampf, unterhalb der MQS. Umgerechnete Kurzbahnzeiten und
            Ergebnisse, bei denen nur die MET erreicht wurde, erscheinen hier bewusst nicht — sie
            stehen in der Förderansicht.
        </div>

        @if($excluded->isNotEmpty())
            <div
                class="mb-6 p-4 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-xl">
                <p class="text-sm font-medium text-amber-700 dark:text-amber-400 mb-2">
                    {{ $excluded->count() }} Ergebnis(se) würden eine Norm erfüllen, zählen aber nicht:
                    Der Wettkampf ist nicht als WPS-anerkannt gekennzeichnet.
                </p>
                <ul class="text-xs text-amber-700 dark:text-amber-400 space-y-1 list-disc list-inside">
                    @foreach($excluded->take(15) as $zeile)
                        <li>
                            {{ $zeile->athlete->full_name }} — {{ $zeile->eventLabel }}
                            {{ $zeile->sportClass }},
                            {{ TimeParser::display($zeile->status->swimTime) }}
                            bei „{{ $zeile->status->meetName }}"
                        </li>
                    @endforeach
                </ul>
                <p class="mt-2 text-xs text-amber-700 dark:text-amber-400">
                    Ist der Wettkampf tatsächlich sanktioniert, lässt sich das beim Wettkampf selbst
                    setzen.
                </p>
            </div>
        @endif

        @forelse($groups as $bezeichnung => $zeilen)
            <div class="mb-6">
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 mb-2">
                    {{ $bezeichnung }}
                </h2>
                <div
                    class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                    <flux:table class="[&_td:first-child]:ps-4 [&_th:first-child]:ps-4">
                        <flux:table.columns>
                            <flux:table.column>Athlet</flux:table.column>
                            <flux:table.column>Verein</flux:table.column>
                            <flux:table.column>Zeit</flux:table.column>
                            <flux:table.column>Wettkampf</flux:table.column>
                            <flux:table.column>Norm</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach($zeilen as $zeile)
                                <flux:table.row>
                                    <flux:table.cell>
                                        {{ $zeile->athlete->full_name }}
                                        @if($zeile->status->exhibition)
                                            <flux:badge color="zinc" size="sm">EXH</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="text-xs">
                                        {{ $zeile->athlete->club?->name }}
                                    </flux:table.cell>
                                    <flux:table.cell class="font-mono text-xs">
                                        {{ TimeParser::display($zeile->status->swimTime) }}
                                    </flux:table.cell>
                                    <flux:table.cell class="text-xs">
                                        {{ $zeile->status->meetName }}
                                        <span class="block text-zinc-500 dark:text-zinc-400">
                                            {{ Carbon::parse($zeile->status->meetDate)->format('d.m.Y') }}
                                        </span>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge color="{{ $zeile->status->colour() }}" size="sm">
                                            {{ $zeile->status->label() }}
                                        </flux:badge>
                                        @if($zeile->status->formattedGap())
                                            <span class="block mt-0.5 font-mono text-xs text-zinc-500">
                                                {{ $zeile->status->formattedGap() }}
                                            </span>
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>
        @empty
            <div
                class="p-6 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl text-sm text-zinc-500 dark:text-zinc-400">
                Bislang hat niemand eine Norm nachweislich erfüllt.
                @if($excluded->isEmpty())
                    Falls das überrascht: Prüfe, ob bei den betreffenden Wettkämpfen die
                    WPS-Anerkennung gesetzt ist und ob die Normen der Meisterschaft gepflegt sind.
                @endif
            </div>
        @endforelse
    </div>
@endsection
