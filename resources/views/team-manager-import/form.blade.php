@extends('layouts.app')

@section('title', 'Team Manager übernehmen')

@section('content')
    <div class="max-w-2xl">
        <div class="flex items-center gap-2 mb-6">
            <flux:button href="{{ route('athletes.index') }}" variant="primary"
                         icon="arrow-left" size="sm" title="Zurück" aria-label="Zurück"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Team Manager übernehmen</h1>
        </div>

        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
            <form method="POST" action="{{ route('team-manager-import.upload') }}"
                  enctype="multipart/form-data" class="space-y-4">
                @csrf

                <flux:field>
                    <flux:label>Datei des Splash Team Managers (.mdb oder .accdb)</flux:label>
                    <flux:input name="team_file" type="file" accept=".mdb,.accdb"/>
                    <flux:error name="team_file"/>
                </flux:field>

                <div class="flex gap-3 pt-2">
                    <flux:button type="submit" variant="primary">Vorschau</flux:button>
                    <flux:button href="{{ route('athletes.index') }}" variant="ghost">Abbrechen</flux:button>
                </div>
            </form>
        </div>

        <div
            class="mt-6 p-4 bg-zinc-50 dark:bg-zinc-900/40 border border-zinc-200 dark:border-zinc-700 rounded-xl text-sm text-zinc-600 dark:text-zinc-400 space-y-2">
            <p class="font-medium text-zinc-700 dark:text-zinc-300">Was übernommen wird</p>
            <p>
                Vereine samt Landesverband sowie Athleten mit Stammdaten, Lizenznummer, SDMS ID, Adresse,
                Behinderungsgruppe, ÖBSV-Level (A, B, T), Sportklassen, Ausnahme-Codes, medizinischer Kontrolle
                und der Klassifizierung (Status, Datum, Ort, Mediziner, Klassifizierer).
            </p>
            <p>
                Vorhandene Vereine werden am Code erkannt, vorhandene Athleten an der Lizenznummer oder an Name und
                Geburtsdatum — ein zweiter Lauf legt nichts doppelt an. Vor dem Import zeigt die Vorschau alle
                Auffälligkeiten; unbekannte Klassifizierer müssen dort zugeordnet werden.
            </p>
            <p>
                Gelesen wird die Datei über den Access-Treiber von Windows — die Übernahme läuft deshalb nur auf
                der Dev-Umgebung.
            </p>
        </div>
    </div>
@endsection
