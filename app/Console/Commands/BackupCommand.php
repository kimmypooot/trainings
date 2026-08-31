<?php

namespace App\Console\Commands;

use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * One archive holding the two things that cannot be rebuilt from source.
 *
 * The database is the obvious half. The private disk is the half that gets
 * forgotten: certificates are rendered to PDF exactly once at release and
 * deliberately never re-rendered, because a template change must not alter a
 * document already in circulation. That makes them the only artifact in the
 * app with no reproduction path — lose storage/app/private and /verify/{code}
 * starts reporting "not found" for certificates that real people are holding
 * printed copies of. Payment proofs and agency documents are evidence for
 * decisions already made, and are equally unreproducible.
 *
 * Both go into a single zip so a restore is one file to find and one file to
 * copy, rather than two that can drift apart.
 */
class BackupCommand extends Command
{
    protected $signature = 'tims:backup
                            {--path= : Write the archive here instead of the configured path}
                            {--keep= : Override how many days of archives to retain}';

    protected $description = 'Archive the database and the private disk into a single zip';

    public function handle(): int
    {
        $destination = $this->option('path') ?: config('backup.path');
        $keepDays = (int) ($this->option('keep') ?? config('backup.keep_days'));

        if (! is_dir($destination) && ! mkdir($destination, 0755, true) && ! is_dir($destination)) {
            $this->components->error("Could not create the backup directory at {$destination}.");

            return self::FAILURE;
        }

        $stamp = Carbon::now()->format('Y-m-d_His');
        $archivePath = rtrim($destination, '/\\').DIRECTORY_SEPARATOR."tims-backup-{$stamp}.zip";

        // Dumped to a temp file rather than streamed straight into the zip, so
        // a mysqldump failure is caught before a half-written archive exists on
        // disk looking like a good one.
        $dumpPath = tempnam(sys_get_temp_dir(), 'tims-sql-');

        try {
            $this->dumpDatabase($dumpPath);
            $this->writeArchive($archivePath, $dumpPath);
        } catch (RuntimeException $exception) {
            if (is_file($archivePath)) {
                unlink($archivePath);
            }

            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            @unlink($dumpPath);
        }

        $this->components->info(sprintf(
            'Wrote %s (%s).',
            basename($archivePath),
            $this->humanSize((int) filesize($archivePath))
        ));

        // Only after a good archive has landed — see config/backup.php.
        $pruned = $this->prune($destination, $keepDays, $archivePath);

        if ($pruned > 0) {
            $this->components->info("Pruned {$pruned} archive(s) older than {$keepDays} days.");
        }

        return self::SUCCESS;
    }

    /**
     * @throws RuntimeException
     */
    private function dumpDatabase(string $dumpPath): void
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        if (($config['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException(
                "tims:backup only knows how to dump MySQL; the '{$connection}' connection is ".
                ($config['driver'] ?? 'undefined').'.'
            );
        }

        // Credentials go in a defaults file rather than on the command line,
        // where every other user on the box can read them out of the process
        // list for as long as the dump runs.
        $defaultsPath = tempnam(sys_get_temp_dir(), 'tims-cnf-');
        file_put_contents($defaultsPath, sprintf(
            "[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n",
            $config['host'] ?? '127.0.0.1',
            $config['port'] ?? 3306,
            $config['username'] ?? '',
            $config['password'] ?? '',
        ));
        @chmod($defaultsPath, 0600);

        $process = new Process([
            $this->mysqldumpBinary(),
            "--defaults-extra-file={$defaultsPath}",
            '--single-transaction',
            '--routines',
            '--events',
            '--result-file='.$dumpPath,
            $config['database'],
        ]);

        $process->setTimeout(600);

        try {
            $process->run();
        } finally {
            @unlink($defaultsPath);
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException('mysqldump failed: '.trim($process->getErrorOutput()));
        }

        if (filesize($dumpPath) === 0) {
            throw new RuntimeException('mysqldump produced an empty file.');
        }
    }

    /**
     * PATH first, then the XAMPP location this project is developed against.
     * A server that keeps it somewhere else sets MYSQLDUMP_PATH.
     *
     * @throws RuntimeException
     */
    private function mysqldumpBinary(): string
    {
        if ($configured = config('backup.mysqldump')) {
            return $configured;
        }

        $lookup = new Process([PHP_OS_FAMILY === 'Windows' ? 'where' : 'which', 'mysqldump']);
        $lookup->run();

        if ($lookup->isSuccessful()) {
            $first = trim((string) strtok($lookup->getOutput(), "\r\n"));

            if ($first !== '') {
                return $first;
            }
        }

        $candidates = [
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Could not find mysqldump. Set MYSQLDUMP_PATH to its full path.');
    }

    /**
     * @throws RuntimeException
     */
    private function writeArchive(string $archivePath, string $dumpPath): void
    {
        $zip = new ZipArchive;

        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not open {$archivePath} for writing.");
        }

        $zip->addFile($dumpPath, 'database.sql');

        $privateRoot = config('filesystems.disks.local.root');

        if (is_dir($privateRoot)) {
            foreach ($this->filesUnder($privateRoot) as $absolute) {
                // Forward slashes inside the archive so a zip written on
                // Windows restores with its directory structure intact on a
                // Linux server, which is where it is most likely to be read.
                $relative = str_replace('\\', '/', substr($absolute, strlen($privateRoot) + 1));

                $zip->addFile($absolute, 'private/'.$relative);
            }
        }

        if (! $zip->close()) {
            throw new RuntimeException("Could not finish writing {$archivePath}.");
        }
    }

    /**
     * @return iterable<string>
     */
    private function filesUnder(string $root): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                yield $file->getPathname();
            }
        }
    }

    /**
     * Deletes archives past the retention window, never the one just written —
     * a clock skew or a mistyped --keep must not eat the archive this run has
     * only just produced.
     */
    private function prune(string $destination, int $keepDays, string $justWritten): int
    {
        if ($keepDays <= 0) {
            return 0;
        }

        $cutoff = Carbon::now()->subDays($keepDays)->getTimestamp();
        $pruned = 0;
        $pattern = rtrim($destination, '/\\').DIRECTORY_SEPARATOR.'tims-backup-*.zip';

        foreach (glob($pattern) ?: [] as $candidate) {
            if (realpath($candidate) === realpath($justWritten)) {
                continue;
            }

            if (filemtime($candidate) < $cutoff) {
                unlink($candidate);
                $pruned++;
            }
        }

        return $pruned;
    }

    private function humanSize(int $bytes): string
    {
        foreach (['B', 'KB', 'MB'] as $unit) {
            if ($bytes < 1024) {
                return $bytes.' '.$unit;
            }

            $bytes = (int) round($bytes / 1024);
        }

        return $bytes.' GB';
    }
}
