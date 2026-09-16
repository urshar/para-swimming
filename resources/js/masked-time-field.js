/**
 * Alpine.js Komponente für ein einzelnes maskiertes Zeit-Eingabefeld (MM:SS.hh).
 *
 * Fasst das bisher wiederholt inline in Blade-Attributen stehende IMask-Muster
 * (x-data + x-init mit eingebettetem JS) in einer registrierten Komponente zusammen —
 * reduziert IDE-Warnungen durch in Blade eingebetteten JS-Code, siehe CLAUDE.md
 * "Alpine-Logik in separate *.js-Dateien auslagern und via Alpine.data() registrieren".
 *
 * Registrierung in resources/js/app.js:
 *   import maskedTimeField from './masked-time-field'
 *   Alpine.data('maskedTimeField', maskedTimeField)
 *
 * Verwendung in Blade (initial-Wert bereits im Anzeigeformat "MM:SS.hh" bzw. "" für leer):
 *   <div x-data="maskedTimeField(@json($initialValue))">
 *       <flux:input name="..." type="text" x-model="value" placeholder="00:00.00" autocomplete="off"/>
 *   </div>
 */
export default function maskedTimeField(initial) {
    return {
        value: initial ?? '',

        init() {
            const mask = IMask(this.$el.querySelector('input') ?? this.$el, {
                mask: '00:00.00',
                lazy: false,
                placeholderChar: '0',
            });
            mask.on('accept', () => {
                this.value = mask.value;
            });
            this.$watch('value', (v) => {
                if (mask.value !== v) mask.value = v;
            });
        },
    };
}
