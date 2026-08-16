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
        $setting = static::first();

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
