<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from v1's `certificates` and `certificate_verifications`.
 *
 * Two identifiers, deliberately: `certificate_number` is the human-readable,
 * sequential one printed on the document, while `verification_code` is random
 * and is the only thing the public lookup accepts. Exposing the sequential
 * number as the lookup key would let anyone enumerate every certificate CSC has
 * ever issued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            // One certificate per registration; re-issuing replaces the file.
            $table->foreignId('registration_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_id')->constrained()->cascadeOnDelete();
            $table->string('certificate_number', 64)->unique();
            $table->string('verification_code', 32)->unique();
            $table->string('file_path', 512)->nullable();
            $table->dateTime('generated_at')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('email_sent_at')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->unsignedInteger('verification_count')->default(0);
            $table->dateTime('last_downloaded_at')->nullable();
            $table->dateTime('last_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('certificate_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certificate_id')->constrained()->cascadeOnDelete();
            $table->dateTime('verified_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->index(['certificate_id', 'verified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_verifications');
        Schema::dropIfExists('certificates');
    }
};
