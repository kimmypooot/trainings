<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The agency reference behind the profile form's employer field.
 *
 * `profiles.organization_name` was free text, so "DEPED", "DepEd" and
 * "Department of Education" were three employers to every export, to the
 * analytics tally and to participant search — and sector and field office were
 * picked beside it with nothing tying the three together, so a DepEd employee
 * could be filed as a water district under the wrong office.
 *
 * A picked agency now carries all three. `profiles.agency_id` is nullable on
 * purpose: null *is* the "my agency is not listed" state, so nothing needs a
 * second flag to say the name was typed rather than chosen.
 *
 * `organization_name` keeps being written either way, and is still what every
 * export, roster, badge and search reads. Copying the name down rather than
 * joining to it is what let this change stay inside the profile form instead of
 * touching the twenty-odd places that read the string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agencies', function (Blueprint $table) {
            $table->id();

            /*
             * The reference's own stable key, carried from whatever list the
             * office maintains — `code` in the data file, and the reason the
             * seeder can tell a *rename* from a *new agency*.
             *
             * Matching on `name` alone cannot: correcting a spelling in the
             * file and re-seeding forks the agency into two rows, leaves every
             * profile already linked pointing at the old one, and offers the
             * new one to everybody after — which is the exact "one employer,
             * three spellings" split this table exists to end.
             *
             * Nullable because a row added by hand need not have one, and it
             * then falls back to matching on name.
             */
            $table->string('code', 64)->nullable()->unique();
            $table->string('name')->unique();
            $table->string('acronym', 32)->nullable();
            $table->string('sector');

            /*
             * The office that serves this agency. Nullable because an agency
             * can be known before its office assignment is decided, and a
             * half-known row is better than no row: the participant still gets
             * a canonical name and sector out of the pick.
             *
             * restrictOnDelete, matching how sme_evaluations guards its expert:
             * offices are deactivated, never deleted, and an agency silently
             * losing its office would send its people to no office at all.
             */
            $table->foreignId('field_office_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::table('profiles', function (Blueprint $table) {
            // nullOnDelete rather than restrict: an agency should be
            // deactivated rather than deleted, but if one ever is, the profile
            // falls back to the typed-name state it would have had anyway —
            // organization_name, sector and field_office_id all still hold
            // their values, so nothing is lost but the link.
            $table->foreignId('agency_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->dropColumn('agency_id');
        });

        Schema::dropIfExists('agencies');
    }
};
