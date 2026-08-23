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

/*
 * The nightly archive of the database and the private disk. At 02:00 because
 * the dump takes a consistent-read snapshot and the private disk can be large,
 * and neither should compete with a room full of people checking in.
 *
 * withoutOverlapping because a slow run on a grown archive must not be started
 * again underneath itself — two mysqldumps and two zips writing at once is how
 * a backup directory fills a disk.
 *
 * Note that this writes locally. An archive on the same machine survives a bad
 * migration or a deleted file, not a dead disk; copying it somewhere else is an
 * ops step, and BACKUP_PATH exists so that destination can be a mapped or
 * synced folder rather than a second manual copy.
 */
Schedule::command('tims:backup')->dailyAt('02:00')->withoutOverlapping();
