<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The singleton row of bank-deposit details for training fees.
 *
 * Training fees are settled by depositing to the office's bank account, and
 * the account details travel in the approval notification and the payments
 * page. One row, overwritten in place — updating it updates every notification
 * and every payment prompt at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_settings', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name');
            $table->string('account_name');
            $table->string('account_number');
            // How the participant should pay and what to attach as proof.
            $table->text('instructions')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_settings');
    }
};
