<?php

namespace App\Http\Controllers;

use App\Enums\ChargeTo;
use App\Enums\Curriculum;
use App\Enums\RegistrationStatus;
use App\Enums\TrainingMode;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Support\SupervisoryEligibility;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class TrainingController extends Controller
{
    /**
     * The catalogue of open trainings.
     */
    public function index(Request $request): Response
    {
        // Filter state comes straight off the query string and is echoed back
        // so the UI can restore the controls. The only validation here is
        // "does it look like one of ours": an unknown value simply matches
        // nothing, which reads as an honest empty result rather than an error.
        $filters = [
            'search' => $request->string('search')->trim()->toString(),
            'mode' => $request->string('mode')->toString(),
            'category' => $request->string('category')->toString(),
            'open' => $request->boolean('open'),
            'sort' => $request->string('sort')->toString(),
        ];

        $trainings = Training::visible()
            ->upcoming()
            ->withCount([
                'registrations as active_registrations_count' => fn ($query) => $query->whereIn('status', RegistrationStatus::occupying()),
            ])
            ->when($filters['search'], fn ($query, $search) => $query->where(
                fn ($query) => $query
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('training_code', 'like', "%{$search}%")
                    ->orWhere('venue', 'like', "%{$search}%")
            ))
            ->when($filters['mode'], fn ($query, $mode) => $query->where('mode', $mode))
            ->when($filters['category'], fn ($query, $category) => $query->where('category', $category))
            ->when($filters['open'], fn ($query) => $query
                // Registration window actually open: past the open date (or no
                // open date set) and before the close date (or none set). The
                // same pair of predicates the model derives from.
                ->where(fn ($query) => $query->whereNull('registration_opens_at')->orWhere('registration_opens_at', '<=', now()))
                ->where(fn ($query) => $query->whereNull('registration_closes_at')->orWhere('registration_closes_at', '>=', now()))
            )
            ->when(
                $filters['sort'] === 'closing',
                // Registrations that close soonest surface first; ones without
                // a deadline sort after every dated deadline.
                fn ($query) => $query->orderByRaw('registration_closes_at IS NULL, registration_closes_at ASC'),
                fn ($query) => $query->orderBy('starts_at')
            )
            ->paginate(9)
            ->withQueryString();

        // training_id => status of this participant's slot-holding registrations
        // on the page's trainings. Lets a card show its own registration badge.
        $myRegistrations = Registration::where('user_id', $request->user()->getKey())
            ->whereIn('training_id', $trainings->pluck('id'))
            ->whereIn('status', RegistrationStatus::occupying())
            ->pluck('status', 'training_id')
            ->map(fn (RegistrationStatus $status) => $status->value)
            ->all();

        // A region-wide count for the "your registrations" strip, so the figure
        // is true across pages rather than only on the page in view.
        $registeredCount = Registration::where('user_id', $request->user()->getKey())
            ->whereIn('status', RegistrationStatus::occupying())
            ->whereHas('training', fn ($query) => $query->visible()->upcoming())
            ->count();

        // The detail a card opens in its modal is fetched on demand, not shipped
        // with every catalogue page: the modal asks for it by partial reload
        // with a "details" id on the query string, and the response only carries
        // that one training's full picture. Keeping it out of the default
        // payload keeps nine full descriptions off a page that shows nine cards.
        $details = null;

        if ($request->filled('details')) {
            $selected = Training::query()
                ->visible()
                ->upcoming()
                ->whereKey($request->integer('details'))
                ->first();

            if ($selected) {
                $details = self::detail($selected, $request->user());
            }
        }

        return Inertia::render('Trainings/Index', [
            'trainings' => [
                'data' => $trainings->map(fn (Training $training) => self::summarize(
                    $training,
                    $myRegistrations[$training->id] ?? null,
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
            'filters' => $filters,
            'filterOptions' => [
                'modes' => TrainingMode::options(),
                'categories' => Curriculum::options(),
            ],
            'registeredCount' => $registeredCount,
            'details' => $details,
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
                ...self::summarize($training, $registration?->status?->value),
                'description' => $training->description,
                'training_code' => $training->training_code,
                'ends_at' => $training->ends_at->format('d M Y'),
                'registration_opens_at' => $training->registration_opens_at?->format('d M Y, g:i A'),
                'registration_closes_at' => $training->registration_closes_at?->format('d M Y, g:i A'),
                'facilitator_name' => $training->facilitator_name,
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
            // What the registration form has to ask this particular
            // participant. Resolved here because it depends on their salary
            // grade, which the page has no business receiving.
            'eligibility' => [
                'barred' => SupervisoryEligibility::isBarred($training, $request->user()),
                'barred_reason' => SupervisoryEligibility::barredMessage(),
                'needs_supporting_document' => SupervisoryEligibility::requiresSupportingDocument(
                    $training,
                    $request->user(),
                ),
                'supporting_document_hint' => SupervisoryEligibility::documentHint(),
            ],
            'chargeOptions' => ChargeTo::options(),
        ]);
    }

    /**
     * The Learning & Development calendar, ported from v1's `calendar.php`.
     *
     * The catalogue answers "what can I sign up for"; this answers "what is
     * running in March", which is the question an HR officer planning a year
     * actually asks and which a paginated list cannot serve.
     *
     * The grid is built here rather than in the browser. Month boundaries,
     * leading blanks and multi-day spans are exactly the arithmetic that goes
     * wrong in JavaScript across time zones, and doing it server-side makes it
     * something a test can assert.
     */
    public function calendar(Request $request): Response
    {
        $month = $this->requestedMonth($request->string('month')->toString());

        $start = $month->startOfMonth();
        $end = $month->endOfMonth();

        /*
         * Overlap, not "starts this month". v1 matched on the start date alone,
         * so a run beginning 30 September and ending 3 October vanished from
         * the October calendar entirely — the month it was mostly running in.
         */
        $trainings = Training::visible()
            ->where('starts_at', '<=', $end->endOfDay())
            ->where(fn ($query) => $query
                ->whereNull('ends_at')
                ->orWhere('ends_at', '>=', $start->startOfDay())
            )
            ->withCount([
                'registrations as active_registrations_count' => fn ($query) => $query
                    ->whereIn('status', RegistrationStatus::occupying()),
            ])
            ->orderBy('starts_at')
            ->get();

        $mine = Registration::where('user_id', $request->user()->getKey())
            ->whereIn('training_id', $trainings->pluck('id'))
            ->whereIn('status', RegistrationStatus::occupying())
            ->pluck('status', 'training_id')
            ->map(fn (RegistrationStatus $status) => $status->value)
            ->all();

        return Inertia::render('Trainings/Calendar', [
            'month' => [
                'value' => $month->format('Y-m'),
                'label' => $month->format('F Y'),
                'previous' => $month->subMonth()->format('Y-m'),
                'next' => $month->addMonth()->format('Y-m'),
                'is_current' => $month->isSameMonth(CarbonImmutable::now()),
            ],
            'weeks' => $this->calendarWeeks($start, $end, $trainings, $mine),
            // The same runs as a plain list underneath the grid: a calendar
            // cell can only carry a title, and the list is what a screen reader
            // and a narrow phone get instead of a seven-column table.
            'trainings' => $trainings
                ->map(fn (Training $training) => self::summarize($training, $mine[$training->id] ?? null))
                ->all(),
        ]);
    }

    /**
     * The month being viewed, as a first-of-month date.
     *
     * Falls back to the current month rather than erroring on anything it
     * cannot read: the value comes off a query string that people edit, share
     * and bookmark, and a 500 on a mistyped URL helps nobody.
     */
    private function requestedMonth(string $value): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $value)) {
            return CarbonImmutable::now()->startOfMonth();
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', "{$value}-01")->startOfMonth();
        } catch (\Throwable) {
            return CarbonImmutable::now()->startOfMonth();
        }
    }

    /**
     * The month laid out as whole weeks, Sunday-first.
     *
     * Leading and trailing days from the neighbouring months are included and
     * flagged, so the grid is always a rectangle — a calendar that changes
     * shape between months is one that reflows the page every time you page
     * through it.
     *
     * @param  Collection<int, Training>  $trainings
     * @param  array<int, string>  $mine
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function calendarWeeks(
        CarbonImmutable $start,
        CarbonImmutable $end,
        $trainings,
        array $mine
    ): array {
        $cursor = $start->startOfWeek(CarbonInterface::SUNDAY);
        $last = $end->endOfWeek(CarbonInterface::SATURDAY);

        $weeks = [];
        $week = [];

        while ($cursor <= $last) {
            $day = $cursor;

            $week[] = [
                'date' => $day->format('Y-m-d'),
                'day' => $day->day,
                'in_month' => $day->month === $start->month,
                'is_today' => $day->isToday(),
                // A training occupies every day it runs, not just its first —
                // that is the whole reason to draw a calendar rather than a list.
                'events' => $trainings
                    ->filter(fn (Training $training) => $day->betweenIncluded(
                        $training->starts_at->startOfDay(),
                        ($training->ends_at ?? $training->starts_at)->endOfDay(),
                    ))
                    ->map(fn (Training $training) => [
                        'id' => $training->id,
                        'title' => $training->title,
                        'mode' => $training->mode->value,
                        'is_registered' => isset($mine[$training->id]),
                        'is_start' => $day->isSameDay($training->starts_at),
                        'url' => route('trainings.show', $training->slug),
                    ])
                    ->values()
                    ->all(),
            ];

            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }

            $cursor = $cursor->addDay();
        }

        return $weeks;
    }

    /**
     * The shape shared by the catalogue and detail pages.
     *
     * @return array<string, mixed>
     */
    private static function summarize(Training $training, ?string $registrationStatus): array
    {
        $taken = $training->active_registrations_count ?? 0;
        $remaining = $training->capacity === null ? null : max(0, $training->capacity - $taken);

        return [
            'id' => $training->id,
            'slug' => $training->slug,
            'title' => $training->title,
            'training_code' => $training->training_code,
            'venue' => $training->venue,
            'starts_at' => $training->starts_at->format('d M Y'),
            'ends_at' => $training->ends_at?->format('d M Y'),
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
            'registration_opens_at' => $training->registration_opens_at?->format('d M Y, g:i A'),
            'registration_closes_at' => $training->registration_closes_at?->format('d M Y, g:i A'),
            'is_registered' => $registrationStatus !== null,
            'registration_status' => $registrationStatus,
            'registration_status_label' => $registrationStatus
                ? RegistrationStatus::from($registrationStatus)->label()
                : null,
            'is_supervisory' => $training->is_supervisory,
            'url' => route('trainings.show', $training->slug),
        ];
    }

    /**
     * The full catalogue picture a card opens in its modal.
     *
     * Everything a participant can read before deciding to register — the
     * schedule, venue details, curriculum, fee, prerequisites and eligibility
     * notes. The earned-only fields (meeting link, finance) stay on the Show
     * page, where a registration has already happened.
     *
     * @return array<string, mixed>
     */
    private static function detail(Training $training, User $user): array
    {
        $training->loadCount([
            'registrations as active_registrations_count' => fn ($query) => $query->whereIn('status', RegistrationStatus::occupying()),
        ]);

        $registration = Registration::where('user_id', $user->getKey())
            ->where('training_id', $training->getKey())
            ->first();

        return [
            ...self::summarize($training, $registration?->status?->value),
            'ends_at' => $training->ends_at?->format('d M Y, g:i A'),
            'description' => $training->description,
            'prerequisites' => $training->prerequisites,
            'target_participants' => $training->target_participants,
            'level_label' => $training->level?->label(),
            'venue_details' => $training->venue_details,
            'is_supervisory' => $training->is_supervisory,
        ];
    }
}
