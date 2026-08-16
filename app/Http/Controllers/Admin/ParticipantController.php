<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\FieldOffice;
use App\Models\Registration;
use App\Models\User;
use App\Support\ParticipantFilter;
use App\Support\PhilippineGeography;
use App\Support\ProfileOptions;
use App\Support\ProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The participants directory, ported from v1's `admin/hrd/participants.php`.
 *
 * v1 packed the whole thing into one page: counters across the top, four
 * filters, a table, and a modal that could both read and edit the record. This
 * splits along Inertia lines — directory, detail, editor — but keeps every
 * action v1 offered: correct a profile, reset a password, deactivate an
 * account, and export whatever the filters currently show.
 *
 * Reading is open to all staff; the three write actions are HRD's, so they sit
 * behind the admin|superadmin group in routes/web.php. Field-office staff read
 * their own office and nothing more — enforced here on every action, not just
 * the listing, or a guessed URL would walk straight past it.
 */
class ParticipantController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = ParticipantFilter::fromRequest($request);
        $officeId = $request->user()->scopedFieldOfficeId();

        $participants = ParticipantFilter::apply(ParticipantFilter::base($officeId), $filters)
            ->with('profile.fieldOffice')
            ->withCount([
                'registrations as total_registrations',
                'registrations as active_registrations' => fn ($query) => $query
                    ->whereIn('status', RegistrationStatus::occupying()),
                // v1 counted "paid trainings" off the registration's own
                // payment_status column. v2 keeps payments in their own table,
                // so the equivalent is a registration with money the collecting
                // officer has actually verified.
                'registrations as settled_registrations' => fn ($query) => $query
                    ->whereHas('payments', fn ($payment) => $payment->where('status', PaymentStatus::Verified)),
            ])
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Participants/Index', [
            'participants' => $participants->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'mobile' => $user->profile?->mobile_number,
                'organization' => $user->profile?->organization_name,
                'position' => $user->profile?->position_title,
                'salary_grade' => $user->profile?->salary_grade,
                'sector' => $user->profile?->sector,
                'region' => $user->profile?->region,
                'field_office' => $user->profile?->fieldOffice?->name,
                'registrations' => $user->total_registrations,
                'active_registrations' => $user->active_registrations,
                'settled_registrations' => $user->settled_registrations,
                'is_active' => $user->is_active,
                'email_verified' => $user->email_verified_at !== null,
                'profile_complete' => $user->hasCompletedProfile(),
                'url' => route('admin.participants.show', $user),
            ]),
            'filters' => $filters,
            'options' => ParticipantFilter::options($officeId),
            'stats' => $this->stats($officeId),
            // The download carries the filters, as v1's Export All did. Built
            // here rather than in the page so the query string cannot drift
            // from what the export controller reads.
            'exportUrl' => route('admin.exports.participants', array_filter($filters)),
            'can' => ['manage' => $this->mayManage($request)],
            'scopedTo' => $request->user()->fieldOffice?->name,
        ]);
    }

    public function show(Request $request, User $user): Response
    {
        $this->authorizeParticipant($request, $user);

        $user->load('profile.fieldOffice');

        $registrations = Registration::with(['training', 'payments'])
            ->where('user_id', $user->getKey())
            ->get()
            ->sortByDesc(fn (Registration $registration) => $registration->training->starts_at)
            ->values();

        return Inertia::render('Admin/Participants/Show', [
            'participant' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatarUrl(),
                'profile_complete' => $user->hasCompletedProfile(),
                'is_active' => $user->is_active,
                'email_verified' => $user->email_verified_at !== null,
                'has_password' => $user->hasPassword(),
                'has_google' => $user->hasGoogleAccount(),
                'last_login_at' => $user->last_login_at?->format('d M Y, g:i A'),
                'joined_at' => $user->created_at?->format('d M Y'),
                'edit_url' => route('admin.participants.edit', $user),
                'history_export_url' => route('admin.exports.participant-history', $user),
                'profile' => $user->profile ? [
                    'date_of_birth' => $user->profile->date_of_birth?->format('d M Y'),
                    'sex' => $user->profile->sex,
                    'civil_status' => $user->profile->civil_status,
                    'is_pwd' => $user->profile->is_pwd ? 'Yes' : 'No',
                    'mobile_number' => $user->profile->mobile_number,
                    'position_title' => $user->profile->position_title,
                    'salary_grade' => $user->profile->salary_grade,
                    'organization_name' => $user->profile->organization_name,
                    'sector' => $user->profile->sector,
                    'csc_field_office' => $user->profile->fieldOffice?->name,
                    'position_level' => $user->profile->position_level,
                    'employment_status' => $user->profile->employment_status,
                    'organization_address' => $user->profile->organization_address,
                    'region' => $user->profile->region,
                    'province' => $user->profile->province,
                    'city_municipality' => $user->profile->city_municipality,
                    'food_restrictions' => $user->profile->food_restrictions_details,
                ] : null,
            ],
            // v1's "Training Statistics" tiles, in v2's vocabulary: a
            // promissory note is a verified payment that is not settlement, so
            // it is counted apart from money that actually arrived.
            'trainingStats' => [
                'total' => $registrations->count(),
                'settled' => $registrations->filter(fn (Registration $r) => $r->hasSettledFee())->count(),
                'awaiting_payment' => $registrations
                    ->filter(fn (Registration $r) => ! $r->hasSettledFee())
                    ->count(),
                'promissory' => $registrations
                    ->filter(fn (Registration $r) => $r->payments->contains(
                        fn ($payment) => $payment->status === PaymentStatus::Verified
                            && $payment->payment_method === PaymentMethod::Promissory
                    ))
                    ->count(),
            ],
            'registrations' => $registrations->map(fn (Registration $registration) => [
                'id' => $registration->id,
                'status' => $registration->status->value,
                'title' => $registration->training->title,
                'starts_at' => $registration->training->starts_at->format('d M Y'),
                'registered_at' => $registration->created_at?->format('d M Y'),
                'payment' => $this->paymentLabel($registration),
                'roster_url' => route('admin.trainings.roster', $registration->training),
            ])->all(),
            'can' => ['manage' => $this->mayManage($request)],
        ]);
    }

    /**
     * v1's in-modal edit mode: HRD correcting a participant's own record.
     */
    public function edit(Request $request, User $user): Response
    {
        $this->authorizeParticipant($request, $user);

        $user->load('profile');

        return Inertia::render('Admin/Participants/Edit', [
            'options' => [...ProfileOptions::all(), 'fieldOffices' => FieldOffice::options()],
            'geography' => PhilippineGeography::nested(),
            'participant' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatarUrl(),
                'show_url' => route('admin.participants.show', $user),
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
            ] : null,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorizeParticipant($request, $user);

        $validated = $request->validate(
            ProfileService::rules($request->all()),
            ProfileService::messages()
        );

        // recordConsent: false — the participant gave consent, or has not. An
        // administrator fixing a typo does not get to answer that for them.
        ProfileService::save($user, $validated, recordConsent: false);

        return redirect()
            ->route('admin.participants.show', $user)
            ->with('success', "{$user->fresh()->name}'s profile has been updated.");
    }

    /**
     * v1's activate/deactivate button. A deactivated participant is refused at
     * sign-in (LoginController and GoogleController both check `is_active`),
     * which is the whole effect — nothing already registered is withdrawn.
     */
    public function toggle(Request $request, User $user): RedirectResponse
    {
        $this->authorizeParticipant($request, $user);

        $user->is_active = ! $user->is_active;
        $user->save();

        return back()->with(
            'success',
            "{$user->name} is now ".($user->is_active ? 'active' : 'deactivated').'.'
        );
    }

    /**
     * v1's key button: mail the participant a reset link on their behalf, for
     * the ones who phone the office instead of using Forgot Password.
     *
     * Goes through the same broker as the public form, so the link is the same
     * single-use, expiring one — the office never learns or sets the password.
     */
    public function sendPasswordReset(Request $request, User $user): RedirectResponse
    {
        $this->authorizeParticipant($request, $user);

        // A Google-only account has no password to reset; the broker would
        // happily mail a link that lands on a form the participant cannot use.
        if (! $user->hasPassword()) {
            return back()->withErrors([
                'participant' => "{$user->name} signs in with Google and has no password to reset.",
            ]);
        }

        $status = Password::broker()->sendResetLink(['email' => $user->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            return back()->withErrors([
                'participant' => 'The reset link could not be sent. Please try again in a moment.',
            ]);
        }

        return back()->with('success', "A password reset link has been sent to {$user->email}.");
    }

    /**
     * The counters across the top of v1's page. Deliberately computed over the
     * unfiltered (but still office-scoped) set: they are the denominator the
     * filtered table is read against, so narrowing the table must not move them.
     *
     * @return array<string, int>
     */
    private function stats(?int $officeId): array
    {
        return [
            'total' => ParticipantFilter::base($officeId)->count(),
            'active' => ParticipantFilter::base($officeId)->where('is_active', true)->count(),
            'verified' => ParticipantFilter::base($officeId)->whereNotNull('email_verified_at')->count(),
            'deactivated' => ParticipantFilter::base($officeId)->where('is_active', false)->count(),
        ];
    }

    /**
     * One participant, or a 404.
     *
     * 404 rather than 403 for the out-of-office case: the record's existence is
     * itself not a scoped user's to know.
     */
    private function authorizeParticipant(Request $request, User $user): void
    {
        abort_unless($user->role === Role::Participant, 404);

        $officeId = $request->user()->scopedFieldOfficeId();

        abort_if($officeId !== null && $user->profile?->field_office_id !== $officeId, 404);
    }

    /**
     * Whether the viewer may act on a participant, not merely read one.
     *
     * Mirrors the route middleware so the page can hide the buttons a
     * management viewer would be refused for pressing. Field-office staff are
     * in: fixing their own office's records is the ordinary case. What keeps
     * "their own" honest is authorizeParticipant(), not this — the role check
     * and the office guard compose, so neither needs to know about the other.
     */
    private function mayManage(Request $request): bool
    {
        return in_array(
            $request->user()->role,
            [Role::Admin, Role::SuperAdmin, Role::FieldOffice],
            true
        );
    }

    /**
     * A registration's payment position, as one badge for the history table.
     *
     * v1 rendered `registrations.payment_status` straight out of the column.
     * v2 has to derive it, because the money lives in its own table and a
     * registration can carry several attempts — what matters is whether any of
     * them was verified, and whether the thing verified was money or a promise.
     *
     * @return array{label: string, tone: string}
     */
    private function paymentLabel(Registration $registration): array
    {
        if (! $registration->training->payment_required) {
            return ['label' => 'No fee', 'tone' => 'neutral'];
        }

        $verified = $registration->payments->first(
            fn ($payment) => $payment->status === PaymentStatus::Verified
        );

        if ($verified) {
            return $verified->payment_method === PaymentMethod::Promissory
                ? ['label' => 'Promissory note', 'tone' => 'pending']
                : ['label' => 'Paid', 'tone' => 'verified'];
        }

        $pending = $registration->payments->contains(
            fn ($payment) => $payment->status === PaymentStatus::Pending
        );

        return $pending
            ? ['label' => 'Awaiting verification', 'tone' => 'pending']
            : ['label' => 'Unpaid', 'tone' => 'neutral'];
    }
}
