<?php

namespace App\Enums;

/**
 * Where the supporting document on a supervisory-course registration stands.
 *
 * A participant at SG 15–17 must attach proof that staff report to them; this
 * status tracks what happens to that proof after it is attached. "Document
 * Required" is the pre-submission state (a registration that somehow reached
 * the roster without a file), "Submitted" is the uploaded-but-unreviewed state,
 * and the terminal pair is the reviewer's verdict. A rejected document can be
 * re-uploaded, which returns it to Submitted — the one loop in the lifecycle.
 */
enum SupervisoryDocumentStatus: string
{
    case Required = 'required';
    case Submitted = 'submitted';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Required => 'Document Required',
            self::Submitted => 'Submitted – For Verification',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
        };
    }

    /** Whether a reviewer can still act on this document. */
    public function isActionable(): bool
    {
        return in_array($this, [self::Submitted, self::Rejected], true);
    }

    /** Whether the participant may attach a replacement file. */
    public function allowsResubmission(): bool
    {
        return in_array($this, [self::Required, self::Rejected], true);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status) => ['value' => $status->value, 'label' => $status->label()],
            self::cases()
        );
    }
}
