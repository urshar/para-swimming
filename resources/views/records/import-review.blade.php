@php
    use App\Models\ImportReviewItem;
    use App\Support\TimeParser;
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
        <div class="flex items-center gap-2">
            {{-- Löscht nur Erledigte; Ignorierte bleiben als Merker, sonst kämen sie beim nächsten Prüflauf wieder. --}}
            <form method="POST" action="{{ route('records.import-review.purge') }}"
                  x-data="{ submit() { if (confirm('Alle erledigten Einträge löschen? Ignorierte bleiben erhalten.')) this.$el.submit() } }"
                  @submit.prevent="submit()">
                @csrf
                <flux:button type="submit" icon="trash" size="sm" variant="ghost">Erledigte löschen</flux:button>
            </form>
            <form method="POST" action="{{ route('records.import-review.scan') }}">
                @csrf
                <flux:button type="submit" icon="magnifying-glass" size="sm">Bestand prüfen</flux:button>
            </form>
        </div>
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
        <li>
            <strong>Abweichung zur Rekordliste:</strong> Die importierte Liste ist maßgeblich. Ihr Eintrag widerspricht
            Rekorden in der Datenbank — einem schnelleren Rekord am selben Tag oder davor, oder einem nicht schnelleren
            danach. Der Import hat dafür nichts geändert. "Liste übernehmen" entfernt die widersprechenden Rekorde und
            hängt den Listeneintrag nach Datum in die Rekord-Historie.
        </li>
        <li>
            <strong>Staffelrekord ohne Verein:</strong> Ein nationaler oder regionaler Staffelrekord hat keinen Verein
            und keine Mitglieder. Passt ein Staffelergebnis (gleiches Datum, gleiche Zeit, gleicher Bewerb), übernimmt
            "Mit Staffelergebnis verknüpfen" Verein und Mitglieder; sonst beim Rekord ergänzen und als geprüft markieren.
        </li>
        <li>"Bestand prüfen" wendet diese Prüfungen (außer der Rekordliste) auch auf bereits gespeicherte Rekorde an.</li>
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
                    <flux:table.column>Athlet / Rekord</flux:table.column>
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
                            $isListMismatch = $item->type === ImportReviewItem::TYPE_LIST_MISMATCH;
                            $isRelayNoClub = $item->type === ImportReviewItem::TYPE_RELAY_NO_CLUB;
                            $removes = $item->removesRecord();
                            $deletes = $item->deletesRecords();
                            $candidate = $candidates->get($item->id);
                            $typeColor = match (true) {
                                $isConflict => 'blue',
                                $isNationality => 'red',
                                $isRegional => 'orange',
                                $isListMismatch => 'fuchsia',
                                $isRelayNoClub => 'cyan',
                                default => 'violet',
                            };
                            $applyLabel = match (true) {
                                $isConflict => 'Verein übernehmen',
                                $removes => 'Rekord entfernen',
                                $isListMismatch => 'Liste übernehmen',
                                $candidate !== null => 'Mit Staffelergebnis verknüpfen',
                                default => 'Geprüft',
                            };
                            $confirmText = $isListMismatch
                                ? 'Rekordliste übernehmen? Die widersprechenden Rekorde werden entfernt.'
                                : 'Rekord entfernen? Die Rekord-Historie wird neu verknüpft.';
                        @endphp
                        <flux:table.row>
                            <flux:table.cell class="font-medium whitespace-normal">
                                @if($item->athlete_id !== null)
                                    <a href="{{ route('athletes.show', $item->athlete_id) }}"
                                       class="text-zinc-900 dark:text-zinc-100 hover:underline">{{ $item->athlete?->display_name }}</a>
                                @elseif($item->swimRecord)
                                    <a href="{{ route('records.show', $item->swim_record_id) }}"
                                       class="text-zinc-900 dark:text-zinc-100 hover:underline">{{ $details['label'] ?? 'Rekord' }}</a>
                                @else
                                    {{ $item->currentClub?->display_name ?? ($details['label'] ?? '—') }}
                                @endif
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
                                @elseif($isListMismatch)
                                    <div>
                                        <span class="text-zinc-500 dark:text-zinc-400">Liste:</span>
                                        <span
                                            class="font-medium tabular-nums">{{ TimeParser::display($details['list']['swim_time'] ?? 0) }}</span>
                                        @if(!empty($details['list']['set_date']))
                                            · {{ Carbon::parse($details['list']['set_date'])->format('d.m.Y') }}
                                        @endif
                                        @if(!empty($details['list']['meet_name']))
                                            · {{ $details['list']['meet_name'] }}
                                        @endif
                                    </div>
                                    <div class="text-zinc-500 dark:text-zinc-400">widerspricht:</div>
                                    <ul class="ps-4 list-disc">
                                        @foreach($details['contradictions'] ?? [] as $contradiction)
                                            <li>
                                                <a href="{{ route('records.show', $contradiction['id']) }}"
                                                   class="tabular-nums hover:underline">{{ TimeParser::display($contradiction['swim_time']) }}</a>
                                                @if(!empty($contradiction['date']))
                                                    · {{ Carbon::parse($contradiction['date'])->format('d.m.Y') }}
                                                @endif
                                                @if(!empty($contradiction['holder']))
                                                    · {{ $contradiction['holder'] }}
                                                @endif
                                                @if($contradiction['is_current'] ?? false)
                                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">(aktuell)</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $details['label'] ?? '' }}</div>
                                @elseif($isRelayNoClub)
                                    <div>
                                        <span
                                            class="font-medium tabular-nums">{{ TimeParser::display($details['swim_time'] ?? 0) }}</span>
                                        @if(!empty($details['date']))
                                            · {{ Carbon::parse($details['date'])->format('d.m.Y') }}
                                        @endif
                                        @if(!empty($details['meet_name']))
                                            · {{ $details['meet_name'] }}
                                        @endif
                                    </div>
                                    @if($candidate)
                                        <div>
                                            <span class="text-zinc-500 dark:text-zinc-400">Staffelergebnis:</span>
                                            <a href="{{ route('meets.show', $candidate->meet_id) }}"
                                               class="font-medium hover:underline">{{ $candidate->club?->display_name }}</a>
                                            · {{ $candidate->meet?->name }}
                                        </div>
                                    @elseif($item->isOpen())
                                        <div class="text-zinc-500 dark:text-zinc-400">
                                            Kein passendes Staffelergebnis —
                                            @if($item->swimRecord)
                                                <a href="{{ route('records.edit', $item->swim_record_id) }}"
                                                   class="underline">Verein und Mitglieder beim Rekord ergänzen</a>.
                                            @else
                                                Rekord gelöscht.
                                            @endif
                                        </div>
                                    @endif
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ ($details['is_current'] ?? false) ? 'aktueller Rekord' : 'Historie' }}
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
                                              @if($deletes)
                                                  data-confirm="{{ $confirmText }}"
                                                  x-data="{ submit() { if (confirm(this.$el.dataset.confirm)) this.$el.submit() } }"
                                                  @submit.prevent="submit()"
                                              @endif>
                                            @csrf
                                            <flux:button type="submit" size="xs"
                                                         :variant="$deletes ? 'danger' : 'primary'"
                                                         :icon="$deletes ? 'trash' : 'check'">
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
