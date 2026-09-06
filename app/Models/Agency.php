<?php

namespace App\Models;

use Database\Factories\AgencyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An employer a participant can pick on the profile form.
 *
 * Reference data, like a field office or a subject matter expert: deactivated
 * rather than deleted, because the profiles pointing at it are the record of
 * who attended what and must keep resolving.
 *
 * The row carries the agency's sector and serving field office, and picking it
 * fills both — that pairing is the whole reason the table exists. It is decided
 * once here rather than by every participant guessing at it on the form.
 *
 * @property int $id
 * @property string|null $code
 * @property string $name
 * @property string|null $acronym
 * @property string $sector
 * @property int|null $field_office_id
 * @property bool $is_active
 * @property-read FieldOffice|null $fieldOffice
 */
#[Fillable(['code', 'name', 'acronym', 'sector', 'field_office_id', 'is_active'])]
class Agency extends Model
{
    /** @use HasFactory<AgencyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Keep the copies on linked profiles in step with the row they came from.
     *
     * `profiles.organization_name`, `.sector` and `.field_office_id` are
     * denormalized on purpose — they are searched with LIKE, grouped by,
     * sorted, scoped on and written into ten exports, and the typed-employer
     * path has no agency row to join to anyway. But a denormalized copy is a
     * promise to keep it current, and without this the reference could be
     * corrected while every profile already pointing at it kept the old value:
     * the list would be right and the data still wrong, which is precisely the
     * split this table exists to end.
     *
     * A model event rather than a call in the seeder because the seeder is not
     * the only writer — an admin screen is the obvious next one — and an
     * integrity rule that each new write path has to remember is one that gets
     * forgotten. (Distinct from the audit trail, which is deliberately *not*
     * on model events: that needs to know why something changed, and this does
     * not.) Note the bypass this shares with every model event: a mass update
     * through the query builder skips it.
     */
    protected static function booted(): void
    {
        static::saved(function (self $agency) {
            if (! $agency->wasChanged(['name', 'sector', 'field_office_id'])) {
                return;
            }

            $changes = [
                // The reference's own spelling, not an upper-cased copy of it.
                // A picked agency is reference data on the profile exactly as a
                // PSGC province is, so it keeps the casing this row maintains —
                // see ProfileService::upperCased, which stopped shouting it for
                // the same reason. Only a *typed* employer is upper-cased now.
                'organization_name' => $agency->name,
                'sector' => $agency->sector,
            ];

            // An agency that names no office leaves the profile's own answer
            // standing rather than blanking it — the same rule the form and
            // ProfileService::resolveEmployer apply. Blanking it would drop the
            // participant out of their field office's sight entirely.
            if ($agency->field_office_id !== null) {
                $changes['field_office_id'] = $agency->field_office_id;
            }

            // Query builder rather than a loop: this can touch every profile at
            // a large agency, and none of the writes needs a model event.
            $agency->profiles()->getQuery()->update($changes);
        });
    }

    /** @return BelongsTo<FieldOffice, $this> */
    public function fieldOffice(): BelongsTo
    {
        return $this->belongsTo(FieldOffice::class);
    }

    /** @return HasMany<Profile, $this> */
    public function profiles(): HasMany
    {
        return $this->hasMany(Profile::class);
    }

    /**
     * @param  Builder<Agency>  $query
     * @return Builder<Agency>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The list the profile form's picker is built from.
     *
     * Every row carries its own sector and office, because the picker fills
     * those two fields the moment one is chosen and a second round-trip to ask
     * the server what it just picked would put a network hop inside a keystroke.
     * The list is shipped whole and filtered in the browser: it is a few hundred
     * rows for a region, and a search endpoint would make the field unusable on
     * the venue connections this form is filled in on.
     *
     * `search` is what the combobox matches against, assembled here so the
     * client cannot decide to match on something else — an acronym is how most
     * people know their employer, and typing "DEPED" must find the agency whose
     * name is spelled out in full.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function options(): array
    {
        return self::query()
            ->active()
            ->with('fieldOffice:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'acronym', 'sector', 'field_office_id'])
            ->map(fn (self $agency) => [
                'value' => $agency->id,
                'label' => $agency->acronym
                    ? "{$agency->name} ({$agency->acronym})"
                    : $agency->name,
                'name' => $agency->name,
                'sector' => $agency->sector,
                'field_office_id' => $agency->field_office_id,
                'field_office' => $agency->fieldOffice?->name,
                'search' => mb_strtolower(trim($agency->name.' '.$agency->acronym)),
            ])
            ->all();
    }
}
