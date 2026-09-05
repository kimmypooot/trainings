<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function asProduction(array $config = []): void
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
}
