<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collapse the profile photo down to a single field.
 *
 * `avatar_source` existed to arbitrate between an uploaded photo and a live
 * Google URL. The Google photo is now copied into `avatar_path` once, when the
 * account is connected (App\Jobs\ImportGoogleAvatar), so there is only ever one
 * photo and nothing left to arbitrate — and nothing rendering a third-party URL
 * on every page.
 *
 * `google_avatar` goes with it: after the import there is no reason to keep
 * Google's URL, and keeping it would invite something to start rendering it
 * again.
 *
 * Accounts whose photo was the live Google one fall back to initials. They
 * re-import on the next connect, and can upload at any time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_source', 'google_avatar']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_avatar')->nullable()->after('google_email');
            $table->string('avatar_source')->default('google')->after('avatar_path');
        });
    }
};
