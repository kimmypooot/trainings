<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single-row settings table. It currently holds the one flag every request
 * has to answer — "are we in maintenance?" — so toggling it is an UPDATE, not
 * a deploy, and the change is in force on the very next page load.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('maintenance_mode')->default(false);
            // Shown on the maintenance page while the flag is set. Nullable so
            // a toggle can take the site down without forcing a message.
            $table->text('maintenance_message')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
