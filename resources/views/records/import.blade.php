@extends('layouts.app')

@section('title', 'Rekorde importieren')

@section('content')
    <div class="max-w-xl">
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('records.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Rekorde importieren</h1>
        </div>

        @if($errors->any())
            <div
                class="mb-4 p-4 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800 rounded-xl text-sm text-red-700 dark:text-red-400">
                @foreach($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
            <form method="POST" action="{{ route('records.import.preview') }}" enctype="multipart/form-data">
                @csrf
                <flux:field>
                    <flux:label>LENEX Rekord-Datei</flux:label>
                    <flux:input type="file" name="lenex_file" accept=".lxf,.xml" required/>
                    <flux:description>.lxf oder .xml · Max. 20 MB</flux:description>
                    <flux:error name="lenex_file"/>
                </flux:field>
                <flux:button type="submit" variant="primary" icon="arrow-up-tray" class="mt-4">
                    Vorschau laden
                </flux:button>
            </form>
        </div>
    </div>
@endsection
