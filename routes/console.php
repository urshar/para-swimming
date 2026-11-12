<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Webhosting ohne Supervisor (netcup/Plesk, docs/deployment.md): Ein einziger Cronjob ruft jede Minute
// "schedule:run" auf, der arbeitet die Queue (WPS-Punkte, Benachrichtigungen) ab und beendet sich, sobald sie leer
// ist. --max-time bleibt unter der Minute, damit sich zwei Läufe nicht überschneiden.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping();
