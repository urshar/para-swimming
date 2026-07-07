@extends('layouts.app')

@section('title', 'Basiswerte importieren')

@section('content')
    <div class="max-w-xl">
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('base-times.import') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Basiswerte importieren</h1>
        </div>

        @if(session('success'))
            <div
                class="mb-4 p-4 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800 rounded-xl text-sm text-green-700 dark:text-green-400">
                {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div
                class="mb-4 p-4 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800 rounded-xl text-sm text-red-700 dark:text-red-400">
                @foreach($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('base-times.import.preview') }}" enctype="multipart/form-data">
            @csrf

            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Version</h2>

                <flux:field>
                    <flux:label>Bezeichnung *</flux:label>
                    <flux:input name="label" placeholder="z.B. 2021–2026" value="{{ old('label') }}" required/>
                    <flux:error name="label"/>
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Gültig ab *</flux:label>
                        <flux:input name="valid_from" type="date" value="{{ old('valid_from') }}" required/>
                        <flux:error name="valid_from"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Gültig bis <span class="font-normal text-zinc-400">(optional)</span></flux:label>
                        <flux:input name="valid_until" type="date" value="{{ old('valid_until') }}"/>
                        <flux:error name="valid_until"/>
                    </flux:field>
                </div>
            </div>

            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 mb-4">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-4">Excel-Datei</h2>

                <flux:field>
                    <flux:label>World-Aquatics-Basiswert-Datei *</flux:label>
                    <input type="file" name="base_time_file" accept=".xlsx" required
                           class="block w-full text-sm text-zinc-600 dark:text-zinc-400
                                  file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0
                                  file:bg-blue-50 file:text-blue-700 dark:file:bg-blue-950/30 dark:file:text-blue-400
                                  file:cursor-pointer cursor-pointer"/>
                    <flux:error name="base_time_file"/>
                    <flux:description>.xlsx · Max. 20 MB</flux:description>
                </flux:field>
            </div>

            <flux:button type="submit" variant="primary" icon="arrow-up-tray">
                Datei analysieren
            </flux:button>
        </form>
    </div>
@endsection
