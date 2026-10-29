@extends('layouts.app')

@section('title', 'Noch kein Zugriff')

@section('content')
    <div class="max-w-xl">
        <flux:heading size="xl" level="1">Noch kein Bereich freigegeben</flux:heading>

        <flux:text class="mt-3">
            Ihr Konto ist eingerichtet, aber es sind Ihnen noch keine Bereiche freigegeben. Bitte wenden Sie sich an
            den ÖBSV, damit Ihnen die passenden Rechte zugewiesen werden.
        </flux:text>

        <div class="mt-6">
            <flux:button href="{{ route('profile.edit') }}" icon="cog">Zu den Einstellungen</flux:button>
        </div>
    </div>
@endsection
