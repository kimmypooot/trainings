<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from v1's `email_logs`.
 *
 * HRD fields "did the participant ever get told?" constantly, and without a
 * record the only answer is a shrug. Written by a listener on Laravel's
 * MessageSent event, so every outbound mail is captured regardless of which
 * notification produced it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            // Nullable: mail can go to an address with no account behind it.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_email');
            $table->string('recipient_name')->nullable();
            $table->string('subject', 512);
            $table->string('status')->default('sent');
            $table->text('error_message')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['recipient_email', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
