<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from v1's `email_templates`.
 *
 * HRD sends the same handful of messages over and over — a payment reminder, a
 * venue change, a certificate-ready notice. Retyping them is how a wrong date
 * or a missing venue goes out to two hundred people, so the wording is stored
 * once and personalised per recipient at send time.
 *
 * `code` is the stable handle for templates the system itself relies on;
 * `is_system` keeps those from being deleted out from under it. Everything else
 * HRD can create and remove freely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Null for the ad-hoc templates HRD writes; set only on the ones
            // code looks up by name.
            $table->string('code', 64)->nullable()->unique();
            $table->string('subject', 512);
            $table->text('body');
            $table->string('category', 32)->default('general');
            $table->boolean('is_system')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['category', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
