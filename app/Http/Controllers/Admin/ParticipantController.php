<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ParticipantController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();

        $officeId = $request->user()->scopedFieldOfficeId();

        $participants = User::query()
            ->with('profile.fieldOffice')
            ->where('role', Role::Participant)
            // Field-office staff see only their own office's participants.
            ->when($officeId !== null, fn ($query) => $query->whereHas(
                'profile',
                fn ($profile) => $profile->where('field_office_id', $officeId)
            ))
            ->when($search, fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhereHas('profile', fn ($p) => $p->where('organization_name', 'like', "%{$search}%"))
            ))
            ->withCount([
                'registrations as active_registrations' => fn ($query) => $query->whereIn('status', RegistrationStatus::occupying()),
            ])
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Participants/Index', [
            'participants' => $participants->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'organization' => $user->profile?->organization_name,
                'position' => $user->profile?->position_title,
                'field_office' => $user->profile?->fieldOffice?->name,
                'registrations' => $user->active_registrations,
                'profile_complete' => $user->hasCompletedProfile(),
                'url' => route('admin.participants.show', $user),
            ]),
            'filters' => ['search' => $search],
            'scopedTo' => $request->user()->fieldOffice?->name,
        ]);
    }

    public function show(Request $request, User $user): Response
    {
        abort_unless($user->role === Role::Participant, 404);

        $user->load('profile.fieldOffice');

        // A scoped user must not be able to read another office's participant
        // by guessing the URL. 404 rather than 403 — the record's existence is
        // itself not theirs to know.
        $officeId = $request->user()->scopedFieldOfficeId();

        abort_if($officeId !== null && $user->profile?->field_office_id !== $officeId, 404);

        $registrations = Registration::with('training')
            ->where('user_id', $user->getKey())
            ->get()
            ->sortByDesc(fn (Registration $registration) => $registration->training->starts_at)
            ->values();

        return Inertia::render('Admin/Participants/Show', [
            'participant' => [
                'name' => $user->name,
                'email' => $user->email,
                'profile_complete' => $user->hasCompletedProfile(),
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
                    'agency_unit' => $user->profile->agency_unit,
                    'region' => $user->profile->region,
                    'province' => $user->profile->province,
                    'city_municipality' => $user->profile->city_municipality,
                    'home_address' => $user->profile->home_address,
                    'food_restrictions' => $user->profile->food_restrictions_details,
                ] : null,
            ],
            'registrations' => $registrations->map(fn (Registration $registration) => [
                'id' => $registration->id,
                'status' => $registration->status->value,
                'title' => $registration->training->title,
                'starts_at' => $registration->training->starts_at->format('d M Y'),
            ])->all(),
        ]);
    }
}
