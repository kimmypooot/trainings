<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who signs a certificate when the training itself does not say.
 *
 * `trainings.signatory_name` already lets one run be signed by whoever
 * actually presided over it, and that stays — but most runs are signed by the
 * same person (the office's Director, typically), and leaving every training
 * form's signatory field blank meant every certificate fell back to the
 * template's bare "Authorized Signatory", which is not who signed it. This is
 * the office-wide default that field falls back to before that generic text,
 * the same two-tier fallback every other office_settings column already
 * follows against config/office.php.
 *
 * Nullable, like the rest of this table: an office that has not set one gets
 * the generic "Authorized Signatory" line rather than a blank name, which is
 * the honest state for a deployment that has not decided who signs yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('office_settings', function (Blueprint $table) {
            $table->string('default_signatory_name', 128)->nullable()->after('certificate_prefix');
            $table->string('default_signatory_title', 128)->nullable()->after('default_signatory_name');
        });
    }

    public function down(): void
    {
        Schema::table('office_settings', function (Blueprint $table) {
            $table->dropColumn(['default_signatory_name', 'default_signatory_title']);
        });
    }
};
