<?php

namespace App\Support;

use App\Models\Agency;
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
            ...self::employerRules($input),
            'region' => ['required', Rule::in(PhilippineGeography::regions())],
            'province' => ['required', Rule::in(PhilippineGeography::provincesOf((string) ($input['region'] ?? '')))],
            'city_municipality' => ['required', Rule::in(PhilippineGeography::citiesOf((string) ($input['province'] ?? '')))],
            'position_level' => ['required', Rule::in(ProfileOptions::positionLevels())],
            'employment_status' => ['required', Rule::in(ProfileOptions::employmentStatuses())],
            'organization_address' => ['required', 'string', 'max:500'],
            // Free text, as in v1: filled means there are restrictions.
            'food_restrictions_details' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The employer half of the form: agency, sector and field office.
     *
     * Two shapes, and which one applies is decided by whether `agency_id`
     * arrived. A picked agency carries its own sector and serving office, so
     * asking for those alongside it would be asking the client to restate
     * something the server already knows — and a client that can restate it can
     * disagree with it, which is how a DepEd employee ends up filed as a water
     * district. The two fields are therefore *not accepted at all* on that
     * branch; `resolveEmployer()` fills them from the row.
     *
     * The typed branch is the form exactly as it was before the reference list
     * existed, and it has to stay that way: an office that has not built its
     * list yet, and any participant whose employer is genuinely not on it, get
     * the same three fields they always had.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, array<int, mixed>>
     */
    private static function employerRules(array $input): array
    {
        if (filled($input['agency_id'] ?? null)) {
            $agency = Agency::query()->active()->find($input['agency_id']);

            return [
                'agency_id' => [
                    'required', 'integer',
                    // Active only. A retired agency stays on the profiles that
                    // already point at it, but must not be newly chosen, and an
                    // id posted past a picker that never offered it is the only
                    // way it could be.
                    Rule::exists('agencies', 'id')->where('is_active', true),
                ],
                // The one field a pick does not always answer. An agency whose
                // serving office has not been decided still has to have that
                // question put to somebody, so it stays required and stays
                // enabled in the picker — the alternative is a profile filed
                // under no office at all, which is the field that decides who
                // can see the participant.
                //
                // A null $agency means the id above is about to fail anyway.
                ...($agency && $agency->field_office_id === null ? [
                    'field_office_id' => [
                        'required', 'integer',
                        Rule::exists('field_offices', 'id')->where('is_active', true),
                    ],
                ] : []),
            ];
        }

        // `agency_id` is deliberately absent rather than nullable here: an id
        // arriving alongside a typed name would be a contradiction, and the
        // branch above is the only place one is accepted. resolveEmployer()
        // nulls the column.
        return [
            'organization_name' => ['required', 'string', 'max:255'],
            'sector' => ['required', Rule::in(ProfileOptions::sectors())],
            'field_office_id' => [
                'required', 'integer',
                Rule::exists('field_offices', 'id')->where('is_active', true),
            ],
        ];
    }

    /**
     * Fill in what a picked agency implies, and clear the link when one was not.
     *
     * Called by both controllers between validating and saving, rather than
     * inside `save()`, because the participant edit screen diffs the validated
     * array to build its audit entry — a sector or field office that moved
     * because the agency moved is exactly the change that entry exists to
     * record, so it has to be present by the time the diff is taken.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function resolveEmployer(array $validated): array
    {
        if (blank($validated['agency_id'] ?? null)) {
            // Explicit null rather than absent: this is the path a participant
            // takes when their employer has *left* the list, or when staff
            // correct a wrong pick, and both have to clear an existing link.
            return [...$validated, 'agency_id' => null];
        }

        $agency = Agency::findOrFail($validated['agency_id']);

        return [
            ...$validated,
            'organization_name' => $agency->name,
            'sector' => $agency->sector,
            // An agency whose serving office has not been decided leaves the
            // profile's office untouched rather than blanking it — see the
            // migration on why that row is allowed to exist half-known.
            'field_office_id' => $agency->field_office_id ?? ($validated['field_office_id'] ?? null),
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
     * Profile records are stored in uppercase — except what came from a
     * reference list, which keeps the spelling that list maintains.
     *
     * Applied server-side too, so a request that bypasses the form still lands
     * in the right shape. Place names (region, province, city/municipality)
     * are excluded because they come from the PSGC reference in their
     * canonical proper-case spellings.
     *
     * **`organization_name` follows that same rule the moment it stops being
     * free text.** It was typed when this was written, so upper-casing it was
     * normalising a field nobody else owned. A participant who picks from the
     * agency list is no longer typing: the name is the reference's, exactly as
     * a province is the PSGC's, and shouting it here made the participant's own
     * record disagree with the picker they chose it in, with the admin list,
     * and with the `agencies` row it was copied from — one employer, two
     * spellings, which is the split that table exists to end.
     *
     * The typed path still upper-cases, and that asymmetry is deliberate: free
     * text has no owner to be canonical for it, and the convention matches the
     * name and position title beside it on the same record.
     *
     * Note this normalisation was never doing integrity work. The column's
     * collation is case-insensitive, so every WHERE, LIKE and SQL GROUP BY on
     * it already ignored case — `AnalyticsController::topAgencies()` is the one
     * place that groups in PHP, and it folds case itself for exactly this
     * reason.
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

        if (filled($validated['agency_id'] ?? null)) {
            $fields = array_diff($fields, ['organization_name']);
        }

        return collect($fields)
            ->filter(fn (string $field) => filled($validated[$field] ?? null))
            ->mapWithKeys(fn (string $field) => [$field => mb_strtoupper($validated[$field])])
            ->all();
    }
}
