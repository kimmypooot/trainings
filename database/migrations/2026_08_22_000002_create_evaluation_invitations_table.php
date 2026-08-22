<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which participants have already been asked to evaluate which training day.
 *
 * The invitation goes out on the evening of each day, from a scheduled command.
 * Without a record of what was sent, a command that runs twice — a retry, a
 * second scheduler on a staging box, an operator running it by hand after a
 * failure — mails the same room again, and a participant who has been asked
 * three times for the same session stops reading the messages entirely.
 *
 * Deliberately a table of its own rather than a nullable column on
 * `training_day_evaluations`: that row means "the participant answered", and
 * making it also mean "we asked" would put a stub in every query that counts
 * responses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('day_number');
            $table->timestamp('sent_at');
            $table->timestamps();

            // The guard itself: one invitation per participant per day, enforced
            // by the database rather than by the command remembering.
            $table->unique(['registration_id', 'day_number'], 'evaluation_invitation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_invitations');
    }
};
