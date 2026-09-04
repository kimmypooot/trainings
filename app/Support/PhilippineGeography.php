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

    /**
     * Whether a stored region string denotes the same region as $canonical.
     *
     * The profile form validates against regions(), so `profiles.region` holds
     * a canonical name in anything entered through the app. Imported and older
     * rows are less tidy — "REGION VIII", "Region VIII" — so this is tolerant
     * rather than an equality check, which is what the code it replaces did.
     *
     * What that code could not do is generalise. It asked
     * `str_contains($region, 'VIII')`, and a bare numeral is not safe to match
     * on: "REGION VIII" contains "VII", so an office in Region VII would have
     * read every Region VIII participant as one of its own. Hence the word
     * boundaries — "REGION VII" as a needle stops matching "REGION VIII" once
     * the numeral has to end where the needle does.
     *
     * A canonical name splits into the parts people actually write: the whole
     * string, the parenthetical ("Eastern Visayas", "NCR"), and the part
     * before it ("Region VIII"). Any one of them identifies the region.
     */
    public static function denotesRegion(?string $candidate, ?string $canonical): bool
    {
        if (blank($candidate) || blank($canonical)) {
            return false;
        }

        $haystack = self::normalise($candidate);

        foreach (self::aliasesFor($canonical) as $alias) {
            if (preg_match('/\b'.preg_quote($alias, '/').'\b/u', $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The ways a canonical region name is written in the wild, longest first
     * so the most specific alias is tried before its own fragments.
     *
     * @return array<int, string>
     */
    private static function aliasesFor(string $canonical): array
    {
        $normalised = self::normalise($canonical);
        $aliases = [$normalised];

        if (preg_match('/^(.*?)\s*\((.+)\)$/u', $normalised, $matches) === 1) {
            // "REGION VIII (EASTERN VISAYAS)" → also "REGION VIII" and
            // "EASTERN VISAYAS", either of which people write on its own.
            $aliases[] = trim($matches[1]);
            $aliases[] = trim($matches[2]);
        }

        return array_values(array_filter(array_unique($aliases)));
    }

    /** Upper-cased with runs of whitespace collapsed, so spacing cannot decide a match. */
    private static function normalise(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtoupper($value)));
    }
}
