<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Enums\TrainingStatus;
use App\Http\Controllers\Controller;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Support\PendingActionCounter;
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

        /*
         * Finished runs with participants still to mark complete.
         *
         * Built once as a query so the card, its dialog and the total in the
         * "View all" label all describe the same set — three separate copies of
         * these predicates is three chances for the button to promise rows the
         * dialog does not have.
         */
        $awaitingCompletion = fn () => Training::visible()
            ->where('ends_at', '<', now())
            ->whereHas('registrations', fn ($query) => $query->where(
                'status', RegistrationStatus::Approved
            ));

        // People are scoped to the staff member's field office; the same rule
        // the roster and participant directory apply.
        $scopeToOffice = fn ($query, string $path) => $query->when(
            $officeId !== null,
            fn ($inner) => $inner->whereHas($path, fn ($profile) => $profile->where('field_office_id', $officeId))
        );

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'published' => Training::where('status', TrainingStatus::Published)->count(),
                // People counts are scoped; the training catalogue is regional
                // and stays the same for everyone.
                'participants' => $scopeToOffice(
                    User::where('role', Role::Participant), 'profile'
                )->count(),
                'registrations' => $scopeToOffice(
                    Registration::whereIn('status', RegistrationStatus::occupying()), 'user.profile'
                )->count(),
                /*
                 * The one tile that is work rather than inventory.
                 *
                 * The other three say how much exists; a staff member opening
                 * this page wants to know what is waiting on them, and until
                 * now the only place that number appeared was the sidebar
                 * badge. Taken from PendingActionCounter so the tile and the
                 * badge can never disagree, and null for a role with no such
                 * queue rather than a zero that reads as "all clear" — see the
                 * template, which drops the tile entirely in that case.
                 */
                'requests' => PendingActionCounter::for($request->user())['admin-requests'] ?? null,
            ],
            'scopedTo' => $request->user()->fieldOffice?->name,
            // So a dialog can say it is showing a capped slice rather than
            // letting the reader assume fifty rows is the whole set.
            'modalLimit' => self::MODAL_LIMIT,
            'upcoming' => $upcoming->map(fn (Training $training) => [
                'id' => $training->id,
                'title' => $training->title,
                'venue' => $training->venue,
                'starts_at' => $training->starts_at->format('d M Y, g:i A'),
                'when' => $training->starts_at->diffForHumans(),
                'registered' => $training->active_count,
                'capacity' => $training->capacity,
                // Surfaced so a nearly-full or empty session gets noticed early.
                // Full is deliberately separate from nearly full: a session at
                // 40/40 read as "Nearly full" understated it, and the two call
                // for different decisions from whoever is looking.
                'nearly_full' => $training->capacity !== null
                    && $training->active_count >= (int) ceil($training->capacity * 0.9)
                    && $training->active_count < $training->capacity,
                'full' => $training->capacity !== null
                    && $training->active_count >= $training->capacity,
                // For the capacity meter. Clamped, because an over-subscribed
                // run would otherwise draw a bar past the end of its track.
                'fill' => $training->capacity > 0
                    ? min(100, (int) round($training->active_count / $training->capacity * 100))
                    : null,
                'roster_url' => route('admin.trainings.roster', $training),
            ])->all(),
            'awaitingCompletion' => $awaitingCompletion()
                ->orderByDesc('ends_at')
                ->limit(5)
                ->get()
                ->map(fn (Training $training) => [
                    'id' => $training->id,
                    'title' => $training->title,
                    'ended' => $training->ends_at->format('d M Y'),
                    'roster_url' => route('admin.trainings.roster', $training),
                ])->all(),
            // How many there are in total, so "View all" can name a number and
            // only appear when there is genuinely more than the card is showing.
            'awaitingCompletionTotal' => $awaitingCompletion()->count(),

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

            'awaitingCompletionList' => Inertia::optional(fn () => $awaitingCompletion()
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
