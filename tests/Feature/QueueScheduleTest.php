<?php

use Illuminate\Console\Scheduling\Schedule;

uses()->group('deployment');

// Auf dem Webhosting gibt es keinen dauerhaften Queue-Worker — der Scheduler startet ihn jede Minute.

it('startet den Queue-Worker jede Minute über den Scheduler', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'queue:work'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *')
        ->and($events->first()->command)->toContain('--stop-when-empty')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});
