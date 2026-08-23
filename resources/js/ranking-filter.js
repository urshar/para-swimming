// noinspection JSUnresolvedReference, JSUnresolvedVariable, JSUnusedLocalSymbols
// Alpine-Magics ($refs, $el) sind der Editor-Analyse unbekannt (kein Typstub für alpinejs).

/**
 * rankingFilter — ersetzt die Reiternavigation der Cup-Wertung und der Jahresbestleistungen durch
 * Dropdown-Filter (Sportklassengruppe, Geschlecht; bei der Cup-Wertung zusätzlich eine
 * "Nur Jugendwertung"-Checkbox) statt vieler untereinanderstehender Tabellen (Rückmeldung: "zu
 * viele Tabellen … ich denke, es ist besser ein Dropdown für die Behindertenklasse, eines für
 * Geschlecht und eine Checkbox für Jugend" — "Passe die Jahresbestleistungen Filter so an wie die
 * ÖBSV Cup Wertung"). Zeigt genau die eine Wertungskategorie-Tabelle, die zur aktuellen
 * Filterkombination passt.
 *
 * "Alle Klassen" (groupId = "all") und "Damen & Herren" (gender = "combined") sind KEINE
 * Platzhalter/Wildcards hier im JS — es sind ganz normale, serverseitig bereits fertig berechnete
 * und neu gerankte Sammel-Wertungskategorien wie jede andere auch (Rückmeldung: "ich meinte das
 * alle gemeinsam über die Punkte gewertet werden", nicht nur mehrere Tabellen gleichzeitig
 * anzeigen). Die eigentliche Zusammenlegung — Zeilen mehrerer Sportklassengruppen bzw. beider
 * Geschlechter zu einer einzigen, neu nach Punkten geordneten und neu gerankten Tabelle
 * (SportRankAssigner) — passiert serverseitig in
 * Public\CupRankingController::mergedGroupBrackets()/mergedGenderBrackets() bzw.
 * AnnualBestService. Dieses Skript filtert wie gehabt nur, welche der bereits vollständigen
 * Sektionen sichtbar ist.
 *
 * Ob die Jugend-Dimension existiert, wird aus `keys` abgeleitet (Jahresbestleistungen kennen keine
 * Altersgruppen, `jugend` fehlt dort in jedem Eintrag) — kein separater Konfigurationsparameter
 * nötig.
 *
 * Wie table-search.js liest isVisible() den zuordenbaren Zustand eines Tabellen-<section> über
 * data-Attribute (dataset) statt über eine zweite, mit den Blade-Werten synchron zu haltende
 * JS-Datenstruktur — die einzige echte JS-Datenstruktur ist `keys`, eine kompakte Liste der
 * tatsächlich vorhandenen (Gruppe, Geschlecht[, Jugend])-Kombinationen, nur für die
 * "keine Daten"-Leermeldung und die Auswahl-Kaskade gebraucht (siehe hasMatch()/syncGender()).
 * Bewusst NICHT `matches` genannt: die verschachtelte tableSearch()-Instanz auf derselben Seite
 * definiert bereits eine Methode dieses Namens für die Namenssuche innerhalb einer Tabelle —
 * Alpine löst Eigenschaften über die nächstgelegene Scope-Kette auf, ein gleichnamiger
 * Methodenname hier hätte also lautlos die Zeilen-Suche überschrieben.
 *
 * groupId ist bewusst ein String (nicht x-model.number): Jahresbestleistungen haben Buckets ohne
 * zugeordnete Behinderungsgruppe ("—"), dafür steht als Gruppen-Id das feste Kürzel "none" statt
 * einer Zahl — dasselbe Prinzip wie "all". Ein numerischer x-model-Modifier würde beides zu NaN
 * machen. Cup-Wertung und Jahresbestleistungen liefern ihre Gruppen-Ids deshalb ebenfalls als
 * Strings (siehe jeweilige View), damit die Vergleiche hier ohne Typumwandlung funktionieren.
 *
 * Beim Wechsel der Gruppe (bzw. danach des Geschlechts) wird eine dadurch ungültig gewordene
 * Auswahl automatisch auf eine tatsächlich vorhandene Kombination nachgezogen (z. B. Gruppe ohne
 * Herren-Wertung → Geschlecht springt auf Damen) — das ist ein normales kaskadierendes
 * Auswahlfeld, keine stille Ersatz-Anzeige. Bewusst KEINE Kaskade für die Jugend-Checkbox: klickt
 * jemand sie explizit auf eine Kombination ohne Daten (z. B. Gruppe hat nur "Offen"), bleibt sie
 * so stehen und die Leermeldung erscheint — ein stiller Ersatz durch andere Inhalte wäre dort
 * irreführend, weil die Checkbox selbst die explizite Nutzerauswahl ist.
 *
 * Fortschreitende Verbesserung: Gruppen- und Geschlechts-<select> sowie die Checkbox tragen echte,
 * serverseitig gerenderte <option>s/einen echten Haken; ohne JavaScript bleiben schlicht alle
 * Wertungskategorie-Tabellen sichtbar (dasselbe Verhalten wie zuvor bei den Reitern).
 */
export default function rankingFilter(keys) {
    const hasJugend = keys.some(k => 'jugend' in k);

    return {
        keys,
        groupId: keys[0]?.groupId ?? null,
        gender: keys[0]?.gender ?? null,
        jugend: hasJugend ? (keys[0]?.jugend ?? false) : null,

        init() {
            this.$watch('groupId', () => this.syncGender());
            if (hasJugend) {
                this.$watch('gender', () => this.syncJugend());
            }
        },

        syncGender() {
            if (this.keys.some(k => k.groupId === this.groupId && k.gender === this.gender)) {
                return;
            }

            const fallback = this.keys.find(k => k.groupId === this.groupId);
            if (fallback) {
                this.gender = fallback.gender;
            }
        },

        syncJugend() {
            if (this.keys.some(k => k.groupId === this.groupId && k.gender === this.gender && k.jugend === this.jugend)) {
                return;
            }

            const fallback = this.keys.find(k => k.groupId === this.groupId && k.gender === this.gender);
            if (fallback) {
                this.jugend = fallback.jugend;
            }
        },

        isVisible(dataset) {
            if (dataset.groupId !== this.groupId || dataset.gender !== this.gender) {
                return false;
            }

            return hasJugend ? (dataset.jugend === '1') === this.jugend : true;
        },

        get hasMatch() {
            return this.keys.some(k => k.groupId === this.groupId
                && k.gender === this.gender
                && (!hasJugend || k.jugend === this.jugend));
        },
    };
}
