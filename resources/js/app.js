import Precognition from 'laravel-precognition-alpine';
import IMask from 'imask';
import documentForm from './document-form';
import fileUploadField from './file-upload-field';
import initFluxFileUploadSync from './flux-file-upload-sync';
import maskedTimeField from './masked-time-field';
import meetPointSystems from './meet-point-systems';
import qualificationFilters from './qualification-filters';
import qualifyingTimesFilter from './qualifying-times-filter';
import relayEntryForm from './relay-entry-form';
import singleEntryForm from './single-entry-form';
import standardCell from './standard-cell';

window.IMask = IMask;

// Globaler Fix für flux:file-upload + Drag & Drop in normalen (nicht-Livewire) Formularen,
// siehe Kommentar in flux-file-upload-sync.js. Braucht kein Alpine, läuft unabhängig davon.
initFluxFileUploadSync();

// Livewire 3+ bringt seine eigene, gebündelte Alpine-Instanz mit und startet sie selbst
// (sobald @livewireScripts geladen wird). Ein zusätzlicher eigener `import Alpine from 'alpinejs'`
// + `Alpine.start()` erzeugt eine ZWEITE Instanz ("Detected multiple instances of Alpine running")
// und bricht dabei wire:model-Bindungen. Plugins/Components daher über den alpine:init-Event
// auf Livewire Instanz (window.Alpine) registrieren, bevor sie selbst startet.
document.addEventListener('alpine:init', () => {
    window.Alpine.plugin(Precognition);
    window.Alpine.data('documentForm', documentForm);
    window.Alpine.data('fileUploadField', fileUploadField);
    window.Alpine.data('maskedTimeField', maskedTimeField);
    window.Alpine.data('meetPointSystems', meetPointSystems);
    window.Alpine.data('qualificationFilters', qualificationFilters);
    window.Alpine.data('qualifyingTimesFilter', qualifyingTimesFilter);
    window.Alpine.data('relayEntryForm', relayEntryForm);
    window.Alpine.data('singleEntryForm', singleEntryForm);
    window.Alpine.data('standardCell', standardCell);
});
