/**
 * theme — Hell/Dunkel/Systemumschalter für den öffentlichen Bereich (Spec public-frontend §3.4).
 *
 * Gespeichert wird der gewählte Modus ('light' | 'dark' | 'system'), nicht nur das daraus
 * abgeleitete Hell/Dunkel — sonst ließe sich "folgt dem System" nach einem Reload nicht mehr
 * von einer bewussten Wahl unterscheiden.
 *
 * Das eigentliche Umschalten vor dem ersten Rendern übernimmt ein Inline-Skript im <head> von
 * layouts/public.blade.php (verhindert das kurze Aufblitzen der hellen Darstellung, bevor
 * dieses Skript geladen ist). Diese Komponente hält danach nur den Umschalter und die
 * Systemänderung synchron.
 *
 * "flux.appearance" wird zusätzlich mitgeschrieben (nie gelesen als führender Wert, nur als
 * Fallback beim Start): Login/Registrierung und der Admin-Bereich laufen über Flux' eigenes
 * Hell/Dunkel-System mit diesem Key. Ohne den Mitschrieb wäre ein hier gewählter Modus beim
 * Wechsel z. B. auf die Login-Seite verloren und würde auf die Systemeinstellung zurückfallen.
 */
export default function theme() {
    return {
        mode: localStorage.getItem('theme') ?? localStorage.getItem('flux.appearance') ?? 'system',
        media: window.matchMedia('(prefers-color-scheme: dark)'),

        init() {
            this.apply();
            this.media.addEventListener('change', () => {
                if (this.mode === 'system') {
                    this.apply();
                }
            });
        },

        set(mode) {
            this.mode = mode;
            localStorage.setItem('theme', mode);
            localStorage.setItem('flux.appearance', mode);
            this.apply();
        },

        isDark() {
            return this.mode === 'dark' || (this.mode === 'system' && this.media.matches);
        },

        apply() {
            document.documentElement.classList.toggle('dark', this.isDark());
        },
    };
}
