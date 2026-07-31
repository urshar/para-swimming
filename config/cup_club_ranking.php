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
    | Kaderathleten (nur Leistungswertung)
    |--------------------------------------------------------------------------
    |
    | restricted_kader_type_codes:  Kaderarten, deren Athleten je Verein nur
    |   begrenzt in die leistungsorientierte Vereinswertung einfließen. Angegeben
    |   werden die (administrierbaren) Codes aus kader_types. Maßgeblich ist, ob
    |   die Kaderzugehörigkeit während des Cup-Jahres aktiv war. Leeres Array =
    |   keine Kaderbegrenzung.
    |
    | counted_kader_athletes_per_club:  Wie viele Kaderathleten je Verein
    |   höchstens gewertet werden. 0 = keiner (Kaderathleten zählen nicht),
    |   höher = die besten N Kaderathleten je Verein zählen mit. Der Wert lässt
    |   sich in der Ansicht je Aufruf überschreiben.
    |
    | Die klassische Startwertung ist von beidem nicht betroffen.
    |
    */

    'restricted_kader_type_codes' => [
        'WELTKLASSE',
        'INTERNATIONALE_KLASSE',
        'SICHTUNGSPOOL',
    ],

    'counted_kader_athletes_per_club' => 0,

];
