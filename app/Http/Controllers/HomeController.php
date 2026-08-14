<?php

namespace App\Http\Controllers;

use App\Enums\RegistrationStatus;
use App\Enums\TrainingStatus;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * Show the public landing page.
     */
    public function index(): Response
    {
        return Inertia::render('Home', [
            'stats' => $this->stats(),
            'upcomingTrainings' => $this->upcomingTrainings(),
        ]);
    }

    /**
     * The next three registrable programs for the landing page.
     *
     * "Registrable" is strict on purpose: published, still in the future, and
     * inside its registration window (no window set = open immediately and
     * indefinitely), with a capped run that is already full dropped from the
     * list. The card opens a detail modal for anonymous visitors, so the payload
     * carries the full catalogue picture (description, schedule,
     * curriculum, fee) but still nothing that is only earned by signing in —
     * meeting links and facilitator details stay off the public page.
     *
     * @return array<int, array{
     *     id: int,
     *     title: string,
     *     venue: string,
     *     venue_details: string|null,
     *     mode: string,
     *     level_label: string|null,
     *     category: string|null,
     *     payment_required: bool,
     *     payment_amount: string|null,
     *     starts_at: string,
     *     ends_at: string|null,
     *     duration_days: int|null,
     *     description: string|null,
     *     prerequisites: string|null,
     *     target_participants: string|null,
     *     registration_closes_at: string|null,
     *     capacity: int|null,
     *     slots_remaining: int|null,
     *     is_supervisory: bool,
     * }>
     */
    private function upcomingTrainings(): array
    {
        return Training::query()
            ->visible()
            ->upcoming()
            ->withCount('activeRegistrations')
            ->where(function ($query) {
                $query->whereNull('registration_opens_at')
                    ->orWhere('registration_opens_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('registration_closes_at')
                    ->orWhere('registration_closes_at', '>=', now());
            })
            ->orderBy('starts_at')
            ->get()
            ->reject(fn (Training $training) => $training->capacity !== null
                && $training->active_registrations_count >= $training->capacity)
            ->take(3)
            ->values()
            ->map(fn (Training $training) => [
                'id' => $training->id,
                'title' => $training->title,
                'venue' => $training->venue,
                'venue_details' => $training->venue_details,
                'mode' => $training->mode->label(),
                'level_label' => $training->level?->label(),
                'category' => $training->category,
                'payment_required' => $training->payment_required,
                'payment_amount' => $training->payment_required ? $training->payment_amount : null,
                'starts_at' => $training->starts_at->format('M j, Y'),
                'ends_at' => $training->ends_at?->format('M j, Y'),
                'duration_days' => $training->duration_days,
                'description' => $training->description,
                'prerequisites' => $training->prerequisites,
                'target_participants' => $training->target_participants,
                'registration_closes_at' => $training->registration_closes_at?->format('M j, Y'),
                'capacity' => $training->capacity,
                'slots_remaining' => $training->capacity === null
                    ? null
                    : max(0, $training->capacity - $training->active_registrations_count),
                'is_supervisory' => $training->is_supervisory,
            ])
            ->all();
    }

    /**
     * Landing-page headline figures.
     *
     * Real counts straight from the database, cached for an hour so a public
     * page never runs four queries per hit. The regional-offices figure stays a
     * constant on purpose: it describes the nationwide CSC organisation, which
     * this database (a single regional deployment) cannot count.
     *
     * @return array<int, array{figure: string, label: string}>
     */
    private function stats(): array
    {
        return Cache::remember('home.stats', now()->addHour(), function () {
            $enrolled = User::whereNotNull('profile_completed_at')->count();

            // "Delivered" = a training that has actually begun, any status that
            // is not still a draft or unceremoniously cancelled.
            $delivered = Training::whereNotIn('status', [TrainingStatus::Draft, TrainingStatus::Cancelled])
                ->where('starts_at', '<=', now())
                ->count();

            $approvedOrCompleted = Registration::whereIn('status', [
                RegistrationStatus::Approved,
                RegistrationStatus::Completed,
            ])->count();
            $completed = Registration::where('status', RegistrationStatus::Completed)->count();
            $completion = $approvedOrCompleted
                ? (int) round($completed / $approvedOrCompleted * 100)
                : 0;

            return [
                ['figure' => number_format($enrolled), 'label' => 'Personnel enrolled'],
                ['figure' => number_format($delivered), 'label' => 'Programs delivered'],
                ['figure' => $completion.'%', 'label' => 'Completion rate'],
                ['figure' => '17', 'label' => 'Regional offices'],
            ];
        });
    }
}
