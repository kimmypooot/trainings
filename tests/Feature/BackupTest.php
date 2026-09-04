<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * The archive has to contain both halves or it is not a backup.
 *
 * A dump alone restores the rows that say a certificate was issued, without
 * the PDF that was issued — and the PDF is the half that cannot be rebuilt,
 * since certificates are rendered exactly once at release. These tests exist
 * because a backup that is quietly missing one half looks exactly like a
 * working one until the day it is needed.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $destination;

    protected function setUp(): void
    {
        parent::setUp();

        $this->destination = storage_path('framework/testing/backups-'.uniqid());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->destination.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->destination);

        parent::tearDown();
    }

    public function test_the_archive_holds_the_database_and_the_private_disk(): void
    {
        // A stand-in for a released certificate: a file on the private disk
        // with no reproduction path anywhere else.
        Storage::disk('local')->put('certificates/2026/CSC-TEST-0001.pdf', 'issued-document');

        $this->artisan('tims:backup', ['--path' => $this->destination])
            ->assertSuccessful();

        $archive = $this->onlyArchive();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archive) === true, 'The archive could not be opened.');

        $sql = $zip->getFromName('database.sql');
        $this->assertIsString($sql, 'The archive contains no database.sql.');
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('registrations', $sql);

        $this->assertSame(
            'issued-document',
            $zip->getFromName('private/certificates/2026/CSC-TEST-0001.pdf'),
            'The private disk was not captured — a restore would lose every issued certificate.'
        );

        $zip->close();
    }

    public function test_it_prunes_archives_past_the_retention_window(): void
    {
        mkdir($this->destination, 0755, true);

        $stale = $this->destination.DIRECTORY_SEPARATOR.'tims-backup-2020-01-01_000000.zip';
        $recent = $this->destination.DIRECTORY_SEPARATOR.'tims-backup-2020-01-02_000000.zip';
        file_put_contents($stale, 'old');
        file_put_contents($recent, 'newer');
        touch($stale, Carbon::now()->subDays(30)->getTimestamp());
        touch($recent, Carbon::now()->subDays(2)->getTimestamp());

        $this->artisan('tims:backup', ['--path' => $this->destination, '--keep' => 14])
            ->assertSuccessful();

        $this->assertFileDoesNotExist($stale);
        $this->assertFileExists($recent);
    }

    /**
     * The run's own archive is never a pruning candidate — otherwise a zero or
     * mistyped retention deletes the backup it has just finished writing.
     */
    public function test_the_new_archive_survives_an_aggressive_retention(): void
    {
        $this->artisan('tims:backup', ['--path' => $this->destination, '--keep' => 1])
            ->assertSuccessful();

        $this->assertFileExists($this->onlyArchive());
    }

    private function onlyArchive(): string
    {
        $found = glob($this->destination.DIRECTORY_SEPARATOR.'tims-backup-*.zip') ?: [];

        $this->assertCount(1, $found, 'Expected exactly one archive to have been written.');

        return $found[0];
    }

    // ---------------------------------------------------------- encryption

    /*
     * The archive holds every participant record, every refund payee's bank
     * account number and every stored document, and the whole point of the
     * configured path is that it leaves this machine. Unencrypted, that is all
     * of it in the clear on every device the destination folder reaches.
     */

    public function test_a_password_encrypts_every_entry(): void
    {
        config(['backup.password' => 'archive-secret']);
        Storage::disk('local')->put('certificates/one.pdf', 'PDF');

        $this->artisan('tims:backup', ['--path' => $this->destination])->assertSuccessful();

        $zip = new ZipArchive;
        $zip->open($this->onlyArchive());

        $this->assertGreaterThan(0, $zip->numFiles);

        // Every entry, not just the dump: zip has no archive-level flag, so a
        // file added without setEncryptionName lands in the clear inside an
        // otherwise-encrypted archive.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            $this->assertFalse(
                @$zip->getFromIndex($i),
                "{$name} was readable without the password."
            );
        }

        $zip->setPassword('archive-secret');
        $this->assertSame('PDF', $zip->getFromName('private/certificates/one.pdf'));
        $this->assertNotFalse($zip->getFromName('database.sql'));
        $zip->close();
    }

    public function test_without_a_password_the_archive_is_readable(): void
    {
        config(['backup.password' => null]);

        $this->artisan('tims:backup', ['--path' => $this->destination])->assertSuccessful();

        $zip = new ZipArchive;
        $zip->open($this->onlyArchive());

        // Stated rather than assumed, so the encryption test above is known to
        // be measuring the password and not some unrelated property of zip.
        $this->assertNotFalse($zip->getFromName('database.sql'));
        $zip->close();
    }

    // -------------------------------------------------- production refusals

    /*
     * Both of these were documented and neither was enforced, which is the gap:
     * config/backup.php was honest that an on-host archive "protects against a
     * bad migration or a deleted file, but not against the disk", and honest
     * documentation of a gap is not a mitigation. The default is what ships.
     */

    public function test_production_refuses_to_write_an_unencrypted_archive(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config(['backup.password' => null]);

        $this->artisan('tims:backup', ['--path' => $this->destination])->assertFailed();

        $this->assertSame(
            [],
            glob($this->destination.DIRECTORY_SEPARATOR.'*.zip') ?: [],
            'An archive was written despite the refusal.'
        );
    }

    public function test_production_refuses_a_destination_inside_the_application(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config(['backup.password' => 'archive-secret']);

        // storage_path() is inside base_path(), which is exactly the default
        // this refusal exists to catch.
        $this->artisan('tims:backup', ['--path' => storage_path('backups')])->assertFailed();
    }

    public function test_production_accepts_an_encrypted_off_host_archive(): void
    {
        $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tims-offhost-'.uniqid();

        app()->detectEnvironment(fn () => 'production');
        config(['backup.password' => 'archive-secret']);

        try {
            $this->artisan('tims:backup', ['--path' => $outside])->assertSuccessful();

            $this->assertNotEmpty(glob($outside.DIRECTORY_SEPARATOR.'*.zip') ?: []);
        } finally {
            foreach (glob($outside.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($outside);
        }
    }
}
