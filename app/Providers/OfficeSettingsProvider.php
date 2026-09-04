<?php

namespace App\Providers;

use App\Models\OfficeSetting;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Lays the office's saved settings over config/office.php.
 *
 * The whole application already reads `config('office.*')` — about twenty call
 * sites across the footer, outgoing mail, the certificate template and the
 * shared Inertia props. Rather than rewrite each one to consult a model, the
 * row is merged into the config repository once, here, so every existing
 * reader keeps working and the settings screen is the only thing that had to
 * be built.
 *
 * `config/office.php` stays as the fallback rather than becoming vestigial:
 * each field falls back independently, so a saved row that leaves the
 * telephone number blank falls back to the configured one instead of blanking
 * a footer row. And an install that has never opened the settings screen
 * behaves exactly as it did when these were env settings, which is what makes
 * the screen optional rather than a step you must complete before the site
 * works.
 *
 * The read is guarded, and that is the load-bearing part. It runs on every
 * request, including the ones where the table cannot be there: `migrate` on an
 * empty database, a test booting before its schema exists, a deployment whose
 * database is briefly unreachable. A failure here falls through to the
 * configured defaults, so the worst case is a site showing its shipped
 * identity — not a site that will not boot. Anything else would make the
 * homepage depend on a table that exists to hold a telephone number.
 */
class OfficeSettingsProvider extends ServiceProvider
{
    public function boot(): void
    {
        self::apply();
    }

    /**
     * Merge the saved office settings into config.
     *
     * Static and re-callable because the settings controller needs it too: the
     * provider ran at boot with the *old* row, so after a save the request's
     * config is stale, and the controller has to know the new effective values
     * to record what actually changed.
     *
     * Which values win is OfficeSetting's decision, not this class's — see
     * OfficeSetting::overrides().
     */
    public static function apply(): void
    {
        try {
            $overrides = OfficeSetting::overrides();
        } catch (Throwable) {
            // No table, no database, no connection — see the class docblock.
            return;
        }

        foreach ($overrides as $key => $value) {
            config(["office.{$key}" => $value]);
        }
    }
}
