<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A scannable address for one training day's evaluation form.
 *
 * The office puts a code on the wall at the end of a session and the room fills
 * the form in before it leaves. What the code replaces is not authentication —
 * everyone in the room already has an account — but *navigation*: without it a
 * participant has to find the site, sign in, open their evaluations list, pick
 * the right training out of several, and then pick the right day. Five decisions,
 * every one of which they can get wrong, standing between a session ending and
 * the only moment anybody is still willing to give feedback.
 *
 * So the token here is an address, not a credential, and the distinction decides
 * the whole schema. Compare `scan_links`, whose token is paired with a hashed
 * code because that door is unauthenticated and writes attendance *for other
 * people*: possession of the link is the authority, so possession has to be
 * proven. Nothing of the sort applies here. Scanning this code opens a session-
 * authenticated page which then asks who you are and whether you are on the
 * roster; a stranger who photographs it off the wall reaches a page telling them
 * they are not registered. Hashing the token would protect nothing and cost the
 * ability to look one up.
 *
 * What is deliberately *not* stored is any judgement about the day: whether it
 * is open, whether it collects a form at all, who may answer it. All of that is
 * re-derived from SmeEvaluationService on every scan, because the expert panel
 * can be edited after the codes are printed — and a code asserting a fact that
 * has since stopped being true is worse than one that asserts nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_day_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_id')->constrained()->cascadeOnDelete();

            /*
             * The day this code speaks for, in the same numbering as
             * `attendances.training_day` and `training_day_evaluations.day_number`.
             *
             * Not every day of a run has one: an expert who returns tomorrow is
             * rated at the end of their stretch, so a four-day course delivered
             * by one person collects a single evaluation and needs a single
             * code. Training::evaluationDays() is what decides which days get
             * one, and it is asked at issue time rather than encoded here.
             */
            $table->unsignedSmallInteger('day_number');

            // 40 random characters, in the URL, stored as-is. See the class
            // comment: this identifies a day, it does not authorise anything.
            $table->string('token', 40)->unique();

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * dateTime, not timestamp, and this is load-bearing on MySQL — the
             * same trap `scan_links` documents at length. MySQL attaches
             * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` to the
             * first NOT NULL TIMESTAMP column that declares no default, and
             * `last_scanned_at` is written on the very first scan.
             */
            $table->dateTime('revoked_at')->nullable();

            /*
             * Whether the sign on the wall is being used at all.
             *
             * The question this answers is asked in the room, not afterwards:
             * "we put the code up twenty minutes ago and have three responses —
             * is the poster in a dark corner, or is nobody filling it in?" A
             * count with no scans and a count with forty scans call for
             * completely different interventions.
             */
            $table->dateTime('last_scanned_at')->nullable();
            $table->unsignedInteger('scan_count')->default(0);

            $table->timestamps();

            /*
             * One live code per day, rotated in place.
             *
             * The alternative — a history of codes with the retired ones flagged
             * — is what `scan_links` does, and it is right there because several
             * doors of one training legitimately need their own link at once.
             * Here there is exactly one wall, one day, one sign. Regenerating
             * overwrites the token, which kills the printed sheet instantly;
             * that is the entire point of regenerating, and a unique index says
             * so more clearly than a `revoked_at` on a superseded row.
             */
            $table->unique(['training_id', 'day_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_day_codes');
    }
};
