<?php

use Illuminate\Support\Facades\Schedule;

/*
| Scheduled jobs (SPEC 3 "Scheduler", SPEC 13, SPEC 15).
| Run by the `scheduler` container with `php artisan schedule:work`.
*/

// Data retention: once a day at 03:00 Vietnam time.
Schedule::command('leadradar:retention')
    ->dailyAt('03:00')
    ->timezone(config('leadradar.display_timezone'))
    ->withoutOverlapping()
    ->onOneServer();

// Collector heartbeats and quiet important sources.
Schedule::command('leadradar:health')->everyFiveMinutes()->withoutOverlapping();

// Digest of leads held back during quiet hours (does nothing while quiet).
Schedule::command('leadradar:quiet-digest')->everyFiveMinutes()->withoutOverlapping();

// Horizon metrics snapshots for the /horizon dashboard.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
