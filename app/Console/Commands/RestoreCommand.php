<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Put a `tims:backup` archive back.
 *
 * This exists because a backup nobody has restored is not a backup — it is a
 * file that is *probably* a backup. `tims:backup` was written and scheduled and
 * never had a counterpart, which meant the recovery procedure was "somebody
 * works it out during the incident", and the one thing an incident does not
 * supply is time to work things out.
 *
 * So it is a command rather than a page in a runbook. A procedure that can be
 * *run* is a procedure that can be rehearsed on a copy, timed, and turned into
 * an RPO and an RTO somebody has actually measured; a procedure written down is
 * a procedure that is true until the day it is needed.
 *
 * Deliberately destructive and deliberately loud about it. It drops every table
 * in the target database and overwrites the private disk, so it confirms twice,
 * refuses production outright unless forced, and reports what it is about to do
 * in terms of the archive rather than of its own options.
 */
class RestoreCommand extends Command
{
    protected $signature = 'tims:restore
                            {archive : Path to a tims-backup-*.zip}
                            {--password= : Archive password; falls back to BACKUP_PASSWORD}
                            {--database= : Restore into this database instead of the configured one}
                            {--skip-files : Restore the database only, leaving the private disk alone}
                            {--force : Skip the confirmations (required in production)}';

    protected $description = 'Restore the database and private disk from a tims:backup archive';

    public function handle(): int
    {
        $archive = $this->argument('archive');

        if (! is_file($archive)) {
            $this->components->error("No archive at {$archive}.");

            return self::FAILURE;
        }

        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        if (($config['driver'] ?? null) !== 'mysql') {
            $this->components->error('tims:restore only knows how to restore MySQL.');

            return self::FAILURE;
        }

        $database = $this->option('database') ?: $config['database'];

        if (! $this->confirmDestruction($database)) {
            return self::FAILURE;
        }

        $workspace = $this->makeWorkspace();

        try {
            $this->extract($archive, $workspace);
            $this->loadDatabase($workspace.DIRECTORY_SEPARATOR.'database.sql', $config, $database);

            if (! $this->option('skip-files')) {
                $this->restorePrivateDisk($workspace.DIRECTORY_SEPARATOR.'private');
            }
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $this->deleteTree($workspace);
        }

        $this->components->info("Restored {$database} from ".basename($archive).'.');

        /*
         * Said out loud because it is the step a rehearsal skips and an
         * incident cannot. A restored database carries the *old* schema; if the
         * archive predates a migration, the application will boot against
         * columns that are not there and fail in ways that look like data
         * corruption rather than a missing migration.
         */
        $this->components->warn('Run `php artisan migrate --force` next: the archive carries the schema it was taken with.');

        return self::SUCCESS;
    }

    /**
     * Two gates, because this drops every table in the target database.
     *
     * Production is refused outright without --force rather than merely
     * confirmed: the overwhelmingly common reason to run this is to rehearse
     * onto a scratch database, and a typo in --database is exactly how a
     * rehearsal becomes the incident.
     */
    private function confirmDestruction(string $database): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (app()->isProduction()) {
            $this->components->error(
                "Refusing to restore over the production database ({$database}) without --force. "
                .'If this really is the recovery, pass --force; if it is a rehearsal, pass --database with a scratch name.'
            );

            return false;
        }

        $this->components->warn("This will DROP every table in `{$database}` and replace it from the archive.");

        return $this->confirm("Restore over `{$database}`?", false);
    }

    private function makeWorkspace(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tims-restore-'.bin2hex(random_bytes(6));

        if (! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException("Could not create a working directory at {$path}.");
        }

        return $path;
    }

    /**
     * @throws RuntimeException
     */
    private function extract(string $archive, string $workspace): void
    {
        $zip = new ZipArchive;

        if ($zip->open($archive) !== true) {
            throw new RuntimeException("Could not open {$archive}.");
        }

        $password = (string) ($this->option('password') ?: config('backup.password'));

        if ($password !== '') {
            $zip->setPassword($password);
        }

        if (! $zip->extractTo($workspace)) {
            $zip->close();

            /*
             * The commonest cause by far, and the one worth naming: a wrong or
             * missing password. libzip reports it as a plain extraction failure,
             * so a bare "could not extract" would send somebody hunting for a
             * corrupt archive when the archive is fine.
             */
            throw new RuntimeException(
                'Could not extract the archive. If it is encrypted, check --password / BACKUP_PASSWORD.'
            );
        }

        $zip->close();

        if (! is_file($workspace.DIRECTORY_SEPARATOR.'database.sql')) {
            throw new RuntimeException('The archive has no database.sql — is it a tims:backup archive?');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws RuntimeException
     */
    private function loadDatabase(string $dumpPath, array $config, string $database): void
    {
        // Same defaults-file trick as tims:backup: credentials on the command
        // line are readable out of the process list by every other user on the
        // box for as long as the load runs.
        $defaultsPath = tempnam(sys_get_temp_dir(), 'tims-cnf-');
        file_put_contents($defaultsPath, sprintf(
            "[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n",
            $config['host'] ?? '127.0.0.1',
            $config['port'] ?? 3306,
            $config['username'] ?? '',
            $config['password'] ?? '',
        ));
        @chmod($defaultsPath, 0600);

        try {
            $this->dropEveryTable($database);

            $process = Process::fromShellCommandline(
                sprintf(
                    '%s --defaults-extra-file=%s %s < %s',
                    escapeshellarg($this->mysqlBinary()),
                    escapeshellarg($defaultsPath),
                    escapeshellarg($database),
                    escapeshellarg($dumpPath),
                )
            );

            // A large private disk and a large dump both take a while, and a
            // restore timing out half way is worse than one that takes an hour.
            $process->setTimeout(1800);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException('mysql import failed: '.trim($process->getErrorOutput()));
            }
        } finally {
            @unlink($defaultsPath);
        }
    }

    /**
     * The dump carries CREATE TABLE, not DROP DATABASE, so anything the archive
     * does not know about would survive a plain import and leave a hybrid of
     * two schemas — which is far harder to diagnose than an empty database.
     */
    private function dropEveryTable(string $database): void
    {
        $previous = config('database.connections.'.config('database.default').'.database');

        config(['database.connections.'.config('database.default').'.database' => $database]);
        DB::purge();

        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            foreach (DB::select('SHOW TABLES') as $row) {
                $table = array_values((array) $row)[0];
                DB::statement('DROP TABLE IF EXISTS `'.$table.'`');
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            config(['database.connections.'.config('database.default').'.database' => $previous]);
            DB::purge();
        }
    }

    private function mysqlBinary(): string
    {
        if ($configured = config('backup.mysql')) {
            return $configured;
        }

        $lookup = new Process([PHP_OS_FAMILY === 'Windows' ? 'where' : 'which', 'mysql']);
        $lookup->run();

        if ($lookup->isSuccessful() && ($found = strtok(trim($lookup->getOutput()), "\r\n"))) {
            return $found;
        }

        foreach (['C:\\xampp\\mysql\\bin\\mysql.exe', '/usr/bin/mysql', '/usr/local/bin/mysql'] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Could not find the mysql client. Set MYSQL_PATH to its full path.');
    }

    /**
     * Copy the archived private disk over the live one.
     *
     * Additive rather than a wipe-and-replace: a certificate present on disk but
     * absent from the archive is a document somebody may be holding a printed
     * copy of, and deleting it to match an older archive would destroy the one
     * artifact in this system with no reproduction path.
     */
    private function restorePrivateDisk(string $source): void
    {
        if (! is_dir($source)) {
            $this->components->warn('The archive carried no private files; the disk was left alone.');

            return;
        }

        $target = config('filesystems.disks.local.root');
        $restored = 0;

        foreach ($this->filesUnder($source) as $absolute) {
            $relative = substr($absolute, strlen($source) + 1);
            $destination = $target.DIRECTORY_SEPARATOR.$relative;
            $directory = dirname($destination);

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            copy($absolute, $destination);
            $restored++;
        }

        $this->components->info("Restored {$restored} private file(s).");
    }

    /**
     * @return iterable<string>
     */
    private function filesUnder(string $root): iterable
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                yield $file->getPathname();
            }
        }
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
