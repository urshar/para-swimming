@props(['athlete'])

{{-- Link auf die Athleten-Detailseite, aber nur, wenn der Benutzer den Athleten sehen darf (AthletePolicy, Spec
     club-permissions P3c-2). Sonst erscheint nur der Name — ein Klick würde mit 403 enden. --}}
@can('view', $athlete)
    <a href="{{ route('athletes.show', $athlete) }}" {{ $attributes }}>{{ $slot }}</a>
@else
    <span>{{ $slot }}</span>
@endcan
