<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subject matter experts, their assignment to a run, and what participants say
 * about them at the end of each training day.
 *
 * Why this replaces `facilitator_name`/`facilitator_contact`: those two columns
 * could hold exactly one person, typed by hand, once per training — so a run
 * delivered by three resource persons recorded one of them, and the same expert
 * appearing on twenty runs was twenty unrelated strings that could never be
 * asked "how do participants rate them?". An SME is a person the office works
 * with repeatedly, which makes them reference data (like a field office), not a
 * property of an event.
 *
 * `facilitator_name` is *renamed* rather than dropped because one place read it
 * for something else entirely — the signature line on the certificate template.
 * Dropping the column would have silently degraded every certificate issued
 * after this migration to "Authorized Signatory", so the value survives under
 * the name that describes what it is actually used for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_matter_experts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            // What to print under the name on an evaluation form — the
            // reference form's heading is "Chief HR Specialist Leilani C.
            // Parel", and a participant identifies the person by the pairing.
            $table->string('position', 160)->nullable();
            $table->string('organization', 160)->nullable();
            $table->string('email')->nullable();
            $table->string('contact_number', 32)->nullable();
            // Free text, not a taxonomy: the office describes what someone is
            // brought in for in its own words, and a fixed list of subjects
            // would be wrong within a year.
            $table->text('expertise')->nullable();
            $table->text('bio')->nullable();
            $table->text('remarks')->nullable();
            // Deactivated, never deleted — the same rule field offices follow.
            // Past evaluations point here and must keep resolving to a name.
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('name');
        });

        /*
         * Which experts deliver which run.
         *
         * `days` is the list of training-day numbers this expert appears on,
         * and null means every day. Without it a three-day run with a different
         * expert each day would ask every participant to rate all three people
         * on all three evenings, two-thirds of which they never saw — and a
         * rating of somebody you did not watch is worse than no rating.
         */
        Schema::create('training_subject_matter_expert', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_matter_expert_id')->constrained()->cascadeOnDelete();
            $table->string('topic')->nullable();
            $table->json('days')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // One row per expert per run. A second assignment would double them
            // on the participant's form.
            $table->unique(['training_id', 'subject_matter_expert_id'], 'training_sme_unique');
        });

        /*
         * One submission per participant per training day.
         *
         * The reference form asks four session-level questions ("what did you
         * learn", "what needs improving") alongside the per-expert ratings.
         * Those belong to the day, not to any one expert, so they live here and
         * the ratings hang off this row — which is also what keeps a
         * participant from being asked the same four essay questions once per
         * expert on a day with three of them.
         */
        Schema::create('training_day_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registration_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('day_number');
            $table->text('learned')->nullable();
            $table->text('liked_most')->nullable();
            $table->text('needs_improvement')->nullable();
            $table->text('suggestions')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            // The idempotency guard: the form can be opened twice, and a double
            // submit must update the one row rather than stack a second.
            $table->unique(['registration_id', 'day_number'], 'day_evaluation_unique');
            $table->index(['training_id', 'day_number']);
        });

        Schema::create('sme_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_day_evaluation_id')->constrained()->cascadeOnDelete();
            /*
             * Restricted, not cascading: an expert with evaluations against
             * their name cannot be deleted out from under the record. The
             * admin screen deactivates instead, and the restriction is what
             * makes that the only available answer.
             */
            $table->foreignId('subject_matter_expert_id')->constrained()->restrictOnDelete();
            /*
             * The four criteria from the Commission's evaluation form, each on
             * the 5-point agreement scale (see App\Enums\EvaluationRating).
             * Columns rather than a JSON blob because every screen that reads
             * these averages them, and an average is the database's job.
             */
            $table->unsignedTinyInteger('knowledge_rating');
            $table->unsignedTinyInteger('interaction_rating');
            $table->unsignedTinyInteger('engagement_rating');
            $table->unsignedTinyInteger('pace_rating');
            $table->text('comments')->nullable();
            $table->timestamps();

            $table->unique(
                ['training_day_evaluation_id', 'subject_matter_expert_id'],
                'sme_evaluation_unique'
            );
            $table->index('subject_matter_expert_id');
        });

        Schema::table('trainings', function (Blueprint $table) {
            $table->renameColumn('facilitator_name', 'signatory_name');
        });

        Schema::table('trainings', function (Blueprint $table) {
            $table->dropColumn('facilitator_contact');
        });
    }

    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->string('facilitator_contact', 32)->nullable()->after('signatory_name');
        });

        Schema::table('trainings', function (Blueprint $table) {
            $table->renameColumn('signatory_name', 'facilitator_name');
        });

        Schema::dropIfExists('sme_evaluations');
        Schema::dropIfExists('training_day_evaluations');
        Schema::dropIfExists('training_subject_matter_expert');
        Schema::dropIfExists('subject_matter_experts');
    }
};
