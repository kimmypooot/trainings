<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Objectives are retired from the catalogue: HRD no longer enters them on the
 * training form and neither the participant-facing pages nor the public modal
 * show them, so the column is dropped rather than left to drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->dropColumn('objectives');
        });
    }

    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->text('objectives')->nullable()->after('facilitator_contact');
        });
    }
};