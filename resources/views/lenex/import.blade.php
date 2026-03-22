@extends('layouts.app')

@section('title', 'LENEX Import')

@section('content')
    <div class="max-w-xl">
        <div class="flex items-center gap-3 mb-6">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">LENEX Import</h1>
        </div>

        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6"
             x-data="{
             dragging: false,
             handleDrop(event) {
                 this.dragging = false;
                 document.getElementById('lenex-file-input').files = event.dataTransfer.files;
             }
         }">

            <form method="POST" action="{{ route('lenex.import.store') }}" enctype="multipart/form-data">
                @csrf

                {{-- Drop Zone --}}
                <div
                    class="border-2 border-dashed rounded-xl p-10 text-center transition-colors cursor-pointer"
                    :class="dragging
                    ? 'border-blue-500 bg-blue-50 dark:bg-blue-950/20'
                    : 'border-zinc-300 dark:border-zinc-600 hover:border-blue-400'"
                    @dragover.prevent="dragging = true"
                    @dragleave.prevent="dragging = false"
                    @drop.prevent="handleDrop($event)"
                    @click="document.getElementById('lenex-file-input').click()"
                >
                    <svg class="w-10 h-10 mx-auto mb-3 text-zinc-400" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                              d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                    <p class="text-zinc-600 dark:text-zinc-400 mb-1">
                        LENEX Datei hierher ziehen oder <span class="text-blue-600 dark:text-blue-400 font-medium">auswählen</span>
                    </p>
                    <p class="text-xs text-zinc-400">.lxf oder .xml · Max. 20 MB</p>
                    <input type="file" name="lenex_file" id="lenex-file-input" accept=".lxf,.xml" class="hidden"
                           @change="$el.closest('form').querySelector('[data-filename]').textContent = $el.files[0]?.name || ''">
                </div>

                <p class="text-sm text-center text-zinc-500 mt-2" data-filename></p>
                <flux:error name="lenex_file" class="mt-2"/>

                {{-- Info --}}
                <div class="mt-5 space-y-2">
                    <p class="text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">Erkannte
                        Import-Typen</p>
                    <div class="grid grid-cols-3 gap-3">
                        @foreach(['Struktur' => 'Meet + Sessions + Events', 'Meldungen' => '+ Clubs, Athleten, Meldungen', 'Ergebnisse' => '+ Ergebnisse, Splits'] as $type => $desc)
                            <div class="bg-zinc-50 dark:bg-zinc-700/50 rounded-lg p-3">
                                <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $type }}</div>
                                <div class="text-xs text-zinc-400 mt-0.5">{{ $desc }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <flux:button type="submit" variant="primary" icon="arrow-up-tray" class="w-full mt-5">
                    Datei importieren
                </flux:button>
            </form>
        </div>
    </div>
@endsection
