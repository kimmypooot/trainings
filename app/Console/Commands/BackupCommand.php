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

        if ($problem = $this->productionObjection($destination)) {
            $this->components->error($problem);

            return self::FAILURE;
        }

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
     * The two ways a production backup is worse than no backup, refused.
     *
     * Both were previously documented and neither was enforced, which is the
     * gap this closes: config/backup.php was honest that an on-host archive
     * "protects against a bad migration or a deleted file, but not against the
     * disk", and honest documentation of a gap is not a mitigation. The default
     * is what ships, and the default was the unsafe one.
     *
     * Only in production. A developer running `tims:backup` on a laptop wants
     * the archive next to the project and has no network share to point at, and
     * making them configure one to try the command would just teach them to
     * skip it.
     *
     * Both are overridable by explicit configuration rather than by a flag: a
     * deployment that genuinely wants a plaintext on-host archive can say so in
     * .env, which leaves a record of the decision where the next person looks.
     */
    private function productionObjection(string $destination): ?string
    {
        if (! app()->isProduction()) {
            return null;
        }

        if ((string) config('backup.password') === '') {
            return 'BACKUP_PASSWORD is not set. The archive holds every participant record, '
                .'every bank account number and every stored document — it must not leave this '
                .'machine unencrypted. Set BACKUP_PASSWORD (and keep it somewhere other than this server).';
        }

        $real = realpath($destination) ?: $destination;
        $inside = static fn (string $root) => str_starts_with(
            str_replace('\\', '/', $real).'/',
            rtrim(str_replace('\\', '/', $root), '/').'/'
        );

        if ($inside(storage_path()) || $inside(base_path())) {
            return sprintf(
                'BACKUP_PATH (%s) is inside the application directory, so the archive dies with the disk '
                .'it is protecting. Point it at a mapped network drive, a synced folder, or anything that '
                .'leaves this machine.',
                $destination
            );
        }

        return null;
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
        $password = (string) config('backup.password');
        $encrypt = $password !== '';

        if ($encrypt && ! defined('ZipArchive::EM_AES_256')) {
            /*
             * Refused rather than downgraded. A password was configured, which
             * is somebody stating that this archive must be encrypted; writing
             * it in the clear anyway — with a cheerful "Wrote 41 MB" — is how
             * an office ends up believing its backups are protected for a year.
             *
             * Depends on the server's libzip, not on PHP itself, so it is
             * checked at run time on the machine that will actually write the
             * archive.
             */
            throw new RuntimeException(
                'BACKUP_PASSWORD is set but this PHP build has no zip encryption support '
                .'(libzip too old). Refusing to write an unencrypted archive.'
            );
        }

        $zip = new ZipArchive;

        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not open {$archivePath} for writing.");
        }

        if ($encrypt) {
            $zip->setPassword($password);
        }

        // Every entry is encrypted individually — zip has no archive-level
        // flag, so a file added without this call lands in the clear inside an
        // otherwise-encrypted archive. Collected as they are added so nothing
        // can be missed.
        $entries = [];

        $zip->addFile($dumpPath, 'database.sql');
        $entries[] = 'database.sql';

        $privateRoot = config('filesystems.disks.local.root');

        if (is_dir($privateRoot)) {
            foreach ($this->filesUnder($privateRoot) as $absolute) {
                // Forward slashes inside the archive so a zip written on
                // Windows restores with its directory structure intact on a
                // Linux server, which is where it is most likely to be read.
                $relative = str_replace('\\', '/', substr($absolute, strlen($privateRoot) + 1));

                $zip->addFile($absolute, 'private/'.$relative);
                $entries[] = 'private/'.$relative;
            }
        }

        if ($encrypt) {
            foreach ($entries as $entry) {
                if (! $zip->setEncryptionName($entry, ZipArchive::EM_AES_256)) {
                    // Close first, or the half-written archive stays locked by
                    // this handle and the caller cannot delete it.
                    $zip->close();

                    throw new RuntimeException("Could not encrypt {$entry} inside the archive.");
                }
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
