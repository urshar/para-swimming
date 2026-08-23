<?php

return [
    'skip_to_content' => 'Zum Inhalt',

    'meta' => [
        // Fallback-Meta-Description für Seiten ohne eigenes @section('description', ...) —
        // siehe layouts/public.blade.php.
        'default_description' => 'Wettkämpfe, Ergebnisse, Rekorde und Ranglisten des ÖBSV im Para-Schwimmen.',
    ],

    'nav' => [
        'label' => 'Hauptnavigation',
        'home' => 'Startseite',
        'meets' => 'Veranstaltungen',
        'records' => 'Rekorde',
        'points' => 'Punkte',
        'base_times' => 'ÖBSV-Punktetabelle',
        'point_calculator' => 'ÖBSV-Punkterechner',
        // Bewusst "WPS-Rechner" statt "WPS-Punkterechner": Die Navigation steht auf jeder Seite,
        // auch der Ergebnisseite, deren Test prüft, dass "WPS-Punkte" dort nicht auftaucht, wenn
        // kein WPS gerechnet wurde (results.columns.wps_points).
        'wps_point_calculator' => 'WPS-Rechner',
        'rankings' => 'Ranglisten',
        'cup' => 'ÖBSV Cup-Wertung',
        'qualifying_times' => 'Startberechtigung',
        'annual_best' => 'Jahresbestleistungen',
        'regulations' => 'Reglemente',
        // Stehen in der Fußzeile, nicht im Hauptmenü (siehe layouts/public.blade.php) — die Keys
        // bleiben trotzdem im nav-Block, da sie wie alle anderen Menü-/Seitenlabels funktionieren.
        'legal_label' => 'Rechtliches',
        'imprint' => 'Impressum',
        'privacy_policy' => 'Datenschutz',
        'accessibility_statement' => 'Barrierefreiheit',
        'open' => 'Menü öffnen',
        'close' => 'Menü schließen',
    ],

    'draft_notice' => [
        'heading' => 'Entwurf — noch nicht rechtsgültig',
        'text' => 'Diese Seite enthält Platzhalter statt endgültiger Angaben und ist noch nicht für die Öffentlichkeit bestimmt (kein Suchmaschineneintrag).',
    ],

    'imprint' => [
        'title' => 'Impressum',
        'heading' => 'Impressum',
        'operator' => 'Medieninhaber und Betreiber',
        'address' => 'Anschrift',
        'register_number' => 'ZVR-Zahl',
        'representative' => 'Vertretungsbefugte Person(en)',
        'contact' => 'Kontakt',
        'purpose' => 'Vereinszweck',
        'placeholder' => [
            'club_name' => 'vollständiger Vereinsname',
            'address' => 'Straße, PLZ, Ort',
            'zvr' => 'ZVR-Zahl',
            'representative' => 'Name(n) und Funktion',
            'purpose' => 'Vereinszweck laut Statuten',
        ],
    ],

    'privacy_policy' => [
        'title' => 'Datenschutz',
        'heading' => 'Datenschutzerklärung',
        'controller' => [
            'heading' => 'Verantwortlicher',
            'contact' => 'Kontakt für Datenschutzanfragen:',
        ],
        'data' => [
            'heading' => 'Welche Daten wir verarbeiten',
            'competition_intro' => 'Im Rahmen der Verbandstätigkeit veröffentlichen wir Wettkampfdaten (Namen von Athlet:innen, Ergebnisse, Vereinszugehörigkeit) öffentlich auf dieser Website.',
            'legal_basis' => 'Rechtsgrundlage dafür:',
            'technical_intro' => 'Zusätzlich verarbeiten wir folgende rein technische Daten:',
            'cookie_locale' => 'Ein Cookie ("locale", Gültigkeit 1 Jahr) speichert Ihre gewählte Sprache — technisch notwendig für die Funktion der Website, keine Einwilligung erforderlich.',
            'storage_theme' => 'Im lokalen Speicher Ihres Browsers ("localStorage", Schlüssel "theme") speichern wir Ihre gewählte Darstellung (hell/dunkel/System) — verlässt nie Ihr Gerät.',
        ],
        'recipients' => [
            'heading' => 'Empfänger',
            'text' => 'Die Website wird gehostet bei:',
        ],
        'rights' => [
            'heading' => 'Ihre Rechte',
            'text' => 'Sie haben das Recht auf Auskunft, Berichtigung, Löschung und Einschränkung der Verarbeitung Ihrer personenbezogenen Daten sowie das Recht auf Datenübertragbarkeit und Widerspruch gegen die Verarbeitung. Wenden Sie sich dafür an die oben genannte Kontaktadresse.',
        ],
        'complaint' => [
            'heading' => 'Beschwerderecht',
            'text' => 'Sie haben das Recht, sich bei der österreichischen Datenschutzbehörde zu beschweren: Österreichische Datenschutzbehörde, Barichgasse 40–42, 1030 Wien, E-Mail: dsb@dsb.gv.at.',
        ],
        'placeholder' => [
            'legal_basis' => 'z. B. berechtigtes Interesse gemäß Art. 6 Abs. 1 lit. f DSGVO / Vereinsstatuten',
            'hosting_provider' => 'Name und Anschrift des Hosting-Anbieters',
        ],
    ],

    'theme' => [
        'label' => 'Darstellung',
        'light' => 'Hell',
        'dark' => 'Dunkel',
        'system' => 'System',
    ],

    'home' => [
        'title' => 'Startseite',
        'heading' => 'Willkommen beim ÖBSV',
        'intro' => 'Wettkämpfe, Ergebnisse und Rekorde des österreichischen Para-Schwimmens auf einen Blick.',
        'next_meet' => [
            'heading' => 'Nächste Veranstaltung',
            'empty' => 'Derzeit ist keine kommende Veranstaltung veröffentlicht.',
            'link' => 'Details ansehen',
        ],
        'recent_records' => [
            'heading' => 'Neue Rekorde',
            'empty' => 'Derzeit liegen keine österreichischen Rekorde vor.',
            'link' => 'Alle Rekorde ansehen',
        ],
        'recent_results' => [
            'heading' => 'Aktuelle Ergebnisse',
            'empty' => 'Für die letzten Veranstaltungen sind noch keine Ergebnisse veröffentlicht.',
            'link' => 'Ergebnisse ansehen',
        ],
    ],

    'meets' => [
        'index' => [
            'title' => 'Veranstaltungen',
            'meta_description' => 'Kommende und vergangene Schwimmveranstaltungen des ÖBSV.',
            'upcoming_heading' => 'Kommende Veranstaltungen',
            'past_heading' => 'Vergangene Veranstaltungen',
            'archive_link' => 'Alle vergangenen Veranstaltungen',
            'none_upcoming' => 'Derzeit sind keine kommenden Veranstaltungen veröffentlicht.',
            'none_past' => 'Es sind keine vergangenen Veranstaltungen veröffentlicht.',
        ],
        'archive' => [
            'title' => 'Archiv',
            'meta_description' => 'Alle vergangenen Schwimmveranstaltungen des ÖBSV, nach Jahr gruppiert.',
            'heading' => 'Veranstaltungsarchiv',
            'back_link' => 'Zurück zu den Veranstaltungen',
            'empty' => 'Es sind keine vergangenen Veranstaltungen veröffentlicht.',
        ],
        'show' => [
            'meta_description' => ':name, :date · :city',
            'back_link' => 'Zurück zu den Veranstaltungen',
            'entries_deadline' => 'Meldeschluss',
            'no_deadline' => 'kein Meldeschluss hinterlegt',
            'livetiming' => 'Livetiming',
            'livetiming_hint' => 'öffnet in neuem Tab',
            'documents_heading' => 'Dokumente',
            'no_documents' => 'Für diese Veranstaltung sind keine Dokumente veröffentlicht.',
            'also_available_in' => 'auch verfügbar auf',
        ],
        'columns' => [
            'date' => 'Datum',
            'name' => 'Name',
            'city' => 'Ort',
            'entries_deadline' => 'Meldeschluss',
            'documents' => 'Dokumente',
        ],
        'results' => [
            'back_link' => 'Zurück zur Veranstaltung',
            'link' => 'Ergebnisse ansehen',
            'title' => 'Ergebnisse',
            'heading' => 'Ergebnisse',
            'empty' => 'Für diese Veranstaltung sind noch keine Ergebnisse veröffentlicht.',
            'class_heading' => 'Sportklasse :class',
            'class_heading_none' => 'Ohne Sportklasse',
            'columns' => [
                'place' => 'Platz',
                'name' => 'Name',
                'club' => 'Verein',
                'birth_year' => 'Jahrgang',
                'sport_class' => 'Sportklasse',
                'time' => 'Zeit',
                'points' => 'Punkte',
                'wps_points' => 'WPS-Punkte',
                'record' => 'Rekord',
            ],
            'status' => [
                'DSQ' => 'Disqualifiziert',
                'DNS' => 'Nicht angetreten',
                'DNF' => 'Nicht beendet',
                'EXH' => 'Ausstellungsstart',
                'SICK' => 'Krank',
                'WDR' => 'Zurückgezogen',
            ],
            'records' => [
                'world' => 'Weltrekord',
                'european' => 'Europarekord',
                'national' => 'Österreichischer Rekord',
                'junior' => 'Juniorenrekord',
                'regional' => 'Landesrekord',
                'regional_junior' => 'Landesjuniorenrekord',
            ],
        ],
    ],

    'records' => [
        'title' => 'Rekorde',
        'meta_description' => 'Österreichische Rekorde im Para-Schwimmen, national und je Landesverband.',
        'heading' => 'Rekorde',
        'empty' => 'Für diese Auswahl liegen keine Rekorde vor.',
        'filter' => [
            'heading' => 'Filter',
            'level' => 'Rekordebene',
            'level_national' => 'Österreich (gesamt)',
            'youth' => 'Nur Jugendrekorde',
            'sport_class' => 'Sportklasse',
            'sport_class_all' => 'Alle Klassen',
            'gender' => 'Geschlecht',
            'gender_all' => 'Alle',
            'course' => 'Bahn',
            'course_all' => 'Alle Bahnen',
            'submit' => 'Filtern',
        ],
        'columns' => [
            'sport_class' => 'Klasse',
            'gender' => 'Geschlecht',
            'distance' => 'Distanz',
            'course' => 'Bahn',
            'time' => 'Zeit',
            'athlete' => 'Athlet/in bzw. Team',
            'club' => 'Verein',
            'location' => 'Ort',
            'date' => 'Datum',
        ],
        'gender' => [
            'M' => 'Herren',
            'F' => 'Damen',
            'X' => 'Gemischt',
        ],
        'downloads' => [
            'heading' => 'Download dieser Auswahl',
            'lenex' => 'Als LENEX herunterladen',
            'pdf' => 'Als PDF herunterladen',
            'lenex_hint' => 'ohne Sportklassen-Eingrenzung, vollständige Bestenliste der Ebene',
        ],
    ],

    'base_times' => [
        'title' => 'ÖBSV-Punktetabelle',
        'heading' => 'ÖBSV-Punktetabelle',
        'intro' => 'Basiszeiten der aktuell gültigen Version. Grundlage der ÖBSV-Punkteberechnung P = 1000 × (B/T)³.',
        'version_label' => 'ÖBSV-Basiswert-Version',
        'empty' => 'Für das heutige Datum ist keine gültige Basiswert-Version hinterlegt.',
        'course' => 'Bahn',
        'not_applicable' => '–',
        'sport_class' => 'Klasse',
        'gender' => [
            'M' => 'Herren',
            'F' => 'Damen',
        ],
        'mobile' => [
            'heading' => 'Sportklasse wählen',
            'select_class' => 'Sportklasse',
            'select_class_all' => 'Bitte wählen',
        ],
    ],

    'point_calculator' => [
        'title' => 'ÖBSV-Punkterechner',
        'heading' => 'ÖBSV-Punkterechner',
        'intro' => 'Rechnet mit der aktuell gültigen Basiswert-Version.',
        'empty' => 'Für das heutige Datum ist keine gültige Basiswert-Version hinterlegt.',
        'mode' => [
            'heading' => 'Richtung',
            'time_to_points' => 'Zeit → Punkte',
            'points_to_time' => 'Punkte → Zeit',
        ],
        'fields' => [
            'course' => 'Bahn',
            'gender' => 'Geschlecht',
            'discipline' => 'Bewerb',
            'sport_class' => 'Sportklasse',
            'sport_class_select' => 'Bitte wählen',
            'time' => 'Zeit',
            'points' => 'Punkte',
            'submit' => 'Berechnen',
        ],
        'gender' => [
            'M' => 'Herren',
            'F' => 'Damen',
        ],
        'result' => [
            'points_heading' => 'Punkte',
            'time_heading' => 'Zeit',
        ],
        'errors' => [
            'invalid_time' => 'Ungültige Zeit.',
            'invalid_points' => 'Ungültige Punktzahl.',
            'no_discipline' => 'Unbekannter Bewerb.',
            'no_category' => 'Für diese Bahn/Geschlecht-Kombination liegt keine Basiswert-Kategorie vor.',
            'no_sport_class' => 'Unbekannte Sportklasse.',
            'no_base_time' => 'Für diese Auswahl liegt kein Basiswert vor.',
        ],
    ],

    'wps_point_calculator' => [
        'title' => 'WPS-Punkterechner',
        'heading' => 'WPS-Punkterechner',
        'intro' => 'Rechnet mit der offiziellen WPS-Punktetabelle (Gompertz-Formel) statt den ÖBSV-Basiswerten — nur Langbahn (LCM), dafür gibt es keine WPS-Kurzbahnwerte.',
        'empty' => 'Für das heutige Datum ist keine gültige WPS-Punkteversion hinterlegt.',
        'version_label' => 'WPS-Punkteversion',
        'mode' => [
            'heading' => 'Richtung',
            'time_to_points' => 'Zeit → Punkte',
            'points_to_time' => 'Punkte → Zeit',
        ],
        'fields' => [
            'gender' => 'Geschlecht',
            'discipline' => 'Bewerb',
            'sport_class' => 'Sportklasse',
            'sport_class_select' => 'Bitte wählen',
            'time' => 'Zeit',
            'points' => 'Punkte',
            'submit' => 'Berechnen',
        ],
        'gender' => [
            'M' => 'Herren',
            'F' => 'Damen',
        ],
        'result' => [
            'points_heading' => 'Punkte',
            'time_heading' => 'Zeit',
        ],
        'errors' => [
            'invalid_time' => 'Ungültige Zeit.',
            'invalid_points' => 'Ungültige Punktzahl.',
            'no_discipline' => 'Unbekannter Bewerb.',
            'no_parameter' => 'Für diese Auswahl liegt kein WPS-Parametersatz vor.',
        ],
    ],

    'cup' => [
        'title' => 'ÖBSV Cup-Wertung',
        'heading' => 'ÖBSV Cup-Wertung',
        'intro' => 'Gesamtwertung des ÖBSV-Cups je Wettkampfjahr — die besten Tageswertungen des Jahres zählen.',
        'year' => 'Jahr',
        'no_years' => 'Es ist noch kein ÖBSV-Cup angelegt.',
        'empty' => 'Für dieses Jahr liegt keine Cup-Wertung vor.',
        'search' => 'Suchen',
        'search_placeholder' => 'Name oder Verein',
        'round_legend' => 'Fett/farbig hervorgehoben = zählt zu den :count besten Runden.',
        'filter' => [
            'group' => 'Klasse',
            'group_all' => 'Alle Klassen',
            'gender' => 'Geschlecht',
            'youth_only' => 'Nur Jugendwertung',
            'no_match' => 'Für diese Auswahl liegt keine Wertung vor.',
        ],
        'columns' => [
            'rank' => 'Rang',
            'athlete' => 'Athlet/in',
            'gender' => 'Geschlecht',
            'sport_class' => 'Klasse',
            'club' => 'Verein',
            'total_points' => 'Gesamtpunkte',
        ],
        'gender' => [
            'M' => 'Herren',
            'F' => 'Damen',
            'combined' => 'Damen & Herren',
        ],
    ],

    'qualifying_times' => [
        'title' => 'Startberechtigung',
        'heading' => 'Startberechtigung ÖSTM & ÖM',
        'intro' => 'Athlet/innen, die im Qualifikationszeitraum die Richtzeiten der aktuellen Liste erreicht haben.',
        'list_label' => 'Richtzeitenliste',
        'empty' => 'Es ist derzeit keine aktive Richtzeitenliste hinterlegt.',
        'empty_results' => 'Für diese Auswahl liegt keine Startberechtigung vor.',
        'filter' => [
            'heading' => 'Filter',
            'discipline' => 'Bewerb',
            'discipline_all' => 'Alle Bewerbe',
            'gender' => 'Geschlecht',
            'gender_all' => 'Alle',
            'sport_class' => 'Sportklasse',
            'sport_class_all' => 'Alle Klassen',
            'sport_class_group' => 'Behinderungsgruppe',
            'sport_class_group_all' => 'Alle Gruppen',
            'club' => 'Verein',
            'club_all' => 'Alle Vereine',
            'submit' => 'Filtern',
        ],
        'reference_time' => 'Richtzeit',
        'columns' => [
            'athlete' => 'Athlet/in',
            'club' => 'Verein',
            'sport_class' => 'Klasse',
            'time' => 'Zeit',
            'points' => 'Punkte',
            'date' => 'Datum',
        ],
        'gender' => [
            'M' => 'Herren',
            'F' => 'Damen',
        ],
    ],

    'annual_best' => [
        'title' => 'Jahresbestleistungen',
        'heading' => 'Jahresbestleistungen',
        'intro' => 'Das punktbeste Einzelergebnis jeder Person im Kalenderjahr, gereiht nach ÖBSV-Punkten.',
        'year' => 'Jahr',
        'empty' => 'Für dieses Jahr liegen keine Jahresbestleistungen vor.',
        'search' => 'Suchen',
        'search_placeholder' => 'Name oder Verein',
        'filter' => [
            'group' => 'Klasse',
            'group_all' => 'Alle Klassen',
            'gender' => 'Geschlecht',
            'no_match' => 'Für diese Auswahl liegen keine Jahresbestleistungen vor.',
        ],
        'columns' => [
            'rank' => 'Rang',
            'athlete' => 'Athlet/in',
            'gender' => 'Geschlecht',
            'club' => 'Verein',
            'discipline' => 'Bewerb',
            'sport_class' => 'Klasse',
            'points' => 'Punkte',
        ],
        'gender' => [
            'M' => 'Herren',
            'F' => 'Damen',
            'combined' => 'Damen & Herren',
        ],
    ],

    'documents' => [
        'category' => [
            'INVITATION' => 'Ausschreibung',
            'START_LIST' => 'Meldeliste',
            'RESULTS' => 'Ergebnisliste',
            'REGULATION' => 'Reglement',
            'FORM' => 'Formular',
        ],
    ],

    'regulations' => [
        'title' => 'Reglemente',
        'heading' => 'Reglemente & Formulare',
        'intro' => 'Regelwerke und Formulare des ÖBSV zum Download.',
        'empty' => 'Derzeit sind keine Reglemente oder Formulare veröffentlicht.',
        // Abschnittsüberschriften teilen sich die Kategorie-Labels mit documents.category oben
        // (REGULATION/FORM) — kein eigener Satz nötig.
        'also_available_in' => 'auch verfügbar auf',
        // Sprachneutrale Dokumente (locale = null) — die beiden echten Sprachen kommen aus
        // languages.de/en unten.
        'language_neutral' => 'sprachneutral',
        'columns' => [
            'title' => 'Titel',
            'language' => 'Sprache',
            'published_at' => 'Veröffentlicht am',
        ],
    ],

    'accessibility_statement' => [
        'title' => 'Barrierefreiheit',
        'heading' => 'Erklärung zur Barrierefreiheit',
        'intro' => 'Der ÖBSV ist bemüht, diese Website für alle Nutzer:innen zugänglich zu gestalten und orientiert sich dabei an den Web Content Accessibility Guidelines (WCAG).',
        'contact_heading' => 'Rückmeldungen',
        'contact_text' => 'Wenn Sie auf Barrieren stoßen oder Verbesserungsvorschläge haben, kontaktieren Sie uns unter',
        'contact_email' => 'schwimmen@obsv.at',
    ],

    'languages' => [
        'de' => 'Deutsch',
        'en' => 'Englisch',
    ],
];
