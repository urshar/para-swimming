@extends('layouts.app')

@section('title', 'Normen importieren')

@section('content')
    <div class="max-w-2xl">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 mb-2">Normen importieren</h1>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-6">{{ $championship->display_name }}</p>

        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
            <form method="POST" action="{{ route('championships.import.preview', $championship) }}"
                  enctype="multipart/form-data" class="space-y-4">
                @csrf

                <flux:field>
                    <flux:label>WPS-Normdatei (.xlsx)</flux:label>
                    <flux:input name="standards_file" type="file" accept=".xlsx,.xls"/>
                    <flux:error name="standards_file"/>
                </flux:field>

                <div class="flex gap-3 pt-2">
                    <flux:button type="submit" variant="primary">Vorschau</flux:button>
                    <flux:button href="{{ route('championships.show', $championship) }}" variant="ghost">
                        Abbrechen
                    </flux:button>
                </div>
            </form>
        </div>

        <div
            class="mt-6 p-4 bg-zinc-50 dark:bg-zinc-900/40 border border-zinc-200 dark:border-zinc-700 rounded-xl text-sm text-zinc-600 dark:text-zinc-400 space-y-2">
            <p class="font-medium text-zinc-700 dark:text-zinc-300">Erwarteter Aufbau</p>
            <p>
                Zeile 1 Titel, Zeile 2 die Überschriften Events, Class, Men, Women, Zeile 3 die
                Unterüberschriften MQS und MET. Ab Zeile 4 die Daten in den Spalten A–F.
            </p>
            <p>
                Der Import füllt ausschließlich MQS und MET. ÖBSV-Prozentsätze und -Zeiten bleiben
                unberührt — ein erneuter Import überschreibt eure Festlegungen also nicht.
            </p>
            <p>
                Das Format der WPS-Dateien ändert sich von Veröffentlichung zu Veröffentlichung.
                Wird es nicht erkannt, bricht der Import mit einer Meldung ab; die Normen lassen
                sich dann über die Normtabelle von Hand pflegen.
            </p>
        </div>
    </div>
@endsection
