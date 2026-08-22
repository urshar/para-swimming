<?php

return [
    'skip_to_content' => 'Skip to content',

    'nav' => [
        'label' => 'Main navigation',
        'home' => 'Home',
        'meets' => 'Meets',
        'records' => 'Records',
        'open' => 'Open menu',
        'close' => 'Close menu',
    ],

    'theme' => [
        'label' => 'Appearance',
        'light' => 'Light',
        'dark' => 'Dark',
        'system' => 'System',
    ],

    'home' => [
        'title' => 'Home',
        'heading' => 'Welcome to the ÖBSV',
    ],

    'meets' => [
        'index' => [
            'title' => 'Meets',
            'upcoming_heading' => 'Upcoming meets',
            'past_heading' => 'Past meets',
            'archive_link' => 'All past meets',
            'none_upcoming' => 'No upcoming meets are currently published.',
            'none_past' => 'No past meets are published.',
        ],
        'archive' => [
            'title' => 'Archive',
            'heading' => 'Meet archive',
            'back_link' => 'Back to meets',
            'empty' => 'No past meets are published.',
        ],
        'show' => [
            'back_link' => 'Back to meets',
            'entries_deadline' => 'Entry deadline',
            'no_deadline' => 'no entry deadline on file',
            'livetiming' => 'Live timing',
            'livetiming_hint' => 'opens in a new tab',
            'documents_heading' => 'Documents',
            'no_documents' => 'No documents are published for this meet.',
            'also_available_in' => 'also available in',
        ],
        'columns' => [
            'date' => 'Date',
            'name' => 'Name',
            'city' => 'City',
            'entries_deadline' => 'Entry deadline',
            'documents' => 'Documents',
        ],
        'results' => [
            'back_link' => 'Back to the meet',
            'link' => 'View results',
            'title' => 'Results',
            'heading' => 'Results',
            'empty' => 'No results have been published for this meet yet.',
            'class_heading' => 'Sport class :class',
            'class_heading_none' => 'No sport class',
            'columns' => [
                'place' => 'Place',
                'name' => 'Name',
                'club' => 'Club',
                'birth_year' => 'Birth year',
                'sport_class' => 'Sport class',
                'time' => 'Time',
                'points' => 'Points',
                'wps_points' => 'WPS points',
                'record' => 'Record',
            ],
            'status' => [
                'DSQ' => 'Disqualified',
                'DNS' => 'Did not start',
                'DNF' => 'Did not finish',
                'EXH' => 'Exhibition',
                'SICK' => 'Sick',
                'WDR' => 'Withdrawn',
            ],
            'records' => [
                'world' => 'World record',
                'european' => 'European record',
                'national' => 'Austrian record',
                'junior' => 'Junior record',
                'regional' => 'Regional record',
                'regional_junior' => 'Regional junior record',
            ],
        ],
    ],

    'records' => [
        'title' => 'Records',
        'heading' => 'Records',
        'empty' => 'No records for this selection.',
        'filter' => [
            'heading' => 'Filter',
            'level' => 'Record level',
            'level_national' => 'Austria (overall)',
            'youth' => 'Youth records only',
            'sport_class' => 'Sport class',
            'sport_class_all' => 'All classes',
            'gender' => 'Sex',
            'gender_all' => 'All',
            'course' => 'Course',
            'course_all' => 'All courses',
            'submit' => 'Filter',
        ],
        'columns' => [
            'sport_class' => 'Class',
            'gender' => 'Sex',
            'distance' => 'Distance',
            'course' => 'Course',
            'time' => 'Time',
            'athlete' => 'Athlete / team',
            'club' => 'Club',
            'location' => 'Location',
            'date' => 'Date',
        ],
        'gender' => [
            'M' => 'Men',
            'F' => 'Women',
            'X' => 'Mixed',
        ],
        'downloads' => [
            'heading' => 'Download this selection',
            'lenex' => 'Download as LENEX',
            'pdf' => 'Download as PDF',
            'lenex_hint' => 'without sport class filtering, the complete record list for this level',
        ],
    ],

    'documents' => [
        'category' => [
            'INVITATION' => 'Invitation',
            'START_LIST' => 'Start list',
            'RESULTS' => 'Results',
            'REGULATION' => 'Regulation',
            'FORM' => 'Form',
        ],
    ],

    'languages' => [
        'de' => 'German',
        'en' => 'English',
    ],
];
