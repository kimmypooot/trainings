<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The v1 registration and receipt fields the port left behind.
 *
 * `charge_to` and `needs_certificate` are asked at registration time and read
 * much later — the first by finance (who issues the OR to, the participant or
 * their agency) and the second by HRD at release, since a participant who does
 * not need a certificate should not have one printed for them. Both were on
 * v1's registration form and neither has anywhere to live in v2.
 *
 * `supporting_document_path` backs the supervisory-course rule: a participant
 * at SG 11–17 has to show they actually supervise staff before they can take
 * an SDC. See SupervisoryEligibility for the rule itself.
 *
 * On payments, the OR block is the receipt record proper. v2 has a generic
 * `reference_number`, which is where an online transfer's trace number goes —
 * that is not the same thing as the official receipt CSC issues, and finance
 * reconciles against the latter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            // Nullable rather than defaulted: existing rows genuinely were not
            // asked, and guessing "personal" for them would be inventing data
            // that finance later reconciles against.
            $table->string('charge_to', 16)->nullable()->after('status');
            $table->boolean('needs_certificate')->default(true)->after('charge_to');
            $table->string('supporting_document_path', 512)->nullable()->after('needs_certificate');
        });

        Schema::table('payments', function (Blueprint $table) {
            // The official receipt. Unique because two registrations sharing an
            // OR number is either a transcription slip or a duplicate, and both
            // are worth catching at the point of entry.
            $table->string('or_number', 32)->nullable()->unique()->after('reference_number');
            $table->date('or_date')->nullable()->after('or_number');
            // Who issued it. A user rather than v1's free-text name, so the
            // roster of officers stays consistent and an officer's payments
            // remain findable after a name change.
            $table->foreignId('collecting_officer_id')->nullable()->after('or_date')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('collecting_officer_id');
            $table->dropUnique(['or_number']);
            $table->dropColumn(['or_number', 'or_date']);
        });

        Schema::table('registrations', function (Blueprint $table) {
            $table->dropColumn(['charge_to', 'needs_certificate', 'supporting_document_path']);
        });
    }
};
