# Barrierefreiheit

Verbindliche Regeln für die Zugänglichkeit aller Oberflächen dieses Projekts — öffentlicher Bereich **und** interner
Bereich. Diese Datei steht auf derselben Ebene wie [conventions.md](conventions.md): Eine Phase gilt erst als fertig,
wenn die hier genannten Anforderungen erfüllt sind.

## Warum das hier verbindlich ist

Die Anwendung verwaltet den Sport von Menschen mit Behinderungen. Ihr Publikum umfasst Athleten mit Sehbeeinträchtigung
(Sportklassen S11–S13), mit motorischen Einschränkungen, die keine Maus bedienen können, und mit kognitiven
Beeinträchtigungen (S14, S21). Eine nicht bedienbare Oberfläche schließt genau die Personen aus, für die die Anwendung
existiert.

Hinzu kommt die Rechtslage: Für öffentliche Stellen und Verbände in Österreich gelten das Web-Zugänglichkeits-Gesetz
(WZG) und, seit Juni 2025, das Barrierefreiheitsgesetz (BaFG) für bestimmte Dienstleistungen. Der genaue
Anwendungsbereich für den ÖBSV ist rechtlich zu klären; technisch bauen wir so, dass die Anforderungen erfüllt sind.

**Ziel: WCAG 2.1 Level AA.**

## Die vier Prinzipien, konkret für dieses Projekt

### Wahrnehmbar

- **Kontrast** ≥ 4,5:1 für Fließtext, ≥ 3:1 für großen Text (ab 18,66px fett / 24px), Bedienelemente und
  bedeutungstragende Grafiken. Gilt in **beiden** Darstellungsmodi.
- **Keine reine Farbcodierung.** Ein roter Wert ist nicht als "schlecht" erkennbar, wenn Rot nicht wahrgenommen wird.
  Status immer zusätzlich als Text oder Symbol mit Textalternative. Betrifft im Bestand: Rekordstatus, Cup-Punkte,
  Qualifikationserfüllung, EXH-Kennzeichnung.
- **Textalternativen** für jedes bedeutungstragende Bild. Dekorative Grafiken bekommen `alt=""`.
- **Tabellen** als echte `<table>` mit `<caption>` und `<th scope="col">` / `scope="row"`. Bei zweistufigen Kopfzeilen
  (Punktetabelle: Geschlecht über Distanz) zusätzlich `headers`/`id`, weil `scope` allein die Zuordnung nicht mehr
  trägt. Die öffentliche Rekordtabelle (public-frontend §5.2, Phase 5) wurde entgegen der hier ursprünglich
  vorgesehenen Bahnlänge-über-Bewerb-Matrix als flache, per Rekordebene/Klasse/Geschlecht/Bahn filterbare Liste mit
  einstufiger Kopfzeile gebaut (Planungsentscheidung Phase 5) — dort reicht `scope="col"`, `headers`/`id` entfällt
  mangels verschmolzener Kopfzellen.
- **Zoom bis 200 %** ohne horizontales Scrollen und ohne Inhaltsverlust. Die breiten Tabellen sind hier der kritische
  Fall — sie brauchen einen scrollbaren Container mit `tabindex="0"` und einer zugänglichen Beschriftung, damit auch
  Tastaturnutzer scrollen können.
- **Reflow** auf 320px Breite: Die Tabellen der Punkteübersicht sind auf Mobilgeräten nicht sinnvoll darstellbar. Dort
  eine alternative Darstellung (Auswahl der Sportklasse, dann Einzelansicht) statt einer Miniaturtabelle. Umgesetzt in
  Phase 6 (public-frontend-modules.md) über Tailwinds `sm`-Breakpoint als praktikable Umsetzungsgrenze, nicht als
  exakte 320px-Pixelgrenze — die Matrix bleibt darunter vollständig ausgeblendet, die Sportklassen-Einzelansicht
  ersetzt sie 1:1 (`resources/views/public/base-times/index.blade.php`).

### Bedienbar

- **Alles per Tastatur.** Keine Funktion ausschließlich per Maus. Testverfahren: Die gesamte Seite einmal nur mit Tab,
  Shift+Tab, Enter, Leertaste und Pfeiltasten durchlaufen.
- **Sichtbarer Fokus** überall, mit ≥ 3:1 Kontrast zum Hintergrund. Tailwinds Default-Ring ist in der Dunkeldarstellung
  häufig zu schwach.
- **Fokusreihenfolge** entspricht der Lesereihenfolge. Nach einem Filterwechsel darf der Fokus nicht an den Seitenanfang
  springen — sonst muss man sich nach jeder Filterung erneut durch die Seite arbeiten.
- **Sprunglink** "Zum Inhalt" als erstes fokussierbares Element.
- **Keine Tastaturfallen**, insbesondere in Dialogen: Fokus wird beim Öffnen hineingesetzt, bleibt darin, kehrt beim
  Schließen zum auslösenden Element zurück, Escape schließt.
- **Reiternavigation** (Punktetabelle, Cup-Wertung) mit `role="tablist"`, Pfeiltastenbedienung und
  `aria-selected`.
- **Keine Zeitbegrenzung** ohne Verlängerungsmöglichkeit. Betrifft die Session im internen Bereich.

### Verständlich

- **`lang`** korrekt gesetzt (`de-AT` / `en`); anderssprachige Passagen im Text ausgezeichnet.
- **Formularfelder** haben sichtbare, programmatisch verknüpfte Labels. Kein Platzhaltertext als Ersatz.
- **Fehlermeldungen** benennen das Feld und sagen, was zu tun ist. Verknüpfung über `aria-describedby`, Fehlerzustand
  über `aria-invalid`. Betrifft im Bestand alle `flux:error`-Verwendungen.
- **Dokumentlinks** nennen Art, Format und Größe im Linktext: "Ausschreibung (PDF, 240 kB)", nicht ein Symbol. Linktexte
  müssen auch aus dem Zusammenhang gerissen verständlich sein — kein "hier".
- **Konsistente Navigation** über alle Seiten.

### Robust

- **Semantisches HTML** vor ARIA. `<nav>`, `<main>`, `<table>`, `<button>` statt Divs mit Rollen.
- **ARIA nur, wo nötig** — falsches ARIA ist schlechter als keines.
- **Statusmeldungen** (Filterergebnis aktualisiert, Datei hochgeladen) über `aria-live="polite"`, damit sie ohne
  Fokuswechsel angesagt werden. Betrifft Livewire-Aktualisierungen im internen Bereich.

## Komponentenbibliotheken

### Tailkit (öffentlicher Bereich)

Tailkit liefert Markup und Alpine-Verhalten, aber die Zugänglichkeit ist nicht durchgehend vollständig. **Vor der
Übernahme jeder interaktiven Komponente zu prüfen:**

- Dropdowns und Menüs: Pfeiltasten, Escape, `aria-expanded`, Fokusrückgabe
- Reiter: `role="tablist"`, Pfeiltasten
- Dialoge: Fokusfalle, Escape, `aria-modal`
- Akkordeons: `aria-expanded`, `aria-controls`
- Kontrastwerte der Grauabstufungen in beiden Modi

Was nicht genügt, wird angepasst — die Bibliothek ist Ausgangspunkt, nicht Maßstab.

### Flux (interner Bereich)

Flux bringt solide ARIA-Grundlagen mit. Zu prüfen bleiben die projektspezifischen Umgehungen aus
[conventions.md](conventions.md): natives `<input>` bei IMask (Label-Verknüpfung selbst herstellen), natives
`<option>` statt `flux:select.option`, und die Tabellen mit ihren Arbitrary-Selectors.

## Prüfung

Je Phase, vor dem Sign-off:

1. **axe DevTools** ohne Verstöße auf jeder neuen Seite.
2. **Tastaturdurchlauf** der neuen Seiten, wie oben beschrieben.
3. **Kontrastprüfung** in beiden Darstellungsmodi.
4. **Zoom auf 200 %** und Ansicht bei 320px Breite.

Automatische Prüfwerkzeuge finden erfahrungsgemäß etwa ein Drittel der Probleme — der Tastaturdurchlauf ist der
wichtigere Teil und nicht ersetzbar.

Zusätzlich vor dem Livegang des öffentlichen Bereichs: eine Durchsicht mit einem Screenreader (NVDA oder VoiceOver) auf
Startseite, Veranstaltungsdetail, Ergebnisliste und Rekordtabelle. Wenn es die Möglichkeit gibt, dafür Personen aus dem
Verband einzubinden, die täglich mit Hilfsmitteln arbeiten, ist das jeder internen Prüfung überlegen.

## Erklärung zur Barrierefreiheit

Der öffentliche Bereich braucht eine **Erklärung zur Barrierefreiheit** unter `/de/barrierefreiheit` mit
Konformitätsstand, bekannten Einschränkungen, Kontaktmöglichkeit für Rückmeldungen und Hinweis auf das
Schlichtungsverfahren. Inhaltlich zu klären, technisch in Phase 9 vorgesehen.
