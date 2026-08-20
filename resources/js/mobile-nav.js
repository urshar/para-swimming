// noinspection JSUnresolvedReference, JSUnresolvedVariable, JSUnresolvedFunction, JSUnusedLocalSymbols
// Alpine-Magics ($refs, $nextTick) sind der Editor-Analyse unbekannt (kein Typstub für
// alpinejs); show()/hide()/trap() werden ausschließlich aus layouts/public.blade.php heraus
// aufgerufen (x-on:click, x-on:keydown.escape.window) — von dort aus für eine reine
// JS-Analyse nicht sichtbar.

/**
 * mobileNav — Aufklapp-Navigation im Seitenkopf für schmale Bildschirme.
 *
 * Der Tailkit-Baustein (m-s-main-headers-01 "Simple") markiert das Panel beim Öffnen als
 * role="dialog"/aria-modal, liefert aber weder Fokusfalle noch Fokusrückgabe noch eine
 * Escape-Bindung mit — alles Pflicht laut accessibility.md für Dialoge/Menüs. Diese Datei holt
 * das nach:
 *
 *   - show()/hide() setzen den Fokus beim Öffnen auf den ersten Link im Panel, beim Schließen
 *     zurück auf den Umschalter-Button.
 *   - trap() hält Tab/Shift+Tab innerhalb des Panels, solange es offen ist.
 *   - Escape schließt (Bindung dafür liegt im Markup, x-on:keydown.escape.window).
 */
export default function mobileNav() {
    return {
        open: false,

        show() {
            this.open = true;
            this.$nextTick(() => {
                this.$refs.panel?.querySelector('a, button')?.focus();
            });
        },

        hide() {
            this.open = false;
            this.$refs.toggle?.focus();
        },

        trap(event) {
            if (!this.open) {
                return;
            }

            const focusable = this.$refs.panel.querySelectorAll('a[href], button:not([disabled])');

            if (focusable.length === 0) {
                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },
    };
}
