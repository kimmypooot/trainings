<?php

use App\Enums\TrainingStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trainings', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('venue');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('registration_closes_at')->nullable();
            // Null means no limit; a number is enforced transactionally on register.
            $table->unsignedInteger('capacity')->nullable();
            $table->string('status')->default(TrainingStatus::Draft->value);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'starts_at']);
        });

        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->dateTime('registered_at');
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('attended_at')->nullable();
            $table->timestamps();

            // The duplicate guard. One row per participant per training — a
            // cancellation flips the status rather than adding another row, so
            // re-registering later is still possible.
            $table->unique(['user_id', 'training_id']);
            $table->index(['training_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrations');
        Schema::dropIfExists('trainings');
    }
};
