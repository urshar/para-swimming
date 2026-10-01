@php
    use App\Models\Meet;

    /** @var Meet $meet */
    /** @var bool $canManage */
@endphp

{{-- Meldeschluss-Status der Vereinsmeldungen (Einzel + Staffel). Nur nach Ablauf des Meldeschlusses sichtbar. --}}
@if($meet->isDeadlinePassed())
    @if($meet->isReopened())
        <div role="status" class="mb-4 p-3 bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800
                    rounded-xl text-sm text-blue-700 dark:text-blue-300">
            Meldeschluss war am {{ $meet->entries_deadline->format('d.m.Y') }}.
            Nachmeldungen sind möglich bis {{ $meet->entries_reopened_until->format('d.m.Y H:i') }} Uhr.
            Neu angelegte Meldungen gelten als Nachmeldung, dafür kann eine Nachmeldegebühr anfallen.
        </div>
    @elseif($canManage)
        <div role="status" class="mb-4 p-3 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-700
                    rounded-xl text-sm text-amber-700 dark:text-amber-400">
            Meldeschluss war am {{ $meet->entries_deadline->format('d.m.Y') }}.
            Als Admin können Sie weiterhin melden; neu angelegte Meldungen gelten als Nachmeldung.
        </div>
    @else
        <div role="status" class="mb-4 p-3 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-700
                    rounded-xl text-sm text-amber-700 dark:text-amber-400">
            Meldeschluss war am {{ $meet->entries_deadline->format('d.m.Y') }}.
            Änderungen sind nicht mehr möglich.
        </div>
    @endif
@endif
