/**
 * Alpine.js Komponente für das Dokumenten-Formular im Adminbereich (Spec public-frontend §6).
 *
 * Registrierung in resources/js/app.js:
 *   import documentForm from './document-form'
 *   Alpine.data('documentForm', documentForm)
 *
 * Verwendung in Blade:
 *   x-data="documentForm({ category: 'INVITATION', candidates: { INVITATION: [...] } })"
 *
 * Aufgaben:
 *   1. "Sprachvariante zu"-Feld auf die Kandidaten der aktuell gewählten Kategorie
 *      beschränken (candidates kommt aus PHP, gruppiert nach Kategorie).
 *   2. Hinweistext einblenden, wenn eine LENEX-Datei (.lxf/.lef/.xml) zur Kategorie
 *      INVITATION hochgeladen wird (§4.3) — unabhängig davon, ob zuerst die Datei gewählt
 *      oder zuerst die Kategorie umgeschaltet wird.
 */
export default function documentForm(config) {
    return {
        category: config.category ?? '',
        candidates: config.candidates ?? {},
        fileExtension: '',

        get currentCandidates() {
            return this.candidates[this.category] ?? [];
        },

        get showLenexHint() {
            return this.category === 'INVITATION' && ['lxf', 'lef', 'xml'].includes(this.fileExtension);
        },

        onFileChange(event) {
            const fileName = event.target.files[0]?.name ?? '';
            this.fileExtension = fileName.includes('.') ? fileName.split('.').pop().toLowerCase() : '';
        },
    };
}
