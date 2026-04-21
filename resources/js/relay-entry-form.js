/**
 * Alpine.js Komponente für Staffelmeldung-Formulare (create + edit).
 *
 * Registrierung in resources/js/app.js:
 *   import relayEntryForm from './relay-entry-form'
 *   Alpine.data('relayEntryForm', relayEntryForm)
 *
 * Verwendung in Blade:
 *   x-data="relayEntryForm({ ... config ... })"
 */
export default function relayEntryForm(config) {
    return {
        // ── Von PHP initialisiert ─────────────────────────────────────────────
        relayAthletesUrl: config.relayAthletesUrl,
        meetCourse: config.meetCourse,
        relayCount: config.relayCount ?? 4,
        entryTime: config.entryTime ?? '',
        entryCourse: config.entryCourse ?? '',
        selectedAthletes: config.selectedAthletes ?? [],
        /** Nur edit: Event-ID ist fix, Athleten werden sofort beim Init geladen */
        fixedEventId: config.fixedEventId ?? null,
        /** Nur edit: eigene RelayEntry-ID, damit eigene Mitglieder wählbar bleiben */
        relayEntryId: config.relayEntryId ?? null,

        // ── Interner Zustand ──────────────────────────────────────────────────
        selectedEventId: config.selectedEventId ?? '',
        availableAthletes: [],
        loadingAthletes: false,
        submitting: false,

        // ── Lifecycle ─────────────────────────────────────────────────────────

        async init() {
            // edit-Modus: Athleten direkt laden (fixedEventId gesetzt)
            if (this.fixedEventId) {
                await this.loadAthletes(this.fixedEventId);
            }
        },

        // ── Event-Handlers ────────────────────────────────────────────────────

        /** create-Modus: Event-Select hat sich geändert */
        async onEventChange() {
            this.selectedAthletes = [];
            this.availableAthletes = [];
            if (!this.selectedEventId) return;

            // relay_count aus data-relay-count Attribut des gewählten <option> lesen
            const opt = this.$refs.eventSelect.selectedOptions[0];
            this.relayCount = parseInt(opt.dataset.relayCount || 4);

            await this.loadAthletes(this.selectedEventId);
        },

        // ── Athleten-Picker ───────────────────────────────────────────────────

        toggleAthlete(athlete) {
            const idx = this.selectedAthletes.findIndex(a => a.id === athlete.id);
            if (idx >= 0) {
                this.selectedAthletes.splice(idx, 1);
            } else if (this.selectedAthletes.length < this.relayCount) {
                this.selectedAthletes.push(athlete);
            }
        },

        isSelected(athlete) {
            return this.selectedAthletes.some(a => a.id === athlete.id);
        },

        moveUp(index) {
            if (index === 0) return;
            [this.selectedAthletes[index - 1], this.selectedAthletes[index]] =
                [this.selectedAthletes[index], this.selectedAthletes[index - 1]];
            this.selectedAthletes = [...this.selectedAthletes];
        },

        moveDown(index) {
            if (index >= this.selectedAthletes.length - 1) return;
            [this.selectedAthletes[index], this.selectedAthletes[index + 1]] =
                [this.selectedAthletes[index + 1], this.selectedAthletes[index]];
            this.selectedAthletes = [...this.selectedAthletes];
        },

        // ── Form ──────────────────────────────────────────────────────────────

        onSubmit() {
            this.submitting = true;
        },

        // ── Privat ────────────────────────────────────────────────────────────

        async loadAthletes(eventId) {
            this.loadingAthletes = true;
            try {
                // relayAthletesUrl enthält bereits club_id als Parameter (für Admins)
                // daher event_id mit & anhängen wenn bereits Parameter vorhanden
                const separator = this.relayAthletesUrl.includes('?') ? '&' : '?';
                const relayParam = this.relayEntryId ? `&relay_entry_id=${this.relayEntryId}` : '';
                const res = await fetch(`${this.relayAthletesUrl}${separator}event_id=${eventId}${relayParam}`, {
                    headers: {'Accept': 'application/json'},
                });
                if (!res.ok) {
                    console.error('Athleten laden fehlgeschlagen:', res.status, await res.text());
                    this.availableAthletes = [];
                    return;
                }
                this.availableAthletes = await res.json();
            } catch (e) {
                console.error('Fehler beim Laden der Athleten', e);
            } finally {
                this.loadingAthletes = false;
            }
        },
    };
}
