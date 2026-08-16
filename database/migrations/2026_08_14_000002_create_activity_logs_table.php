<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One audit trail, replacing v1's five.
 *
 * v1 kept `activity_logs`, `security_logs`, `registration_status_logs`,
 * `refund_status_logs` and `training_activity_log` side by side, each with its
 * own columns and its own idea of who an actor is. Answering "what happened to
 * this participant" meant five queries and a manual merge, so in practice
 * nobody asked.
 *
 * This is one polymorphic table instead. The subject is whatever the action
 * happened *to* — a registration, a payment, a certificate — so the trail for
 * any record is a single `where`. Actions are dotted strings (`payment.verified`)
 * rather than an enum, because the vocabulary grows with every feature and a
 * migration per new verb is friction that ends with people not logging at all.
 *
 * What v1's table mostly held — login and logout rows, thousands of them — is
 * deliberately *not* here. Laravel's session handling and the rate limiter
 * already cover the security questions those rows were meant to answer, and
 * their volume is what buried the decisions worth keeping. `users.last_login_at`
 * carries the one piece of that which staff actually read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            // What the action happened to. Nullable because a few actions
            // (a bulk export, a failed lookup) have no single subject.
            $table->nullableMorphs('subject');

            // Who did it. Null for anything the system did on its own — a
            // scheduled reminder, a queued release — and null after the actor's
            // account is deleted, since the trail must outlive the account.
            $table->foreignId('causer_id')->nullable()->constrained('users')->nullOnDelete();
            // Denormalised: the whole point of an audit trail is that it still
            // reads correctly once the actor is gone or has been renamed.
            $table->string('causer_name')->nullable();

            $table->string('action', 64);
            $table->text('description')->nullable();

            // Before/after values, ids, amounts — whatever the action needs to
            // be reconstructed. Schemaless on purpose: pinning this down would
            // mean a migration every time a service records one more field.
            $table->json('properties')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('created_at');

            // "What happened to this record", the query the table exists for.
            $table->index(['subject_type', 'subject_id', 'created_at'], 'activity_subject_index');
            // "What did this person do", for reviewing an account.
            $table->index(['causer_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            // v1 kept this on both user tables and staff genuinely read it —
            // it is how a dormant account gets noticed.
            $table->timestamp('last_login_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_login_at');
        });

        Schema::dropIfExists('activity_logs');
    }
};
