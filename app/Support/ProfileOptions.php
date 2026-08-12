<?php

namespace App\Support;

/**
 * Option lists for the profile form.
 *
 * Values are aligned with the v2 system (`participants` table enums and the
 * profile form's dropdowns) so records stay comparable across both systems.
 * Kept server-side so validation and the rendered dropdowns cannot drift apart.
 */
class ProfileOptions
{
    /** @return array<int, string> */
    public static function suffixes(): array
    {
        return ['JR.', 'SR.', 'II', 'III', 'IV', 'V'];
    }

    /** @return array<int, string> */
    public static function sexes(): array
    {
        return ['Male', 'Female', 'LGBTQIA+'];
    }

    /** @return array<int, string> */
    public static function civilStatuses(): array
    {
        return ['Single', 'Married', 'Widowed', 'Divorced', 'Separated'];
    }

    /** @return array<int, string> */
    public static function sectors(): array
    {
        return [
            'National Government Agency',
            'Local Government Unit',
            'Government-Owned and Controlled Corporation',
            'State University or College',
            'Constitutional Body',
            'Judiciary',
            'Legislature',
            'Private Sector',
            'Non-Government Organization',
            'Other',
        ];
    }

    /** @return array<int, string> */
    public static function positionLevels(): array
    {
        return [
            '1st Level',
            '2nd Level (Rank and File)',
            '2nd Level (Supervisory)',
            '3rd Level',
            'Not Applicable',
        ];
    }

    /**
     * Nature of appointment, per v2's `nature_appointment` enum.
     *
     * @return array<int, string>
     */
    public static function employmentStatuses(): array
    {
        return [
            'Permanent',
            'Casual',
            'Contractual',
            'Coterminous',
            'Elective',
            'Temporary',
            'Job Order / Contract of Service',
            'Presidential Appointee',
            'Others',
        ];
    }

    /** @return array<int, string> */
    public static function salaryGrades(): array
    {
        return [...array_map(fn (int $grade) => "SG {$grade}", range(1, 33)), 'Not Applicable'];
    }

    /** @return array<int, string> */
    public static function yesNo(): array
    {
        return ['Yes', 'No'];
    }

    /** @return array<string, array<int, string>> */
    public static function all(): array
    {
        return [
            'suffixes' => self::suffixes(),
            'sexes' => self::sexes(),
            'civilStatuses' => self::civilStatuses(),
            'sectors' => self::sectors(),
            'positionLevels' => self::positionLevels(),
            'employmentStatuses' => self::employmentStatuses(),
            'salaryGrades' => self::salaryGrades(),
            'yesNo' => self::yesNo(),
        ];
    }
}
