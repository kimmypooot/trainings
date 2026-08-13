<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last of v1's training fields that the create form never carried across.
 *
 * `meeting_link` is the important one. Until now an online run had to hide its
 * join link inside `venue`, which meant the one piece of information a remote
 * participant needs was stored in a column labelled as a street address — not
 * separable in exports, not linkable in the UI, and silently lost the moment
 * someone edited the venue of a hybrid run to name the physical room.
 *
 * `accepts_promissory` and `is_supervisory` are policy flags: whether the
 * collecting officer will take a promissory note in place of payment, and
 * whether the run is an SDC, which obliges participants to submit an output.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->string('level', 32)->nullable()->after('category');
            // Free text beside the venue line: directions, room number, parking.
            $table->text('venue_details')->nullable()->after('venue');
            $table->string('meeting_link')->nullable()->after('venue_details');
            // Defaulted to the more permissive value, matching v1's pre-checked
            // box — refusing promissory notes is the exception, not the norm.
            $table->boolean('accepts_promissory')->default(true)->after('payment_amount');
            $table->boolean('is_supervisory')->default(false)->after('accepts_promissory');
        });
    }

    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->dropColumn([
                'level', 'venue_details', 'meeting_link', 'accepts_promissory', 'is_supervisory',
            ]);
        });
    }
};
