<?php

namespace App\Support;

/**
 * The PSGC reference behind the cascading Region → Province → City/Municipality
 * pickers on the profile form.
 *
 * The dataset is the Philippine Standard Geographic Code (PSGC) published by
 * the Philippine Statistics Authority (PSA) — 18 regions (including the
 * Negros Island Region), their provinces, and their cities/municipalities —
 * committed as a JSON file (database/data/psgc.json) so the app stays
 * offline-friendly. The profile's region, province and city_municipality
 * fields store the canonical proper-case names, which keeps the validation
 * here and the dropdowns the frontend renders on one source of truth, the
 * same way ProfileOptions does for the fixed lists.
 *
 * Highly urbanized cities are carried at the provincial level, as the official
 * PSGC does, so the province picker includes entries like "City of Tacloban"
 * alongside Leyte. A few places that have no province row of their own
 * (NCR's Pateros, Region IX's City of Isabela, and the BARMM Special
 * Geographic Area) are modelled as pseudo-provinces carrying themselves.
 */
class PhilippineGeography
{
    /**
     * @var array<int, array{name: string, provinces: array<int, array{name: string, cities: array<int, string>}>}>|null
     */
    private static ?array $data = null;

    private static function data(): array
    {
        if (self::$data === null) {
            $raw = file_get_contents(database_path('data/psgc.json'));
            self::$data = json_decode($raw ?: '', true) ?: [];
        }

        return self::$data;
    }

    /** @return array<int, string> */
    public static function regions(): array
    {
        return array_column(self::data(), 'name');
    }

    /** @return array<int, string> */
    public static function provincesOf(string $region): array
    {
        $node = collect(self::data())->first(fn (array $r) => $r['name'] === $region);

        return $node ? array_column($node['provinces'], 'name') : [];
    }

    /** @return array<int, string> */
    public static function citiesOf(string $province): array
    {
        foreach (self::data() as $region) {
            foreach ($region['provinces'] as $node) {
                if ($node['name'] === $province) {
                    return $node['cities'];
                }
            }
        }

        return [];
    }

    public static function isValidRegion(string $region): bool
    {
        return in_array($region, self::regions(), true);
    }

    public static function isValidProvinceFor(string $province, string $region): bool
    {
        return in_array($province, self::provincesOf($region), true);
    }

    public static function isValidCityFor(string $city, string $province): bool
    {
        return in_array($city, self::citiesOf($province), true);
    }

    /**
     * The full dataset in nested form, for the frontend cascade.
     *
     * @return array<int, array{name: string, provinces: array<int, array{name: string, cities: array<int, string>}>}>
     */
    public static function nested(): array
    {
        return self::data();
    }
}
