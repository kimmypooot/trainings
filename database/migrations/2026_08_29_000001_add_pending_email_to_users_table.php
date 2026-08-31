<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The address a participant has asked to move to, before they have proved they
 * can read it.
 *
 * Deliberately *not* the `email` column and deliberately not unique. Until the
 * link is clicked this is only a claim, and a claim must not be able to take an
 * address out of circulation — otherwise typing someone else's address into the
 * form is enough to stop them ever registering with it. The uniqueness that
 * matters is still the one on `email`, checked again at the moment of the swap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_email')->nullable()->after('email');
            $table->timestamp('pending_email_requested_at')->nullable()->after('pending_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pending_email', 'pending_email_requested_at']);
        });
    }
};
