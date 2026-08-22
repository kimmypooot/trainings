<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Morning reminders the day before, so participants can still arrange leave.
Schedule::command('tims:send-reminders --days=1')->dailyAt('08:00');

/*
 * The end-of-day evaluation prompt, sent after the sessions have finished but
 * while the room is still fresh — the seeded runs end at 16:30, so 17:30 clears
 * the last of the afternoon with an hour to spare.
 *
 * withoutOverlapping is belt to the unique index's braces: the command already
 * refuses to invite the same participant twice, and this keeps a slow run on a
 * large training from being started again underneath itself.
 */
Schedule::command('tims:invite-evaluations')->dailyAt('17:30')->withoutOverlapping();
