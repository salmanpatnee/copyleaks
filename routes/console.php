<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hostinger has no permanent worker, so cron drains the database queue every minute (spec 0001).
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping(10);

// Rescues scans stuck after the Copyleaks summary (spec 0005). It never creates a scan.
Schedule::command('copyleaks:sweep-fetching')->everyFiveMinutes()->withoutOverlapping(10);
