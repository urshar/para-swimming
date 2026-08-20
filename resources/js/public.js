import Alpine from 'alpinejs';
import theme from './theme';
import mobileNav from './mobile-nav';

// Eigener Vite-Entry ohne Livewire (Spec public-frontend §3.1): Anders als in app.js gibt es
// hier keine bereits gestartete Alpine-Instanz, an die angedockt werden könnte — Alpine muss
// also selbst gestartet werden. Das steht im Gegensatz zu CLAUDE.md's Hinweis auf app.js, der
// genau diesen Fall betrifft: eine zweite Instanz neben der von Livewire mitgebrachten. Da der
// öffentliche Bereich kein Livewire lädt, entfällt der Konflikt.
window.Alpine = Alpine;

document.addEventListener('alpine:init', () => {
    Alpine.data('theme', theme);
    Alpine.data('mobileNav', mobileNav);
});

Alpine.start();
