{{-- Meine Anträge (Spec club-permissions §4.5): Anträge des eigenen Vereins mit Status und Antwort des Verbands. --}}
@extends('layouts.app')

@section('title', 'Meine Anträge')

@section('content')

    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2">
            <flux:button href="{{ route('athletes.index') }}" variant="primary" icon="arrow-left" size="sm"
                         title="Zurück" aria-label="Zurück"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Meine Anträge</h1>
        </div>
        <flux:button href="{{ route('change-requests.transfer.create') }}" variant="primary" icon="arrows-right-left">
            Ummeldung beantragen
        </flux:button>
    </div>

    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden p-4 [--flux-bleed:1rem]">
    <flux:table bleed>
        <flux:table.columns>
            <flux:table.column>Gestellt</flux:table.column>
            <flux:table.column>Art</flux:table.column>
            <flux:table.column>Athlet</flux:table.column>
            <flux:table.column>Status</flux:table.column>
            <flux:table.column>Antwort des Verbands</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse($changeRequests as $changeRequest)
                <flux:table.row>
                    <flux:table.cell class="text-sm text-zinc-500">{{ $changeRequest->created_at?->format('d.m.Y') }}</flux:table.cell>
                    <flux:table.cell>{{ $changeRequest->type_label }}</flux:table.cell>
                    <flux:table.cell>
                        @if($changeRequest->athlete)
                            <x-athlete-link :athlete="$changeRequest->athlete">{{ $changeRequest->athlete->display_name }}</x-athlete-link>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$changeRequest->status_color">{{ $changeRequest->status_label }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500 whitespace-normal">{{ $changeRequest->response ?: '–' }}</flux:table.cell>
                    <flux:table.cell>
                        @can('withdraw', $changeRequest)
                            <form method="POST" action="{{ route('change-requests.withdraw', $changeRequest) }}">
                                @csrf
                                <flux:button type="submit" size="sm" variant="ghost">Zurückziehen</flux:button>
                            </form>
                        @endcan
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center py-8 text-zinc-400">Noch keine Anträge gestellt.</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
    </div>

    <div class="mt-4">{{ $changeRequests->links() }}</div>

@endsection
