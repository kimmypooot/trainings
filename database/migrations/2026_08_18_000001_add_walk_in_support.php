<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Walk-ins: people who turn up at the venue without having registered.
 *
 * Two columns, and they answer two different questions.
 *
 * `trainings.accepts_walk_ins` is a policy flag, the same shape as
 * `accepts_promissory` beside it: whether *this* run was published as one where
 * somebody may be admitted at the door. It defaults to false — the opposite of
 * the promissory flag — because a walk-in relaxes both the registration
 * deadline and the capacity cap, and that has to be a decision an organiser
 * made on purpose for a specific event, never something a training inherits by
 * being created.
 *
 * `registrations.is_walk_in` is a fact about how one person got in. It could
 * almost be derived — a registration created after the run started is a walk-in
 * — but "almost" is the problem: the office needs to count walk-ins per event
 * to plan chairs, meals and kits for the next one, and a report that infers the
 * number from timestamps would quietly miscount anyone admitted during a
 * rescheduled or backdated run. A column states it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->boolean('accepts_walk_ins')->default(false)->after('accepts_promissory');
        });

        Schema::table('registrations', function (Blueprint $table) {
            $table->boolean('is_walk_in')->default(false)->after('registered_at');
        });
    }

    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->dropColumn('accepts_walk_ins');
        });

        Schema::table('registrations', function (Blueprint $table) {
            $table->dropColumn('is_walk_in');
        });
    }
};
