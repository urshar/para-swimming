// noinspection JSUnresolvedReference

/**
 * baseTimeMobileClass — Sportklassen-Auswahl der mobilen Einzelansicht (unter dem sm-Breakpoint,
 * ersetzt die Matrix — accessibility.md "Reflow"). Eigene Alpine.data()-Komponente statt eines
 * inline object-literal x-data="{ ... }": Ein bei Statement-Position stehendes "{" lässt sich als
 * reines JS-Snippet nicht eindeutig als Objektliteral erkennen (kollidiert mit einem
 * Block-Statement) und erzeugt IDE-Rauschen — CLAUDE.md verlangt ohnehin ausgelagerte
 * Alpine-Logik. Aus demselben Grund liest die Komponente ihren Anfangswert aus
 * data-initial-class statt aus einem x-data-Argument (siehe base-time-tabs.js).
 */
export default function baseTimeMobileClass() {
    return {
        mobileClass: null,

        init() {
            this.mobileClass = this.$el.dataset.initialClass;
        },
    };
}
