<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The singleton row of site-wide settings.
 *
 * Modelled on PhysicalOrSetting: one row, created lazily with safe defaults
 * and overwritten in place. Currently the only setting is the maintenance
 * switch that EnsureSiteIsAvailable reads on every request — the superadmin
 * turns it on here, and the whole public site plus every non-superadmin
 * session immediately sees the maintenance page.
 */
#[Fillable([
    'maintenance_mode', 'maintenance_message', 'updated_by',
])]
class SiteSetting extends Model
{
    protected function casts(): array
    {
        return [
            'maintenance_mode' => 'boolean',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The one row, created on first read so the rest of the app never has to
     * decide what the defaults are — those live here, in the one place that
     * owns them.
     */
    public static function current(): self
    {
        /*
         * `oldest('id')`, not a bare `first()`.
         *
         * There is meant to be one row, and nothing in the database enforces
         * that: two requests arriving together can both find none and both
         * create one, and a restore or a hand-run insert can do the same. An
         * unordered read then returns whichever row the storage engine feels
         * like, so two requests a second apart can disagree about a setting —
         * and the answer is stable in development right up until the day it
         * is not.
         *
         * Ordering does not stop a duplicate being written. It makes every
         * reader agree on which row is the real one, which is the half that
         * actually matters: the older row is the one the application has been
         * using, so it wins.
         */ $setting = static::oldest('id')->first();

        if ($setting !== null) {
            return $setting;
        }

        return static::create([
            'maintenance_mode' => false,
        ]);
    }

    /**
     * Whether the site is in maintenance right now.
     *
     * A single primary-key read per request, deliberately not cached: the
     * toggle has to be in force on the next request, and the read is cheap
     * enough that no cache invalidation dance is worth it.
     */
    public static function isInMaintenance(): bool
    {
        return static::current()->maintenance_mode;
    }
}
