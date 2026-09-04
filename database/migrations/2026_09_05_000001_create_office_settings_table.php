<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who this deployment is, as a row rather than a deploy.
 *
 * This codebase goes to regional offices one copy each, and the office's own
 * name, address, contacts and region were `.env` settings — which put a
 * clerical fact behind server access, an editor and a `config:cache` clear.
 * Wrong audience: the people who know the office's new telephone number are
 * not the people with a shell on the box.
 *
 * `config/office.php` keeps its values and becomes the fallback, so a fresh
 * database still boots with a sane identity and nothing has to exist before
 * the first page renders. OfficeSettingsProvider overlays this row on top when
 * the table is there and holds one — see that class for why the read is guarded
 * rather than assumed.
 *
 * Every column is nullable and every one falls back independently, following
 * config/office.php's own rule: anything left empty is omitted rather than
 * guessed, because no telephone number beats the wrong telephone number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_settings', function (Blueprint $table) {
            $table->id();

            // The full legal name — footer, outgoing mail, the structured data
            // a search engine indexes, and the certificate masthead.
            $table->string('name')->nullable();

            // The sidebar wordmark and the certificate signature line, where
            // the full name does not fit.
            $table->string('short_name', 64)->nullable();

            // Prose, for reading: "Eastern Visayas".
            $table->string('region', 128)->nullable();

            /*
             * The same region as the PSA spells it, and a different column on
             * purpose: this one is matched against `profiles.region` to decide
             * who this office serves, which drives the physical-OR rule. It is
             * chosen from a list rather than typed, so the misspelling that
             * `tims:doctor` had to check for cannot happen here.
             */
            $table->string('psgc_region', 128)->nullable();

            $table->string('address', 500)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('email', 128)->nullable();

            /*
             * The prefix on printed certificate numbers: CSC8-2026-000042.
             *
             * Editable only until the first certificate is issued. After that
             * the screen locks it, because a change puts a permanent seam in a
             * numbered series the office quotes in correspondence, and the
             * numbers already assigned have to keep matching the paper.
             */
            $table->string('certificate_prefix', 16)->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_settings');
    }
};
