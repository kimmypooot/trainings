<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The uploaded photo's path on the private `local` disk. Never a
            // public URL — the file is streamed by ProfilePhotoController.
            $table->string('avatar_path')->nullable()->after('google_avatar');
            // Which of the two the user actually wants shown. The literal
            // 'google' rather than the enum case it came from: a migration has
            // to keep running after the app class it was written against is
            // gone, and this one is — see the migration that drops this column.
            $table->string('avatar_source')->default('google')->after('avatar_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_path', 'avatar_source']);
        });
    }
};
