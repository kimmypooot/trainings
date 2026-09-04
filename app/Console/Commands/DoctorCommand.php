<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Is this deployment actually configured the way the runbook says?
 *
 * Everything here was already written down — in .env.example, in README's
 * Deploying section, in config/backup.php — and nothing checked any of it. That
 * gap has a particular shape: the settings that matter most fail *silently*.
 * `APP_DEBUG=true` in production does not break a page, it just hands stack
 * traces, SQL and environment values to anyone who can trigger a 500. A queue
 * worker that was never started does not error, it simply means no participant
 * ever receives an email. A scheduler cron entry nobody added means the nightly
 * backup does not exist, and that is discovered on the day it is needed.
 *
 * So this is the post-deploy step: run it, read it, and only then call the
 * deployment done. Failures exit non-zero so a deploy script can gate on it;
 * warnings do not, because some are legitimately deferred.
 */
class DoctorCommand extends Command
{
    protected $signature = 'tims:doctor {--all : Run the production checks even outside production}';

    protected $description = 'Check that this deployment is configured for production';

    /** @var array<int, array{level: string, label: string, detail: string}> */
    private array $results = [];

    public function handle(): int
    {
        $production = app()->isProduction() || $this->option('all');

        if (! $production) {
            $this->components->info(
                'Not running in production — checking only what applies anywhere. Use --all to see every check.'
            );
        }

        $this->checkApplication($production);
        $this->checkSessionAndCookies($production);
        $this->checkCaches($production);
        $this->checkQueue();
        $this->checkScheduler($production);
        $this->checkBackups($production);
        $this->checkMail($production);
        $this->checkStorage();

        return $this->report();
    }

    private function checkApplication(bool $production): void
    {
        $this->assert(
            ! config('app.debug') || ! $production,
            'APP_DEBUG is off',
            'APP_DEBUG=true in production hands stack traces, SQL and .env values to anyone who can trigger a 500.'
        );

        $this->assert(
            filled(config('app.key')),
            'APP_KEY is set',
            'Without it sessions, encrypted cookies and every scan-link grant cannot be decrypted.'
        );

        $url = (string) config('app.url');

        $this->assert(
            ! $production || ! str_contains($url, 'localhost'),
            'APP_URL is a real hostname',
            "APP_URL is {$url}. It is the root of every generated URL and every mailed link, and it is "
            .'baked permanently into certificates at render time.'
        );

        $this->assert(
            ! $production || str_starts_with($url, 'https://'),
            'APP_URL is https',
            "APP_URL is {$url}, so mailed links will send people to plain HTTP."
        );
    }

    private function checkSessionAndCookies(bool $production): void
    {
        $this->assert(
            ! $production || config('session.secure') === true,
            'Session cookie is HTTPS-only',
            'SESSION_SECURE_COOKIE is not true, so the session cookie can be sent in the clear on an HTTP fallback.'
        );

        $this->assert(
            config('session.http_only') !== false,
            'Session cookie is HttpOnly',
            'SESSION_HTTP_ONLY is false, so scripts on the page can read the session cookie.'
        );

        /*
         * Not a failure, because it only bites behind a proxy — but when it
         * bites it takes every per-IP throttle with it, and silently. See
         * config/trustedproxy.php.
         */
        $this->warnIf(
            $production && config('trustedproxy.proxies') === null,
            'Proxy trust is unset',
            'If this deployment sits behind a load balancer or reverse proxy, set TRUSTED_PROXIES — otherwise '
            .'every rate limit counts against the proxy as one client and HTTPS is invisible to Laravel.'
        );
    }

    private function checkCaches(bool $production): void
    {
        foreach ([
            'config' => app()->getCachedConfigPath(),
            'routes' => app()->getCachedRoutesPath(),
            'events' => app()->getCachedEventsPath(),
        ] as $what => $path) {
            $this->warnIf(
                $production && ! file_exists($path),
                "The {$what} cache is cold",
                "Run `php artisan optimize` — {$what} are re-parsed on every request without it.",
            );
        }
    }

    /**
     * The queue is the check most worth having, because its failure is
     * invisible: nothing errors, participants simply never hear anything.
     */
    private function checkQueue(): void
    {
        if (config('queue.default') !== 'database') {
            return;
        }

        try {
            $pending = DB::table('jobs')->count();
            $stale = DB::table('jobs')
                ->where('available_at', '<', Carbon::now()->subMinutes(15)->getTimestamp())
                ->count();
            $failed = DB::table('failed_jobs')->count();
        } catch (Throwable $e) {
            $this->results[] = ['level' => 'fail', 'label' => 'Queue tables readable', 'detail' => $e->getMessage()];

            return;
        }

        $this->assert(
            $stale === 0,
            'Queue is being worked',
            "{$stale} job(s) have been waiting over 15 minutes (of {$pending} queued). A worker is probably not "
            .'running: every participant email, certificate release and announcement goes through it.'
        );

        $this->warnIf(
            $failed > 0,
            'There are failed jobs',
            "{$failed} job(s) in failed_jobs. Inspect with `php artisan queue:failed`."
        );
    }

    /**
     * There is no scheduler heartbeat in Laravel, so this asks the question
     * through its most consequential effect instead: if `schedule:run` is in
     * cron, last night produced a backup.
     */
    private function checkScheduler(bool $production): void
    {
        // A developer machine has no backups and no cron, and a check that
        // always fails there is one people learn to scroll past.
        if (! $production) {
            return;
        }

        $path = (string) config('backup.path');
        $newest = null;

        foreach (glob(rtrim($path, '/\\').DIRECTORY_SEPARATOR.'tims-backup-*.zip') ?: [] as $archive) {
            $newest = max($newest ?? 0, filemtime($archive));
        }

        if ($newest === null) {
            $this->results[] = [
                'level' => 'fail',
                'label' => 'A backup exists',
                'detail' => "No archive found in {$path}. Either `tims:backup` has never run, or the scheduler "
                    .'cron entry (`* * * * * php artisan schedule:run`) was never added — without it, none of the '
                    .'three scheduled jobs run at all.',
            ];

            return;
        }

        $age = Carbon::createFromTimestamp($newest)->diffForHumans();

        $this->assert(
            $newest > Carbon::now()->subHours(36)->getTimestamp(),
            'Last backup is recent',
            "The newest archive is from {$age}. The backup is scheduled for 02:00 daily, so anything older than "
            .'a day and a half means the scheduler is not running.'
        );
    }

    private function checkBackups(bool $production): void
    {
        $this->assert(
            ! $production || filled(config('backup.password')),
            'Backups are encrypted',
            'BACKUP_PASSWORD is not set. The archive holds every participant record, every bank account number '
            .'and every stored document.'
        );

        $path = realpath((string) config('backup.path')) ?: (string) config('backup.path');
        $inside = str_starts_with(
            str_replace('\\', '/', $path).'/',
            rtrim(str_replace('\\', '/', base_path()), '/').'/'
        );

        $this->assert(
            ! $production || ! $inside,
            'Backups are written off-host',
            "BACKUP_PATH ({$path}) is inside the application directory, so the archive dies with the disk it is "
            .'protecting against losing.'
        );

        $this->warnIf(
            $production,
            'Has a restore been rehearsed?',
            'This cannot be checked from here. `tims:restore` exists so it can be: restore the newest archive into '
            .'a scratch database and record how long it took. A backup nobody has restored is not a backup.'
        );
    }

    private function checkMail(bool $production): void
    {
        $mailer = config('mail.default');

        $this->assert(
            ! $production || ! in_array($mailer, ['log', 'array'], true),
            'Mail has a real transport',
            "MAIL_MAILER is '{$mailer}', so nothing is actually delivered — including password resets and "
            .'certificate releases.'
        );
    }

    private function checkStorage(): void
    {
        foreach ([storage_path('app/private'), storage_path('framework'), storage_path('logs')] as $path) {
            $this->assert(
                is_dir($path) && is_writable($path),
                'Writable: '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $path),
                "{$path} is not writable by the web user."
            );
        }

        // config/logging.php has already exploded LOG_STACK into an array.
        $stack = (array) config('logging.channels.stack.channels', []);

        $this->warnIf(
            in_array('single', $stack, true) || config('logging.default') === 'single',
            'Logs are not rotated',
            'LOG_STACK=single writes one file that grows without bound. Use `daily` with a retention.'
        );
    }

    private function assert(bool $passed, string $label, string $detail): void
    {
        $this->results[] = [
            'level' => $passed ? 'pass' : 'fail',
            'label' => $label,
            'detail' => $detail,
        ];
    }

    private function warnIf(bool $condition, string $label, string $detail): void
    {
        if ($condition) {
            $this->results[] = ['level' => 'warn', 'label' => $label, 'detail' => $detail];
        }
    }

    private function report(): int
    {
        $failures = 0;

        foreach ($this->results as $result) {
            match ($result['level']) {
                'pass' => $this->components->twoColumnDetail($result['label'], '<fg=green>OK</>'),
                'warn' => $this->components->twoColumnDetail($result['label'], '<fg=yellow>CHECK</>'),
                default => $this->components->twoColumnDetail($result['label'], '<fg=red>FAIL</>'),
            };

            if ($result['level'] !== 'pass') {
                $this->line('    <fg=gray>'.wordwrap($result['detail'], 96, "\n    ").'</>');
            }

            if ($result['level'] === 'fail') {
                $failures++;
            }
        }

        $this->newLine();

        if ($failures > 0) {
            $this->components->error("{$failures} check(s) failed. This deployment is not ready to serve.");

            return self::FAILURE;
        }

        $this->components->info('No failures. Read any CHECK lines above before calling the deploy done.');

        return self::SUCCESS;
    }
}
