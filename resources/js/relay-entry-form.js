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
        /** AJAX-Endpoint für den Meldezeit-Vorschlag (Summe der Einzel-Bestzeiten). */
        relayBestTimeUrl: config.relayBestTimeUrl,
        meetCourse: config.meetCourse,
        /** create-Modus: {[event_id]: relay_count } aller wählbaren Staffel-Events —
         *  ersetzt das frühere Auslesen von data-relay-count aus dem gewählten <option>
         *  (this.$refs.eventSelect.selectedOptions[0].dataset), das mit variant="listbox"
         *  (kein natives <select> mehr) nicht mehr sicher funktioniert hätte. */
        events: config.events ?? {},
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

        // Picker-Filter (client-seitig, auf die bereits geladenen Athleten)
        filterGender: '',
        /** Mehrfach-Auswahl von Sportklassen (z. B. ['S14','S21'] gleichzeitig). */
        filterClasses: [],

        /** Meldezeit-Vorschlag je Kurs: {LCM: {year, absolute, legs}|null, SCM: …}. */
        relayBestTimes: {LCM: null, SCM: null},
        loadingRelayTimes: false,
        /** Entprell-Timer, damit schnelles Umsortieren nicht pro Schritt fetcht. */
        relayTimesTimer: null,
        /** Pro Schwimmer gewählte Bestzeit-Art für die gemischte Summe: athleteId → 'year'|'absolute'.
         *  Default (fehlender Eintrag) = 'year' (JBZ). */
        legMode: {},

        // ── Lifecycle ─────────────────────────────────────────────────────────

        async init() {
            // Vorschlag neu berechnen, sobald sich die Aufstellung (Auswahl/Reihenfolge) ändert.
            this.$watch('selectedAthletes', () => this.scheduleRelayTimes());

            // edit-Modus: Athleten direkt laden (fixedEventId gesetzt) + Vorschlag zeigen
            if (this.fixedEventId) {
                await this.loadAthletes(this.fixedEventId);
                this.scheduleRelayTimes();
            }
        },

        // ── Event-Handlers ────────────────────────────────────────────────────

        /** create-Modus: Event-Select hat sich geändert */
        async onEventChange() {
            this.selectedAthletes = [];
            this.availableAthletes = [];
            if (!this.selectedEventId) return;

            this.relayCount = parseInt(this.events[this.selectedEventId] || 4);
            this.relayBestTimes = {LCM: null, SCM: null};
            this.legMode = {}; // neues Event → neue Aufstellung, Auswahl zurücksetzen
            this.filterGender = '';
            this.filterClasses = [];

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

        /** Aktuelle Startposition (0-basiert) eines Athleten in der Aufstellung. */
        positionOf(athlete) {
            return this.selectedAthletes.findIndex(a => a.id === athlete.id);
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

        // ── Picker-Filter (Geschlecht + Sportklassen-Mehrfachauswahl) ─────────

        /** Sportklassen eines Athleten als Array (aus dem "S14, SB14, SM14"-String). */
        athleteClasses(a) {
            return (a.classes || '').split(',').map(c => c.trim()).filter(Boolean);
        },

        /** Nummer einer Sportklasse (S7→7, SB7→7, SM14→14); null wenn keine Ziffer. */
        classNumber(token) {
            const m = String(token).match(/\d+/);
            return m ? parseInt(m[0], 10) : null;
        },

        /** Distinkte Klassennummern eines Athleten (über alle Kategorien S/SB/SM). */
        athleteClassNumbers(a) {
            return [...new Set(this.athleteClasses(a).map(c => this.classNumber(c)).filter(n => n !== null))];
        },

        /** Verfügbare Athleten nach den aktiven Filtern (Geschlecht + gewählte Klassennummern). */
        filteredAvailableAthletes() {
            return this.availableAthletes.filter(a => {
                if (this.filterGender && a.gender !== this.filterGender) return false;
                if (this.filterClasses.length > 0) {
                    const numbers = this.athleteClassNumbers(a);
                    if (!this.filterClasses.some(n => numbers.includes(n))) return false;
                }
                return true;
            });
        },

        /** In der geladenen Auswahl tatsächlich vorkommende Geschlechter (für die Filter-Optionen). */
        availableGenders() {
            return [...new Set(this.availableAthletes.map(a => a.gender))].filter(Boolean).sort();
        },

        genderLabel(g) {
            return {M: 'Männlich', F: 'Weiblich', N: 'Divers'}[g] ?? g;
        },

        /** Alle vorkommenden Klassennummern, aufsteigend — angezeigt als "S<nr>"-Chips
         *  (SB/SM erscheinen nicht separat; die Nummer deckt alle Kategorien ab). */
        availableClasses() {
            const set = new Set();
            this.availableAthletes.forEach(a => this.athleteClassNumbers(a).forEach(n => set.add(n)));
            return [...set].sort((a, b) => a - b);
        },

        isClassSelected(classNumber) {
            return this.filterClasses.includes(classNumber);
        },

        toggleClass(classNumber) {
            const idx = this.filterClasses.indexOf(classNumber);
            if (idx >= 0) {
                this.filterClasses.splice(idx, 1);
            } else {
                this.filterClasses.push(classNumber);
            }
        },

        // ── Form ──────────────────────────────────────────────────────────────

        onSubmit() {
            this.submitting = true;
        },

        // ── Meldezeit-Vorschlag (Summe der Einzel-Bestzeiten) ─────────────────

        /** Entprellt den Vorschlags-Abruf (schnelles Umsortieren feuert sonst pro Schritt). */
        scheduleRelayTimes() {
            clearTimeout(this.relayTimesTimer);
            this.relayTimesTimer = setTimeout(() => {
                void this.fetchRelayTimes();
            }, 250);
        },

        async fetchRelayTimes() {
            this.relayBestTimes = {LCM: null, SCM: null};

            const eventId = this.fixedEventId || this.selectedEventId;
            if (!eventId || this.selectedAthletes.length === 0) return;

            this.loadingRelayTimes = true;
            try {
                // relayBestTimeUrl enthält bereits club_id (für Admins); event_id + die
                // Athleten IN Startreihenfolge anhängen (Position bestimmt bei Lagen den Stil).
                const sep = this.relayBestTimeUrl.includes('?') ? '&' : '?';
                const ids = this.selectedAthletes.map(a => `&athlete_ids[]=${a.id}`).join('');
                const res = await fetch(`${this.relayBestTimeUrl}${sep}event_id=${eventId}${ids}`, {
                    headers: {'Accept': 'application/json'},
                });
                if (res.ok) {
                    this.relayBestTimes = await res.json();
                } else {
                    console.error('relay-best-time fehlgeschlagen:', res.status);
                }
            } catch (e) {
                console.error('Fehler beim Laden der Staffel-Bestzeiten', e);
            } finally {
                this.loadingRelayTimes = false;
            }
        },

        /** Klick auf eine vorgeschlagene Summe (Jahres-/absolut, LCM/SCM) übernehmen. */
        applyRelayTime(course, formatted) {
            if (formatted && formatted !== 'NT') {
                this.entryTime = formatted;
                this.entryCourse = course;
            }
        },

        // ── Gemischte Summe (je Schwimmer JBZ oder ABZ) ───────────────────────

        /** Zentisekunden → Anzeige, spiegelt App\Support\TimeParser::display. */
        formatCenti(cs) {
            if (cs === null || cs === undefined) return 'NT';
            const p = n => String(n).padStart(2, '0');
            const hours = Math.floor(cs / 360000);
            const minutes = Math.floor((cs % 360000) / 6000);
            const seconds = Math.floor((cs % 6000) / 100);
            const c = cs % 100;
            return hours > 0
                ? `${p(hours)}:${p(minutes)}:${p(seconds)}.${p(c)}`
                : `${p(minutes)}:${p(seconds)}.${p(c)}`;
        },

        /** Aktive Bestzeit-Art eines Schwimmers (Default 'year' = JBZ). */
        legModeFor(athleteId) {
            return this.legMode[athleteId] === 'absolute' ? 'absolute' : 'year';
        },

        isLegMode(athlete, mode) {
            return this.legModeFor(athlete.id) === mode;
        },

        setLegMode(athlete, mode) {
            this.legMode[athlete.id] = mode;
        },

        /** Leg-Detail eines Schwimmers für den aktuell gewählten Kurs, oder null. */
        legFor(athleteId) {
            const course = this.relayBestTimes[this.entryCourse];
            if (!course || !course.legs) return null;
            return course.legs.find(l => l.athlete_id === athleteId) ?? null;
        },

        /** Label neben dem Namen, z. B. "JBZ: 01:23.45" / "ABZ: NT". */
        legTimeLabel(athlete) {
            const mode = this.legModeFor(athlete.id);
            const leg = this.legFor(athlete.id);
            const value = leg ? leg[mode].formatted : 'NT';
            return `${mode === 'absolute' ? 'ABZ' : 'JBZ'}: ${value}`;
        },

        /** Gemischte Summe für den aktuellen Kurs aus den je Schwimmer gewählten Zeiten. */
        mixedSum() {
            const course = this.relayBestTimes[this.entryCourse];
            const total = course && course.year ? course.year.total : this.relayCount;
            if (!course || !course.legs) {
                return {raw: null, formatted: 'NT', missing: total, total};
            }
            let sum = 0;
            let contributed = 0;
            for (const athlete of this.selectedAthletes) {
                const leg = course.legs.find(l => l.athlete_id === athlete.id);
                if (!leg) continue;
                const raw = leg[this.legModeFor(athlete.id)].raw;
                if (raw !== null && raw !== undefined) {
                    sum += raw;
                    contributed++;
                }
            }
            return {
                raw: contributed > 0 ? sum : null,
                formatted: contributed > 0 ? this.formatCenti(sum) : 'NT',
                missing: total - contributed,
                total,
            };
        },

        mixedSumFormatted() {
            return this.mixedSum().formatted;
        },

        mixedMissingLabel() {
            const m = this.mixedSum();
            return m.missing > 0 ? `${m.missing} von ${m.total} Zeiten fehlen` : '';
        },

        /** Gemischte Summe als Meldezeit übernehmen (Kurs bleibt der aktuell gewählte). */
        applyMixedSum() {
            const f = this.mixedSumFormatted();
            if (f && f !== 'NT') {
                this.entryTime = f;
            }
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
