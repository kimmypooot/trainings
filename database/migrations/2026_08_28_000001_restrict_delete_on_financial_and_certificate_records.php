<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments, refund_requests and certificates cascade-deleted from
 * registrations/trainings/users, unlike sme_evaluations, which deliberately
 * restricts the delete because expert reference data is "deactivate, never
 * delete". Money and issued-certificate records deserve the same guard: no
 * route deletes a Training, Registration or User today, but a future admin
 * delete feature, a tinker session, or a raw SQL fix against production
 * should not be able to silently take payment history and issued
 * certificates down with it. Restricting forces that decision to be made on
 * purpose — clear the payments/certificates first — rather than by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['registration_id']);
            $table->dropForeign(['user_id']);
            $table->dropForeign(['training_id']);
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('registration_id')->references('id')->on('registrations')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('training_id')->references('id')->on('trainings')->restrictOnDelete();
        });

        Schema::table('refund_requests', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
        });
        Schema::table('refund_requests', function (Blueprint $table) {
            $table->foreign('payment_id')->references('id')->on('payments')->restrictOnDelete();
        });

        Schema::table('certificates', function (Blueprint $table) {
            $table->dropForeign(['registration_id']);
            $table->dropForeign(['user_id']);
            $table->dropForeign(['training_id']);
        });
        Schema::table('certificates', function (Blueprint $table) {
            $table->foreign('registration_id')->references('id')->on('registrations')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('training_id')->references('id')->on('trainings')->restrictOnDelete();
        });

        // "My pending payments" (PaymentController::index, participant-facing)
        // filters by user_id + status; the FK gives user_id its own index, but
        // not this composite.
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status']);
        });

        Schema::table('certificates', function (Blueprint $table) {
            $table->dropForeign(['registration_id']);
            $table->dropForeign(['user_id']);
            $table->dropForeign(['training_id']);
        });
        Schema::table('certificates', function (Blueprint $table) {
            $table->foreign('registration_id')->references('id')->on('registrations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('training_id')->references('id')->on('trainings')->cascadeOnDelete();
        });

        Schema::table('refund_requests', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
        });
        Schema::table('refund_requests', function (Blueprint $table) {
            $table->foreign('payment_id')->references('id')->on('payments')->cascadeOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['registration_id']);
            $table->dropForeign(['user_id']);
            $table->dropForeign(['training_id']);
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('registration_id')->references('id')->on('registrations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('training_id')->references('id')->on('trainings')->cascadeOnDelete();
        });
    }
};
