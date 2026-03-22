@extends('layouts.app')

@section('title', 'Rekorde importieren')

@section('content')
    <div class="max-w-xl">
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('records.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Rekorde importieren</h1>
        </div>
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
            <form method="POST" action="{{ route('records.import.store') }}" enctype="multipart/form-data">
                @csrf
                <flux:field>
                    <flux:label>LENEX Rekord-Datei</flux:label>
                    <flux:input type="file" name="lenex_file" accept=".lxf,.xml" required/>
                    <flux:description>.lxf oder .xml · Max. 20 MB</flux:description>
                    <flux:error name="lenex_file"/>
                </flux:field>
                <flux:button type="submit" variant="primary" icon="arrow-up-tray" class="mt-4">Importieren</flux:button>
            </form>
        </div>
    </div>
@endsection
