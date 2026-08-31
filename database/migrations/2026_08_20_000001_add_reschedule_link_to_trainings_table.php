<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which run a training was created to replace.
 *
 * A rescheduled training is not an edit. The office leaves the original record
 * standing — its registration dates, its attendance, its collected fees are all
 * history that a new set of dates would falsify — and publishes the new
 * schedule as a separate run, then moves the participants across.
 *
 * That move already had a home (RegistrationService::transfer). What it lacked
 * was a way to *find* the people who needed moving. Until now the only trace
 * that two runs were related at all was a pair of ids buried in an activity-log
 * payload that nothing ever read back, so the question finance actually asks —
 * who has already paid, or signed a promissory note, against a run that is no
 * longer happening — could only be answered by someone reading a roster and
 * remembering.
 *
 * A nullable self-reference answers it in a query. Null is the norm: most runs
 * replace nothing. It is deliberately not a status on the *old* run, because
 * the old run's status is its own business — it may be closed, cancelled, or
 * left published while both dates are on offer — and hanging the relationship
 * off the replacement keeps a training that was rescheduled twice from needing
 * to name two successors.
 *
 * nullOnDelete rather than cascade: deleting an old run must never take the
 * replacement — and everyone moved onto it — down with it. The link is
 * provenance, not ownership.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->foreignId('rescheduled_from_training_id')
                ->nullable()
                ->after('created_by')
                ->constrained('trainings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->dropForeign(['rescheduled_from_training_id']);
            $table->dropColumn('rescheduled_from_training_id');
        });
    }
};
