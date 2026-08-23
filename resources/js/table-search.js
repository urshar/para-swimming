/**
 * tableSearch — Suchfeld für Ranglisten (Cup-Wertung, Jahresbestleistungen), das ausschließlich
 * die bereits geladene Tabelle filtert (Spec public-frontend §2.3 Punkt 3: "Suchfelder in
 * Ranglisten filtern nur die bereits geladene Tabelle. Keine serverseitige Volltextsuche über
 * Personen.") — kein Reload, kein Request.
 *
 * Zeilen tragen ihren durchsuchbaren Text serverseitig vorbereitet in data-search (Name + Verein,
 * kleingeschrieben), damit hier keine Blade-Werte in JS-Stringliterale eingebettet werden müssen
 * (Anführungszeichen in Namen wie "O'Brien" wären sonst ein Escaping-Risiko). Ohne JavaScript
 * bleibt x-show wirkungslos — alle Zeilen stehen einfach in der Tabelle (progressive enhancement).
 */
export default function tableSearch() {
    return {
        query: '',

        matches(searchText) {
            const needle = this.query.trim().toLowerCase();

            return needle === '' || (searchText ?? '').includes(needle);
        },
    };
}
