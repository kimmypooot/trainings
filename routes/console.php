<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Morning reminders the day before, so participants can still arrange leave.
Schedule::command('tims:send-reminders --days=1')->dailyAt('08:00');
