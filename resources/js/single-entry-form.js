/**
 * Alpine.js Komponente für Einzelmeldungs-Formulare (create + edit).
 *
 * Registrierung in resources/js/app.js:
 *   import singleEntryForm from './single-entry-form'
 *   Alpine.data('singleEntryForm', singleEntryForm)
 *
 * Verwendung in Blade:
 *   x-data="singleEntryForm({ ... config ... })"
 */
export default function singleEntryForm(config) {
    return {
        // ── Von PHP initialisiert ─────────────────────────────────────────────
        eligibleUrl: config.eligibleUrl,
        bestTimesUrl: config.bestTimesUrl,
        meetCourse: config.meetCourse,
        selectedEventId: config.selectedEventId ?? '',
        selectedAthleteId: config.selectedAthleteId ?? '',
        entryTime: config.entryTime ?? '',
        entryCourse: config.entryCourse ?? '',

        // ── Interner Zustand ──────────────────────────────────────────────────
        eligibleAthletes: [],
        bestTimes: {LCM: null, SCM: null},
        loadingAthletes: false,
        loadingTimes: false,
        submitting: false,

        // ── Event-Handlers ────────────────────────────────────────────────────

        async onEventChange() {
            this.selectedAthleteId = '';
            this.eligibleAthletes = [];
            this.bestTimes = {LCM: null, SCM: null};
            if (!this.selectedEventId) return;

            this.loadingAthletes = true;
            try {
                const sep = this.eligibleUrl.includes('?') ? '&' : '?';
                const res = await fetch(
                    `${this.eligibleUrl}${sep}event_id=${this.selectedEventId}`,
                    {headers: {'Accept': 'application/json'}}
                );
                if (!res.ok) {
                    console.error('eligible-athletes fehlgeschlagen:', res.status);
                    return;
                }
                this.eligibleAthletes = await res.json();
            } catch (e) {
                console.error('Fehler beim Laden der Athleten', e);
            } finally {
                this.loadingAthletes = false;
            }
        },

        async onAthleteChange() {
            this.bestTimes = {LCM: null, SCM: null};
            if (!this.selectedAthleteId || !this.selectedEventId) return;

            this.loadingTimes = true;
            try {
                const sep = this.bestTimesUrl.includes('?') ? '&' : '?';
                const res = await fetch(
                    `${this.bestTimesUrl}${sep}event_id=${this.selectedEventId}&athlete_id=${this.selectedAthleteId}`,
                    {headers: {'Accept': 'application/json'}}
                );
                if (!res.ok) {
                    console.error('best-times fehlgeschlagen:', res.status);
                    return;
                }
                this.bestTimes = await res.json();
            } catch (e) {
                console.error('Fehler beim Laden der Bestzeiten', e);
            } finally {
                this.loadingTimes = false;
            }
        },

        // ── Bestzeit übernehmen ───────────────────────────────────────────────

        applyBestTime() {
            const bt = this.bestTimes[this.meetCourse];
            if (bt && bt.formatted && bt.formatted !== 'NT') {
                this.entryTime = bt.formatted;
                this.entryCourse = this.meetCourse;
            }
        },

        // ── Form ──────────────────────────────────────────────────────────────

        onSubmit() {
            this.submitting = true;
        },
    };
}
