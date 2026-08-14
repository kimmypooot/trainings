<?php

namespace App\Enums;

/**
 * The documents that change hands during an agency request.
 *
 * v1 kept these in three tables — `training_requirements`, `training_confirmations`
 * and `training_completions` — each with its own hardcoded columns per file.
 * Adding a document meant a migration, and "show me everything attached to this
 * request" meant three joins and a manual merge.
 *
 * One table keyed by this enum instead. The stage each document belongs to is a
 * property of the document, not a separate table, and the scalar fields that
 * genuinely belong to the request (confirmed dates, payment amount) live on the
 * request itself where they can be queried.
 */
enum AgencyDocumentKind: string
{
    // From the agency, opening the request.
    case RequestLetter = 'request_letter';

    // From HRD, in reply.
    case ResponseLetter = 'response_letter';
    case BlankConfirmationForm = 'blank_confirmation_form';
    case RejectionLetter = 'rejection_letter';

    // From the agency, accepting.
    case SignedConfirmationForm = 'signed_confirmation_form';

    // From the agency, after the training has run.
    case CertificateOfDuties = 'certificate_of_duties';
    case AttendanceSheet = 'attendance_sheet';
    case AttendanceList = 'attendance_list';
    case ProofOfPayment = 'proof_of_payment';

    public function label(): string
    {
        return match ($this) {
            self::RequestLetter => 'Letter of Request',
            self::ResponseLetter => 'HRD Response Letter',
            self::BlankConfirmationForm => 'Confirmation Form (blank)',
            self::RejectionLetter => 'Rejection Letter',
            self::SignedConfirmationForm => 'Signed Confirmation Form',
            self::CertificateOfDuties => 'Certificate of Actual Duties Rendered',
            self::AttendanceSheet => 'Signed Attendance Sheet',
            self::AttendanceList => 'Participant List (spreadsheet)',
            self::ProofOfPayment => 'Proof of Payment',
        };
    }

    /** Who supplies it — drives which side's upload form shows it. */
    public function fromStaff(): bool
    {
        return in_array($this, [
            self::ResponseLetter,
            self::BlankConfirmationForm,
            self::RejectionLetter,
        ], true);
    }

    /**
     * The documents that must be present before a completion can be accepted.
     *
     * The participant list is not among them: v1 had it optional, since a small
     * run's attendance sheet already lists everyone and a spreadsheet would be
     * a transcription of it.
     *
     * @return array<int, self>
     */
    public static function requiredForCompletion(): array
    {
        return [self::CertificateOfDuties, self::AttendanceSheet, self::ProofOfPayment];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $kind) => ['value' => $kind->value, 'label' => $kind->label()],
            self::cases()
        );
    }
}
