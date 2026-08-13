<?php

namespace App\Http\Controllers;

use App\Enums\RegistrationStatus;
use App\Models\Registration;
use App\Models\Training;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TrainingController extends Controller
{
    /**
     * The catalogue of open trainings.
     */
    public function index(Request $request): Response
    {
        $trainings = Training::visible()
            ->upcoming()
            ->withCount([
                'registrations as active_registrations_count' => fn ($query) => $query->whereIn('status', RegistrationStatus::occupying()),
            ])
            ->orderBy('starts_at')
            ->paginate(9)
            ->withQueryString();

        $myRegistrations = Registration::where('user_id', $request->user()->getKey())
            ->whereIn('training_id', $trainings->pluck('id'))
            ->whereIn('status', RegistrationStatus::occupying())
            ->pluck('training_id')
            ->all();

        return Inertia::render('Trainings/Index', [
            'trainings' => [
                'data' => $trainings->map(fn (Training $training) => self::summarize(
                    $training,
                    in_array($training->id, $myRegistrations, true)
                ))->all(),
                'meta' => [
                    'current_page' => $trainings->currentPage(),
                    'last_page' => $trainings->lastPage(),
                    'per_page' => $trainings->perPage(),
                    'from' => $trainings->firstItem(),
                    'to' => $trainings->lastItem(),
                    'total' => $trainings->total(),
                ],
            ],
        ]);
    }

    /**
     * A single training.
     */
    public function show(Request $request, Training $training): Response
    {
        abort_unless($training->status->isOpenToParticipants(), 404);

        $registration = Registration::with('payments')
            ->where('user_id', $request->user()->getKey())
            ->where('training_id', $training->getKey())
            ->first();

        $training->loadCount([
            'registrations as active_registrations_count' => fn ($query) => $query->whereIn('status', RegistrationStatus::occupying()),
        ]);

        /*
         * The join link is withheld on the server rather than sent and hidden
         * in the page: an Inertia payload is plain JSON in the response body,
         * so anything shipped here is readable whatever the template does with
         * it. Only the two booleans below cross the wire when the link does not.
         */
        $mayJoin = (bool) $registration?->setRelation('training', $training)->mayViewMeetingLink();

        return Inertia::render('Trainings/Show', [
            'training' => [
                ...self::summarize($training, (bool) $registration?->isActive()),
                'description' => $training->description,
                'training_code' => $training->training_code,
                'ends_at' => $training->ends_at->format('d M Y, g:i A'),
                'registration_opens_at' => $training->registration_opens_at?->format('d M Y, g:i A'),
                'registration_closes_at' => $training->registration_closes_at?->format('d M Y, g:i A'),
                'facilitator_name' => $training->facilitator_name,
                'objectives' => $training->objectives,
                'prerequisites' => $training->prerequisites,
                'target_participants' => $training->target_participants,
                'level_label' => $training->level?->label(),
                'venue_details' => $training->venue_details,
                'is_supervisory' => $training->is_supervisory,
                'accepts_promissory' => $training->payment_required && $training->accepts_promissory,
                'meeting_link' => $mayJoin ? $training->meeting_link : null,
                // Drives the "why can't I see it yet" line, so the page can say
                // a link exists without disclosing it.
                'has_meeting_link' => filled($training->meeting_link),
            ],
            'registration' => $registration ? [
                'id' => $registration->id,
                'status' => $registration->status->value,
                'registered_at' => $registration->registered_at->format('d M Y'),
                // Lets the page name the one thing still standing between the
                // participant and the link, rather than a blanket "not yet".
                'fee_settled' => $registration->hasSettledFee(),
            ] : null,
        ]);
    }

    /**
     * The shape shared by the catalogue and detail pages.
     *
     * @return array<string, mixed>
     */
    private static function summarize(Training $training, bool $isRegistered): array
    {
        $taken = $training->active_registrations_count ?? 0;
        $remaining = $training->capacity === null ? null : max(0, $training->capacity - $taken);

        return [
            'id' => $training->id,
            'slug' => $training->slug,
            'title' => $training->title,
            'venue' => $training->venue,
            'starts_at' => $training->starts_at->format('d M Y, g:i A'),
            'day' => $training->starts_at->format('d'),
            'month' => $training->starts_at->format('M'),
            'capacity' => $training->capacity,
            'category' => $training->category,
            'mode' => $training->mode->value,
            'mode_label' => $training->mode->label(),
            'duration_days' => $training->duration_days,
            'payment_required' => $training->payment_required,
            'payment_amount' => $training->payment_required ? $training->payment_amount : null,
            'slots_remaining' => $remaining,
            'is_full' => $remaining !== null && $remaining === 0,
            'registration_closed' => $training->registrationHasClosed(),
            'registration_not_yet_open' => ! $training->registrationHasOpened(),
            'is_registered' => $isRegistered,
            'url' => route('trainings.show', $training->slug),
        ];
    }
}
