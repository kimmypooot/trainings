<?php

use App\Enums\AgencyRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An agency formally asking CSC to run a training for its own staff, and the
 * document exchange that follows.
 *
 * Ported from v1's `training_requests` plus `training_requirements`,
 * `training_confirmations` and `training_completions`.
 *
 * Two tables rather than four. The three child tables in v1 were each a fixed
 * set of file-path columns for one stage — adding a document meant a migration,
 * and answering "what is attached to this request" meant three joins. Here the
 * files live in one table keyed by AgencyDocumentKind, and only the scalars
 * that are genuinely properties of the request (confirmed dates, payment
 * amount, who is handling it) stay on the request row where they can be
 * queried and reported on.
 *
 * v1's `training_activity_log` is not reproduced either: activity_logs already
 * records every transition, and a second per-feature trail was exactly the
 * fragmentation that made v1's history unreadable.
 *
 * This is deliberately separate from the existing `training_requests` table,
 * which in this codebase means something else — a participant suggesting a
 * topic, ending in a Training being created. See AgencyRequestStatus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_requests', function (Blueprint $table) {
            $table->id();
            // AGR-YYYY-NNN. Quoted in correspondence with the agency, so it is
            // the one identifier that leaves the system.
            $table->string('request_code', 32)->unique();

            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            // Captured at submission rather than read through the profile: the
            // request is filed on behalf of an agency, and the requester may
            // move on or have their profile edited afterwards.
            $table->string('agency_name');

            $table->string('training_title');
            $table->date('proposed_start');
            $table->date('proposed_end');
            $table->string('proposed_venue');
            $table->unsignedInteger('expected_participants')->nullable();

            $table->string('status')->default(AgencyRequestStatus::Pending->value);

            // HRD notifies the Office of the Regional Director that a request
            // has come in; recorded so nobody sends it twice.
            $table->dateTime('ord_notified_at')->nullable();

            // Whoever picks the request up owns it until it closes.
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('assigned_at')->nullable();
            $table->text('review_notes')->nullable();

            // What HRD asks for, sent alongside the response letter.
            $table->text('requirements_text')->nullable();
            $table->dateTime('requirements_sent_at')->nullable();

            // What the agency comes back with. Kept apart from the proposed
            // dates rather than overwriting them — the gap between what was
            // asked for and what was agreed is the thing people query later.
            $table->date('confirmed_start')->nullable();
            $table->date('confirmed_end')->nullable();
            $table->string('confirmed_venue')->nullable();
            $table->dateTime('confirmed_at')->nullable();

            $table->dateTime('completion_submitted_at')->nullable();
            $table->decimal('payment_amount', 10, 2)->nullable();
            $table->foreignId('payment_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('payment_verified_at')->nullable();

            $table->text('rejection_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->dateTime('closed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['assigned_to', 'status']);
        });

        Schema::create('agency_request_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_request_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            // Private disk, served through an authorising controller — these
            // are agency letters and payment records, never public URLs.
            $table->string('file_path', 512);
            $table->string('original_filename');
            $table->unsignedInteger('file_size');
            $table->string('mime_type', 128);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            // "The response letter for this request" is the commonest lookup,
            // and re-uploading a document of the same kind supersedes the last,
            // so the index is on the pair rather than unique on it.
            $table->index(['agency_request_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_request_documents');
        Schema::dropIfExists('agency_requests');
    }
};
