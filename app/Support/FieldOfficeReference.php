<?php

namespace App\Support;

use Database\Seeders\FieldOfficeSeeder;

/**
 * The office list this deployment serves, from database/data/field-offices.json.
 *
 * Held in one place so the migration that creates the table and the seeder that
 * keeps it current cannot drift apart — the migration needs the rows in place
 * before profiles can be linked to them.
 *
 * It is a data file rather than a PHP array because the rows are a deployment
 * fact, not a constant: this codebase goes to regional offices one copy each,
 * and every office has different field offices, different jurisdictions and
 * different people running them. As an array it was Regional Office VIII's nine
 * offices compiled into the application, seeded automatically on first migrate,
 * so another region's installation came up holding Biliran, Leyte I and II and
 * the rest — an org chart belonging to a region 500km away, with names attached.
 *
 * An office replaces the file before its first `migrate`, the same way it
 * replaces the facade photograph. Deliberately not `config/`: it is a list of
 * records rather than settings, it is long, and it is read at migration time
 * when a config cache may be cold or stale.
 *
 * @see FieldOfficeSeeder
 * @see docs/deployment.md
 */
class FieldOfficeReference
{
    /** @var array<int, array<string, mixed>>|null */
    private static ?array $offices = null;

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        if (self::$offices === null) {
            $raw = file_get_contents(self::path());

            self::$offices = json_decode($raw ?: '', true) ?: [];
        }

        return self::$offices;
    }

    /**
     * Where the list lives.
     *
     * Exposed so the seeder's failure message and the deployment check can name
     * the file, and so a test can point at a fixture without writing over the
     * committed one.
     */
    public static function path(): string
    {
        return database_path('data/field-offices.json');
    }

    /**
     * Forget the cached list.
     *
     * Only needed by tests that swap the file underneath a booted application;
     * nothing in a request's lifetime changes it.
     */
    public static function flush(): void
    {
        self::$offices = null;
    }
}
