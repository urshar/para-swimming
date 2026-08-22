<?php

return [
    'skip_to_content' => 'Zum Inhalt',

    'nav' => [
        'label' => 'Hauptnavigation',
        'home' => 'Startseite',
        'meets' => 'Veranstaltungen',
        'records' => 'Rekorde',
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
