<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The address on the linked Google account, which is not required
            // to be the address the CSC corresponds with: a participant
            // registers with their agency email and signs in with a personal
            // Gmail. Stored so the Linked Accounts card can name the account
            // that signs them in — otherwise "Connected" says nothing about
            // *what* is connected, and there is no way to notice the wrong
            // Google account was picked.
            $table->string('google_email')->nullable()->after('google_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('google_email');
        });
    }
};
