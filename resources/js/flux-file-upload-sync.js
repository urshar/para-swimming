/**
 * flux-file-upload-sync — spiegelt bei flux:file-upload (Flux Pro) per Drag & Drop abgelegte
 * Dateien auf das eigentliche, im <form> übermittelte <input type="file"> zurück.
 *
 * flux:file-upload hält ausgewählte Dateien intern in einem JS-Array (für Vorschau/Chips und
 * für Livewires wire:model-Upload). Beim Klick-Weg (nativer Dateiauswahl-Dialog) setzt der
 * Browser .files auf dem versteckten echten <input> selbst — das reicht für ein normales
 * multipart/form-data-POST. Beim Drag & Drop passiert das NICHT: Flux liest die Dateien nur in
 * dieses interne Array ein, ohne sie auf das echte <input> zurückzuschreiben (geprüft im
 * kompilierten flux.js: keine einzige Verwendung von DataTransfer). Ohne diesen Fix würde eine
 * per Drag & Drop abgelegte Datei also optisch als ausgewählt erscheinen, beim Absenden des
 * (nicht-Livewire-)Formulars aber fehlen — betrifft hier die klassischen POST-Importformulare,
 * nicht wire:model-Verwendung in Livewire-Komponenten.
 *
 * flux:file-upload feuert bei jeder Änderung (Klick UND Drop) ein "change"-Event direkt auf
 * dem <ui-file-upload>-Element selbst (nicht nur auf dem inneren <input>) — genau dieses
 * Signal wird hier abgegriffen, unabhängig vom Auswahlweg.
 */
export default function initFluxFileUploadSync() {
    document.addEventListener('change', (event) => {
        const upload = event.target;
        if (!(upload instanceof HTMLElement) || !upload.matches('[data-flux-file-upload]')) {
            return;
        }

        const receiver = upload.querySelector('input[data-slot="receiver"]');
        if (!receiver) {
            return;
        }

        const transfer = new DataTransfer();
        upload.files.forEach((file) => transfer.items.add(file));
        receiver.files = transfer.files;
    });
}
