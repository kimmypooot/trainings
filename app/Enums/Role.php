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
     * Roles that reach the money screens by virtue of the job itself.
     *
     * Everyone else needs the collecting-officer designation — see
     * User::collectsPayments(), which is the predicate the routes actually
     * check. Kept as a list here so the two callers agree on who is included
     * without repeating the pair.
     *
     * @return array<int, self>
     */
    public static function financial(): array
    {
        return [self::Admin, self::SuperAdmin];
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
