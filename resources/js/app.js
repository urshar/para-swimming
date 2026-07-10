import Precognition from 'laravel-precognition-alpine';
import IMask from 'imask';
import relayEntryForm from './relay-entry-form';
import singleEntryForm from './single-entry-form';

window.IMask = IMask;

// Livewire 3+ bringt seine eigene, gebündelte Alpine-Instanz mit und startet sie selbst
// (sobald @livewireScripts geladen wird). Ein zusätzlicher eigener `import Alpine from 'alpinejs'`
// + `Alpine.start()` erzeugt eine ZWEITE Instanz ("Detected multiple instances of Alpine running")
// und bricht dabei wire:model-Bindungen. Plugins/Components daher über den alpine:init-Event
// auf Livewires Instanz (window.Alpine) registrieren, bevor sie selbst startet.
document.addEventListener('alpine:init', () => {
    window.Alpine.plugin(Precognition);
    window.Alpine.data('relayEntryForm', relayEntryForm);
    window.Alpine.data('singleEntryForm', singleEntryForm);
});
