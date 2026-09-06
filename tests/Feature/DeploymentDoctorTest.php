<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The post-deploy check has to actually fail on a bad deployment.
 *
 * Every setting `tims:doctor` looks at was already written down somewhere and
 * none of it was checked, so the command's whole value is that it turns
 * documentation into a gate. A doctor that reports OK on a deployment with
 * APP_DEBUG on would be worse than no doctor, because somebody would trust it.
 */
class DeploymentDoctorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  bool  $compressed  false when the test sets its own HTTP fake.
     *                            Http::fake() keeps the *first* matching stub, so a
     *                            test cannot override one registered here — it has
     *                            to ask this not to register one at all.
     */
    private function asProduction(array $config = [], bool $compressed = true): void
    {
        app()->detectEnvironment(fn () => 'production');

        config(array_merge([
            'app.debug' => false,
            'app.url' => 'https://tims.example.gov.ph',
            'session.secure' => true,
            'mail.default' => 'smtp',
            'backup.password' => 'archive-secret',
            'backup.path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'tims-doctor-offhost',
        ], $config));

        if ($compressed) {
            $this->fakeCompressedResponse();
        }
    }

    public function test_it_passes_a_correctly_configured_deployment(): void
    {
        $this->asProduction();

        // The scheduler check infers "cron is running" from a recent archive,
        // so a passing deployment needs one to exist.
        $path = (string) config('backup.path');
        @mkdir($path, 0755, true);
        touch($path.DIRECTORY_SEPARATOR.'tims-backup-2026-09-04_020000.zip');

        try {
            $this->artisan('tims:doctor')->assertSuccessful();
        } finally {
            @unlink($path.DIRECTORY_SEPARATOR.'tims-backup-2026-09-04_020000.zip');
            @rmdir($path);
        }
    }

    public function test_debug_mode_in_production_fails_the_check(): void
    {
        $this->asProduction(['app.debug' => true]);

        $this->artisan('tims:doctor')
            ->expectsOutputToContain('APP_DEBUG')
            ->assertFailed();
    }

    public function test_an_insecure_session_cookie_fails_the_check(): void
    {
        $this->asProduction(['session.secure' => false]);

        $this->artisan('tims:doctor')->assertFailed();
    }

    public function test_a_localhost_app_url_fails_the_check(): void
    {
        $this->asProduction(['app.url' => 'http://localhost:8000']);

        $this->artisan('tims:doctor')->assertFailed();
    }

    public function test_mail_that_delivers_nothing_fails_the_check(): void
    {
        $this->asProduction(['mail.default' => 'log']);

        $this->artisan('tims:doctor')->assertFailed();
    }

    public function test_an_unencrypted_backup_fails_the_check(): void
    {
        $this->asProduction(['backup.password' => null]);

        $this->artisan('tims:doctor')->assertFailed();
    }

    public function test_a_backup_path_inside_the_application_fails_the_check(): void
    {
        $this->asProduction(['backup.path' => storage_path('backups')]);

        $this->artisan('tims:doctor')->assertFailed();
    }

    /**
     * A missing archive is how the command infers a missing cron entry, which
     * is the failure that costs the most and announces itself the least.
     */
    public function test_no_recent_backup_fails_the_check(): void
    {
        $this->asProduction([
            'backup.path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'tims-doctor-empty-'.uniqid(),
        ]);

        $this->artisan('tims:doctor')
            ->expectsOutputToContain('schedule:run')
            ->assertFailed();
    }

    /**
     * On a developer machine it must stay quiet, or it becomes a command people
     * learn to scroll past — and then nobody reads it on the day it matters.
     */
    public function test_it_is_quiet_outside_production(): void
    {
        $this->artisan('tims:doctor')->assertSuccessful();
    }

    /**
     * The compression check makes a real HTTP request, so every test in this
     * file has to be insulated from the network — including the ones that have
     * nothing to do with it, which would otherwise sit through a DNS timeout
     * against a hostname that does not exist.
     *
     * Faked as compressed by default so the existing cases keep asserting what
     * they were written to assert; the cases below override it.
     */
    private function fakeCompressedResponse(): void
    {
        Http::fake(['*' => Http::response('', 200, ['Content-Encoding' => 'gzip'])]);
    }

    public function test_a_server_that_does_not_compress_fails_the_check(): void
    {
        $this->asProduction(compressed: false);

        // A page big enough that a correctly configured server would have
        // compressed it. Below about a kilobyte mod_deflate leaves the body
        // alone anyway, so a small response cannot tell us anything — see the
        // case below.
        Http::fake(['*' => Http::response(str_repeat('a', 4096), 200)]);

        // The archive is present so the scheduler check passes, which leaves
        // compression as the only thing that can fail. Asserting on the
        // command's exit status rather than on its prose is what makes this a
        // real guard: the detail text can be reworded without the test
        // quietly stopping to check anything.
        $this->backupExists(fn () => $this->artisan('tims:doctor')->assertFailed());
    }

    public function test_a_small_response_warns_rather_than_failing(): void
    {
        // mod_deflate leaves a very small body uncompressed even when it is
        // configured correctly, because deflating it would make it larger.
        // Failing a deploy on that would be a false alarm.
        $this->asProduction(compressed: false);
        Http::fake(['*' => Http::response('tiny', 200)]);

        $this->backupExists(fn () => $this->artisan('tims:doctor')->assertSuccessful());
    }

    public function test_an_error_page_warns_rather_than_failing(): void
    {
        // Found by running this against a real server: a 404 error page is
        // small and uncompressed, and reading it as "compression is off"
        // fails a deploy for a reason that has nothing to do with the check.
        $this->asProduction(compressed: false);
        Http::fake(['*' => Http::response(str_repeat('a', 4096), 503)]);

        $this->backupExists(fn () => $this->artisan('tims:doctor')->assertSuccessful());
    }

    public function test_an_unreachable_host_warns_rather_than_failing(): void
    {
        $this->asProduction(compressed: false);

        // A deploy script can reach the application before the web server is
        // serving. Failing there would make the gate untrustworthy for a
        // reason that has nothing to do with compression.
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->backupExists(function () {
            $this->artisan('tims:doctor')
                ->expectsOutputToContain('Could not reach')
                ->assertSuccessful();
        });
    }

    public function test_the_compression_check_is_skipped_outside_production(): void
    {
        // `artisan serve` does not read .htaccess and does not compress, so a
        // local run must not make the request at all.
        Http::preventStrayRequests();

        $this->artisan('tims:doctor')->assertSuccessful();
    }

    /** Run something with a recent archive present, then clean up. */
    private function backupExists(callable $body): void
    {
        $path = (string) config('backup.path');
        @mkdir($path, 0755, true);
        $archive = $path.DIRECTORY_SEPARATOR.'tims-backup-2026-09-04_020000.zip';
        touch($archive);

        try {
            $body();
        } finally {
            @unlink($archive);
            @rmdir($path);
        }
    }
}
