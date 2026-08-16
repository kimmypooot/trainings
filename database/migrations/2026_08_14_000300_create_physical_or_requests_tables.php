<?php

use App\Enums\PhysicalOrRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A participant outside Region VIII may ask for a physical copy of their
 * official receipt, rather than the online copy everyone gets. Shipping costs
 * money, so the request carries a courier fee that is paid via GCash and
 * verified against a screenshot before anything is prepared.
 *
 * Three tables: the requests themselves, their append-only status trail, and a
 * single-row settings table holding the GCash details and delivery
 * instructions that Admin/Super Admin can edit without a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('physical_or_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_code', 32)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            // Snapshot of the courier fee at request time — raising the fee
            // later must not change what an already-filed request owes.
            $table->decimal('courier_fee', 10, 2);
            $table->string('status')->default(PhysicalOrRequestStatus::RequestSubmitted->value);
            // The participant's GCash screenshot. Private disk, never public.
            $table->string('proof_path', 512)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            // Shipping information, filled in only once the request is shipped.
            $table->string('courier_name')->nullable();
            $table->string('tracking_number', 128)->nullable();
            $table->dateTime('shipped_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('physical_or_request_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('physical_or_request_id')->constrained()->cascadeOnDelete();
            // Null on the opening row: the request did not come from anywhere.
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('notes')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');

            // Explicit short name — MySQL's 64-char limit truncates the
            // default `physical_or_request_status_logs_...` composite name.
            $table->index(['physical_or_request_id', 'changed_at'], 'physical_or_logs_request_changed');
        });

        // A singleton row: the GCash details and delivery instructions shown in
        // the participant's request modal. Admin and Super Admin edit these.
        Schema::create('physical_or_settings', function (Blueprint $table) {
            $table->id();
            $table->string('gcash_number', 32);
            $table->string('account_name');
            $table->decimal('courier_fee', 10, 2);
            $table->text('delivery_instructions');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('physical_or_settings');
        Schema::dropIfExists('physical_or_request_status_logs');
        Schema::dropIfExists('physical_or_requests');
    }
};
