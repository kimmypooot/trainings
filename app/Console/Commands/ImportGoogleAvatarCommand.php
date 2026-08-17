<?php

namespace App\Console\Commands;

use App\Jobs\ImportGoogleAvatar;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Run the Google photo import again for one account.
 *
 * The import is dispatched once, when a Google identity is first attached, and
 * is queued so a slow fetch never sits inside the sign-in round trip. That
 * leaves one way for it to be lost silently: the job is dispatched and then
 * never runs — no worker on a development machine, a flushed queue — in which
 * case nothing retries it, because the "already attached" state that gates the
 * dispatch is exactly what a second sign-in now sees.
 *
 * A participant cannot recover from that on their own. Disconnecting and
 * reconnecting Google would re-import, but disconnecting is refused when
 * Google is the only way in — which is true of precisely the accounts that
 * were created through it. Hence a command for the office to run.
 *
 * Note that a failed job is *not* this command's problem: those land in
 * `failed_jobs` and `queue:retry` already exists for them.
 */
class ImportGoogleAvatarCommand extends Command
{
    protected $signature = 'tims:import-google-avatar
                            {user : Email address, or the numeric user id}
                            {--force : Replace a photo the account already has}';

    protected $description = 'Re-run the Google profile photo import for one account';

    public function handle(): int
    {
        $key = (string) $this->argument('user');

        $user = ctype_digit($key)
            ? User::find((int) $key)
            : User::where('email', $key)->first();

        if (! $user) {
            $this->error("No account matches \"{$key}\".");

            return self::FAILURE;
        }

        if (blank($user->google_avatar_url)) {
            $this->error(
                $user->hasGoogleAccount()
                    ? "{$user->email} is connected to Google but has no photo on that account."
                    : "{$user->email} is not connected to Google."
            );

            return self::FAILURE;
        }

        // The job refuses to overwrite a photo the account already has — that
        // is the participant's own choice and it wins. --force is the operator
        // saying they know, so the slot has to be cleared for the job to fill.
        if (filled($user->avatar_path)) {
            if (! $this->option('force')) {
                $this->warn("{$user->email} already has a photo. Re-run with --force to replace it.");

                return self::FAILURE;
            }

            $user->forceFill(['avatar_path' => null])->save();
        }

        ImportGoogleAvatar::dispatch($user->getKey(), $user->google_avatar_url);

        $this->info("Queued the Google photo import for {$user->email}.");

        // Worth saying, because the silent failure this command exists to undo
        // is a job that was queued and never run.
        $this->line('It runs on the queue — make sure a worker is running.');

        return self::SUCCESS;
    }
}
