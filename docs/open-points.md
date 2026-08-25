# Offene Punkte

Bewusst zurückgestellte Entscheidungen und TODOs, die nicht in einer einzelnen Phase untergehen sollen. Jeder
Eintrag: was fehlt, warum es zurückgestellt wurde, was zum Schließen gebraucht wird. Erledigte Punkte werden aus
dieser Datei entfernt (Historie steht im jeweiligen Phasen-Abschnitt von `specs/public-frontend-modules.md`), nicht
nur abgehakt liegen gelassen.

## Barrierefreiheitserklärung — Konformitätsstand & Schlichtungsverfahren

**Seit:** Phase 9 (`/de/barrierefreiheit`, `docs/accessibility.md` §Erklärung zur Barrierefreiheit).

**Was fehlt:** Die veröffentlichte Erklärung nennt bislang nur die Kontaktmöglichkeit
(`schwimmen@obsv.at`) für Rückmeldungen. Zwei Abschnitte fehlen bewusst:

1. **Konformitätsstand** (z. B. "vollständig konform" / "teilweise konform" mit WCAG 2.1 AA,
   inkl. bekannter Einschränkungen) — dafür bräuchte es zuerst eine echte Prüfung
   (siehe `docs/accessibility.md` §Prüfung: axe DevTools, Tastaturdurchlauf, Kontrastprüfung,
   Screenreader-Durchsicht), keine Selbstauskunft ohne Grundlage.
2. **Schlichtungsverfahren** — das österreichische Web-Zugänglichkeits-Gesetz (WZG) richtet sich
   primär an öffentliche Stellen; ob und in welcher Form der ÖBSV als privater Verband freiwillig
   eine Schlichtungsstelle nennen möchte, ist eine Entscheidung des Verbands, keine technische.

**Wer entscheidet:** ÖBSV (Erik/Vorstand) — Inhalt, nicht Umsetzung.

**Zum Schließen nötig:** Entscheidung + Text zu beiden Punkten, dann Ergänzung in
`resources/views/public/accessibility-statement/index.blade.php` (Dateiname zum Zeitpunkt der
Eintragung — bei Umbenennung hier nachziehen) und den zugehörigen `lang/{de,en}/public.php`-Keys.

## Impressum & Datenschutzerklärung — echter Inhalt statt Platzhalter

**Seit:** Phase 9 Nachtrag (`/de/impressum`, `/de/datenschutz`).

**Was fehlt:** Beide Seiten sind aktuell reine Entwürfe mit sichtbar markierten Platzhaltern
(`x-draft-notice`, `x-placeholder-field`) statt echtem Inhalt — via `noindex` + `robots.txt`
gesperrt und **nicht** in `sitemap.xml`, bis das erledigt ist:

1. **Impressum** (`resources/views/public/imprint/index.blade.php`): vollständiger Vereinsname,
   Anschrift, ZVR-Zahl, vertretungsbefugte Person(en), Vereinszweck.
2. **Datenschutzerklärung** (`resources/views/public/privacy-policy/index.blade.php`):
   Rechtsgrundlage für die öffentliche Veröffentlichung von Athletennamen/Ergebnissen/
   Vereinszugehörigkeit (Vorschlag im Platzhalter: berechtigtes Interesse nach Art. 6 Abs. 1
   lit. f DSGVO oder Vereinsstatuten — zu bestätigen), Hosting-Anbieter (Name + Anschrift).
   Kontrolle empfohlen, bevor es live geht: Adresse/E-Mail der österreichischen
   Datenschutzbehörde im Beschwerderecht-Abschnitt sind als aktuell bekannt eingetragen
   (Stand meines Wissens), aber nicht anhand einer Live-Quelle zum Zeitpunkt der Erstellung
   gegengeprüft.

**Wer entscheidet:** ÖBSV (Erik/Vorstand) — Inhalt, nicht Umsetzung. Bei Bedarf mit
rechtskundiger Person/Anwalt gegenprüfen, insbesondere die Rechtsgrundlage für die
Athletendaten-Veröffentlichung.

**Zum Schließen nötig:** Echten Text in beide Views einsetzen, `x-draft-notice`-Aufrufe entfernen,
`@section('robots', 'noindex, nofollow')` entfernen, die beiden Routen aus den
`Disallow`-Zeilen in `app/Http/Controllers/Public/RobotsController.php` streichen und in
`app/Http/Controllers/Public/SitemapController.php::STATIC_ROUTES` aufnehmen.
