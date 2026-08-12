<?php

namespace App\Enums;

/**
 * Mirrors the v2 lifecycle: a participant submits, HRD reviews, and only then
 * is the registration approved, waitlisted, or rejected.
 */
enum RegistrationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Waitlisted = 'waitlisted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending Approval',
            self::Approved => 'Approved',
            self::Waitlisted => 'Waitlisted',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
            self::Completed => 'Completed',
        };
    }

    /**
     * Statuses that hold a slot.
     *
     * As in v2, a slot is reserved the moment the participant registers — it is
     * not held back until approval — so a pending registration still counts.
     * Waitlisted explicitly does not, which is the point of a waitlist.
     */
    public function occupiesSlot(): bool
    {
        return in_array($this, self::occupying(), true);
    }

    /**
     * The slot-holding statuses, for querying.
     *
     * Rejected and waitlisted deliberately do not hold a slot — counting "not
     * cancelled" would wrongly include both.
     *
     * @return array<int, self>
     */
    public static function occupying(): array
    {
        return [self::Pending, self::Approved, self::Completed];
    }

    /** Statuses the participant may still withdraw from. */
    public function isCancellable(): bool
    {
        return in_array($this, [self::Pending, self::Approved, self::Waitlisted], true);
    }

    /** Statuses HRD may still act on. */
    public function isAwaitingReview(): bool
    {
        return $this === self::Pending;
    }
}
