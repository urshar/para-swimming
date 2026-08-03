/**
 * Typdeklarationen für die Alpine.js-Komponenten des Projekts.
 *
 * Hintergrund
 * -----------
 * Alpine-Komponenten werden zur Laufzeit über `Alpine.data(name, factory)` registriert
 * (siehe resources/js/app.js) und in Blade über `x-data="name({ … })"` verwendet. Für die
 * IDE ist dieser Name statisch nicht auflösbar — sie meldet in jeder betroffenen View
 * "Missing import statement".
 *
 * Diese Datei deklariert die Komponenten als globale Funktionen. Sie hat KEINE Auswirkung
 * zur Laufzeit und wird von Vite nicht gebündelt; sie dient ausschließlich der statischen
 * Analyse.
 *
 * Neue Alpine-Komponente angelegt?
 * --------------------------------
 * 1. Datei unter resources/js/ anlegen
 * 2. in resources/js/app.js importieren und im alpine:init-Listener registrieren
 * 3. hier deklarieren
 *
 * Was diese Datei NICHT löst
 * --------------------------
 * Meldungen wie "Element is not exported" bei `x-show="wps"` oder `x-model="course"`.
 * Diese Ausdrücke werden von Alpine im Scope der Komponenteninstanz ausgewertet, den es
 * zur Analysezeit nicht gibt. Diese Meldungen lassen sich nur über die
 * Inspektionseinstellungen der IDE abschalten (siehe README-Abschnitt unten).
 */

/** Konfiguration für singleEntryForm — Einzelmeldungs-Formular. */
interface SingleEntryFormConfig {
    eligibleUrl: string;
    bestTimesUrl: string;
    meetCourse: string;
    selectedEventId?: string;
    selectedAthleteId?: string;
    entryTime?: string;
    entryCourse?: string;
}

/** Konfiguration für relayEntryForm — Staffelmeldungs-Formular. */
interface RelayEntryFormConfig {
    relayAthletesUrl: string;
    meetCourse: string;
    relayCount?: number;
    fixedEventId?: number;
    relayEntryId?: number;
    selectedEventId?: string;
    selectedAthletes?: unknown[];
    entryTime?: string;
    entryCourse?: string;
}

/** Konfiguration für meetPointSystems — Abschnitt "Punkteberechnung" im Wettkampf-Formular. */
interface MeetPointSystemsConfig {
    /** Ist das Punktesystem WPS für diesen Wettkampf angehakt? */
    wpsSelected?: boolean;
    /** Bahnlänge des Wettkampfs, z. B. LCM oder SCM. */
    course?: string;
}

declare function singleEntryForm(config: SingleEntryFormConfig): Record<string, unknown>;

declare function relayEntryForm(config: RelayEntryFormConfig): Record<string, unknown>;

declare function meetPointSystems(config: MeetPointSystemsConfig): Record<string, unknown>;

/**
 * Kein `export` in dieser Datei: nur eine globale Skriptdatei — also eine ohne import oder
 * export — stellt ihre Deklarationen global bereit. Mit einem export würde sie zum Modul,
 * und genau die Auflösung, um die es hier geht, wäre wieder weg.
 */
interface Window {
    /** Livewire bringt seine eigene Alpine-Instanz mit; siehe Kommentar in app.js. */
    Alpine: {
        data(name: string, factory: (...args: never[]) => unknown): void;
        plugin(plugin: unknown): void;
    };

    IMask: unknown;
}
