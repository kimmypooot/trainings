<?php

namespace App\Http\Controllers;

use App\Models\FieldOffice;
use App\Support\EmailChangeService;
use App\Support\PhilippineGeography;
use App\Support\ProfileOptions;
use App\Support\ProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the one-time profile form shown after registration.
     */
    public function create(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Complete', [
            'options' => [...ProfileOptions::all(), 'fieldOffices' => FieldOffice::options()],
            'geography' => PhilippineGeography::nested(),
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
            // A participant who just completed the gate has an unverified email
            // by construction. Derived from durable state (not a one-shot
            // flash) so the "Registration Successful" modal survives the resend
            // round-trip on the same page.
            'registration_complete' => $user->hasCompletedProfile() && ! $user->hasVerifiedEmail(),
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
            'geography' => PhilippineGeography::nested(),
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'role_label' => $user->role->label(),
                'is_verified' => $user->email_verified_at !== null,
                // A change that is waiting on its link. Null once it is
                // confirmed, cancelled, or has outlived the link — the card
                // must not go on saying "check your inbox" about a message
                // whose link has already died.
                'pending_email' => EmailChangeService::isPending($user, (string) $user->pending_email)
                    ? $user->pending_email
                    : null,
                // Google-only accounts have no password to re-enter, so the
                // form asks for one only when there is one. Same rule the
                // controller enforces, read from the same place.
                'confirms_with_password' => $user->hasPassword(),
                'avatar' => $user->avatarUrl(),
                'has_google' => $user->hasGoogleAccount(),
                // Named in the card rather than a bare "Connected": the Google
                // address need not match the TIMS one, so this is the only way
                // to notice the wrong account was connected.
                'google_email' => $user->google_email,
                // Drives the Linked Accounts card. Disconnect is hidden rather
                // than shown-and-refused when Google is the only way in, so the
                // card explains the situation instead of the participant
                // finding out by being turned down.
                'has_password' => $user->hasPassword(),
                'google_configured' => filled(config('services.google.client_id')),
            ],
            'profile' => $user->profile ? [
                ...$user->profile->only([
                    'first_name', 'middle_name', 'last_name', 'suffix', 'sex', 'civil_status',
                    'mobile_number', 'position_title', 'salary_grade', 'organization_name', 'sector',
                    'region', 'province', 'city_municipality', 'field_office_id', 'position_level',
                    'employment_status', 'organization_address', 'food_restrictions_details',
                ]),
                'date_of_birth' => $user->profile->date_of_birth?->format('Y-m-d'),
                'is_pwd' => $user->profile->is_pwd ? 'Yes' : 'No',
                'updated_at' => $user->profile->updated_at?->toISOString(),
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

        // This is the moment a self-service registration is complete, so this
        // is when the verification email goes out — not at account creation,
        // where the draftable gate form could let the 60-minute link expire
        // before the participant finished. A verified user can only reach this
        // route before completing their profile, so the guard is cheap belt.
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();

            return redirect()->route('profile.complete');
        }

        return redirect()->intended(route('dashboard'))
            ->with('success', 'Your profile is complete — you can now register for trainings.');
    }

    /**
     * Validate and persist. Shared by the first-time gate and later edits.
     */
    private function save(Request $request): void
    {
        // The field rules live with the service so the HRD editor cannot drift
        // from what the participant's own form enforces. Consent is the one
        // rule that belongs only here — nobody can accept it on the
        // participant's behalf.
        $validated = $request->validate([
            ...ProfileService::rules($request->all()),
            'consent' => ['accepted'],
        ], [
            ...ProfileService::messages(),
            'consent.accepted' => 'You must give consent for the processing of your personal data to continue.',
        ]);

        ProfileService::save($request->user(), $validated, recordConsent: true);
    }
}
