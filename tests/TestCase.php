<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PDO;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests run on a real MySQL server (see phpunit.xml), so the dedicated
     * test database may not exist yet on a fresh machine. Create it before the
     * application boots so `RefreshDatabase` migrations have somewhere to go.
     */
    protected function setUp(): void
    {
        $this->ensureTestDatabaseExists();

        parent::setUp();

        /*
         * Nothing in this suite asserts an asset URL, but almost everything in
         * it renders app.blade.php, and that template's @vite() throws
         * ViteManifestNotFoundException when public/build/manifest.json is
         * absent. Locally the manifest is always there because somebody has
         * run `npm run build`; in CI the PHP job never builds the frontend, so
         * every Inertia page render 500s and the suite reports 241 failures
         * saying "Not a valid Inertia response" — one missing file wearing 241
         * disguises, and none of them naming it.
         *
         * Building assets in the PHP job would also fix it, at the cost of
         * doubling that job to check something the JS job already checks:
         * `npm run build` is that job's Vue-template correctness gate, and is
         * commented there as exactly that. So the PHP suite stubs the tag out
         * instead and stays a test of server behaviour.
         */
        $this->withoutVite();
    }

    private function ensureTestDatabaseExists(): void
    {
        if (getenv('DB_CONNECTION') !== 'mysql') {
            return;
        }

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $database = getenv('DB_DATABASE') ?: 'laravel';
        $username = getenv('DB_USERNAME') ?: 'root';
        $password = getenv('DB_PASSWORD') ?: '';

        $pdo = new PDO(
            "mysql:host={$host};port={$port}",
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
}
