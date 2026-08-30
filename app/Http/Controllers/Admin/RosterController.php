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
use App\Support\SmeEvaluationService;
use App\Support\SupervisoryDocumentService;
use App\Support\TransferTargets;
use App\Support\UndoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            ],
            // Whether this run has already been rescheduled, so the roster can
            // point at the affected list rather than offering to start again.
            'rescheduledTo' => $training->reschedules()->latest('id')->first()?->only(['id', 'title']),
            'paymentMethods' => array_values(array_filter(
                PaymentMethod::options(),
                fn (array $option) => $training->accepts_promissory
                    || $option['value'] !== PaymentMethod::Promissory->value
            )),
            // Everyone designated to collect, whatever their role — which is
            // the point of the designation: a field office's own officer is a
            // field-office user, and is exactly who gets named on the receipt.
            'collectingOfficers' => User::query()
                ->where('is_collecting_officer', true)
                ->where('is_active', true)
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
            'registrations' => $registrations->map(fn (Registration $registration) => [
                'id' => $registration->id,
                'status' => $registration->status->value,
                'status_label' => $registration->status->label(),
                'name' => $registration->user->name,
                'email' => $registration->user->email,
                'organization' => $registration->user->profile?->organization_name,
                'position' => $registration->user->profile?->position_title,
                'field_office' => $registration->user->profile?->fieldOffice?->name,
                'food_restrictions' => $registration->user->profile?->food_restrictions_details,
                'registered_at' => $registration->registered_at->format('d M Y'),
                'registered_at_ts' => $registration->registered_at->timestamp,
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
                'attended' => $registration->attended_at !== null,
                // Keyed by day number so the grid can look each cell up directly.
                'attendance' => $registration->attendances
                    ->keyBy('training_day')
                    ->map(fn ($attendance) => [
                        'status' => $attendance->status->value,
                        'status_label' => $attendance->status->label(),
                        'time_in' => $attendance->time_in,
                        'time_out' => $attendance->time_out,
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
            ])->all(),
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
             * an outstanding balance, so the split is what makes the chasing
             * possible — the flat roster buries it.
             *
             * Cancelled registrations are left out: they hold no slot and owe
             * nothing, so counting them would overstate every row.
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
                ->reject(fn (Registration $r) => $r->status === RegistrationStatus::Cancelled)
                ->groupBy(fn (Registration $r) => $r->user->profile?->fieldOffice?->name ?? 'Unassigned')
                ->map(function ($group, $office) {
                    // Three buckets, as v1's geo breakdown had them: paid,
                    // promised, and neither. hasSettledFee() is the wrong
                    // question here — it treats a promissory note as settled,
                    // which is right for letting someone through the door and
                    // wrong for a column headed Outstanding. An office whose
                    // participants had all merely promised showed nothing
                    // owing, which is the opposite of what HRD needs to see.
                    $cleared = $group->filter(fn (Registration $r) => $r->hasClearedFee());
                    $promised = $group
                        ->reject(fn (Registration $r) => $r->hasClearedFee())
                        ->filter(fn (Registration $r) => $r->hasSettledFee());

                    return [
                        'label' => $office,
                        'count' => $group->count(),
                        'settled' => $cleared->count(),
                        'promissory' => $promised->count(),
                        'outstanding' => $group->count() - $cleared->count() - $promised->count(),
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
