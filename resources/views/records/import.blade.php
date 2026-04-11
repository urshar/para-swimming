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

        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6"
             x-data="{
                 dragging: false,
                 uploading: false,
                 filename: '',
                 handleDrop(event) {
                     this.dragging = false;
                     const file = event.dataTransfer.files[0];
                     if (file) {
                         document.getElementById('record-file-input').files = event.dataTransfer.files;
                         this.filename = file.name;
                     }
                 },
                 handleFileChange(event) {
                     this.filename = event.target.files[0]?.name || '';
                 },
                 handleSubmit() {
                     if (!this.filename) return;
                     this.uploading = true;
                     document.getElementById('record-import-form').submit();
                 }
             }">

            {{-- Lade-Overlay --}}
            <div x-show="uploading"
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
                <p class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-1">Datei wird analysiert…</p>
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-1">
                    <span x-text="filename" class="font-mono"></span>
                </p>
                <p class="text-xs text-zinc-400">Bitte warten — Rekorde werden ausgelesen.</p>
            </div>

            {{-- Import-Formular --}}
            <form id="record-import-form"
                  method="POST"
                  action="{{ route('records.import.preview') }}"
                  enctype="multipart/form-data"
                  x-show="!uploading"
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
                    @click="document.getElementById('record-file-input').click()">

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
                           id="record-file-input"
                           name="lenex_file"
                           accept=".lxf,.xml"
                           class="hidden"
                           @change="handleFileChange($event)">
                </div>

                {{-- Dateiname --}}
                <p class="text-sm text-center mt-2 min-h-5"
                   :class="filename ? 'text-zinc-700 dark:text-zinc-300 font-medium' : 'text-zinc-400'">
                    <span x-text="filename || ''"></span>
                </p>

                <flux:error name="lenex_file" class="mt-1"/>

                {{-- Submit --}}
                <flux:button
                    type="submit"
                    variant="primary"
                    icon="arrow-up-tray"
                    class="w-full mt-5"
                    x-bind:disabled="!filename">
                    Vorschau anzeigen
                </flux:button>

                <p x-show="!filename"
                   class="text-xs text-center text-zinc-400 mt-2">
                    Bitte zuerst eine Datei auswählen
                </p>

            </form>
        </div>
    </div>
@endsection
