import Alpine from 'alpinejs';
import Precognition from 'laravel-precognition-alpine';
import IMask from 'imask';
import relayEntryForm from './relay-entry-form';
import singleEntryForm from './single-entry-form';

window.Alpine = Alpine;
window.IMask = IMask;

Alpine.data('relayEntryForm', relayEntryForm);
Alpine.data('singleEntryForm', singleEntryForm);
Alpine.plugin(Precognition);

Alpine.start();
