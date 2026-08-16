<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The participants directory's filter set, in one place.
 *
 * The on-screen listing and the export both read it, and that is the point:
 * v1's "Export All" button carried whatever the administrator had narrowed the
 * table down to into the download, and the only way that stays true is if both
 * surfaces narrow the query through the same code.
 *
 * base() carries the field-office restriction that ExportScopingTest and
 * FieldOfficeScopingTest guard — it is not a filter the user can clear.
 */
class ParticipantFilter
{
    /**
     * The filters as they arrived, normalised to strings so they can be handed
     * straight back to the page for the controls to re-select.
     *
     * @return array<string, string>
     */
    public static function fromRequest(Request $request): array
    {
        return [
            'search' => $request->string('search')->toString(),
            'status' => $request->string('status')->toString(),
            'verified' => $request->string('verified')->toString(),
            'sector' => $request->string('sector')->toString(),
            'region' => $request->string('region')->toString(),
        ];
    }

    /**
     * Every participant this staff member may see, before any filtering.
     *
     * A field-office account sees its own office only. `scopedFieldOfficeId()`
     * resolves to 0 when the account has no office assigned, which matches
     * nothing — failing closed rather than exposing the region.
     */
    public static function base(?int $officeId): Builder
    {
        return User::query()
            ->where('role', Role::Participant)
            ->when($officeId !== null, fn (Builder $query) => $query->whereHas(
                'profile',
                fn (Builder $profile) => $profile->where('field_office_id', $officeId)
            ));
    }

    /**
     * @param  array<string, string>  $filters
     */
    public static function apply(Builder $query, array $filters): Builder
    {
        $search = $filters['search'] ?? '';
        $status = $filters['status'] ?? '';
        $verified = $filters['verified'] ?? '';
        $sector = $filters['sector'] ?? '';
        $region = $filters['region'] ?? '';

        return $query
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhereHas('profile', fn (Builder $p) => $p
                    ->where('organization_name', 'like', "%{$search}%")
                    ->orWhere('mobile_number', 'like', "%{$search}%")
                )
            ))
            ->when($status === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->when($verified === '1', fn (Builder $q) => $q->whereNotNull('email_verified_at'))
            ->when($verified === '0', fn (Builder $q) => $q->whereNull('email_verified_at'))
            // A profile filter on a participant who has not filled one in
            // excludes them, which is the honest answer: they have no sector.
            ->when($sector !== '', fn (Builder $q) => $q->whereHas(
                'profile',
                fn (Builder $p) => $p->where('sector', $sector)
            ))
            ->when($region !== '', fn (Builder $q) => $q->whereHas(
                'profile',
                fn (Builder $p) => $p->where('region', $region)
            ));
    }

    /**
     * The sector and region values actually present in the visible set.
     *
     * Derived rather than taken from ProfileOptions so the dropdowns never
     * offer a value that would filter the table down to nothing.
     *
     * @return array{sectors: array<int, string>, regions: array<int, string>}
     */
    public static function options(?int $officeId): array
    {
        $distinct = fn (string $column) => Profile::query()
            ->when($officeId !== null, fn (Builder $query) => $query->where('field_office_id', $officeId))
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->all();

        return [
            'sectors' => $distinct('sector'),
            'regions' => $distinct('region'),
        ];
    }
}
