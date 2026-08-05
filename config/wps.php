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

    /*
    |--------------------------------------------------------------------------
    | Ermittlung der Kurzbahn-Umrechnungsfaktoren
    |--------------------------------------------------------------------------
    */

    'calibration' => [

        /*
         | Höchstabstand zwischen der Langbahn- und der Kurzbahnzeit eines Athleten,
         | in Monaten.
         |
         | Ohne diese Begrenzung misst der Vergleich nicht den Bahnunterschied, sondern die
         | LEISTUNGSENTWICKLUNG dazwischen: Stammt die Langbahnzeit von 2023 und die
         | Kurzbahn-Bestzeit von 2026, ist der Athlet schlicht besser geworden — der
         | errechnete Faktor wäre dann viel zu hoch.
         |
         | Kleineres Fenster = sauberere Messung, aber weniger Paare. 6 Monate deckt in der
         | Regel eine Saison ab.
         */
        'window_months' => (int) env('WPS_CALIBRATION_WINDOW_MONTHS', 6),

        /*
         | Plausibilitätsgrenzen für ein einzelnes Verhältnis LCM/SCM.
         |
         | Auf 100 m hat die Kurzbahn zwei Wenden mehr; realistisch sind daraus ein bis drei
         | Prozent. Werte außerhalb dieser Spanne beruhen fast immer auf einem Vergleich
         | ungleicher Formzustände und würden bei drei bis neun Athleten den Median spürbar
         | verziehen. Sie fließen nicht ein, werden im Faktorenbericht aber ausgewiesen —
         | verworfene Daten sollen sichtbar bleiben, nicht stillschweigend verschwinden.
         */
        'min_ratio' => (float) env('WPS_CALIBRATION_MIN_RATIO', 0.98),
        'max_ratio' => (float) env('WPS_CALIBRATION_MAX_RATIO', 1.06),

        /*
         | Mindestzahl an Athleten, ab der ein eigener Faktor gebildet wird.
         */
        'min_sample_size' => (int) env('WPS_CALIBRATION_MIN_SAMPLE_SIZE', 3),

    ],

];
