<?php

use App\Enums\PaymentStatus;
use App\Enums\RequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from v1's `payments`, plus the refund workflow that lived only as
 * pages there (`request-refund.php`, `refund-mgmt.php`) with no table of
 * its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained()->cascadeOnDelete();
            // Denormalised from the registration so the collecting officer's
            // queue and the exports do not have to join through it.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('payment_method');
            $table->string('reference_number', 64)->nullable();
            $table->date('payment_date');
            // Proof of payment lives on a private disk, never a public URL.
            $table->string('proof_path', 512)->nullable();
            $table->string('status')->default(PaymentStatus::Pending->value);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['training_id', 'status']);
        });

        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->text('reason');
            $table->string('status')->default(RequestStatus::Pending->value);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();
            $table->dateTime('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_requests');
        Schema::dropIfExists('payments');
    }
};
