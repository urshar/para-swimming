/**
 * standardCell — bearbeitbare Zelle in der Normtabelle (Qualifikationsnormen).
 *
 * Zuständig für zwei Feldarten:
 *   - Zeiten (MQS, MET, ÖBSV-Norm) mit IMask-Zeitmaske MM:SS.hh
 *   - den ÖBSV-Prozentsatz, ohne Maske, aber mit Komma-Eingabe
 *
 * Warum alles in JavaScript und nichts im Markup
 * -----------------------------------------------
 * flux:input rendert nicht ein nacktes <input>, sondern einen Wrapper mit einem inneren
 * Eingabefeld. Ein im Blade notiertes x-on:blur landet damit auf dem Wrapper — und weil
 * blur nicht bubbelt, wird der Handler nie ausgelöst. Genauso greift eine Maske ins Leere,
 * wenn sie an den Wrapper gebunden wird. Diese Komponente sucht deshalb das tatsächliche
 * <input> und hängt Maske und Listener direkt dort an.
 *
 * Aus demselben Grund gibt es kein x-model: Alpine schriebe dann parallel zu IMask in
 * dasselbe Feld, und die beiden überschreiben einander abwechselnd. Maßgeblich ist hier
 * immer das Eingabefeld selbst.
 *
 * Konfiguration über data-cell
 * ----------------------------
 * Die Werte stammen aus PHP. Stünden sie als Blade-Ausdrücke im x-data-Attribut, wäre dort
 * kein gültiges JavaScript mehr — die IDE parst x-data als JS und meldet jede Zelle als
 * Typfehler.
 *
 * Zeitmaske mit lazy: true (anders als bei den Meldezeiten)
 * ---------------------------------------------------------
 * Ein leeres Feld bedeutet in einer Normliste "Bewerb nicht ausgeschrieben" und ist damit
 * eine echte Information. Mit lazy: false stünde in jeder leeren Zelle "00:00.00" — eine
 * Zeit, die niemand eingetragen hat.
 */
export default function standardCell() {
    return {
        standardId: 0,
        field: '',
        lastSaved: '',
        input: null,
        mask: null,

        init() {
            const config = JSON.parse(this.$el.dataset.cell ?? '{}');

            this.standardId = config.standardId ?? 0;
            this.field = config.field ?? '';
            this.lastSaved = config.value ?? '';

            this.input = this.$el.querySelector('input');

            if (this.input === null) {
                console.error('standardCell: kein Eingabefeld gefunden', this.$el);

                return;
            }

            if (config.masked === true) {
                if (typeof window.IMask !== 'function') {
                    console.error('standardCell: IMask ist nicht geladen — siehe resources/js/app.js');
                } else {
                    this.mask = window.IMask(this.input, {
                        mask: '00:00.00',
                        lazy: true,
                        placeholderChar: '_',
                    });
                }
            }

            this.setValue(this.lastSaved);

            this.input.addEventListener('blur', () => this.commit());
            this.input.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter') {
                    return;
                }

                event.preventDefault();
                this.commit();
            });
        },

        /** Der aktuelle Feldinhalt — bei Maske deren normalisierter Wert. */
        currentValue() {
            return this.mask !== null ? this.mask.value : (this.input?.value ?? '');
        },

        setValue(wert) {
            if (this.mask !== null) {
                this.mask.value = wert ?? '';

                return;
            }

            if (this.input !== null) {
                this.input.value = wert ?? '';
            }
        },

        /**
         * Schickt den Wert an Livewire.
         *
         * Ein unveränderter Wert löst bewusst keinen Request aus: Beim Durchtabben durch
         * eine lange Liste wäre sonst jede Zelle ein Serveraufruf.
         */
        commit() {
            const wert = this.currentValue();

            if (wert === this.lastSaved) {
                return;
            }

            this.lastSaved = wert;

            this.$wire.saveCell(this.standardId, this.field, wert);
        },
    };
}
