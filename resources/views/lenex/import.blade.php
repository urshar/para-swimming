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
             importing: false,
             filename: '',
             handleDrop(event) {
                 this.dragging = false;
                 const file = event.dataTransfer.files[0];
                 if (file) {
                     document.getElementById('lenex-file-input').files = event.dataTransfer.files;
                     this.filename = file.name;
                 }
             },
             handleFileChange(event) {
                 this.filename = event.target.files[0]?.name || '';
             },
             handleSubmit() {
                 if (!this.filename) return;
                 this.importing = true;
                 document.getElementById('lenex-import-form').submit();
             }
         }">

            {{-- Lade-Overlay --}}
            <div x-show="importing"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 class="text-center py-8">
                <svg class="w-12 h-12 mx-auto mb-4 text-blue-600 dark:text-blue-400 animate-spin"
                     fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10"
                            stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor"
                          d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                <p class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-1">Import läuft…</p>
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-1">
                    <span x-text="filename" class="font-mono"></span>
                </p>
                <p class="text-xs text-zinc-400">
                    Bitte warten — Wettkampf, Sessions und Disziplinen werden verarbeitet.
                </p>
            </div>

            {{-- Import-Formular --}}
            <form id="lenex-import-form"
                  method="POST"
                  action="{{ route('lenex.import.store') }}"
                  enctype="multipart/form-data"
                  x-show="!importing"
                  @submit.prevent="handleSubmit()">
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
                        LENEX Datei hierher ziehen oder
                        <span class="text-blue-600 dark:text-blue-400 font-medium">auswählen</span>
                    </p>
                    <p class="text-xs text-zinc-400">.lxf oder .xml · Max. 20 MB</p>
                    <input type="file"
                           id="lenex-file-input"
                           name="lenex_file"
                           accept=".lxf,.lef,.xml"
                           class="hidden"
                           @change="handleFileChange($event)">
                </div>

                {{-- Dateiname --}}
                <p class="text-sm text-center mt-2 min-h-5"
                   :class="filename ? 'text-zinc-700 dark:text-zinc-300 font-medium' : 'text-zinc-400'">
                    <span x-text="filename || ''"></span>
                </p>

                <flux:error name="lenex_file" class="mt-2"/>

                {{-- Import-Typen --}}
                <div class="mt-5 space-y-2">
                    <p class="text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                        Erkannte Import-Typen
                    </p>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-zinc-50 dark:bg-zinc-700/50 rounded-lg p-3">
                            <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Struktur</div>
                            <div class="text-xs text-zinc-400 mt-0.5">Meet + Sessions + Events</div>
                        </div>
                        <div class="bg-zinc-50 dark:bg-zinc-700/50 rounded-lg p-3">
                            <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Meldungen</div>
                            <div class="text-xs text-zinc-400 mt-0.5">+ Clubs, Athleten, Meldungen</div>
                        </div>
                        <div class="bg-zinc-50 dark:bg-zinc-700/50 rounded-lg p-3">
                            <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Ergebnisse</div>
                            <div class="text-xs text-zinc-400 mt-0.5">+ Ergebnisse, Splits, Plätze</div>
                        </div>
                        <div
                            class="bg-zinc-50 dark:bg-zinc-700/50 rounded-lg p-3 border border-zinc-200 dark:border-zinc-600">
                            <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Rekorde</div>
                            <div class="text-xs text-zinc-400 mt-0.5">Separater Import</div>
                            <a href="{{ route('records.import') }}"
                               class="inline-block mt-2 text-xs text-blue-600 dark:text-blue-400 hover:underline">
                                Zu Rekorde importieren →
                            </a>
                        </div>
                    </div>
                </div>

                {{-- Submit --}}
                <flux:button
                    type="submit"
                    variant="primary"
                    icon="arrow-up-tray"
                    class="w-full mt-5"
                    x-bind:disabled="!filename">
                    Datei importieren
                </flux:button>

                <p
                    x-show="!filename"
                    class="text-xs text-center text-zinc-400 mt-2"
                >Bitte zuerst eine Datei auswählen</p>
            </form>
        </div>
    </div>

@endsection
