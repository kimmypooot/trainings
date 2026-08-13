<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a scanning station as a rehearsal.
 *
 * A test link behaves exactly like a real one — it downloads a real roster and
 * reaches real verdicts — but every scan it sends is rolled back rather than
 * written. That is what makes it useful for proving a venue's phones, cameras
 * and signal before the morning of the session, on the actual data.
 *
 * Stored on the link rather than chosen by the device on purpose. The station
 * is handed to someone else, often on a phone nobody controls, so whether it
 * writes cannot be a setting that end can change: it is fixed when a super
 * administrator issues the link, and the server reads it from this column on
 * every sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scan_links', function (Blueprint $table) {
            // Defaulted false so every link that already exists stays live —
            // the safe direction for an existing row is "this one is real".
            $table->boolean('is_test')->default(false)->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('scan_links', function (Blueprint $table) {
            $table->dropColumn('is_test');
        });
    }
};
