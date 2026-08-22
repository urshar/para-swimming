<?php

return [
    'skip_to_content' => 'Zum Inhalt',

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
        'open' => 'Menü öffnen',
        'close' => 'Menü schließen',
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
    ],

    'meets' => [
        'index' => [
            'title' => 'Veranstaltungen',
            'upcoming_heading' => 'Kommende Veranstaltungen',
            'past_heading' => 'Vergangene Veranstaltungen',
            'archive_link' => 'Alle vergangenen Veranstaltungen',
            'none_upcoming' => 'Derzeit sind keine kommenden Veranstaltungen veröffentlicht.',
            'none_past' => 'Es sind keine vergangenen Veranstaltungen veröffentlicht.',
        ],
        'archive' => [
            'title' => 'Archiv',
            'heading' => 'Veranstaltungsarchiv',
            'back_link' => 'Zurück zu den Veranstaltungen',
            'empty' => 'Es sind keine vergangenen Veranstaltungen veröffentlicht.',
        ],
        'show' => [
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

    'documents' => [
        'category' => [
            'INVITATION' => 'Ausschreibung',
            'START_LIST' => 'Meldeliste',
            'RESULTS' => 'Ergebnisliste',
            'REGULATION' => 'Regelment',
            'FORM' => 'Formular',
        ],
    ],

    'languages' => [
        'de' => 'Deutsch',
        'en' => 'Englisch',
    ],
];
