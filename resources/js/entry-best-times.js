/**
 * Alpine.js Komponente für die admin-seitige Einzelmeldungserfassung (entries/form.blade.php).
 *
 * Vereint zwei Dinge im selben Scope:
 *   1. Club-Vorbelegung: beim Athleten-Wechsel wird der meldende Club automatisch auf den
 *      aktuellen Verein des Athleten gesetzt (bleibt änderbar) — via athleteClubMap.
 *   2. Bestzeiten-Panel: Jahres- + absolute Bestzeit (LCM/SCM) für den gewählten Athleten +
 *      die gewählte Disziplin; Klick auf eine Zeit übernimmt sie als Meldezeit + Bahnlänge.
 *
 * Der Endpoint (EntryController::bestTimes) ist NICHT club-scoped — Admins dürfen jeden
 * Athleten melden. Struktur identisch zu single-entry-form.js (Vereinsmeldung).
 *
 * Registrierung in app.js: Alpine.data('entryBestTimes', entryBestTimes)
 * Verwendung: x-data='entryBestTimes(@json($config))'
 */
export default function entryBestTimes(config) {
    return {
        bestTimesUrl: config.bestTimesUrl,
        athleteClubMap: config.athleteClubMap ?? {},

        athleteId: config.athleteId ?? '',
        clubId: config.clubId ?? '',
        selectedEventId: config.eventId ?? '',
        entryTime: config.entryTime ?? '',
        entryCourse: config.entryCourse ?? '',

        bestTimes: {LCM: null, SCM: null},
        loadingTimes: false,

        init() {
            this.$watch('athleteId', id => {
                // Meldenden Club automatisch auf den aktuellen Verein des Athleten setzen.
                if (this.athleteClubMap[id]) {
                    this.clubId = String(this.athleteClubMap[id]);
                }
                void this.fetchTimes();
            });
            this.$watch('selectedEventId', () => this.fetchTimes());

            // Bereits vorbelegte Auswahl (entries/edit mit fixem Athlet+Disziplin, oder
            // create nach Validierungsfehler) sofort laden.
            void this.fetchTimes();
        },

        async fetchTimes() {
            this.bestTimes = {LCM: null, SCM: null};
            if (!this.athleteId || !this.selectedEventId) return;

            this.loadingTimes = true;
            try {
                const sep = this.bestTimesUrl.includes('?') ? '&' : '?';
                const res = await fetch(
                    `${this.bestTimesUrl}${sep}event_id=${this.selectedEventId}&athlete_id=${this.athleteId}`,
                    {headers: {'Accept': 'application/json'}}
                );
                if (res.ok) {
                    this.bestTimes = await res.json();
                } else {
                    console.error('best-times fehlgeschlagen:', res.status);
                }
            } catch (e) {
                console.error('Fehler beim Laden der Bestzeiten', e);
            } finally {
                this.loadingTimes = false;
            }
        },

        // Klick auf eine angezeigte Zeit (Jahres- oder absolute Bestzeit, LCM/SCM) übernehmen.
        applyTime(course, formatted) {
            if (formatted && formatted !== 'NT') {
                this.entryTime = formatted;
                this.entryCourse = course;
            }
        },
    };
}
