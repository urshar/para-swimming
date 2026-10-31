{{--
    Telefonnummer als tel:-Link (am Handy direkt anrufen); ohne Nummer ein Strich. Im Link nur Ziffern und "+", die
    Anzeige bleibt wie eingetragen (Spec club-permissions P10b-3).
--}}
@props(['phone'])

@if($phone)
    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}"
       class="text-blue-600 dark:text-blue-400 hover:underline">{{ $phone }}</a>
@else
    –
@endif
