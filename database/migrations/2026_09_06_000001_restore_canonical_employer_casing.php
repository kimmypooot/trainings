<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Put the reference's own spelling back on the profiles that came from it.
 *
 * `profiles.organization_name` was upper-cased for every profile, including the
 * ones whose employer was *picked* from the agency list rather than typed. That
 * made a participant's own record disagree with the picker they chose it in and
 * with the `agencies` row it was copied from — one employer, two spellings,
 * which is the split that table exists to end. ProfileService::upperCased now
 * leaves a picked name alone, exactly as it already left PSGC place names
 * alone; this brings the rows written before that into line.
 *
 * Only rows carrying an `agency_id`. A null one *is* the typed path, and typed
 * employers stay upper-cased: free text has no owner to be canonical for it,
 * and the convention matches the name and position title beside it on the same
 * record. Guessing a proper spelling for those would invent data — `DEPED` has
 * no correct capitalisation this migration could know.
 *
 * Safe to run against a database with no linked profiles, which is what a
 * deployment that has not started using the picker yet looks like: the join
 * matches nothing and the migration is a no-op.
 *
 * Query builder rather than Eloquent, deliberately. The `saved` hook on Agency
 * pushes a changed name out to every linked profile, and touching agencies here
 * would fire it once per row for a value it is already about to write.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('profiles')
            ->join('agencies', 'profiles.agency_id', '=', 'agencies.id')
            ->whereNotNull('profiles.agency_id')
            ->update(['profiles.organization_name' => DB::raw('agencies.name')]);
    }

    /**
     * Reversible, and lossless in the direction that matters.
     *
     * Upper-casing is a one-way transform on free text — but not on these rows:
     * every one of them takes its name from an agency, so `up()` can always
     * reconstruct the proper spelling from the reference again. Nothing is
     * stranded by going back.
     */
    public function down(): void
    {
        DB::table('profiles')
            ->whereNotNull('agency_id')
            ->update(['organization_name' => DB::raw('UPPER(organization_name)')]);
    }
};
