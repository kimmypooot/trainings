<?php

use App\Enums\RefundStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brings v1's real refund workflow across.
 *
 * The original port reduced `refund_requests` to payment/amount/reason plus a
 * three-value status, which cannot represent a disbursement: there was nowhere
 * to record *where to send the money*. A refund approved under that schema
 * could never actually be paid.
 *
 * Two things come back here. The payee block (account name, bank, number, and
 * the proof of the original payment) is what MSD needs to cut the transfer.
 * The staged status is what the participant is actually asking about when they
 * follow up.
 *
 * v1 also carried five separate actor/timestamp column pairs — reviewed_by/at,
 * processed_by/at, forwarded_by/at, released_by/at, refunded_by/at. Those are
 * not repeated. `refund_status_logs` records the actor and the moment of every
 * transition, which answers the same questions without five columns that are
 * null for most of a row's life, and it keeps the notes attached to the step
 * they belong to rather than overwriting a single `admin_notes` field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refund_requests', function (Blueprint $table) {
            // Human-readable handle. Participants quote this on follow-up and
            // MSD files by it, so it is the one identifier that leaves the app.
            $table->string('request_code', 32)->nullable()->unique()->after('id');

            // Where the money goes. Required from the participant at request
            // time; nullable here only because existing rows predate it.
            $table->string('account_name')->nullable()->after('reason');
            $table->string('bank_name')->nullable()->after('account_name');
            $table->string('account_number', 64)->nullable()->after('bank_name');

            // The participant's own proof of the original payment — usually the
            // CSC official receipt. Private disk, served through a controller.
            $table->string('proof_path', 512)->nullable()->after('account_number');

            $table->text('rejection_reason')->nullable()->after('review_remarks');

            $table->index(['status', 'created_at'], 'refund_requests_stage_index');
        });

        $this->migrateExistingStatuses();

        Schema::create('refund_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_request_id')->constrained()->cascadeOnDelete();
            // Null on the opening row: the request did not come from anywhere.
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('notes')->nullable();
            // Null when the participant filed it, or when the actor has since
            // been deleted. The log outlives the account.
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');

            $table->index(['refund_request_id', 'changed_at']);
        });

        $this->seedLogsForExistingRequests();
    }

    /**
     * Existing rows carry RequestStatus values. Map them onto the pipeline:
     * a pending claim is at the front of it, an approved one has been paid.
     */
    private function migrateExistingStatuses(): void
    {
        DB::table('refund_requests')->where('status', 'pending')
            ->update(['status' => RefundStatus::ForReview->value]);

        DB::table('refund_requests')->where('status', 'approved')
            ->update(['status' => RefundStatus::Refunded->value]);

        // 'rejected' already matches RefundStatus::Rejected.

        // Backfill request codes in creation order, matching v1's RFD-YYYY-NNN.
        $counters = [];

        DB::table('refund_requests')
            ->select('id', 'created_at')
            ->orderBy('id')
            ->each(function ($refund) use (&$counters) {
                $year = substr((string) $refund->created_at, 0, 4) ?: date('Y');
                $counters[$year] = ($counters[$year] ?? 0) + 1;

                DB::table('refund_requests')->where('id', $refund->id)->update([
                    'request_code' => sprintf('RFD-%s-%03d', $year, $counters[$year]),
                ]);
            });
    }

    /**
     * Give every pre-existing request an opening log entry, so the trail is
     * never empty and the UI does not have to special-case older rows.
     */
    private function seedLogsForExistingRequests(): void
    {
        DB::table('refund_requests')
            ->select('id', 'status', 'created_at', 'reviewed_by', 'reviewed_at', 'review_remarks')
            ->orderBy('id')
            ->chunkById(200, function ($refunds) {
                $rows = [];

                foreach ($refunds as $refund) {
                    $rows[] = [
                        'refund_request_id' => $refund->id,
                        'from_status' => null,
                        'to_status' => RefundStatus::ForReview->value,
                        'notes' => 'Request filed.',
                        'changed_by' => null,
                        'changed_at' => $refund->created_at,
                    ];

                    // Only record a second entry where a decision was actually
                    // taken; an untouched request has one row, as it should.
                    if ($refund->status !== RefundStatus::ForReview->value && $refund->reviewed_at !== null) {
                        $rows[] = [
                            'refund_request_id' => $refund->id,
                            'from_status' => RefundStatus::ForReview->value,
                            'to_status' => $refund->status,
                            'notes' => $refund->review_remarks,
                            'changed_by' => $refund->reviewed_by,
                            'changed_at' => $refund->reviewed_at,
                        ];
                    }
                }

                if ($rows !== []) {
                    DB::table('refund_status_logs')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_status_logs');

        Schema::table('refund_requests', function (Blueprint $table) {
            $table->dropIndex('refund_requests_stage_index');
            $table->dropUnique(['request_code']);
            $table->dropColumn([
                'request_code', 'account_name', 'bank_name', 'account_number',
                'proof_path', 'rejection_reason',
            ]);
        });

        DB::table('refund_requests')
            ->whereIn('status', [RefundStatus::ForReview->value, RefundStatus::Processing->value])
            ->update(['status' => 'pending']);

        DB::table('refund_requests')
            ->whereIn('status', [
                RefundStatus::ForwardedToMsd->value,
                RefundStatus::ForRelease->value,
                RefundStatus::Refunded->value,
            ])
            ->update(['status' => 'approved']);
    }
};
