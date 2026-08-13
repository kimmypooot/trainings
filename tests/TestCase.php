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
