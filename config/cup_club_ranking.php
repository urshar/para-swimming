<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ausländische Vereine
    |--------------------------------------------------------------------------
    |
    | Steuert, ob Vereine ohne österreichische Vereinsnation
    | (club.nation.code != 'AUT') in die ÖBSV-Cup-Vereinswertung einfließen.
    | Gilt für beide Wertungssysteme (Startwertung und Leistungswertung).
    | Standard: nur österreichische Vereine.
    |
    */

    'include_foreign_clubs' => false,

    /*
    |--------------------------------------------------------------------------
    | Leistungswertung (Wertungssystem B)
    |--------------------------------------------------------------------------
    |
    | counted_meets_per_athlete     Anzahl der besten Cup-Meets je Athlet und
    |                               Verein, die in den Athleten-Saisonwert
    |                               einfließen.
    | max_counted_athletes_per_club Anzahl der besten Athleten je Verein, die
    |                               gewichtet in den Vereinsgesamtwert einfließen.
    | athlete_weights               Gewicht je Position (1-basiert). Positionen
    |                               ohne Eintrag zählen nicht.
    |
    | Diese Werte werden erst ab Phase 2 (Leistungswertung) ausgewertet.
    |
    */

    'counted_meets_per_athlete' => 3,

    'max_counted_athletes_per_club' => 5,

    'athlete_weights' => [
        1 => 1.00,
        2 => 0.80,
        3 => 0.60,
        4 => 0.40,
        5 => 0.20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ausgeschlossene Kaderarten (nur Leistungswertung)
    |--------------------------------------------------------------------------
    |
    | Athleten, die während des Cup-Jahres in einer dieser Kaderarten aktiv
    | waren, fließen NICHT in die leistungsorientierte Vereinswertung ein
    | (Kaderathleten sollen die Wertung nicht dominieren). Angegeben werden die
    | (administrierbaren) Codes aus kader_types. Leeres Array = kein Ausschluss.
    | Die klassische Startwertung ist davon nicht betroffen.
    |
    */

    'excluded_kader_type_codes' => [
        'WELTKLASSE',
        'INTERNATIONALE_KLASSE',
        'SICHTUNGSPOOL',
    ],

];
