<?php

use App\Enums\SupervisoryDocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Track where the supporting document on a supervisory-course registration
 * stands in the verification workflow.
 *
 * The status is distinct from the registration's own review status: HRD can
 * verify the document before, after, or independently of approving the
 * registration, and a participant may be told to fix the file without their
 * whole registration being bounced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->string('supervisory_document_status')->nullable()->after('supporting_document_path');
            $table->foreignId('supervisory_document_reviewed_by')->nullable()
                ->after('supervisory_document_status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('supervisory_document_reviewed_at')->nullable()
                ->after('supervisory_document_reviewed_by');
            $table->text('supervisory_document_remarks')->nullable()
                ->after('supervisory_document_reviewed_at');
        });

        // A document already on file was uploaded through the current flow,
        // which puts it straight into the "awaiting verification" bucket.
        DB::table('registrations')
            ->whereNotNull('supporting_document_path')
            ->update(['supervisory_document_status' => SupervisoryDocumentStatus::Submitted->value]);
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->dropForeign(['supervisory_document_reviewed_by']);
            $table->dropColumn([
                'supervisory_document_status',
                'supervisory_document_reviewed_by',
                'supervisory_document_reviewed_at',
                'supervisory_document_remarks',
            ]);
        });
    }
};
