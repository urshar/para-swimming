// noinspection JSUnresolvedReference, JSUnresolvedVariable
// Alpine-Magics ($refs, $el) sind der Editor-Analyse unbekannt (kein Typstub für alpinejs).

/**
 * navDropdown — Untermenü in der Kopfzeile (aktuell "Punkte": Punktetabelle + zwei Rechner).
 * accessibility.md verlangt für Dropdowns/Menüs explizit Pfeiltasten, Escape, aria-expanded und
 * Fokusrückgabe — hier umgesetzt nach dem WAI-ARIA-Disclosure-Muster (Links, keine Aktionen,
 * daher role="menu" bewusst NICHT verwendet — das wäre das schwerere "menu button"-Muster für
 * Befehle, nicht für Navigation).
 *
 * Progressiv verbessert: Der Trigger ist ohne JS ein ganz normaler Link auf die erste
 * Untermenü-Seite (kein <button> ohne Funktion), das Panel steht ohne JS einfach offen im
 * Fließtext — alle drei Ziele bleiben also auch ohne JavaScript direkt erreichbar.
 */
export default function navDropdown() {
    return {
        open: false,

        toggle() {
            this.open ? this.close() : this.openMenu();
        },

        openMenu() {
            this.open = true;
            this.$nextTick(() => this.$refs.panel?.querySelector('a')?.focus());
        },

        close() {
            if (!this.open) {
                return;
            }
            this.open = false;
            this.$refs.trigger?.focus();
        },

        onTriggerKeydown(event) {
            if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                this.openMenu();
            }
        },

        onPanelKeydown(event) {
            const items = Array.from(this.$refs.panel.querySelectorAll('a'));
            const index = items.indexOf(document.activeElement);

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                items[(index + 1) % items.length]?.focus();
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                items[(index - 1 + items.length) % items.length]?.focus();
            } else if (event.key === 'Tab' && !event.shiftKey && index === items.length - 1) {
                this.open = false;
            }
        },
    };
}
