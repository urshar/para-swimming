{{--
    admin/documents/form — Anlegen/Bearbeiten eines Dokuments (Spec public-frontend §6).

    documentForm() (resources/js/document-form.js) übernimmt zwei rein clientseitige
    Aufgaben: das "Sprachvariante zu"-Feld auf die Kandidaten der gewählten Kategorie
    beschränken, und den LENEX-Hinweis (§4.3) unabhängig davon einblenden, ob zuerst die
    Datei gewählt oder zuerst die Kategorie umgeschaltet wird.
--}}
@extends('layouts.app')

@section('title', $document ? 'Dokument bearbeiten' : 'Dokument hochladen')

@section('content')
    <div class="max-w-2xl">

        <div class="flex items-center gap-3 mb-6">
            <flux:button
                href="{{ $meet ? route('admin.meets.documents.index', $meet) : route('admin.documents.index') }}"
                variant="ghost" icon="arrow-left" size="sm"/>
            <div>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                    {{ $document ? 'Dokument bearbeiten' : 'Dokument hochladen' }}
                </h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                    {{ $meet ? $meet->name : 'Regelmente & Formulare — kein Veranstaltungsbezug' }}
                </p>
            </div>
        </div>

        <form method="POST"
              action="{{ $document
                  ? route('admin.documents.update', $document)
                  : ($meet ? route('admin.meets.documents.store', $meet) : route('admin.documents.store')) }}"
              enctype="multipart/form-data"
              x-data='documentForm({
                  category: "{{ old('category', $document?->category ?? '') }}",
                  candidates: @json($pairCandidates),
              })'>
            @csrf
            @if($document)
                @method('PUT')
            @endif

            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-5">

                <flux:field>
                    <flux:label>Titel *</flux:label>
                    <flux:input name="title" value="{{ old('title', $document->title ?? '') }}"
                                placeholder="z. B. Ausschreibung" required/>
                    <flux:error name="title"/>
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Kategorie *</flux:label>
                        <flux:select name="category" x-model="category" required>
                            <option value="">Bitte wählen…</option>
                            @foreach(['INVITATION', 'START_LIST', 'RESULTS', 'REGULATION', 'FORM'] as $category)
                                <option value="{{ $category }}"
                                    @selected(old('category', $document->category ?? '') === $category)>
                                    {{ __('public.documents.category.'.$category) }}
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:error name="category"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Sprache</flux:label>
                        <flux:select name="locale">
                            <option value="" @selected(old('locale', $document->locale ?? '') === '')>
                                Sprachneutral (beide Sprachen)
                            </option>
                            <option value="de" @selected(old('locale', $document->locale ?? '') === 'de')>
                                Deutsch
                            </option>
                            <option value="en" @selected(old('locale', $document->locale ?? '') === 'en')>
                                Englisch
                            </option>
                        </flux:select>
                        <flux:description>
                            Sprachneutrale Dokumente werden in beiden Sprachen gezeigt.
                        </flux:description>
                    </flux:field>
                </div>

                <flux:field x-show="currentCandidates.length > 0" x-cloak>
                    <flux:label>Sprachvariante zu</flux:label>
                    <select name="pair_with_document_id"
                            class="w-full rounded-lg border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 text-sm">
                        <option value="">Eigenständig (neue Reihenfolge)</option>
                        <template x-for="candidate in currentCandidates" :key="candidate.id">
                            <option :value="candidate.id" x-text="candidate.label"></option>
                        </template>
                    </select>
                    <flux:description>
                        Verknüpft dieses Dokument mit der bestehenden Fassung — auf der öffentlichen Seite wird die
                        passende Sprache gezeigt und die andere daneben verlinkt (§4.1).
                    </flux:description>
                </flux:field>

                <flux:field>
                    <flux:label>{{ $document ? 'Datei ersetzen' : 'Datei *' }}</flux:label>
                    <flux:file-upload name="file" x-on:change="onFileChange($event)">
                        <flux:file-upload.dropzone heading="Datei hierher ziehen" text="oder klicken zum Auswählen"/>
                    </flux:file-upload>
                    <p x-show="fileName" x-cloak class="mt-1 text-sm text-zinc-600 dark:text-zinc-400" x-text="fileName"></p>
                    @if($document)
                        @php
                            $currentFileLabel = collect([$document->formatLabel(), $document->sizeLabel()])
                                ->filter()
                                ->implode(', ');
                        @endphp
                        <flux:description>
                            Aktuell: {{ $document->title }}
                            @if($currentFileLabel)
                                ({{ $currentFileLabel }})
                            @endif
                            — leer lassen, um die bestehende Datei zu behalten.
                        </flux:description>
                    @else
                        <flux:description>PDF, DOC(X), XLS(X), ZIP oder LENEX (.lxf/.lef/.xml) · max. 20 MB.</flux:description>
                    @endif
                    <flux:error name="file"/>
                </flux:field>

                <div x-show="showLenexHint" x-cloak
                     class="p-4 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-xl text-sm text-amber-700 dark:text-amber-400">
                    Ein LENEX mit Meldungen enthält Name, Jahrgang, Sportklasse und Meldezeit aller gemeldeten
                    Personen (§4.3). Öffentlich ausgespielt werden darf ausschließlich die Wettkampfstruktur ohne
                    Meldungen — Meldungen dürfen daher nicht als "Öffentlich" markiert werden.
                </div>

                <flux:field>
                    <flux:label>Öffentlich</flux:label>
                    <div class="flex items-center gap-3 mt-1">
                        <input type="checkbox" name="is_public" value="1" id="is_public"
                               class="w-4 h-4 rounded border-zinc-300 dark:border-zinc-600
                                      text-blue-600 bg-white dark:bg-zinc-800
                                      focus:ring-blue-500 focus:ring-2"
                            {{ old('is_public', $document->is_public ?? false) ? 'checked' : '' }}>
                        <label for="is_public" class="text-sm text-zinc-700 dark:text-zinc-300 cursor-pointer">
                            Für eine öffentliche Auslieferung freigegeben
                        </label>
                    </div>
                    <flux:description>
                        Grundvoraussetzung für die Sichtbarkeit — entscheidet zusammen mit dem
                        Veröffentlichungsdatum, ob das Dokument aktuell auf der öffentlichen Seite erscheint.
                    </flux:description>
                </flux:field>

                <flux:field>
                    <flux:label>Veröffentlichungsdatum</flux:label>
                    <flux:input name="published_at" type="datetime-local"
                                value="{{ old('published_at', $document?->published_at?->format('Y-m-d\TH:i') ?? '') }}"/>
                    <flux:description>
                        Leer = Entwurf, auch wenn "Öffentlich" angehakt ist. Ein Zeitpunkt in der Zukunft
                        veröffentlicht das Dokument erst ab diesem Zeitpunkt.
                    </flux:description>
                    <flux:error name="published_at"/>
                </flux:field>

            </div>

            <div class="flex gap-3 mt-6">
                <flux:button type="submit" variant="primary">
                    {{ $document ? 'Speichern' : 'Hochladen' }}
                </flux:button>
                <flux:button
                    href="{{ $meet ? route('admin.meets.documents.index', $meet) : route('admin.documents.index') }}"
                    variant="ghost">
                    Abbrechen
                </flux:button>
            </div>
        </form>

    </div>
@endsection
