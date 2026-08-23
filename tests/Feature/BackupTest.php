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
}
