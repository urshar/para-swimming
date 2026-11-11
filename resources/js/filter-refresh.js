/**
 * Aktualisiert eine Filter-Liste, ohne die Seite neu zu laden.
 *
 * Bisher schickten die Auto-Submit-Filter (indexFilters, entriesCockpitFilters, qualificationFilters)
 * das Formular per $el.submit() ab. Die Seite lud neu, der Fokus war danach nicht mehr im Suchfeld,
 * und was während des Ladens getippt wurde, ging verloren. Stattdessen holt diese Funktion dieselbe
 * URL per fetch und tauscht nur die Bereiche aus, die vom Filter abhängen. Das Formular selbst bleibt
 * stehen, damit bleiben Fokus, Cursor und laufende Eingaben erhalten.
 *
 * Welche Bereiche ausgetauscht werden, legt die View fest: jedes Element mit
 * data-filter-region="<name>" wird durch das gleichnamige Element der neuen Antwort ersetzt (Tabelle
 * samt Seitennavigation, Kennzahlen-Kacheln, der "Zurücksetzen"-Knopf usw.). Ein Bereich muss deshalb
 * IMMER gerendert werden, auch wenn er leer ist (z. B. der "Zurücksetzen"-Knopf ohne aktiven Filter:
 * leerer Wrapper mit class="contents"). Fehlt ein Bereich in der Antwort, die Antwort ist kein 200
 * oder wurde umgeleitet (z. B. abgelaufene Sitzung → Login), lädt die Seite ganz normal neu.
 *
 * Neu eingesetzte Elemente initialisiert Alpine selbst (MutationObserver), Flux' Custom Elements
 * ebenso (connectedCallback). Die Adresszeile wird per history.replaceState nachgezogen, damit
 * Neuladen, Lesezeichen und die gemerkte Listen-URL (remember.list) den aktuellen Filter kennen.
 */

// Laufende Anfrage: eine neue Filteränderung bricht die vorige ab, damit eine langsamere ältere
// Antwort nie ein neueres Ergebnis überschreibt.
let pending = null;

export default async function refreshFilterResults(form) {
    const url = new URL(form.action, window.location.href);
    url.search = new URLSearchParams(new FormData(form)).toString();

    const regions = [...document.querySelectorAll('[data-filter-region]')];

    if (regions.length === 0) {
        window.location.assign(url);

        return;
    }

    pending?.abort();
    const controller = new AbortController();
    pending = controller;

    regions.forEach((region) => region.setAttribute('aria-busy', 'true'));

    let html;

    try {
        const response = await fetch(url, {
            signal: controller.signal,
            headers: {Accept: 'text/html'},
            credentials: 'same-origin',
        });

        if (!response.ok || response.redirected) {
            window.location.assign(response.redirected ? response.url : url);

            return;
        }

        html = await response.text();
    } catch (error) {
        if (error.name !== 'AbortError') {
            window.location.assign(url);
        }

        return;
    }

    if (controller !== pending) {
        return;
    }

    pending = null;

    const doc = new DOMParser().parseFromString(html, 'text/html');
    const replacements = regions.map((region) => [
        region,
        doc.querySelector(`[data-filter-region="${CSS.escape(region.dataset.filterRegion)}"]`),
    ]);

    if (replacements.some(([, fresh]) => fresh === null)) {
        window.location.assign(url);

        return;
    }

    replacements.forEach(([region, fresh]) => region.replaceWith(document.importNode(fresh, true)));

    window.history.replaceState(window.history.state, '', url);

    announce(form);
}

/**
 * Sagt die Aktualisierung über eine unsichtbare Statusmeldung an (aria-live="polite", siehe
 * docs/accessibility.md), weil der Screenreader den ausgetauschten Bereich sonst nicht bemerkt.
 */
function announce(form) {
    let status = form.querySelector('[data-filter-status]');

    if (status === null) {
        status = document.createElement('p');
        status.className = 'sr-only';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        status.dataset.filterStatus = '';
        form.append(status);
    }

    // Erst leeren, dann neu setzen: derselbe Text zweimal hintereinander würde sonst nicht erneut
    // angesagt.
    status.textContent = '';
    window.setTimeout(() => {
        status.textContent = 'Liste aktualisiert.';
    }, 100);
}
