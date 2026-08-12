<?php

use App\Enums\RequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three request queues ported from v1: cancellation requests,
 * agency-requested trainings, and post-training output submissions.
 *
 * They share a review shape — status, reviewed_by, reviewed_at, review_remarks
 * — mirroring the columns already on `registrations`, so staff screens and
 * tests read the same way across all three.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancellation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            $table->string('status')->default(RequestStatus::Pending->value);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        // Only one request may be open per registration at a time. A partial
        // unique index would be neater, but it is not portable across the
        // MySQL used in production and the SQLite used by the test suite, so
        // the rule is enforced in CancellationRequestService instead.
        Schema::create('training_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('justification');
            $table->string('category', 100)->nullable();
            $table->unsignedInteger('expected_participants')->nullable();
            $table->date('preferred_start')->nullable();
            $table->date('preferred_end')->nullable();
            $table->string('status')->default(RequestStatus::Pending->value);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();
            // Set when HRD turns an approved request into a real training.
            $table->foreignId('training_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('registration_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            // Stored on a private disk and served through an authorising route.
            $table->string('file_path', 512);
            $table->string('original_filename');
            $table->unsignedInteger('file_size');
            $table->string('mime_type', 128);
            $table->string('status')->default(RequestStatus::Pending->value);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();
            $table->timestamps();

            $table->index(['registration_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_outputs');
        Schema::dropIfExists('training_requests');
        Schema::dropIfExists('cancellation_requests');
    }
};
