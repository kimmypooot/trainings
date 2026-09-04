<?php

namespace App\Models;

use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $date_of_birth
 * @property bool $is_pwd
 * @property Carbon|null $consented_at
 * @property-read User $user
 * @property-read FieldOffice|null $fieldOffice
 *
 * Larastan reads casts from the `$casts` property rather than the `casts()`
 * method this model uses, so the two cast columns resolved to their raw
 * database types and `$profile->date_of_birth->format(...)` read as a method
 * call on a string. Same cause as the blocks on `User`, `Registration` and
 * `ScanLink`; see CLAUDE.md.
 */
#[Fillable([
    'first_name', 'middle_name', 'last_name', 'suffix', 'date_of_birth', 'sex',
    'is_pwd', 'civil_status', 'mobile_number', 'position_title', 'salary_grade',
    'organization_name', 'sector', 'region', 'province',
    'city_municipality', 'field_office_id', 'position_level', 'employment_status',
    'organization_address', 'food_restrictions_details', 'consented_at',
])]
class Profile extends Model
{
    /** @use HasFactory<ProfileFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'is_pwd' => 'boolean',
            'consented_at' => 'datetime',
        ];
    }

    /**
     * v2 keeps no yes/no flag — the presence of text is the answer.
     */
    public function hasFoodRestrictions(): bool
    {
        return filled($this->food_restrictions_details);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fieldOffice(): BelongsTo
    {
        return $this->belongsTo(FieldOffice::class);
    }

    /**
     * Whether the participant lives outside Region VIII.
     *
     * The region field holds the canonical PSGC name ("Region VIII (Eastern
     * Visayas)", "Region XI (Davao Region)", ...), so the check is a tolerant
     * contains rather than an exact match. Fails open: a blank region is
     * treated as outside, because the physical-OR option only ever matters to
     * people who cannot collect the receipt in person — erring toward showing
     * the option is the safe side.
     */
    public function isOutsideCscRegion(): bool
    {
        if (blank($this->region)) {
            return true;
        }

        $region = mb_strtoupper((string) $this->region);

        return ! str_contains($region, 'VIII') && ! str_contains($region, 'EASTERN VISAYAS');
    }

    /**
     * The middle name reduced to an initial, which is how names are rendered
     * on certificates and event lists even though the full name is stored.
     */
    public function middleInitial(): ?string
    {
        if (blank($this->middle_name)) {
            return null;
        }

        return mb_strtoupper(mb_substr(trim($this->middle_name), 0, 1)).'.';
    }

    /**
     * Full name as it should appear on certificates and event lists.
     */
    public function fullName(): string
    {
        return collect([
            $this->first_name,
            $this->middleInitial(),
            $this->last_name,
            $this->suffix,
        ])->filter()->implode(' ');
    }

    /**
     * The inverted directory form — "Last, First M." — for listings where the
     * family name leads the line.
     */
    public function directoryName(): ?string
    {
        if (blank($this->last_name)) {
            return null;
        }

        $given = implode(' ', array_filter([
            $this->first_name,
            $this->middleInitial(),
            $this->suffix,
        ]));

        return $given === '' ? $this->last_name : $this->last_name.', '.$given;
    }
}
