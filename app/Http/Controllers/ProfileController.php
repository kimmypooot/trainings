<?php

namespace App\Http\Controllers;

use App\Models\FieldOffice;
use App\Support\ProfileOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the one-time profile form shown after registration.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Profile/Complete', [
            'options' => [...ProfileOptions::all(), 'fieldOffices' => FieldOffice::options()],
            'user' => [
                'name' => $request->user()->name,
                'email' => $request->user()->email,
            ],
        ]);
    }

    /**
     * Show the editable profile for a participant who is already through the gate.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user()->loadMissing('profile');

        return Inertia::render('Profile/Edit', [
            'options' => [...ProfileOptions::all(), 'fieldOffices' => FieldOffice::options()],
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'role_label' => $user->role->label(),
                'is_verified' => $user->email_verified_at !== null,
            ],
            'profile' => $user->profile ? [
                ...$user->profile->only([
                    'first_name', 'middle_name', 'last_name', 'suffix', 'sex', 'civil_status',
                    'mobile_number', 'position_title', 'salary_grade', 'organization_name',
                    'agency_unit', 'sector', 'region', 'province', 'city_municipality',
                    'field_office_id', 'position_level', 'employment_status', 'organization_address',
                    'home_address', 'food_restrictions_details',
                ]),
                'date_of_birth' => $user->profile->date_of_birth?->format('Y-m-d'),
                'is_pwd' => $user->profile->is_pwd ? 'Yes' : 'No',
            ] : null,
        ]);
    }

    /**
     * Update an existing profile.
     */
    public function update(Request $request): RedirectResponse
    {
        $this->save($request);

        return back()->with('success', 'Your profile has been updated.');
    }

    /**
     * Save the profile and open up the rest of the system.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->save($request);

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Validate and persist. Shared by the first-time gate and later edits.
     */
    private function save(Request $request): void
    {
        $validated = $request->validate([
            // Personal information
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:64'],
            'last_name' => ['required', 'string', 'max:255'],
            'suffix' => ['nullable', Rule::in(ProfileOptions::suffixes())],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'sex' => ['required', Rule::in(ProfileOptions::sexes())],
            'is_pwd' => ['required', Rule::in(ProfileOptions::yesNo())],
            'civil_status' => ['required', Rule::in(ProfileOptions::civilStatuses())],
            'mobile_number' => ['required', 'string', 'max:30'],

            // Employment details
            'position_title' => ['required', 'string', 'max:255'],
            'salary_grade' => ['required', Rule::in(ProfileOptions::salaryGrades())],
            'organization_name' => ['required', 'string', 'max:255'],
            'agency_unit' => ['nullable', 'string', 'max:255'],
            'sector' => ['required', Rule::in(ProfileOptions::sectors())],
            'region' => ['required', 'string', 'max:64'],
            'province' => ['required', 'string', 'max:64'],
            'city_municipality' => ['required', 'string', 'max:64'],
            'field_office_id' => ['required', 'integer', Rule::exists('field_offices', 'id')->where('is_active', true)],
            'position_level' => ['required', Rule::in(ProfileOptions::positionLevels())],
            'employment_status' => ['required', Rule::in(ProfileOptions::employmentStatuses())],
            'organization_address' => ['required', 'string', 'max:500'],
            'home_address' => ['nullable', 'string', 'max:500'],
            // Free text, as in v2: filled means there are restrictions.
            'food_restrictions_details' => ['nullable', 'string', 'max:500'],

            'consent' => ['accepted'],
        ], [
            'consent.accepted' => 'You must give consent for the processing of your personal data to continue.',
        ]);

        $user = $request->user();

        $profile = $user->profile()->updateOrCreate([], [
            ...collect($validated)->except('consent')->all(),
            ...self::upperCased($validated),
            'is_pwd' => $validated['is_pwd'] === 'Yes',
            'consented_at' => now(),
        ]);

        // Keep the display name in step with the name given on the profile.
        $user->forceFill([
            'name' => $profile->fullName(),
            'profile_completed_at' => $user->profile_completed_at ?? now(),
        ])->save();
    }

    /**
     * Profile records are stored in uppercase. Applied server-side too, so a
     * request that bypasses the form still lands in the right shape.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, string>
     */
    private static function upperCased(array $validated): array
    {
        $fields = [
            'first_name', 'middle_name', 'last_name', 'position_title',
            'organization_name', 'agency_unit', 'organization_address', 'home_address',
            'region', 'province', 'city_municipality', 'food_restrictions_details',
        ];

        return collect($fields)
            ->filter(fn (string $field) => filled($validated[$field] ?? null))
            ->mapWithKeys(fn (string $field) => [$field => mb_strtoupper($validated[$field])])
            ->all();
    }
}
