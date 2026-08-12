<?php

namespace App\Enums;

/**
 * The lifecycle shared by every staff-reviewed request: cancellations,
 * training requests, and submitted outputs.
 *
 * Deliberately separate from RegistrationStatus — a registration has slot and
 * attendance semantics a plain request does not.
 */
enum RequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending Review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    public function isPending(): bool
    {
        return $this === self::Pending;
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
