<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AttendanceStatus;
use App\Enums\Curriculum;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Enums\SupervisoryDocumentStatus;
use App\Enums\TrainingLevel;
use App\Enums\TrainingMode;
use App\Enums\TrainingStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\ScanLink;
use App\Models\SubjectMatterExpert;
use App\Models\Training;
use App\Models\User;
use App\Support\RegistrationService;
use App\Support\RescheduleService;
use App\Support\SmeEvaluationService;
use App\Support\SupervisoryDocumentService;
use App\Support\UndoService;
use DateTimeImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TrainingController extends Controller
{
    public function index(Request $request): Response
    {
        /*
         * The Registered column is a fee breakdown, and the three payment
         * buckets are a partition of the occupying registrations on *paid*
         * trainings: paid = verified money, promissory = verified note, pending
         * = everything else still holding a slot (proof awaiting verification
         * or none uploaded yet). Free trainings register straight into the
         * "free" bucket and cancelled registrations are counted apart from the
         * rest, so Total = paid + promissory + pending never double-counts.
         */
        $trainings = Training::query()
            ->withCount([
                'registrations as paid_count' => fn ($query) => $query
                    ->whereIn('status', RegistrationStatus::occupying())
                    ->whereHas('payments', fn ($payment) => $payment
                        ->where('status', PaymentStatus::Verified)
                        ->whereNot('payment_method', PaymentMethod::Promissory)
                    ),
                'registrations as promissory_count' => fn ($query) => $query
                    ->whereIn('status', RegistrationStatus::occupying())
                    ->whereHas('payments', fn ($payment) => $payment
                        ->where('status', PaymentStatus::Verified)
                        ->where('payment_method', PaymentMethod::Promissory)
                    ),
                'registrations as pending_count' => fn ($query) => $query
                    ->whereIn('status', RegistrationStatus::occupying())
                    ->whereHas('training', fn ($training) => $training->where('payment_required', true))
                    ->whereDoesntHave('payments', fn ($payment) => $payment->where('status', PaymentStatus::Verified)),
                'registrations as free_count' => fn ($query) => $query
                    ->whereIn('status', RegistrationStatus::occupying())
                    ->whereHas('training', fn ($training) => $training->where('payment_required', false)),
                'registrations as cancelled_count' => fn ($query) => $query->where('status', RegistrationStatus::Cancelled),
            ])
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where(
                'title', 'like', "%{$search}%"
            ))
            ->orderByDesc('starts_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Admin/Trainings/Index', [
            'trainings' => $trainings->through(fn (Training $training) => [
                'id' => $training->id,
                'title' => $training->title,
                'venue' => $training->venue,
                'starts_at' => $training->starts_at->format('d M Y'),
                'ends_at' => $training->ends_at?->format('d M Y'),
                'starts_time' => $training->starts_at->format('g:i A'),
                'status' => $training->status->value,
                'is_supervisory' => $training->is_supervisory,
                'capacity' => $training->capacity,
                'registered' => $training->paid_count + $training->promissory_count + $training->pending_count,
                'paid' => $training->paid_count,
                'promissory' => $training->promissory_count,
                'pending' => $training->pending_count,
                'free' => $training->free_count,
                'cancelled' => $training->cancelled_count,
                'roster_url' => route('admin.trainings.roster', $training),
                'edit_url' => route('admin.trainings.edit', $training),
            ]),
            'filters' => [
                'status' => $request->string('status')->toString(),
                'search' => $request->string('search')->toString(),
            ],
            // The status tabs, with their live counts. Counts are global (not
            // narrowed by the search box) so the tabs stay a stable map of the
            // catalogue; "All" carries the region-wide total.
            'tabs' => $this->statusTabs(),
        ]);
    }

    /**
     * Tab definitions for the Manage Trainings screen: All plus one per status,
     * each carrying how many trainings it currently covers.
     *
     * @return array<int, array{value: string, label: string, count: int}>
     */
    private function statusTabs(): array
    {
        $counts = Training::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        return [
            ['value' => '', 'label' => 'All', 'count' => array_sum($counts)],
            ...array_map(
                fn (TrainingStatus $status) => [
                    'value' => $status->value,
                    'label' => $status->label(),
                    'count' => $counts[$status->value] ?? 0,
                ],
                TrainingStatus::cases()
            ),
        ];
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Trainings/Form', [
            'training' => null,
            ...$this->formOptions(),
        ]);
    }

    /**
     * Select options shared by the create and edit forms.
     *
     * @return array<string, mixed>
     */
    private function formOptions(?Training $training = null): array
    {
        return [
            'statuses' => array_map(
                fn (TrainingStatus $status) => ['value' => $status->value, 'label' => $status->label()],
                TrainingStatus::cases()
            ),
            'modes' => TrainingMode::options(),
            'levels' => TrainingLevel::options(),
            'curricula' => Curriculum::options(),
            'experts' => $this->expertOptions($training),
            'expertsUrl' => route('admin.smes.index'),
        ];
    }

    /**
     * The picker's options: every active expert, plus any inactive one this
     * run already carries.
     *
     * The second half matters. An expert retired after being assigned would
     * otherwise vanish from the select, and saving the form — which posts what
     * the select holds — would drop them from a programme that has already been
     * announced with their name on it.
     *
     * @return array<int, array{value: int, label: string}>
     */
    private function expertOptions(?Training $training): array
    {
        $options = collect(SubjectMatterExpert::options());

        if ($training === null) {
            return $options->all();
        }

        $assigned = $training->subjectMatterExperts
            ->reject(fn (SubjectMatterExpert $expert) => $expert->is_active)
            ->map(fn (SubjectMatterExpert $expert) => [
                'value' => $expert->getKey(),
                'label' => $expert->displayName().' (inactive)',
            ]);

        return $options->concat($assigned)->unique('value')->values()->all();
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $experts = $this->pullExperts($data);

        $training = Training::create([
            ...$data,
            'slug' => $this->uniqueSlug($data['title']),
            'created_by' => $request->user()->getKey(),
        ]);

        $this->syncExperts($training, $experts);

        return redirect()
            ->route('admin.trainings.index')
            ->with('success', "“{$training->title}” has been created.");
    }

    public function edit(Training $training): Response
    {
        return Inertia::render('Admin/Trainings/Form', [
            'training' => $this->formPayload($training),
            'rescheduling' => null,
            // Passed the run so an expert retired since the assignment stays
            // selectable — see expertOptions().
            ...$this->formOptions($training),
        ]);
    }

    /**
     * One training as the form reads it.
     *
     * Shared by edit and reschedule so a field added to the form is carried
     * into a rescheduled copy automatically. The alternative — a second literal
     * of this list inside reschedule() — is how a replacement run quietly loses
     * its supervisory flag or its promissory policy a release after someone
     * adds one.
     *
     * @return array<string, mixed>
     */
    private function formPayload(Training $training): array
    {
        $training->loadMissing('subjectMatterExperts');

        return [
            'id' => $training->id,
            'title' => $training->title,
            'training_code' => $training->training_code,
            'description' => $training->description,
            'category' => $training->category,
            'level' => $training->level?->value,
            'venue' => $training->venue,
            'venue_details' => $training->venue_details,
            'meeting_link' => $training->meeting_link,
            'mode' => $training->mode->value,
            'starts_at' => $training->starts_at->format('Y-m-d\TH:i'),
            'ends_at' => $training->ends_at->format('Y-m-d\TH:i'),
            'duration_days' => $training->duration_days,
            'registration_opens_at' => $training->registration_opens_at?->format('Y-m-d\TH:i'),
            'registration_closes_at' => $training->registration_closes_at?->format('Y-m-d\TH:i'),
            'capacity' => $training->capacity,
            'signatory_name' => $training->signatory_name,
            /*
             * Carried as the form posts them, which is also what makes a
             * rescheduled run inherit its predecessor's panel: the replacement
             * is usually the same programme with the same people on a later
             * date, and retyping the roster of experts is how one of them gets
             * left off.
             */
            'subject_matter_experts' => $training->subjectMatterExperts
                ->map(fn (SubjectMatterExpert $expert) => [
                    'id' => $expert->getKey(),
                    'topic' => $expert->pivot->topic,
                    'days' => is_string($expert->pivot->days)
                        ? json_decode($expert->pivot->days, true)
                        : $expert->pivot->days,
                ])
                ->all(),
            'prerequisites' => $training->prerequisites,
            'target_participants' => $training->target_participants,
            'payment_required' => $training->payment_required,
            'payment_amount' => $training->payment_amount,
            'accepts_promissory' => $training->accepts_promissory,
            'accepts_walk_ins' => $training->accepts_walk_ins,
            'is_supervisory' => $training->is_supervisory,
            'status' => $training->status->value,
            'rescheduled_from_training_id' => $training->rescheduled_from_training_id,
        ];
    }

    /**
     * The form for the run that will replace this one.
     *
     * A reschedule is a new record, not an edit — see the migration that added
     * `rescheduled_from_training_id` for why the original has to stand. But it
     * is a new record that differs from its predecessor in about three fields,
     * so it opens as a copy: everything is carried over except the dates, which
     * are the point of the exercise and are left blank so nobody publishes the
     * old ones by pressing save too quickly.
     *
     * The status is carried over rather than blanked, because it is the one
     * decision that has to be made deliberately: a transfer refuses a target
     * that is not published, so a draft replacement is a run nobody can be
     * moved onto.
     */
    public function reschedule(Training $training): Response
    {
        return Inertia::render('Admin/Trainings/Form', [
            'training' => [
                ...$this->formPayload($training),
                'id' => null,
                'starts_at' => null,
                'ends_at' => null,
                'registration_opens_at' => null,
                'registration_closes_at' => null,
                // A code identifies one run, so the copy must not claim the
                // original's — the column is unique and would reject it anyway.
                'training_code' => null,
                'rescheduled_from_training_id' => $training->getKey(),
            ],
            'rescheduling' => [
                'id' => $training->id,
                'title' => $training->title,
                'starts_at' => $training->starts_at->format('d M Y, g:i A'),
                'affected' => Registration::where('training_id', $training->getKey())
                    ->whereIn('status', RescheduleService::affectedStatuses())
                    ->count(),
            ],
            ...$this->formOptions($training),
        ]);
    }

    /**
     * Who a rescheduled run has left stranded, and where they can go.
     *
     * Deliberately a separate screen from the roster rather than a filter on
     * it. The roster is a list of people at an event; this is a list of
     * decisions about money, read at a different moment by someone asking a
     * different question, and folding it into the roster's filters would bury
     * it under the attendance and certificate columns that matter on the day.
     */
    public function affected(Request $request, Training $training): Response
    {
        $target = $this->rescheduleTargetFor($request, $training);

        $affected = RescheduleService::affected(
            $training,
            $target,
            $request->user()->scopedFieldOfficeId(),
        );

        return Inertia::render('Admin/Trainings/Affected', [
            'training' => [
                'id' => $training->id,
                'title' => $training->title,
                'starts_at' => $training->starts_at->format('d M Y, g:i A'),
                'venue' => $training->venue,
                'status_label' => $training->status->label(),
                'payment_required' => $training->payment_required,
                'payment_amount' => $training->payment_amount === null
                    ? null
                    : (float) $training->payment_amount,
                /*
                 * Surfaced because it is a live hazard rather than a detail: a
                 * run still open while its replacement is on offer keeps taking
                 * registrations for dates that will not happen, and each one
                 * lands on this list a day later.
                 */
                'still_open' => $training->status->isOpenToParticipants(),
            ],
            'target' => $target === null ? null : [
                'id' => $target->id,
                'title' => $target->title,
                'starts_at' => $target->starts_at->format('d M Y, g:i A'),
                'venue' => $target->venue,
                'payment_amount' => $target->payment_amount === null
                    ? null
                    : (float) $target->payment_amount,
                // A transfer refuses a target that is not published, so the
                // screen says so up front instead of letting the move fail.
                'accepts_transfers' => $target->status->isOpenToParticipants(),
                'status_label' => $target->status->label(),
                // What finance will have to chase, or refund, per head. Already
                // recorded per transfer in the activity log; shown here because
                // this is where the decision is actually made.
                'fee_difference' => round(
                    (float) ($target->payment_amount ?? 0) - (float) ($training->payment_amount ?? 0),
                    2,
                ),
            ],
            'affected' => $affected->all(),
            'summary' => RescheduleService::summarise($affected),
            'scopedTo' => $request->user()->scopedFieldOfficeId() === null
                ? null
                : $request->user()->fieldOffice?->name,
            // Where a selection can go. Same rule as the roster's transfer
            // dialog: open runs only, never this one.
            'transferTargets' => $this->transferTargetsFor($training),
        ]);
    }

    public function update(Request $request, Training $training): RedirectResponse
    {
        $data = $this->validated($request, $training);
        $experts = $this->pullExperts($data);

        $training->update($data);
        $this->syncExperts($training, $experts);

        return redirect()
            ->route('admin.trainings.index')
            ->with('success', "“{$training->title}” has been updated.");
    }

    /**
     * The roster for a single training.
     */
    public function roster(Request $request, Training $training): Response
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
            'transferTargets' => $this->transferTargetsFor($training),
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
     * Apply one decision to a selection from the roster.
     *
     * Bulk deliberately does less than the per-row actions: it never forces a
     * completion past a short attendance record. An override has to carry a
     * reason for that specific person to stay auditable, and a single reason
     * smeared across forty people is not one. Anything ineligible is skipped
     * and reported rather than silently dropped.
     */
    public function bulk(Request $request, Training $training): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['approved', 'waitlisted', 'rejected', 'completed'])],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validated['action'] === 'rejected' && blank($validated['remarks'] ?? null)) {
            return back()->withErrors(['remarks' => 'Give a reason when rejecting registrations.']);
        }

        $officeId = $request->user()->scopedFieldOfficeId();

        /*
         * Re-resolved from the training and the office scope rather than taken
         * on trust: a selection posted from the page must not become a way to
         * act on a registration the staff member cannot even see.
         */
        $registrations = Registration::with(['user', 'training', 'attendances'])
            ->where('training_id', $training->getKey())
            ->whereIn('id', $validated['ids'])
            ->when($officeId !== null, fn ($query) => $query->whereHas(
                'user.profile',
                fn ($profile) => $profile->where('field_office_id', $officeId)
            ))
            ->get();

        $skipped = 0;
        // Snapshots are taken per row, immediately before it changes, so an
        // undo restores exactly what this action touched and nothing it skipped.
        $snapshot = [];

        if ($validated['action'] === 'completed') {
            foreach ($registrations as $registration) {
                if ($registration->status !== RegistrationStatus::Approved
                    || ! $registration->hasSufficientAttendance()) {
                    $skipped++;

                    continue;
                }

                $snapshot = [...$snapshot, ...UndoService::capture(collect([$registration]))];
                $this->markCompleted($registration);
            }

            return back()
                ->with('success', $this->bulkMessage(
                    count($snapshot).' participant(s) marked as completed.',
                    $skipped,
                    'not approved, or short on attendance — complete those individually'
                ))
                ->with('undo', $this->undoOffer($request, 'Completions undone.', $snapshot));
        }

        $decision = RegistrationStatus::from($validated['action']);

        foreach ($registrations as $registration) {
            if ($registration->status !== RegistrationStatus::Pending) {
                $skipped++;

                continue;
            }

            $snapshot = [...$snapshot, ...UndoService::capture(collect([$registration]))];
            RegistrationService::review($registration, $decision, $request->user(), $validated['remarks'] ?? null);
        }

        return back()
            ->with('success', $this->bulkMessage(
                count($snapshot)." registration(s) — {$decision->label()}.",
                $skipped,
                'no longer pending'
            ))
            ->with('undo', $this->undoOffer($request, 'Decisions taken back.', $snapshot));
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

    /**
     * Where a selection taken off this training can be moved to.
     *
     * Open runs only, and never this one — a transfer to the training you are
     * already on is a misclick. Shared by the roster's transfer dialog and the
     * affected list so the two cannot offer different destinations.
     *
     * @return array<int, array{value: int, label: string}>
     */
    private function transferTargetsFor(Training $training): array
    {
        return Training::visible()
            ->whereKeyNot($training->getKey())
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Training $option) => [
                'value' => $option->id,
                'label' => $option->title.' — '.$option->starts_at->format('d M Y'),
            ])
            ->all();
    }

    /**
     * The run the affected list should be measured against.
     *
     * An explicit ?target= wins, because the office frequently weighs two
     * candidate dates against the same roster before committing to either.
     * Failing that it falls back to the replacement already recorded against
     * this run, which is the common case and means the screen is useful the
     * moment it is opened rather than after a dropdown is touched.
     *
     * The newest reschedule is chosen where a run was split across several: it
     * is the one still being filled, the earlier ones having been dealt with
     * already.
     */
    private function rescheduleTargetFor(Request $request, Training $training): ?Training
    {
        $requested = $request->integer('target');

        if ($requested > 0) {
            return Training::whereKey($requested)
                ->whereKeyNot($training->getKey())
                ->first();
        }

        return $training->reschedules()->latest('id')->first();
    }

    /**
     * Re-resolve a route-bound registration against the field-office scope.
     *
     * Route-model binding does not know about scoping, so an action that only
     * took the bound model would let a scoped officer act on another office's
     * participant by posting its id. 404 rather than 403: the registration's
     * existence is not theirs to know, which is the same answer the roster and
     * the participant directory give.
     */
    private function scopedRegistration(Request $request, Registration $registration): Registration
    {
        $officeId = $request->user()->scopedFieldOfficeId();

        return Registration::whereKey($registration->getKey())
            ->when($officeId !== null, fn ($query) => $query->whereHas(
                'user.profile',
                fn ($profile) => $profile->where('field_office_id', $officeId)
            ))
            ->firstOr(fn () => abort(404));
    }

    /**
     * Build the flash payload the toast turns into an Undo button.
     *
     * @return array{token: string, label: string, seconds: int}|null
     */
    private function undoOffer(Request $request, string $label, array $snapshot): ?array
    {
        $token = UndoService::offer($request->user(), $label, $snapshot);

        return $token === null ? null : [
            'token' => $token,
            'label' => $label,
            'seconds' => UndoService::WINDOW_SECONDS,
        ];
    }

    /** Compose the outcome so a partial result never reads as a full one. */
    private function bulkMessage(string $summary, int $skipped, string $reason): string
    {
        return $skipped === 0 ? $summary : "{$summary} {$skipped} skipped ({$reason}).";
    }

    private function markCompleted(Registration $registration, ?string $remarks = null): void
    {
        $registration->forceFill([
            'status' => RegistrationStatus::Completed,
            // Falls back to the training's start only when completion was
            // forced; otherwise attendance has already set this.
            'attended_at' => $registration->attended_at ?? $registration->training->starts_at,
            'review_remarks' => $remarks ?? $registration->review_remarks,
        ])->save();
    }

    /**
     * Move a selection of the roster to another training.
     *
     * Ported from v1's `transfer-participants.php`. The usual cause is a run
     * being rescheduled or split, where the alternative is cancelling and
     * re-registering everyone — losing the original registration dates, the
     * attendance recorded against them, and any payment attached.
     */
    public function transfer(Request $request, Training $training): RedirectResponse
    {
        $validated = $request->validate([
            'target_training_id' => [
                'required',
                'integer',
                // Never this one: a transfer onto the training you are already
                // looking at is a misclick, not an intent.
                Rule::notIn([$training->getKey()]),
                Rule::exists('trainings', 'id'),
            ],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $result = RegistrationService::transfer(
            $validated['ids'],
            Training::findOrFail($validated['target_training_id']),
            $request->user(),
            $validated['reason'],
            $request->user()->scopedFieldOfficeId(),
        );

        if ($result['moved'] === 0) {
            return back()->withErrors([
                'transfer' => 'Nobody could be moved. '.implode('; ', $result['skipped']),
            ]);
        }

        // Reported rather than swallowed: "moved 12" when 3 were skipped is how
        // a participant quietly stays on a training that no longer runs.
        $message = "{$result['moved']} participant(s) moved.";

        if ($result['skipped'] !== []) {
            $message .= ' Skipped: '.implode('; ', $result['skipped']).'.';
        }

        return back()->with('success', $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Training $training = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'training_code' => [
                'nullable', 'string', 'max:50',
                Rule::unique('trainings', 'training_code')->ignore($training),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['nullable', Rule::enum(Curriculum::class)],
            'level' => ['nullable', Rule::enum(TrainingLevel::class)],
            'venue' => ['required', 'string', 'max:255'],
            'venue_details' => ['nullable', 'string', 'max:2000'],
            /*
             * v1 demanded a link for online and hybrid runs and this keeps that
             * rule, but as a URL rather than v1's free text: the link is
             * rendered as an anchor for participants, and "check your email"
             * typed into an href is worse than an empty field.
             */
            'meeting_link' => [
                'nullable', 'url', 'max:512',
                Rule::requiredIf(fn () => (
                    TrainingMode::tryFrom($request->string('mode')->toString()) ?? $training?->mode
                )?->requiresMeetingLink() ?? false),
            ],
            // Both default rather than being required: face-to-face is the norm,
            // and a duration left blank is derived from the dates below. Making
            // them mandatory would block HRD on fields they rarely change.
            'mode' => ['nullable', Rule::enum(TrainingMode::class)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'registration_opens_at' => ['nullable', 'date', 'before_or_equal:starts_at'],
            'registration_closes_at' => [
                'nullable', 'date', 'before_or_equal:starts_at', 'after_or_equal:registration_opens_at',
            ],
            // Null means no limit.
            'capacity' => ['nullable', 'integer', 'min:1', 'max:10000'],
            // Who signs the certificate for this run. Not the panel of experts
            // below: an expert delivers the session, the signatory attests to
            // it, and the two are rarely the same person.
            'signatory_name' => ['nullable', 'string', 'max:128'],
            /*
             * The panel. Sent as a list so the order is HRD's — it is the order
             * the participant's evaluation form asks about them in, which for a
             * sequenced programme is the order they actually spoke.
             *
             * `days` narrows an expert to particular training days; an empty or
             * absent list means the whole run. The day numbers are bounded by
             * the run's own length, checked below rather than here because
             * duration_days may be derived from the dates at save time.
             */
            'subject_matter_experts' => ['array', 'max:20'],
            'subject_matter_experts.*.id' => [
                'required', 'integer', Rule::exists('subject_matter_experts', 'id'),
            ],
            'subject_matter_experts.*.topic' => ['nullable', 'string', 'max:255'],
            'subject_matter_experts.*.days' => ['nullable', 'array'],
            'subject_matter_experts.*.days.*' => ['integer', 'min:1', 'max:365'],
            'prerequisites' => ['nullable', 'string', 'max:5000'],
            'target_participants' => ['nullable', 'string', 'max:5000'],
            'payment_required' => ['boolean'],
            // Only meaningful — and only required — when the training is paid.
            'payment_amount' => [
                'nullable', 'required_if:payment_required,true', 'numeric', 'min:0', 'max:1000000',
            ],
            // Whether the collecting officer will hold a slot against a
            // promissory note. Only consulted on a paid training.
            'accepts_promissory' => ['boolean'],
            /*
             * Whether somebody may be admitted at the venue after the deadline
             * has passed. Unlike the flag above this is not only about money:
             * it also waives the capacity cap, because a walk-in the office has
             * decided to seat is already in the room and refusing the record
             * would only hide them from the register. So it stays off unless an
             * organiser turns it on for a specific event.
             */
            'accepts_walk_ins' => ['boolean'],
            // An SDC obliges the participant to submit an output before the
            // certificate is defensible.
            'is_supervisory' => ['boolean'],
            'status' => ['required', Rule::enum(TrainingStatus::class)],
            /*
             * Set once, when a replacement run is created from the form that
             * the reschedule action opens. It is provenance — which run this
             * one was published to replace — so it is accepted on create and
             * ignored on edit: repointing it later would rewrite history, and
             * `notIn` on the training being edited stops the obvious cycle of
             * a run recorded as its own predecessor.
             */
            'rescheduled_from_training_id' => [
                'nullable', 'integer',
                Rule::notIn(array_filter([$training?->getKey()])),
                Rule::exists('trainings', 'id'),
            ],
        ]);

        if ($training !== null) {
            unset($data['rescheduled_from_training_id']);
        }

        return $this->withDerivedDefaults($data, $training);
    }

    /**
     * Fill in the fields HRD may reasonably leave blank.
     *
     * Kept here rather than in the model so that the derived values are written
     * once at save time — attendance numbers days from `duration_days`, and a
     * value that silently recomputed on every read would renumber a running
     * training if someone nudged its end date.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withDerivedDefaults(array $data, ?Training $training): array
    {
        $starts = new DateTimeImmutable($data['starts_at']);
        $ends = new DateTimeImmutable($data['ends_at']);

        $data['mode'] ??= $training?->mode->value ?? TrainingMode::FaceToFace->value;

        // A run switched back to face-to-face drops its link rather than
        // keeping a dead join URL that the training page would still render.
        if (! TrainingMode::from($data['mode'])->requiresMeetingLink()) {
            $data['meeting_link'] = null;
        }

        // Inclusive of both endpoints: a one-day training spans one day.
        $data['duration_days'] ??= $starts->setTime(0, 0)->diff($ends->setTime(0, 0))->days + 1;

        $data['training_code'] = ($data['training_code'] ?? null)
            ?: $training?->training_code
            ?: $this->generateCode($starts);

        return $data;
    }

    /**
     * A readable, unique training code when HRD leaves the field blank.
     *
     * Sequential per year, matching v1's `training_code` convention.
     */
    private function generateCode(\DateTimeInterface $startsAt): string
    {
        $year = $startsAt->format('Y');
        $sequence = Training::whereYear('starts_at', $year)->count() + 1;

        do {
            $code = sprintf('TRN-%s-%04d', $year, $sequence);
            $sequence++;
        } while (Training::where('training_code', $code)->exists());

        return $code;
    }

    /**
     * Lift the expert assignments out of the validated attributes.
     *
     * They are not columns on `trainings`, so they must not reach create() or
     * update() — mass assignment would either throw or, worse, be silently
     * dropped by the Fillable list and take the whole panel with it.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function pullExperts(array &$data): array
    {
        $experts = $data['subject_matter_experts'] ?? [];
        unset($data['subject_matter_experts']);

        return $experts;
    }

    /**
     * Write the panel, in the order the form listed it.
     *
     * sync() rather than an append: removing a row from the form has to remove
     * the assignment, or an expert taken off the programme keeps appearing on
     * participants' evaluation forms. Evaluations already filed survive that —
     * they hang off the day evaluation, not off the assignment — which is
     * deliberate: a session that happened stays evaluated even if the office
     * later corrects who was billed for it.
     *
     * Day numbers outside the run are dropped rather than rejected. Shortening
     * a training from five days to three is a legitimate edit that should not
     * be blocked by a stale tick on day 4, and the alternative — keeping it —
     * is a number that matches no day and quietly excludes the expert from
     * every form.
     *
     * @param  array<int, array<string, mixed>>  $experts
     */
    private function syncExperts(Training $training, array $experts): void
    {
        $lastDay = max(1, $training->duration_days ?? 1);

        $payload = [];

        foreach (array_values($experts) as $index => $expert) {
            $days = collect($expert['days'] ?? [])
                ->map(fn ($day) => (int) $day)
                ->filter(fn (int $day) => $day >= 1 && $day <= $lastDay)
                ->unique()
                ->sort()
                ->values()
                ->all();

            // Last one wins on a duplicated expert — the pivot is unique on
            // (training, expert) and sync() would otherwise fail the save on
            // what is, from HRD's side, a double-click.
            $payload[(int) $expert['id']] = [
                'topic' => $expert['topic'] ?? null,
                // Null, not an empty array: null is the documented "every day"
                // and `[]` would read as "no days at all".
                'days' => $days === [] ? null : json_encode($days),
                'sort_order' => $index,
            ];
        }

        $training->subjectMatterExperts()->sync($payload);
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title);
        $slug = $base;
        $suffix = 2;

        while (Training::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
