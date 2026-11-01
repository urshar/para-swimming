@extends('layouts.app')

@section('title', 'WPS Point Scores importieren')

@section('content')
    <div class="max-w-2xl">
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <flux:button href="{{ route('wps.versions.index') }}" variant="primary" icon="arrow-left"
                             size="sm" title="Zurück" aria-label="Zurück"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">WPS Point Scores importieren</h1>
            </div>
        </div>

        @if($errors->any())
            <div class="mb-4 p-4 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800 rounded-xl text-sm text-red-700 dark:text-red-400">
                @foreach($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <div class="mb-4 p-4 bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-xl text-sm text-blue-700 dark:text-blue-400">
            Erwartet wird entweder die offizielle Datei von World Para Swimming (xlsx) mit dem
            Arbeitsblatt <span class="font-mono">Parameters</span> oder eine Punktetabelle im Textformat
            (txt, z. B. "WPP 2017") mit dem Abschnitt <span class="font-mono">&lt;BASETIMES&gt;</span>.
            Beide Formate enthalten ausschließlich Langbahn-Parameter (LCM); Kurzbahn-Werte werden
            separat abgeleitet. Bezeichnung, Jahr und Gültigkeit der Version folgen in der Vorschau,
            vorbelegt aus den Angaben der Datei.
        </div>

        <form method="POST" action="{{ route('wps.import.preview') }}" enctype="multipart/form-data">
            @csrf

            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Datei</h2>

                <flux:field x-data="fileUploadField()">
                    <flux:label>WPS Point Scores (.xlsx oder .txt)<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                    <flux:file-upload name="wps_file" accept=".xlsx,.txt" x-on:change="onChange">
                        <flux:file-upload.dropzone heading="Datei hierher ziehen" text="oder klicken zum Auswählen"/>
                    </flux:file-upload>
                    <p x-show="fileName" x-cloak class="mt-1 text-sm text-zinc-600 dark:text-zinc-400" x-text="fileName"></p>
                    <flux:error name="wps_file"/>
                </flux:field>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button href="{{ route('wps.versions.index') }}" variant="ghost">Abbrechen</flux:button>
                <flux:button type="submit" variant="primary">Vorschau anzeigen</flux:button>
            </div>
        </form>
    </div>
@endsection
