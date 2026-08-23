// noinspection JSUnresolvedReference, JSUnresolvedVariable, JSUnusedLocalSymbols
// Alpine-Magics ($refs, $el) sind der Editor-Analyse unbekannt (kein Typstub für alpinejs).

/**
 * baseTimeTabs — Reiternavigation zwischen den fünf Lagen-Tabellen der Punktetabelle
 * (accessibility.md: "Reiternavigation mit role=tablist, Pfeiltastenbedienung und
 * aria-selected").
 *
 * Progressiv verbessert statt JS-Pflicht: Die Tab-"Buttons" sind im Markup echte
 * <a href="#panel- …">-Sprunglinks, alle fünf Panels stehen ohne JS ungefiltert im Fließtext
 * (x-show wirkt nur, wenn Alpine läuft — ohne JS bleibt es folgenlos, ein Klick springt einfach
 * zum jeweiligen Abschnitt). Mit JS übernimmt diese Komponente: nur das aktive Panel sichtbar,
 * volle role=tablist/tab/tabpanel-Semantik, Pfeiltasten wandern zwischen den Reitern und
 * aktivieren automatisch (APG "Automatic Activation").
 *
 * Liest den anfangs aktiven Reiter aus data-initial-stroke statt aus einem x-data-Argument —
 * ein per Blade in ein Inline-x-data-Argument interpoliertes '{{ $wert }}' wird von PhpStorms
 * JS-Analyse nicht zuverlässig als String-Literal erkannt (u. a. "Expression statement is not
 * assignment or call"); ein normales HTML-Attribut hat dieses Problem nicht.
 */
export default function baseTimeTabs() {
    return {
        active: null,

        init() {
            this.active = this.$el.dataset.initialStroke;
        },

        isActive(code) {
            return this.active === code;
        },

        select(code) {
            this.active = code;
            this.$nextTick(() => this.$refs[`tab-${code}`]?.focus());
        },

        onKeydown(event, codes) {
            const index = codes.indexOf(this.active);
            if (index === -1) {
                return;
            }

            let nextIndex = null;
            if (event.key === 'ArrowRight') {
                nextIndex = (index + 1) % codes.length;
            } else if (event.key === 'ArrowLeft') {
                nextIndex = (index - 1 + codes.length) % codes.length;
            } else if (event.key === 'Home') {
                nextIndex = 0;
            } else if (event.key === 'End') {
                nextIndex = codes.length - 1;
            } else {
                return;
            }

            event.preventDefault();
            this.select(codes[nextIndex]);
        },
    };
}
