<?php

namespace App\Support;

use Database\Seeders\AgencySeeder;

/**
 * The agency list this deployment serves, from database/data/agencies.json.
 *
 * A data file rather than a PHP array, for the reason spelled out on
 * FieldOfficeReference: this codebase goes to regional offices one copy each,
 * and the employers in Region VIII's catchment are not the ones in Region IV's.
 * Compiling a list in would ship one region's org chart to all of them.
 *
 * Unlike the office list, an empty file here is a legitimate state rather than a
 * broken install. An office that has not built its list yet still has a working
 * profile form — every participant simply takes the "not listed" path and types
 * their employer, which is exactly what the form did before this table existed.
 * So nothing fails loudly on an empty list, and nothing should.
 *
 * Each entry names its field office by *code* rather than id, because ids are
 * assigned by the migration and a deployment's data file cannot know them.
 *
 * @see AgencySeeder
 */
class AgencyReference
{
    /** @var array<int, array<string, mixed>>|null */
    private static ?array $agencies = null;

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        if (self::$agencies === null) {
            $raw = @file_get_contents(self::path());

            self::$agencies = json_decode($raw ?: '', true) ?: [];
        }

        return self::$agencies;
    }

    public static function path(): string
    {
        return database_path('data/agencies.json');
    }

    /**
     * Forget the cached list. Only tests that swap the file need this.
     */
    public static function flush(): void
    {
        self::$agencies = null;
    }
}
