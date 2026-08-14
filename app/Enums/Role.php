<?php

namespace App\Enums;

enum Role: string
{
    case Participant = 'participant';
    case FieldOffice = 'field-office';
    case CollectingOfficer = 'collecting-officer';
    case Admin = 'admin';
    case Management = 'management';
    case SuperAdmin = 'superadmin';

    public function label(): string
    {
        return match ($this) {
            self::Participant => 'Participant',
            self::FieldOffice => 'Field Office',
            self::CollectingOfficer => 'Collecting Officer',
            self::Admin => 'Administrator',
            self::Management => 'Management',
            self::SuperAdmin => 'Super Administrator',
        };
    }

    /**
     * Roles that administer the system rather than take trainings.
     *
     * @return array<int, self>
     */
    public static function staff(): array
    {
        return [
            self::FieldOffice, self::CollectingOfficer,
            self::Admin, self::Management, self::SuperAdmin,
        ];
    }

    public function isStaff(): bool
    {
        return in_array($this, self::staff(), true);
    }

    /**
     * Roles that may verify payments and settle refunds.
     *
     * Collecting officers exist only for this — they are staff, but the cashier
     * has no business in the participant directory or the training roster.
     */
    public static function financial(): array
    {
        return [self::CollectingOfficer, self::Admin, self::SuperAdmin];
    }

    public function handlesPayments(): bool
    {
        return in_array($this, self::financial(), true);
    }

    /**
     * Narrower than financial(): who may read a refund payee's full bank
     * account number.
     *
     * Everyone in financial() can open the refund queue, but only the cashier
     * actually cuts the transfer. HRD reviews whether a claim is valid, which
     * needs the amount and the reason and nothing else — so the account number
     * reaches them masked. The distinction is worth keeping even though both
     * roles are trusted: the number is on screen far longer than it is needed,
     * usually in a shared office.
     */
    public function seesBankDetails(): bool
    {
        return $this === self::CollectingOfficer || $this === self::SuperAdmin;
    }

    /**
     * Who manages the physical-OR request queue and its GCash/delivery
     * settings. Delivery of receipts is HRD admin work — it is not a payment
     * the collecting officer touches, so it deliberately shares the trainings
     * roles rather than the financial() ones.
     */
    public function handlesPhysicalOrRequests(): bool
    {
        return $this === self::Admin || $this === self::SuperAdmin;
    }
}
