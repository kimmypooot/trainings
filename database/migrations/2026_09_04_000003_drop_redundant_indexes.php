<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two indexes that cost writes and buy nothing.
 *
 * `refund_requests` carries `(status, created_at)` twice under two names —
 * `refund_requests_stage_index` and `refund_requests_status_created_at_index`.
 * Identical columns in identical order: one of them was added by a later
 * migration that did not notice the first. Every insert and every status
 * transition maintains both.
 *
 * `activity_logs` carries `(subject_type, subject_id)` alongside
 * `(subject_type, subject_id, created_at)`. The shorter one is a strict prefix
 * of the longer, and MySQL will use a leading subset of a composite index for
 * exactly this reason, so the two-column index can never be chosen over the
 * three-column one for any query the short one could serve. It is dead weight
 * on the busiest write path in the audit trail.
 *
 * Dropped rather than left alone because `activity_logs` is append-only and now
 * carries considerably more traffic than it did — every administrative decision
 * writes here — so the write cost is paid on every one of them.
 *
 * The *duplicate* is dropped in each case, never the original, and `down()`
 * puts them back exactly as they were: a rollback should restore the schema it
 * found, redundancy included, rather than quietly improving it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refund_requests', function (Blueprint $table) {
            $table->dropIndex('refund_requests_stage_index');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('activity_logs_subject_type_subject_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('refund_requests', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'refund_requests_stage_index');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index(['subject_type', 'subject_id'], 'activity_logs_subject_type_subject_id_index');
        });
    }
};
