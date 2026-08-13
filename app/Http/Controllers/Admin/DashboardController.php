<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Enums\TrainingStatus;
use App\Http\Controllers\Controller;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /** How many rows a dialog will show before it stops being a dialog. */
    private const MODAL_LIMIT = 50;

    public function __invoke(Request $request): Response
    {
        $officeId = $request->user()->scopedFieldOfficeId();

        $upcoming = Training::visible()
            ->upcoming()
            ->withCount([
                'registrations as active_count' => fn ($query) => $query->whereIn('status', RegistrationStatus::occupying()),
            ])
            ->orderBy('starts_at')
            ->limit(5)
            ->get();

        // People are scoped to the staff member's field office; the same rule
        // the roster and participant directory apply.
        $scopeToOffice = fn ($query, string $path) => $query->when(
            $officeId !== null,
            fn ($inner) => $inner->whereHas($path, fn ($profile) => $profile->where('field_office_id', $officeId))
        );

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'published' => Training::where('status', TrainingStatus::Published)->count(),
                'drafts' => Training::where('status', TrainingStatus::Draft)->count(),
                // People counts are scoped; the training catalogue is regional
                // and stays the same for everyone.
                'participants' => $scopeToOffice(
                    User::where('role', Role::Participant), 'profile'
                )->count(),
                'registrations' => $scopeToOffice(
                    Registration::whereIn('status', RegistrationStatus::occupying()), 'user.profile'
                )->count(),
            ],
            'scopedTo' => $request->user()->fieldOffice?->name,
            'upcoming' => $upcoming->map(fn (Training $training) => [
                'id' => $training->id,
                'title' => $training->title,
                'venue' => $training->venue,
                'starts_at' => $training->starts_at->format('d M Y, g:i A'),
                'when' => $training->starts_at->diffForHumans(),
                'registered' => $training->active_count,
                'capacity' => $training->capacity,
                // Surfaced so a nearly-full or empty session gets noticed early.
                'nearly_full' => $training->capacity !== null
                    && $training->active_count >= (int) ceil($training->capacity * 0.9),
                'roster_url' => route('admin.trainings.roster', $training),
            ])->all(),
            'awaitingCompletion' => Training::visible()
                ->where('ends_at', '<', now())
                ->whereHas('registrations', fn ($query) => $query->where(
                    'status', RegistrationStatus::Approved
                ))
                ->orderByDesc('ends_at')
                ->limit(5)
                ->get()
                ->map(fn (Training $training) => [
                    'id' => $training->id,
                    'title' => $training->title,
                    'ended' => $training->ends_at->format('d M Y'),
                    'roster_url' => route('admin.trainings.roster', $training),
                ])->all(),

            /*
             * Modal payloads.
             *
             * Optional props are skipped on the initial render and only run when
             * the page asks for them by name, so opening a dialog costs one
             * query and a dashboard visit that opens nothing costs none. Both
             * are capped rather than paginated — a dialog is for looking, and
             * the roster is where the work actually happens.
             */
            'registrationsList' => Inertia::optional(fn () => $scopeToOffice(
                Registration::with(['user', 'training'])
                    ->whereIn('status', RegistrationStatus::occupying()),
                'user.profile'
            )
                ->latest()
                ->limit(self::MODAL_LIMIT)
                ->get()
                ->map(fn (Registration $registration) => [
                    'id' => $registration->id,
                    'participant' => $registration->user->name,
                    'training' => $registration->training->title,
                    'status' => $registration->status->value,
                    'registered_on' => $registration->created_at->format('d M Y'),
                    'roster_url' => route('admin.trainings.roster', $registration->training),
                ])->all()),

            'awaitingCompletionList' => Inertia::optional(fn () => Training::visible()
                ->where('ends_at', '<', now())
                ->whereHas('registrations', fn ($query) => $query->where(
                    'status', RegistrationStatus::Approved
                ))
                ->withCount([
                    'registrations as pending_count' => fn ($query) => $query->where(
                        'status', RegistrationStatus::Approved
                    ),
                ])
                ->orderByDesc('ends_at')
                ->limit(self::MODAL_LIMIT)
                ->get()
                ->map(fn (Training $training) => [
                    'id' => $training->id,
                    'title' => $training->title,
                    'ended' => $training->ends_at->format('d M Y'),
                    'pending' => $training->pending_count,
                    'roster_url' => route('admin.trainings.roster', $training),
                ])->all()),
        ]);
    }
}
