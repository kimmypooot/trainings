<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The singleton row holding this deployment's office identity.
 *
 * Modelled on SiteSetting, with one deliberate difference: this row is *not*
 * created on first read. Every field falls back to config/office.php, so an
 * install with no row behaves exactly as it did when these were env settings —
 * which means the settings screen is something an office reaches for when it
 * wants to change something, not a step it must complete before the site works.
 *
 * Creating a row lazily would also mean a plain page view writes to the
 * database, and this is read on every request through the shared Inertia props.
 *
 * @property string|null $name
 * @property string|null $short_name
 * @property string|null $region
 * @property string|null $psgc_region
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $certificate_prefix
 * @property-read User|null $updatedBy
 */
#[Fillable([
    'name', 'short_name', 'region', 'psgc_region', 'address', 'phone', 'email',
    'certificate_prefix', 'updated_by',
])]
class OfficeSetting extends Model
{
    /**
     * The config keys this row overrides, in the order the settings form shows
     * them. Column name and config key are deliberately identical, so the
     * provider, the controller and the audit entry can all iterate this rather
     * than each repeating the list — three copies of a field list is three
     * chances for a new field to reach two of them.
     *
     * @var array<int, string>
     */
    public const FIELDS = [
        'name', 'short_name', 'region', 'psgc_region', 'address', 'phone',
        'email', 'certificate_prefix',
    ];

    /**
     * The fields the application cannot run without, and which therefore fall
     * back to config when the column is null.
     *
     * The distinction exists because Laravel converts an empty form field to
     * null before it reaches the controller, so "cleared" and "never set" arrive
     * identically. For an optional field the office's intent is clear — they
     * emptied the box, and a blank telephone row is a supported state — so a
     * saved row wins outright. For a required one a null can only mean the row
     * predates the field, and falling back is the safe reading; without this,
     * clearing the telephone number would be impossible because it would spring
     * back to the configured default every time.
     *
     * `certificate_prefix` is here for a second reason: it is not posted at all
     * once the prefix has locked, so a first save made after certificates exist
     * leaves the column null and has to keep using the configured value.
     *
     * @var array<int, string>
     */
    public const REQUIRED_FIELDS = [
        'name', 'short_name', 'psgc_region', 'certificate_prefix',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The one row, or null when the office has never saved any settings.
     *
     * `oldest('id')` for the same reason as SiteSetting: nothing in the
     * database enforces one row, and an unordered read would let two requests
     * disagree about which one is real. The older row is the one the
     * application has been using, so it wins.
     */
    public static function current(): ?self
    {
        return static::oldest('id')->first();
    }

    /**
     * The values this row imposes on `config('office.*')`.
     *
     * Lives here rather than in OfficeSettingsProvider so the provider (which
     * applies them at boot) and the settings controller (which has to know what
     * the values became, in order to record what changed) cannot disagree about
     * the rules. Returns an empty array when there is no row, which is what
     * makes the settings screen optional.
     *
     * @return array<string, string|null>
     */
    public static function overrides(): array
    {
        $setting = static::current();

        if ($setting === null) {
            return [];
        }

        $overrides = [];

        foreach (self::FIELDS as $field) {
            $value = $setting->{$field};

            // A required field that is null has never been set here; anything
            // else — including a deliberately emptied optional field — is the
            // office's answer. See REQUIRED_FIELDS.
            if ($value === null && in_array($field, self::REQUIRED_FIELDS, true)) {
                continue;
            }

            $overrides[$field] = $value;
        }

        return $overrides;
    }
}
