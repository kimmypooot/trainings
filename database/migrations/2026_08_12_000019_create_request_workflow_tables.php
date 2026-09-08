<?php

use App\Enums\RequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Request queues ported from v1: cancellation requests and post-training
 * output submissions.
 *
 * They share a review shape — status, reviewed_by, reviewed_at, review_remarks
 * — mirroring the columns already on `registrations`, so staff screens and
 * tests read the same way across both.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Only one request may be open per registration at a time. A partial
        // unique index would be neater, but MySQL has no such feature, so the
        // rule is enforced in CancellationRequestService instead.
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
        Schema::dropIfExists('cancellation_requests');
    }
};
