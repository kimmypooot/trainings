<?php

namespace App\Support;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * The participant profile's validation rules and the write that follows them.
 *
 * Two surfaces fill in the same record: the participant's own form and the
 * HRD editor ported from v1's participants page, where an administrator
 * corrects a misspelled agency or a wrong field office on someone's behalf.
 * They must not drift — a rule that only the participant's form enforces is a
 * rule an administrator can quietly break — so both read from here.
 */
class ProfileService
{
    /**
     * @param  array<string, mixed>  $input  the unvalidated request data; the
     *                                       geography rules depend on what was
     *                                       picked one level up.
     * @return array<string, array<int, mixed>>
     */
    public static function rules(array $input): array
    {
        return [
            // Personal information
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:64'],
            'last_name' => ['required', 'string', 'max:255'],
            'suffix' => ['nullable', Rule::in(ProfileOptions::suffixes())],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'sex' => ['required', Rule::in(ProfileOptions::sexes())],
            'is_pwd' => ['required', Rule::in(ProfileOptions::yesNo())],
            'civil_status' => ['required', Rule::in(ProfileOptions::civilStatuses())],
            'mobile_number' => ['required', 'string', 'regex:/^09\d{9}$/'],

            // Employment details
            'position_title' => ['required', 'string', 'max:255'],
            'salary_grade' => ['required', Rule::in(ProfileOptions::salaryGrades())],
            'organization_name' => ['required', 'string', 'max:255'],
            'sector' => ['required', Rule::in(ProfileOptions::sectors())],
            'region' => ['required', Rule::in(PhilippineGeography::regions())],
            'province' => ['required', Rule::in(PhilippineGeography::provincesOf((string) ($input['region'] ?? '')))],
            'city_municipality' => ['required', Rule::in(PhilippineGeography::citiesOf((string) ($input['province'] ?? '')))],
            'field_office_id' => ['required', 'integer', Rule::exists('field_offices', 'id')->where('is_active', true)],
            'position_level' => ['required', Rule::in(ProfileOptions::positionLevels())],
            'employment_status' => ['required', Rule::in(ProfileOptions::employmentStatuses())],
            'organization_address' => ['required', 'string', 'max:500'],
            // Free text, as in v1: filled means there are restrictions.
            'food_restrictions_details' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'mobile_number.regex' => 'Enter a valid PH mobile number starting with 09 (e.g. 0917 123 4567).',
        ];
    }

    /**
     * Persist a validated profile against its owner.
     *
     * `$recordConsent` is what separates the two callers. Consent is the
     * participant's to give, so an administrator correcting a record leaves
     * `consented_at` exactly as they found it — re-stamping it here would
     * manufacture a consent the participant never gave. The completeness
     * stamp is different: it only records that the record is filled in, and an
     * administrator filling it in is as good a witness as the participant.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function save(User $user, array $validated, bool $recordConsent): Profile
    {
        $profile = $user->profile()->updateOrCreate([], [
            ...collect($validated)->except('consent')->all(),
            ...self::upperCased($validated),
            'is_pwd' => $validated['is_pwd'] === 'Yes',
            ...($recordConsent ? ['consented_at' => now()] : []),
        ]);

        // Keep the display name in step with the name given on the profile.
        $user->forceFill([
            'name' => $profile->fullName(),
            'profile_completed_at' => $user->profile_completed_at ?? now(),
        ])->save();

        return $profile;
    }

    /**
     * Profile records are stored in uppercase. Applied server-side too, so a
     * request that bypasses the form still lands in the right shape. Place
     * names (region, province, city/municipality) are deliberately excluded:
     * they come from the PSGC reference in their canonical proper-case
     * spellings.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, string>
     */
    private static function upperCased(array $validated): array
    {
        $fields = [
            'first_name', 'middle_name', 'last_name', 'position_title',
            'organization_name', 'organization_address', 'food_restrictions_details',
        ];

        return collect($fields)
            ->filter(fn (string $field) => filled($validated[$field] ?? null))
            ->mapWithKeys(fn (string $field) => [$field => mb_strtoupper($validated[$field])])
            ->all();
    }
}
