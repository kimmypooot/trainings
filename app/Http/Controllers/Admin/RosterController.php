<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AttendanceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RegistrationStatus;
use App\Enums\SupervisoryDocumentStatus;
use App\Http\Controllers\Concerns\ManagesRosterDecisions;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\ScanLink;
use App\Models\Training;
use App\Models\User;
use App\Support\RegistrationService;
use App\Support\RosterFilter;
use App\Support\SmeEvaluationService;
use App\Support\SupervisoryDocumentService;
use App\Support\TransferTargets;
use App\Support\UndoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The roster for one training, and the decisions taken on it a row at a time.
 *
 * Split out of Admin\TrainingController, where the roster and its five
 * actions had grown to about 460 of that class's 1,361 lines and had nothing to
 * do with creating or editing a training. The routes, their names and their URLs
 * are unchanged; only the class they point at moved.
 *
 * Every action here re-resolves its route-bound registration through
 * ManagesRosterDecisions::scopedRegistration() before touching it. Route-model
 * binding does not know about field-office scoping, so that call is the whole
 * of what stops a scoped officer acting on another office's participant by
 * posting its id. FieldOfficeScopingTest is the guard.
 */
class RosterController extends Controller
{
    use ManagesRosterDecisions;

    /**
     * The roster for a single training.
     */
    public function show(Request $request, Training $training): Response
    {
        $officeId = $request->user()->scopedFieldOfficeId();

        // dayEvaluations rides along with attendances because
        // SmeEvaluationService::progressFor() reads both, and without it the
        // evaluation column would cost a query per participant.
        $registrations = Registration::with([
            'user.profile.fieldOffice', 'attendances', 'certificate', 'payments',
            'supervisoryDocumentReviewer', 'dayEvaluations',
        ])
            ->where('training_id', $training->getKey())
            // Field-office staff see only their own office's participants on
            // the roster; the training itself stays visible to everyone.
            ->when($officeId !== null, fn ($query) => $query->whereHas(
                'user.profile',
                fn ($profile) => $profile->where('field_office_id', $officeId)
            ))
            ->orderBy('registered_at')
            ->get();

        // Every row belongs to the training already in hand, so handing it over
        // saves the attendance and fee predicates a query each per participant.
        $registrations->each(fn (Registration $registration) => $registration->setRelation('training', $training));

        $todayDay = $training->dayNumberFor(now());
        $filters = RosterFilter::fromRequest($request);

        // The rows this request is actually about, in the order it asked for.
        // Everything below that counts rather than lists keeps reading
        // `$registrations` — a chip reading "12 cancelled" has to say twelve
        // whether or not "cancelled" is the filter currently applied.
        $matching = RosterFilter::apply($registrations, $filters, $todayDay);

        $page = LengthAwarePaginator::resolveCurrentPage();
        $paginator = new LengthAwarePaginator(
            $matching->forPage($page, RosterFilter::PER_PAGE)->values(),
            $matching->count(),
            RosterFilter::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return Inertia::render('Admin/Trainings/Roster', [
            'training' => [
                'id' => $training->id,
                'title' => $training->title,
                'venue' => $training->venue,
                'starts_at' => $training->starts_at->format('d M Y, g:i A'),
                'ends_at' => $training->ends_at->format('d M Y, g:i A'),
                'capacity' => $training->capacity,
                'status_label' => $training->status->label(),
                'duration_days' => $training->duration_days,
                'is_supervisory' => $training->is_supervisory,
                // Whether this run collects evaluations at all. A training with
                // no panel assigned has nothing to chase, so the roster drops
                // the column rather than showing a page of dashes.
                'collects_evaluations' => $training->subjectMatterExperts()->exists(),
                // Drives the counter-payment dialog: whether there is a fee to
                // collect at all, what it comes to, and whether a promissory
                // note is on offer for this run.
                'payment_required' => $training->payment_required,
                'payment_amount' => $training->payment_required
                    ? (float) $training->payment_amount
                    : null,
                'accepts_promissory' => $training->accepts_promissory,
                'accepts_walk_ins' => $training->accepts_walk_ins,
                'days' => array_map(fn (array $day) => [
                    'day' => $day['day'],
                    'label' => $day['date']->format('d M'),
                    'is_today' => $day['date']->isToday(),
                    // Lets the roster land on the right day without parsing
                    // dates in the browser: today while the run is on, the last
                    // day that happened once it is over, and day one before it
                    // starts.
                    'is_past' => $day['date']->isPast() && ! $day['date']->isToday(),
                ], $training->trainingDays()),
            ],
            'scopedTo' => $request->user()->fieldOffice?->name,
            'attendanceStatuses' => AttendanceStatus::options(),
            /*
             * The counter-payment dialog. Only the roles that may actually post
             * one get the options at all, so the roster never renders a form
             * the server would refuse — and the officer list is drawn from the
             * people who can hold money, not from every staff account.
             */
            'can' => [
                'record_payment' => $request->user()->collectsPayments(),
                // The roster is read by roles that may not reschedule from it.
                'reschedule' => $request->user()->role->managesTrainings(),
                /*
                 * Reading a roster and deciding on it are different jobs. A
                 * field office runs its own sessions and marks its own
                 * attendance, but approving, completing, cancelling, moving a
                 * registration and issuing a certificate are HRD's — see the
                 * `admin|superadmin` group in routes/web.php. Without this the
                 * page drew those buttons for everyone and the server answered
                 * a click with 403, which is a dead end rather than an answer.
                 */
                'manage_roster' => $request->user()->role->managesTrainings(),
            ],
            // Whether this run has already been rescheduled, so the roster can
            // point at the affected list rather than offering to start again.
            'rescheduledTo' => $training->reschedules()->latest('id')->first()?->only(['id', 'title']),
            'paymentMethods' => array_values(array_filter(
                PaymentMethod::options(),
                fn (array $option) => $training->accepts_promissory
                    || $option['value'] !== PaymentMethod::Promissory->value
            )),
            /*
             * Everyone designated to collect, whatever their role — which is
             * the point of the designation: a field office's own officer is a
             * field-office user, and is exactly who gets named on the receipt.
             *
             * Scoped like every other list on this page. A field office naming
             * who took the money is naming one of its own — an officer three
             * provinces away never handled that cash — and the unscoped list
             * was the one control here that read across the whole region,
             * which made it a roster of other offices' staff names for anyone
             * who opened the dropdown.
             *
             * HRD entering money on a field office's behalf still sees every
             * officer, which is the case the field exists for.
             */
            'collectingOfficers' => User::query()
                ->where('is_collecting_officer', true)
                ->where('is_active', true)
                ->when($officeId !== null, fn ($query) => $query->where('field_office_id', $officeId))
                ->orderBy('name')
                ->get()
                ->map(fn (User $officer) => ['value' => $officer->id, 'label' => $officer->name])
                ->all(),
            // The lifecycle of the supervisory supporting document, so the
            // roster can draw its own filter chips without hardcoding a second
            // copy of the statuses.
            'supervisoryDocumentStatuses' => SupervisoryDocumentStatus::options(),
            // Where a selection can be moved to. Only open runs, and never this
            // one — a transfer to the training you are already on is a misclick.
            'transferTargets' => TransferTargets::for($training),
            // Live stations only. A revoked or expired link is not something an
            // operator can act on, and listing them would bury the one or two
            // links that actually open a door today.
            'scanLinks' => $training->scanLinks()
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->latest()
                ->get()
                ->map(fn (ScanLink $link) => [
                    'id' => $link->id,
                    'label' => $link->label,
                    'is_test' => $link->is_test,
                    'url' => route('station.show', $link->token),
                    'expires_at' => $link->expires_at->format('d M Y'),
                    'last_used_at' => $link->last_used_at?->diffForHumans(),
                ])
                ->all(),
            /*
             * The evaluation posters, one row per day of the run.
             *
             * Every day, not just the ones that collect a form: a four-day
             * course showing a single code has to say why the other three are
             * absent, or the reader goes looking for a bug that is not there.
             *
             * Only HRD can act on these, and the panel is withheld entirely
             * from everyone else rather than shown disabled — the roster is
             * read by four roles and a card full of buttons none of them can
             * press is noise on a page that is already dense.
             */
            'evaluationCodes' => $request->user()->role->managesTrainings()
                ? $this->evaluationCodes($training)
                : null,
            'filters' => $filters,
            /*
             * The chip counts, and the three tiles above them.
             *
             * Computed over the whole roster rather than the page or even the
             * filtered set, because that is what a chip is for: "Cancelled 12"
             * is an offer to go and look at twelve people, and a chip that
             * counted only the rows already on screen would read zero for every
             * status except the one selected.
             */
            'counts' => [
                'status' => $registrations
                    ->countBy(fn (Registration $r) => $r->status->value)
                    ->put('all', $registrations->count())
                    ->all(),
                'evaluation' => $registrations
                    ->countBy(fn (Registration $r) => RosterFilter::evaluationState($r))
                    ->put('all', $registrations->count())
                    ->all(),
                // Keyed the same way, but counted only over the participants
                // who were actually asked for a document — "All" on that chip
                // row means every document, not every participant, or a
                // supervisory run would show a total nobody could reach by
                // adding up the chips beside it.
                'document' => $registrations
                    ->filter(fn (Registration $r) => $r->supervisory_document_status !== null)
                    ->countBy(fn (Registration $r) => $r->supervisory_document_status->value)
                    ->put('all', $registrations
                        ->filter(fn (Registration $r) => $r->supervisory_document_status !== null)
                        ->count())
                    ->all(),
                'not_checked_in_today' => $registrations
                    ->filter(fn (Registration $r) => RosterFilter::notCheckedInOn($r, $todayDay))
                    ->count(),
                // Completed, with nothing issued yet — what "Release all"
                // is about to act on, so it has to count the whole roster and
                // not whatever is being looked at.
                'awaiting_certificates' => $registrations
                    ->filter(fn (Registration $r) => $r->status === RegistrationStatus::Completed
                        && ! ($r->certificate?->isReleased() ?? false))
                    ->count(),
                'pending' => $registrations
                    ->filter(fn (Registration $r) => $r->status === RegistrationStatus::Pending)
                    ->count(),
            ],
            /*
             * The printed attendance sheet, which is a different document from
             * the screen: it is every participant the current filters match,
             * not the twenty-five of them being looked at. It is an optional
             * prop because it is only ever wanted at the moment somebody
             * presses Print — carrying six hundred of these rows on every
             * roster load to serve an action taken once a session is precisely
             * the weight this change exists to remove. The page asks for it
             * with a partial reload and prints when it lands.
             */
            'printRows' => Inertia::optional(fn () => $matching
                ->map(fn (Registration $registration) => [
                    'id' => $registration->id,
                    'name' => $registration->user->name,
                    'organization' => $registration->user->profile?->organization_name,
                    'field_office' => $registration->user->profile?->fieldOffice?->name,
                    'status_label' => $registration->status->label(),
                    'attendance' => $registration->attendances
                        ->keyBy('training_day')
                        ->map(fn ($attendance) => $attendance->status->label())
                        ->all(),
                ])->all()),
            /*
             * Everyone with something the caterer needs to know, whatever the
             * roster is currently filtered to — the kitchen is catering the
             * session, not the search. Small enough to send whole: a name and a
             * line of text for the few participants who have one.
             */
            'restrictions' => $registrations
                ->filter(fn (Registration $r) => filled($r->user->profile?->food_restrictions_details))
                ->map(fn (Registration $r) => [
                    'id' => $r->id,
                    'name' => $r->user->name,
                    'food_restrictions' => $r->user->profile?->food_restrictions_details,
                ])->values()->all(),
            'registrations' => $paginator->through(fn (Registration $registration) => [
                'id' => $registration->id,
                'status' => $registration->status->value,
                'status_label' => $registration->status->label(),
                'name' => $registration->user->name,
                'email' => $registration->user->email,
                'organization' => $registration->user->profile?->organization_name,
                'position' => $registration->user->profile?->position_title,
                'field_office' => $registration->user->profile?->fieldOffice?->name,
                'food_restrictions' => $registration->user->profile?->food_restrictions_details,
                'review_remarks' => $registration->review_remarks,
                // The supervisory document, for the monitoring column on the
                // roster. Null when the training is not supervisory or the
                // participant's grade is above the band, so the roster never
                // implies a requirement that does not exist.
                'supervisory_document' => $training->is_supervisory
                    && $registration->supervisory_document_status !== null
                        ? [
                            'status' => $registration->supervisory_document_status->value,
                            'status_label' => $registration->supervisory_document_status->label(),
                            'download_url' => $registration->supporting_document_path
                                ? route('registrations.supporting-document', $registration)
                                : null,
                            'can_review' => $registration->supervisory_document_status->isActionable(),
                            'reviewed_by' => $registration->supervisoryDocumentReviewer?->name,
                            'reviewed_at' => $registration->supervisory_document_reviewed_at?->format('d M Y'),
                            'remarks' => $registration->supervisory_document_remarks,
                        ]
                        : null,
                // Keyed by day number so the grid can look each cell up directly.
                'attendance' => $registration->attendances
                    ->keyBy('training_day')
                    ->map(fn ($attendance) => [
                        'status' => $attendance->status->value,
                        'status_label' => $attendance->status->label(),
                        'time_in' => $attendance->time_in,
                        'remarks' => $attendance->remarks,
                    ])->all(),
                'credited_days' => $registration->creditedDays(),
                /*
                 * Evaluation progress, for the column field offices chase from.
                 *
                 * Measured against the days actually open to this participant,
                 * not against the length of the run — a three-day training on
                 * its second morning owes one evaluation, not three, and a
                 * column that said 0/3 on day one would have every office
                 * chasing people for sessions that have not happened.
                 */
                'evaluation' => SmeEvaluationService::progressFor($registration),
                'can_complete' => $registration->status === RegistrationStatus::Approved
                    && $registration->hasSufficientAttendance(),
                'certificate_number' => $registration->certificate?->isReleased()
                    ? $registration->certificate->certificate_number
                    : null,
                // Drives the "fee outstanding" note where a promissory note is
                // still standing in for the money.
                'fee_cleared' => $registration->hasClearedFee(),
                // What the office holds against this registration. `settled`
                // and `cleared` differ by exactly one case — a promissory note
                // settles the slot without clearing the money — and the roster
                // needs both: one decides whether to offer the counter-payment
                // dialog, the other whether a certificate can be issued.
                'payment' => [
                    'settled' => $registration->hasSettledFee(),
                    'or_number' => $registration->payments
                        ->firstWhere('status', PaymentStatus::Verified)?->or_number,
                    'method' => $registration->payments
                        ->firstWhere('status', PaymentStatus::Verified)?->payment_method->label(),
                    'awaiting_review' => $registration->payments
                        ->contains(fn (Payment $payment) => $payment->status->isPending()),
                ],
            ]),
            'summary' => [
                'active' => $registrations->filter(fn (Registration $r) => $r->status->occupiesSlot())->count(),
                'completed' => $registrations->where('status', RegistrationStatus::Completed)->count(),
                'cancelled' => $registrations->where('status', RegistrationStatus::Cancelled)->count(),
                'with_food_restrictions' => $registrations
                    ->filter(fn (Registration $r) => filled($r->user->profile?->food_restrictions_details))
                    ->count(),
                'checked_in_today' => $registrations
                    ->filter(fn (Registration $r) => $r->attendances
                        ->firstWhere('training_day', $training->dayNumberFor(now()))?->time_in !== null)
                    ->count(),
                // Participants with at least one session still unevaluated —
                // the figure a field office works down, scoped to its own
                // people exactly as the roster rows are.
                'evaluations_outstanding' => $registrations
                    ->filter(fn (Registration $r) => $r->status->occupiesSlot()
                        && SmeEvaluationService::progressFor($r)['outstanding'] !== []
                    )
                    ->count(),
                // Documents awaiting a verdict, so the supervising staff get a
                // single figure for the work in front of them.
                'documents_to_review' => $training->is_supervisory
                    ? $registrations
                        ->filter(fn (Registration $r) => $r->supervisory_document_status?->isActionable())
                        ->count()
                    : 0,
            ],
            /*
             * v1's per-training "geo breakdown": who is coming, by field
             * office, and how much of the fee each office still owes. The
             * office that recruited the participants is the one HRD chases for
             * an unpaid balance, so the split is what makes the chasing
             * possible — the flat roster buries it.
             *
             * A cancelled registration is counted in its own column rather than
             * dropped. It holds no slot and owes nothing, so it must stay out of
             * every other figure — but an office with six withdrawals is telling
             * HRD something, and leaving it out of the grouping made that
             * invisible.
             */
            /*
             * What this run actually earned, and what it forgave.
             *
             * Only verified payments count — a pending upload is a claim, not
             * revenue — and a promissory note is excluded from `collected`
             * because no money has arrived, while still being shown so the
             * outstanding balance is visible rather than merely absent.
             *
             * Every figure adds up from the payment rows themselves, which
             * froze their own arithmetic at the time, so nothing here can move
             * if the course fee is edited later.
             */
            'revenue' => $this->revenueFor($registrations),
            'officeBreakdown' => $registrations
                ->groupBy(fn (Registration $r) => $r->user->profile?->fieldOffice?->name ?? 'Unassigned')
                ->map(function ($group, $office) use ($training) {
                    $cancelled = $group->where('status', RegistrationStatus::Cancelled);
                    // Everyone still on the run. Every money figure below is
                    // taken from this, never from the whole group: a withdrawn
                    // participant owes nothing and would read as an office
                    // that had not paid.
                    $active = $group->reject(fn (Registration $r) => $r->status === RegistrationStatus::Cancelled);

                    /*
                     * On a run with no fee there is nothing to pay, nothing
                     * promised and nothing owing — but hasClearedFee() answers
                     * true for exactly that reason, so the naive arithmetic
                     * filed every participant of a free course under Paid and
                     * the office looked like it had settled a bill it was never
                     * sent. Free is its own column, and the three money columns
                     * are empty beside it.
                     */
                    $free = $training->payment_required ? collect() : $active;

                    /*
                     * Then the three buckets v1's geo breakdown had: paid,
                     * promised, and neither. hasSettledFee() is the wrong
                     * question here — it treats a promissory note as settled,
                     * which is right for letting someone through the door and
                     * wrong for a column of what is still owed. An office whose
                     * participants had all merely promised showed nothing
                     * owing, which is the opposite of what HRD needs to see.
                     */
                    $cleared = $training->payment_required
                        ? $active->filter(fn (Registration $r) => $r->hasClearedFee())
                        : collect();
                    $promised = $training->payment_required
                        ? $active
                            ->reject(fn (Registration $r) => $r->hasClearedFee())
                            ->filter(fn (Registration $r) => $r->hasSettledFee())
                        : collect();

                    return [
                        'label' => $office,
                        'count' => $active->count(),
                        'pending' => $group->where('status', RegistrationStatus::Pending)->count(),
                        'cancelled' => $cancelled->count(),
                        'free' => $free->count(),
                        'settled' => $cleared->count(),
                        'promissory' => $promised->count(),
                        'unpaid' => $active->count()
                            - $free->count()
                            - $cleared->count()
                            - $promised->count(),
                    ];
                })
                ->sortByDesc('count')
                ->values()
                ->all(),
        ]);
    }

    /**
     * HRD decision on a pending registration.
     */
    public function review(Request $request, Registration $registration): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'waitlisted', 'rejected'])],
            'remarks' => ['nullable', 'string', 'max:1000'],
            // A rejection without a reason is not reviewable after the fact.
            'remarks_required' => ['nullable'],
        ]);

        if ($validated['decision'] === 'rejected' && blank($validated['remarks'] ?? null)) {
            return back()->withErrors(['remarks' => 'Give a reason when rejecting a registration.']);
        }

        $decision = RegistrationStatus::from($validated['decision']);

        $snapshot = UndoService::capture(collect([$registration]));

        RegistrationService::review($registration, $decision, $request->user(), $validated['remarks'] ?? null);

        return back()
            ->with('success', "{$registration->user->name} — {$decision->label()}.")
            ->with('undo', $this->undoOffer($request, 'Decision taken back.', $snapshot));
    }

    /**
     * Cancel a registration on the participant's behalf, freeing the slot.
     *
     * v1's registration-details page had this; v2 could only *review* a
     * cancellation the participant had filed, so a phoned-in withdrawal, a
     * duplicate, or a confirmed no-show had no way out of the roster at all.
     * The reason is required for the same reason a rejection's is: the
     * participant loses their place on someone else's say-so, and the record
     * has to say whose and why.
     *
     * Undoable like every other roster decision — the slot comes back with it.
     */
    public function cancelRegistration(Request $request, Registration $registration): RedirectResponse
    {
        $registration = $this->scopedRegistration($request, $registration);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $snapshot = UndoService::capture(collect([$registration]));

        RegistrationService::cancel($registration, $request->user(), $validated['reason']);

        return back()
            ->with('success', "{$registration->user->name}'s registration has been cancelled.")
            ->with('undo', $this->undoOffer($request, 'Cancellation taken back.', $snapshot));
    }

    /**
     * Verify or reject a supervisory-course supporting document.
     *
     * Lives beside the roster's registration decisions because it is the same
     * screen and the same reviewer: the person deciding whether the claim is
     * real also judges whether the paper proves it. The document is re-resolved
     * against the field-office scope, exactly as the roster is.
     */
    public function reviewSupervisoryDocument(Request $request, Registration $registration): RedirectResponse
    {
        $registration = $this->scopedRegistration($request, $registration);

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['verified', 'rejected'])],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validated['decision'] === 'rejected' && blank($validated['remarks'] ?? null)) {
            return back()->withErrors(['remarks' => 'Give a reason when rejecting a document.']);
        }

        $snapshot = UndoService::capture(collect([$registration]));

        if ($validated['decision'] === 'verified') {
            SupervisoryDocumentService::verify($registration, $request->user(), $validated['remarks'] ?? null);
        } else {
            SupervisoryDocumentService::reject($registration, $request->user(), (string) $validated['remarks']);
        }

        return back()
            ->with('success', "{$registration->user->name} — supporting document "
                .($validated['decision'] === 'verified' ? 'verified.' : 'rejected.'))
            ->with('undo', $this->undoOffer($request, 'Document review undone.', $snapshot));
    }

    /**
     * Mark a participant as having completed the training.
     *
     * Completion now follows the attendance record rather than a staff member's
     * word for it: a certificate is only defensible if there is a check-in
     * behind it. `force` exists for the venue where scanning failed outright,
     * and it demands a reason so the exception stays auditable.
     */
    public function complete(Request $request, Registration $registration): RedirectResponse
    {
        if ($registration->status !== RegistrationStatus::Approved) {
            return back()->withErrors([
                'registration' => 'Only an approved registration can be marked complete.',
            ]);
        }

        $validated = $request->validate([
            'force' => ['boolean'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $registration->loadMissing(['training', 'attendances']);
        $forced = (bool) ($validated['force'] ?? false);

        if (! $forced && ! $registration->hasSufficientAttendance()) {
            return back()->withErrors([
                'registration' => sprintf(
                    '%s was recorded for %d of %d day(s) — not enough to complete. Override with a reason if attendance was taken off-system.',
                    $registration->user->name,
                    $registration->creditedDays(),
                    $registration->training->duration_days
                ),
            ]);
        }

        if ($forced && blank($validated['remarks'] ?? null)) {
            return back()->withErrors([
                'remarks' => 'Give a reason when completing without a full attendance record.',
            ]);
        }

        $snapshot = UndoService::capture(collect([$registration]));

        $this->markCompleted($registration, $forced ? $validated['remarks'] : null);

        return back()
            ->with('success', "{$registration->user->name} marked as completed.")
            ->with('undo', $this->undoOffer($request, 'Completion undone.', $snapshot));
    }

    /**
     * What a training earned, and what it forgave to PRIME-HRM.
     *
     * Summed from the payment rows rather than from the course fee × head
     * count: each payment froze its own gross and discount when it was taken,
     * so these totals stay true even after the fee is edited. Deriving them
     * from `trainings.payment_amount` would silently restate last year's
     * revenue the first time somebody repriced the course.
     *
     * Only verified payments count — a pending upload is a claim, not money.
     *
     * The evaluation posters for this run, one row per training day.
     *
     * The board comes from SmeEvaluationService so the panel, the printable
     * sheet and the scan itself all read the same answer to "does this day
     * collect a form"; the codes are merged onto it here because whether a
     * poster has been cut yet is a fact about paper, not about the domain.
     *
     * @return array<string, mixed>
     */
    private function evaluationCodes(Training $training): array
    {
        $codes = $training->evaluationDayCodes()->get()->keyBy('day_number');

        $days = array_map(function (array $day) use ($codes) {
            $code = $codes->get($day['day']);

            return [
                'day' => $day['day'],
                'date' => $day['date']->format('d M Y'),
                'collects' => $day['collects'],
                // Set only when the day is carried over, and it is the whole
                // explanation for why this row has no code of its own.
                'rated_on' => $day['rated_on'],
                'experts' => $day['experts']->map(fn ($expert) => $expert->displayName())->all(),
                'submitted' => $day['submitted'],
                'expected' => $day['expected'],
                'code' => $code === null ? null : [
                    'id' => $code->id,
                    'url' => $code->url(),
                    'image_url' => route('admin.evaluation-codes.image', $code),
                    'active' => $code->isActive(),
                    'scan_count' => $code->scan_count,
                    'last_scanned_at' => $code->last_scanned_at?->diffForHumans(),
                ],
            ];
        }, SmeEvaluationService::codeBoard($training));

        return [
            'days' => $days,
            // Whether there is anything to cut at all. A run with no panel
            // assigned collects nothing, and the panel says that rather than
            // offering a button that would fail.
            'collects' => $training->evaluationDays() !== [],
            'generateUrl' => route('admin.evaluation-codes.store', $training),
            'printUrl' => route('admin.evaluation-codes.print', $training),
        ];
    }

    /**
     * @param  Collection<int, Registration>  $registrations
     * @return array<string, mixed>
     */
    private function revenueFor($registrations): array
    {
        /*
         * Paired with their registration rather than flattened, so the
         * participant's name comes from the already-loaded registration user
         * instead of a lazy `payment->user` lookup per row.
         */
        $verified = $registrations->flatMap(
            fn (Registration $registration) => $registration->payments
                ->filter(fn (Payment $payment) => $payment->status === PaymentStatus::Verified)
                ->map(fn (Payment $payment) => [$payment, $registration->user])
        );

        // A promissory note is verified but no money arrived, so it is counted
        // apart rather than folded into what was collected.
        $settled = $verified->filter(fn (array $row) => $row[0]->payment_method->isSettlement());
        $promissory = $verified->reject(fn (array $row) => $row[0]->payment_method->isSettlement());
        $discounted = $verified->filter(fn (array $row) => $row[0]->prime_hrm_discount);

        return [
            'gross' => round($settled->sum(fn (array $row) => $row[0]->grossAmount()), 2),
            'discount' => round($settled->sum(fn (array $row) => (float) $row[0]->discount_amount), 2),
            'collected' => round($settled->sum(fn (array $row) => (float) $row[0]->amount), 2),
            'promissory' => round($promissory->sum(fn (array $row) => (float) $row[0]->amount), 2),
            'promissory_count' => $promissory->count(),
            'discounted_count' => $discounted->count(),
            // Who got the discount, so the report answers "which participant"
            // without anyone opening each payment in turn.
            'discounted' => $discounted
                ->map(fn (array $row) => [
                    'id' => $row[0]->id,
                    'participant' => $row[1]->name,
                    'gross' => $row[0]->grossAmount(),
                    'discount' => (float) $row[0]->discount_amount,
                    'net' => (float) $row[0]->amount,
                    'or_number' => $row[0]->or_number,
                ])
                ->values()
                ->all(),
        ];
    }
}
