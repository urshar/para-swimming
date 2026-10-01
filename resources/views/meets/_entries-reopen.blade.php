@php
    use App\Http\Controllers\MeetEntriesReopenController;
    use App\Models\Meet;

    /** @var Meet $meet */
    $reopenUntil = old('until_date', $meet->isReopened() ? $meet->entries_reopened_until->format('Y-m-d') : '');
    $reopenTime = old('until_time', $meet->isReopened() ? $meet->entries_reopened_until->format('H:i') : '');
@endphp

{{-- Meldeschluss abgelaufen: Admin kann die Meldungen für alle Vereine befristet wieder öffnen (MeetEntriesReopenController).
     Links die Bedienung, rechts der Status; auf schmalen Bildschirmen steht der Status oben. --}}
<div class="grid gap-4 lg:grid-cols-2 mb-6">
    <section aria-labelledby="entries-reopen-heading"
             class="lg:order-1 order-2 p-4 bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700">
        <h2 id="entries-reopen-heading" class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">
            Für Vereine wieder öffnen
        </h2>

        <form method="POST" action="{{ route('meets.entries-reopen.store', $meet) }}" class="mt-3 flex flex-wrap gap-2">
            @csrf
            @foreach(MeetEntriesReopenController::QUICK_HOURS as $hours)
                <flux:button type="submit" name="hours" value="{{ $hours }}" variant="filled" icon="lock-open"
                             class="text-blue-500!">
                    {{ $hours }} h öffnen
                </flux:button>
            @endforeach
        </form>

        <form method="POST" action="{{ route('meets.entries-reopen.store', $meet) }}"
              class="mt-4 flex flex-wrap items-end gap-2">
            @csrf
            <flux:field>
                <flux:label>Öffnen bis Datum</flux:label>
                <flux:date-picker type="input" locale="de-AT" selectable-header name="until_date"
                                  value="{{ $reopenUntil }}" min="{{ now()->format('Y-m-d') }}"
                                  :invalid="$errors->has('until_date')"/>
            </flux:field>
            <flux:field>
                <flux:label>Uhrzeit</flux:label>
                <flux:time-picker type="input" time-format="24-hour" locale="de-AT" name="until_time"
                                  value="{{ $reopenTime }}" :invalid="$errors->has('until_time')"/>
            </flux:field>
            <flux:button type="submit" variant="primary" icon="lock-open">
                Öffnen
            </flux:button>
        </form>
        <flux:error name="until_date"/>
        <flux:error name="until_time"/>
        <flux:error name="hours"/>
    </section>

    <section aria-labelledby="entries-deadline-heading"
             class="lg:order-2 order-1 p-4 bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700">
        <h2 id="entries-deadline-heading" class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">
            Meldeschluss
        </h2>
        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
            Abgelaufen am {{ $meet->entries_deadline->format('d.m.Y') }}.
        </p>
        <p class="mt-2">
            @if($meet->isReopened())
                <flux:badge color="green" size="sm">
                    Für Vereine wieder geöffnet bis {{ $meet->entries_reopened_until->format('d.m.Y H:i') }} Uhr
                </flux:badge>
            @else
                <flux:badge color="zinc" size="sm">Für Vereine geschlossen</flux:badge>
            @endif
        </p>
        @if($meet->entries_reopened_at)
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-2">
                Zuletzt wiedereröffnet am {{ $meet->entries_reopened_at->format('d.m.Y H:i') }} Uhr
                @if($meet->entriesReopenedBy)
                    von {{ $meet->entriesReopenedBy->name }}
                @endif
                @unless($meet->isReopened())
                    (bis {{ $meet->entries_reopened_until?->format('d.m.Y H:i') }} Uhr)
                @endunless
            </p>
        @endif
        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">
            Meldungen, die Vereine oder Admins nach Meldeschluss neu anlegen, gelten als Nachmeldung.
        </p>

        @if($meet->isReopened())
            <form method="POST" action="{{ route('meets.entries-reopen.destroy', $meet) }}" class="mt-3">
                @csrf
                @method('DELETE')
                <flux:button type="submit" variant="filled" icon="lock-closed" class="text-red-500!">
                    Jetzt schließen
                </flux:button>
            </form>
        @endif
    </section>
</div>
