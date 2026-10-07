@php
    use App\Models\ImportReviewItem;
    use Illuminate\Support\Carbon;
@endphp

@extends('layouts.app')

@section('title', 'Prüfliste Rekordimport')

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div class="flex items-center gap-2">
            <flux:button href="{{ $backUrl }}" variant="primary" icon="arrow-left" size="sm"
                         title="Zurück" aria-label="Zurück zu den Rekorden"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Prüfliste Rekordimport</h1>
            @if($openCount > 0)
                <flux:badge color="amber" size="sm">{{ $openCount }} offen</flux:badge>
            @endif
        </div>
        <form method="POST" action="{{ route('records.import-review.scan') }}">
            @csrf
            <flux:button type="submit" icon="magnifying-glass" size="sm">Bestand prüfen</flux:button>
        </form>
    </div>

    <ul class="text-sm text-zinc-500 dark:text-zinc-400 mb-4 max-w-3xl space-y-1 list-disc ps-5">
        <li>
            <strong>Vereinskonflikt:</strong> Der Verein laut Rekord weicht vom Stammverein des Athleten ab (maßgeblich
            ist der jüngste Einzelrekord, sonst die jüngste nationale Staffel; Rekorde für Verbände wie den ÖBSV zählen
            nicht). Nicht aufgenommen wird ein Fall, wenn der Athlet danach nachweislich schon für den aktuellen Verein
            angetreten ist (Eintritt oder Wettkampfergebnis). "Verein übernehmen" trägt einen Vereinswechsel zum
            Rekorddatum ein.
        </li>
        <li>
            <strong>Geburtsdatum abweichend:</strong> Ein Athlet aus der Datei wurde einer bestehenden Person mit anderem
            Geburtsdatum zugeordnet (z. B. Jahrgang ohne Tag/Monat) — bitte die Zuordnung kontrollieren.
        </li>
        <li>
            <strong>Nationalität nicht AUT:</strong> Nationale und regionale Rekorde gibt es nur für österreichische
            Athleten. "Rekord entfernen" löscht den Rekord und verknüpft die Rekord-Historie neu (ein Vorgänger wird
            wieder aktuell). Ist die Nationalität falsch eingetragen, stattdessen ignorieren und beim Athleten korrigieren.
        </li>
        <li>
            <strong>Regionalrekord: falscher Verband:</strong> Der Regionalrekord passt nicht zum Landesverband des
            Vereins, für den er geschwommen wurde (früher leitete die Rekordprüfung den Verband vom aktuellen Verein des
            Athleten ab). "Rekord entfernen" löscht ihn; danach auf dem Wettkampf "Rekorde prüfen" erneut starten, damit
            der richtige Regionalrekord entsteht.
        </li>
        <li>"Bestand prüfen" wendet diese Prüfungen auch auf bereits gespeicherte Rekorde an.</li>
    </ul>

    {{-- Filter als Links: keine Formular-Selects nötig, Zustand steht in der URL. --}}
    <div class="flex flex-wrap items-center gap-x-6 gap-y-2 mb-4">
        <nav class="flex flex-wrap gap-1" aria-label="Status">
            @foreach($statuses as $value => $label)
                <flux:button size="sm" :variant="$status === $value ? 'filled' : 'ghost'"
                             href="{{ route('records.import-review.index', ['status' => $value, 'type' => $type]) }}"
                             :aria-current="$status === $value ? 'page' : null">{{ $label }}</flux:button>
            @endforeach
        </nav>
        <nav class="flex flex-wrap gap-1" aria-label="Art">
            @foreach($types as $value => $label)
                <flux:button size="sm" :variant="$type === $value ? 'filled' : 'ghost'"
                             href="{{ route('records.import-review.index', ['status' => $status, 'type' => $value]) }}"
                             :aria-current="$type === $value ? 'page' : null">{{ $label }}</flux:button>
            @endforeach
        </nav>
    </div>

    <div
        class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden p-4 [--flux-bleed:1rem]">
        @if($items->isEmpty())
            <p class="text-sm text-zinc-500 dark:text-zinc-400 py-6 text-center">Keine Einträge.</p>
        @else
            <flux:table bleed>
                <flux:table.columns>
                    <flux:table.column>Athlet</flux:table.column>
                    <flux:table.column>Art</flux:table.column>
                    <flux:table.column>Befund</flux:table.column>
                    <flux:table.column>Quelle</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column class="text-right">Aktionen</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($items as $item)
                        @php
                            $details = $item->details ?? [];
                            $isConflict = $item->type === ImportReviewItem::TYPE_CLUB_CONFLICT;
                            $isNationality = $item->type === ImportReviewItem::TYPE_NATIONALITY;
                            $isRegional = $item->type === ImportReviewItem::TYPE_REGIONAL;
                            $removes = $item->removesRecord();
                            $typeColor = match (true) {
                                $isConflict => 'blue',
                                $isNationality => 'red',
                                $isRegional => 'orange',
                                default => 'violet',
                            };
                            $applyLabel = $isConflict ? 'Verein übernehmen' : ($removes ? 'Rekord entfernen' : 'Geprüft');
                        @endphp
                        <flux:table.row>
                            <flux:table.cell class="font-medium">
                                <a href="{{ route('athletes.show', $item->athlete_id) }}"
                                   class="text-zinc-900 dark:text-zinc-100 hover:underline">{{ $item->athlete?->display_name }}</a>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm"
                                            :color="$typeColor">{{ $item->type_label }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-normal text-sm">
                                @if($isConflict)
                                    <div>
                                        <span class="text-zinc-500 dark:text-zinc-400">Stammverein:</span>
                                        {{ $item->currentClub?->display_name ?? '—' }}
                                    </div>
                                    <div>
                                        <span class="text-zinc-500 dark:text-zinc-400">laut Rekord:</span>
                                        <span
                                            class="font-medium">{{ $item->lenexClub?->display_name ?? '(gelöscht)' }}</span>
                                    </div>
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ ($details['relay'] ?? false) ? 'Staffel' : 'Einzel' }}:
                                        {{ $details['label'] ?? '' }}
                                        @if(!empty($details['date']))
                                            · {{ Carbon::parse($details['date'])->format('d.m.Y') }}
                                        @endif
                                    </div>
                                @elseif($removes)
                                    @if($isNationality)
                                        <div>
                                            <span class="text-zinc-500 dark:text-zinc-400">Nationalität:</span>
                                            <span class="font-medium">{{ $details['nation'] ?? '' }}</span>
                                        </div>
                                    @else
                                        <div>
                                            <span class="text-zinc-500 dark:text-zinc-400">Rekord-Verein:</span>
                                            {{ $item->currentClub?->display_name ?? '—' }}
                                            ({{ $details['expected'] ?? '' }})
                                        </div>
                                        <div>
                                            <span class="text-zinc-500 dark:text-zinc-400">eingetragen als:</span>
                                            <span class="font-medium">{{ $details['record_type'] ?? '' }}</span>
                                        </div>
                                        @if(!empty($details['meet_id']))
                                            <div class="text-xs">
                                                <a href="{{ route('meets.show', $details['meet_id']) }}"
                                                   class="text-zinc-500 dark:text-zinc-400 hover:underline">{{ $details['meet_name'] ?? 'Wettkampf' }}</a>
                                            </div>
                                        @endif
                                    @endif
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                        @if($item->swimRecord)
                                            <a href="{{ route('records.show', $item->swim_record_id) }}"
                                               class="hover:underline">{{ $details['label'] ?? '' }}</a>
                                        @else
                                            {{ $details['label'] ?? '' }}
                                        @endif
                                        @if(!empty($details['date']))
                                            · {{ Carbon::parse($details['date'])->format('d.m.Y') }}
                                        @endif
                                        · {{ ($details['is_current'] ?? false) ? 'aktueller Rekord' : 'Historie' }}
                                    </div>
                                @else
                                    <div>
                                        <span class="text-zinc-500 dark:text-zinc-400">Datei:</span>
                                        {{ $details['file_name'] ?? '' }},
                                        {{ !empty($details['file_birth_date']) ? Carbon::parse($details['file_birth_date'])->format('d.m.Y') : 'ohne Geburtsdatum' }}
                                    </div>
                                    <div>
                                        <span class="text-zinc-500 dark:text-zinc-400">Datenbank:</span>
                                        {{ !empty($details['db_birth_date']) ? Carbon::parse($details['db_birth_date'])->format('d.m.Y') : 'ohne Geburtsdatum' }}
                                    </div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400 whitespace-normal">
                                {{ $item->source }}
                                <div class="text-xs">{{ $item->created_at?->format('d.m.Y') }}</div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm"
                                            :color="$item->isOpen() ? 'amber' : ($item->status === ImportReviewItem::STATUS_APPLIED ? 'emerald' : 'zinc')">
                                    {{ $item->status_label }}
                                </flux:badge>
                                @if(!$item->isOpen() && $item->resolvedBy)
                                    <div
                                        class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">{{ $item->resolvedBy->name }}</div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-right">
                                @if($item->isOpen())
                                    <div class="flex items-center justify-end gap-1">
                                        {{-- Rekord entfernen löscht Daten: mit Rückfrage (wie beim Löschen in den Listen). --}}
                                        <form method="POST" action="{{ route('records.import-review.apply', $item) }}"
                                              @if($removes)
                                                  x-data="{ submit() { if (confirm('Rekord entfernen? Die Rekord-Historie wird neu verknüpft.')) this.$el.submit() } }"
                                                  @submit.prevent="submit()"
                                              @endif>
                                            @csrf
                                            <flux:button type="submit" size="xs"
                                                         :variant="$removes ? 'danger' : 'primary'"
                                                         :icon="$removes ? 'trash' : 'check'">
                                                {{ $applyLabel }}
                                            </flux:button>
                                        </form>
                                        <form method="POST" action="{{ route('records.import-review.ignore', $item) }}">
                                            @csrf
                                            <flux:button type="submit" size="xs" variant="ghost">Ignorieren
                                            </flux:button>
                                        </form>
                                    </div>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    <div class="mt-4">
        {{ $items->links() }}
    </div>
@endsection
