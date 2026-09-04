@extends('layouts.app')

@section('title', $version->label)

@section('content')
    <div class="max-w-5xl">
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $version->label }}</h1>
                @if($version->isArchived())
                    <flux:badge color="zinc" size="sm">Archiviert</flux:badge>
                @endif
            </div>
            <div class="mt-4">
                <flux:button href="{{ route('wps.versions.index') }}" variant="filled" icon="arrow-left" size="sm">
                    Zurück
                </flux:button>
            </div>
        </div>

        @if(session('success'))
            <div class="mb-4 p-4 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800 rounded-xl text-sm text-green-700 dark:text-green-400">
                {{ session('success') }}
            </div>
        @endif

        {{-- x-model + $watch statt onchange="this.form.submit()" — dasselbe Muster wie
             records/index.blade.php: flux:select (Custom Element <ui-select>) feuert sein
             internes "change"-Event mit bubbles:false, ein @change/onchange kommt nicht
             zuverlässig an. x-model übernimmt dabei auch die Vorbelegung — :selected() auf den
             Optionen wird dadurch überflüssig. --}}
        <form method="GET"
              class="flex flex-wrap items-end gap-3 mb-4"
              x-data="{
                  gender: @js($filters['gender'] ?? ''),
                  sportClass: @js($filters['sport_class'] ?? ''),
                  course: @js($filters['course'] ?? ''),
                  submitForm() { this.$el.submit(); },
              }"
              x-init="
                  $watch('gender', () => submitForm());
                  $watch('sportClass', () => submitForm());
                  $watch('course', () => submitForm());
              ">
            <flux:field>
                <flux:label>Geschlecht</flux:label>
                <flux:select variant="listbox" name="gender" x-model="gender" class="w-32">
                    <flux:select.option value="">alle</flux:select.option>
                    <flux:select.option value="M">männlich</flux:select.option>
                    <flux:select.option value="F">weiblich</flux:select.option>
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>Sportklasse</flux:label>
                <flux:select variant="listbox" name="sport_class" x-model="sportClass" class="w-32">
                    <flux:select.option value="">alle</flux:select.option>
                    @foreach($sportClasses as $sportClass)
                        <flux:select.option value="{{ $sportClass }}">{{ $sportClass }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>Bahnlänge</flux:label>
                <flux:select variant="listbox" name="course" x-model="course" class="w-28">
                    <flux:select.option value="">alle</flux:select.option>
                    <flux:select.option value="LCM">LCM</flux:select.option>
                    <flux:select.option value="SCM">SCM</flux:select.option>
                </flux:select>
            </flux:field>

            <flux:button href="{{ route('wps.versions.show', $version) }}" variant="filled" icon="x-mark"
                         class="text-red-500!">
                Zurücksetzen
            </flux:button>
        </form>

        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Bahn</flux:table.column>
                    <flux:table.column>Geschlecht</flux:table.column>
                    <flux:table.column>Bewerb</flux:table.column>
                    <flux:table.column>Klasse</flux:table.column>
                    <flux:table.column align="end">a</flux:table.column>
                    <flux:table.column align="end">b</flux:table.column>
                    <flux:table.column align="end">c</flux:table.column>
                    <flux:table.column>Art</flux:table.column>
                </flux:table.columns>
                <flux:table.rows class="[&_td:first-child]:ps-4">
                    @foreach($parameters as $parameter)
                        <flux:table.row>
                            <flux:table.cell>{{ $parameter->course }}</flux:table.cell>
                            <flux:table.cell>{{ $parameter->gender }}</flux:table.cell>
                            <flux:table.cell>
                                {{ $parameter->distance }}m {{ $parameter->strokeType?->name_de }}
                            </flux:table.cell>
                            <flux:table.cell>{{ $parameter->sport_class }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $parameter->parameter_a }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $parameter->parameter_b }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $parameter->parameter_c }}</flux:table.cell>
                            <flux:table.cell>
                                @if($parameter->official)
                                    <flux:badge color="green" size="sm">offiziell</flux:badge>
                                @else
                                    <flux:badge color="amber" size="sm">abgeleitet</flux:badge>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>

        <div class="mt-4">
            {{ $parameters->links() }}
        </div>
    </div>
@endsection
