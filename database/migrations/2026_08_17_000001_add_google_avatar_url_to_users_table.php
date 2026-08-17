<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers where the Google photo was fetched from, so the import can be run
 * again if it never happened.
 *
 * The photo is imported once, on a queued job, and the URL otherwise lives
 * only for the length of the OAuth callback. That is fine until the job does
 * not run — a worker that was not started, a `queue:flush` — at which point
 * the account is left with initials and nothing in the system knows where the
 * photo was meant to come from. The participant cannot fix it themselves
 * either: the usual disconnect-and-reconnect is refused for a Google-created
 * account, because Google is the only way into it.
 *
 * Deliberately *not* used for rendering — see ImportGoogleAvatar for why the
 * bytes are copied rather than hot-linked. This column exists for
 * `tims:import-google-avatar` alone, and Google's URLs rotate, so it is a
 * backstop for soon after the fact rather than an archive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_avatar_url')->nullable()->after('google_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('google_avatar_url');
        });
    }
};
