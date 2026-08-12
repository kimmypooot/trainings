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
}
