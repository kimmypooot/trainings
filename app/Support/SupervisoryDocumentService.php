<?php

namespace App\Support;

use App\Enums\SupervisoryDocumentStatus;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The verification of a supervisory-course supporting document.
 *
 * Lives apart from RegistrationService because the document is reviewed on its
 * own track: HRD can check it while the registration still sits pending, and a
 * rejection should ask the participant to fix the file without unceremoniously
 * bouncing the whole application.
 */
class SupervisoryDocumentService
{
    /**
     * A document can only be judged once it is on file.
     */
    public static function reviewable(Registration $registration): bool
    {
        return $registration->supporting_document_path !== null;
    }

    /**
     * Mark the document as verified, recording who decided and when.
     */
    public static function verify(Registration $registration, User $reviewer, ?string $remarks = null): Registration
    {
        return self::decide($registration, SupervisoryDocumentStatus::Verified, $reviewer, $remarks);
    }

    /**
     * Mark the document as rejected, which lets the participant re-upload.
     *
     * A rejection without a reason is not reviewable after the fact, so the
     * reason is mandatory.
     */
    public static function reject(Registration $registration, User $reviewer, string $remarks): Registration
    {
        return self::decide($registration, SupervisoryDocumentStatus::Rejected, $reviewer, $remarks);
    }

    /**
     * Apply a terminal verdict to a document.
     */
    private static function decide(
        Registration $registration,
        SupervisoryDocumentStatus $verdict,
        User $reviewer,
        ?string $remarks,
    ): Registration {
        if (! self::reviewable($registration)) {
            throw ValidationException::withMessages([
                'registration' => 'This registration has no supporting document to review.',
            ]);
        }

        if ($registration->supervisory_document_status === null) {
            throw ValidationException::withMessages([
                'registration' => 'This registration is not required to submit a supporting document.',
            ]);
        }

        $from = $registration->supervisory_document_status;

        if (! $from->isActionable()) {
            throw ValidationException::withMessages([
                'registration' => "The document is already {$from->label()}.",
            ]);
        }

        $registration->forceFill([
            'supervisory_document_status' => $verdict,
            'supervisory_document_reviewed_by' => $reviewer->getKey(),
            'supervisory_document_reviewed_at' => now(),
            'supervisory_document_remarks' => $remarks,
        ])->save();

        ActivityLogger::recordTransition(
            'supervisory_document.'.$verdict->value,
            $registration,
            $from,
            $verdict,
            "Supervisory document {$verdict->label()} by {$reviewer->name}.",
            ['remarks' => $remarks, 'training_id' => $registration->training_id],
            $reviewer,
        );

        return $registration;
    }

    /**
     * Replace a rejected (or missing) document and send it back for review.
     *
     * A replacement starts the workflow over: the old verdict and its trail are
     * cleared so the re-uploaded file is judged on its own merits.
     */
    public static function resubmit(
        Registration $registration,
        string $documentPath,
    ): Registration {
        $from = $registration->supervisory_document_status;

        if ($from !== null && ! $from->allowsResubmission()) {
            throw ValidationException::withMessages([
                'registration' => "A {$from->label()} document cannot be replaced.",
            ]);
        }

        $registration->forceFill([
            'supporting_document_path' => $documentPath,
            'supervisory_document_status' => SupervisoryDocumentStatus::Submitted,
            'supervisory_document_reviewed_by' => null,
            'supervisory_document_reviewed_at' => null,
            'supervisory_document_remarks' => null,
        ])->save();

        ActivityLogger::recordTransition(
            'supervisory_document.resubmitted',
            $registration,
            $from,
            SupervisoryDocumentStatus::Submitted,
            'Supporting document re-uploaded for verification.',
            ['training_id' => $registration->training_id],
            $registration->user,
        );

        return $registration;
    }
}
