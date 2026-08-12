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
