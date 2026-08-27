/**
 * fileUploadField — zeigt bei flux:file-upload (Flux Pro) den Namen der zuletzt gewählten Datei
 * an, unabhängig davon ob per Klick oder per Drag & Drop ausgewählt wurde.
 *
 * flux:file-upload versteckt das eigentliche <input type="file"> (sr-only) und übernimmt anders
 * als ein sichtbares natives <input type="file"> keine eigene Namensanzeige — flux:file-item
 * liefert nur die Optik einer Datei-Karte, aber keine Bindung an die aktuell gewählte Datei.
 *
 * event.target.files ist hier ein Array (kein FileList), weil <ui-file-upload> die gewählten
 * Dateien intern selbst verwaltet (siehe flux-file-upload-sync.js) — [0]-Zugriff funktioniert
 * bei beidem gleich.
 */
export default function fileUploadField() {
    return {
        fileName: null,

        onChange(event) {
            this.fileName = event.target.files[0]?.name ?? null;
        },
    };
}
