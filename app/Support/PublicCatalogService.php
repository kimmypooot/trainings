<?php

namespace App\Support;

use App\Models\Training;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the Commission is offering, as an anonymous visitor sees it.
 *
 * Two public surfaces read this: the landing page shows the first handful, and
 * /programs shows the whole calendar with filters. They were always going to
 * describe the same programs in the same words, so the query, the payload and
 * the status vocabulary live here rather than being written twice and drifting.
 *
 * The signed-in catalogue (TrainingController) is deliberately *not* built on
 * this. It answers a different question — "what can I, this participant, do
 * about this run" — and carries registration state, eligibility, and meeting
 * links that have no business in an anonymous payload.
 *
 * This is a catalogue, not a registration queue. A run that is full, not yet
 * open, past its deadline, or already under way is still listed; it just says
 * so. Hiding those was the old behaviour and it kept most of a regional
 * office's calendar off its own public site.
 */
class PublicCatalogService
{
    /** Below this many days to the deadline, a run is flagged as closing. */
    public const CLOSING_SOON_DAYS = 7;

    /**
     * Every published run that has not finished, soonest first.
     *
     * Left as a Builder so callers can filter and paginate: the landing page
     * takes a fixed few, /programs applies search and facets.
     *
     * @return Builder<Training>
     */
    public static function query(): Builder
    {
        return Training::query()
            ->visible()
            ->notEnded()
            ->withCount('activeRegistrations')
            ->orderBy('starts_at');
    }

    /**
     * One program as a public card.
     *
     * Catalogue facts only. Meeting links and facilitator contact details are
     * earned by signing in and must never reach this array — an Inertia payload
     * is readable JSON in the response body, so anything included here is
     * published whatever the template chooses to render.
     *
     * @return array<string, mixed>
     */
    public static function card(Training $training): array
    {
        [$status, $label] = self::registrationState($training);

        return [
            'id' => $training->id,
            'title' => $training->title,
            'venue' => $training->venue,
            'venue_details' => $training->venue_details,
            'mode' => $training->mode->label(),
            'mode_value' => $training->mode->value,
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
            'registration_opens_at' => $training->registration_opens_at?->format('M j, Y'),
            'registration_closes_at' => $training->registration_closes_at?->format('M j, Y'),
            'capacity' => $training->capacity,
            'slots_remaining' => $training->capacity === null
                ? null
                : max(0, $training->capacity - $training->active_registrations_count),
            'is_supervisory' => $training->is_supervisory,
            'status' => $status,
            'status_label' => $label,
            'is_registrable' => $status === 'open' || $status === 'closing-soon',
        ];
    }

    /**
     * Where a run sits in its registration lifecycle, as [key, label].
     *
     * Ordered by what a visitor most needs to know first: a run already being
     * delivered is past registering whatever its window says; a window that has
     * not opened is the answer even for a run that looks full, because the slot
     * count can still move before opening day; a full run beats a still-open
     * window because the window is moot once the seats are gone. Only what
     * survives all of that is genuinely registrable.
     *
     * "Closing soon" is a hint, not a different permission — it stays
     * registrable, and exists to give a card its one piece of urgency without
     * ever overstating it.
     *
     * @return array{0: string, 1: string}
     */
    public static function registrationState(Training $training): array
    {
        if ($training->starts_at->isPast()) {
            return ['ongoing', 'In progress'];
        }

        if (! $training->registrationHasOpened()) {
            return ['opening', 'Opens '.$training->registration_opens_at->format('M j')];
        }

        if ($training->capacity !== null && $training->active_registrations_count >= $training->capacity) {
            return ['full', 'Fully booked'];
        }

        if ($training->registrationHasClosed()) {
            return ['closed', 'Registration closed'];
        }

        $deadline = $training->registration_closes_at ?? $training->starts_at;

        // Absolute is safe here: registrationHasClosed() already ruled out a
        // deadline in the past, so the difference can only be forward.
        if (now()->startOfDay()->diffInDays($deadline, absolute: true) <= self::CLOSING_SOON_DAYS) {
            return ['closing-soon', 'Closes '.$deadline->format('M j')];
        }

        return ['open', 'Open for registration'];
    }
}
