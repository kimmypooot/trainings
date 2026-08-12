<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-day attendance, ported from v1's `attendance` table.
 *
 * The unique key on [registration_id, training_day] is the whole safety story:
 * a participant scanning twice at the door, or a scan racing a manual roster
 * entry, can only ever touch one row. It plays the same role here that
 * [user_id, training_id] plays on `registrations`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('training_day');
            $table->date('attendance_date');
            $table->string('status');
            $table->time('time_in')->nullable();
            $table->time('time_out')->nullable();
            $table->text('remarks')->nullable();
            // Null when the row came from a scan by a since-deleted staff account.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['registration_id', 'training_day']);
            $table->index(['attendance_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
