<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The PRIME-HRM 20% discount, recorded against the payment it was granted on.
 *
 * Two columns rather than one, and the second is the important one. A bare
 * boolean would leave the discount to be recomputed at report time from
 * `trainings.payment_amount` — which is editable, so raising a course fee next
 * year would silently rewrite every historical revenue figure for that course,
 * making money that was never collected appear to have been.
 *
 * `discount_amount` freezes the peso value at the moment it was granted. The
 * gross is then `amount + discount_amount` and the rate is derivable from the
 * pair, so nothing else needs storing and nothing can drift afterwards. It is
 * the same reasoning that has certificates rendered to PDF once at release.
 *
 * Existing rows backfill to false/0, which reads as "full price" — true of every
 * payment taken before this feature existed, so historical revenue is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->boolean('prime_hrm_discount')->default(false)->after('amount');
            $table->decimal('discount_amount', 10, 2)->default(0)->after('prime_hrm_discount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['prime_hrm_discount', 'discount_amount']);
        });
    }
};
