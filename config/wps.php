<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Schwelle für die Hintergrundberechnung
    |--------------------------------------------------------------------------
    |
    | Bis zu dieser Anzahl Ergebnisse wird die WPS-Punkteberechnung synchron im Request
    | ausgeführt, damit die Rückmeldung sofort erscheint. Darüber wird sie an die Queue
    | übergeben.
    |
    | Achtung: Wird der Job verwendet, muss produktiv ein Queue-Worker laufen, sonst
    | bleiben Berechnungen unbearbeitet liegen.
    |
    */

    'sync_threshold' => (int) env('WPS_SYNC_THRESHOLD', 500),

];
