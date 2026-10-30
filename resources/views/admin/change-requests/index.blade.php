{{-- admin/change-requests/index — Eingang der Anträge (Spec club-permissions §4.5), Filter nach Status. --}}
@php use App\Models\ChangeRequest; @endphp

@extends('layouts.app')

@section('title', 'Anträge')

@section('content')

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Anträge</h1>
    </div>

    <nav aria-label="Status" class="flex flex-wrap gap-2 mb-4">
        @foreach(ChangeRequest::STATUS_LABELS + ['ALL' => 'alle'] as $value => $label)
            <flux:button size="sm" href="{{ route('admin.change-requests.index', ['status' => $value]) }}"
                         :variant="$status === $value ? 'primary' : 'filled'"
                         aria-current="{{ $status === $value ? 'page' : 'false' }}">
                {{ ucfirst($label) }}
            </flux:button>
        @endforeach
    </nav>

    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden p-4 [--flux-bleed:1rem]">
    <flux:table bleed>
        <flux:table.columns>
            <flux:table.column>Gestellt</flux:table.column>
            <flux:table.column>Art</flux:table.column>
            <flux:table.column>Athlet</flux:table.column>
            <flux:table.column>Verein</flux:table.column>
            <flux:table.column>Status</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse($changeRequests as $changeRequest)
                <flux:table.row>
                    <flux:table.cell class="text-sm text-zinc-500">{{ $changeRequest->created_at?->format('d.m.Y H:i') }}</flux:table.cell>
                    <flux:table.cell>{{ $changeRequest->type_label }}</flux:table.cell>
                    <flux:table.cell class="font-medium text-zinc-900 dark:text-zinc-100">{{ $changeRequest->athlete?->display_name }}</flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500">{{ $changeRequest->club?->display_name ?? '–' }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$changeRequest->status_color">{{ $changeRequest->status_label }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:button href="{{ route('admin.change-requests.show', $changeRequest) }}" size="sm"
                                     variant="ghost" icon="eye" title="Antrag ansehen" aria-label="Antrag ansehen"/>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center py-8 text-zinc-400">Keine Anträge.</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
    </div>

    <div class="mt-4">{{ $changeRequests->links() }}</div>

@endsection
