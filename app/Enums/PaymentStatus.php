<?php

namespace App\Enums;

/**
 * Ported from v1's `payments.status`.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting Verification',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
        };
    }

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    /** Only a verified payment can back a refund. */
    public function isRefundable(): bool
    {
        return $this === self::Verified;
    }

    /**
     * Whether the participant may correct and resubmit this payment.
     *
     * Rejected only, matching SupervisoryDocumentStatus::allowsResubmission():
     * a payment still Pending is under review right now, and letting the
     * details shift under a collecting officer mid-decision is the race
     * PaymentService::decide() already locks against — the participant's
     * correction belongs after a verdict, not during one. This is the one
     * loop in the payment lifecycle, the same way a rejected supporting
     * document is the one loop in that one: the mistake that sent it back
     * (the wrong screenshot, a mistyped reference number) is exactly the kind
     * a participant can see and fix themselves, and until this existed the
     * only way back from it was asking the office to intervene.
     */
    public function allowsResubmission(): bool
    {
        return $this === self::Rejected;
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
