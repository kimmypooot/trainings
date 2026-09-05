<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Curriculum;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RegistrationStatus;
use App\Enums\TrainingLevel;
use App\Enums\TrainingMode;
use App\Enums\TrainingStatus;
use App\Http\Controllers\Controller;
use App\Models\Registration;
use App\Models\SubjectMatterExpert;
use App\Models\Training;
use App\Support\RescheduleService;
use App\Support\TransferTargets;
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
         * Field-office staff count their own people only.
         *
         * The same invariant the roster keeps one screen down: the *training*
         * stays visible to every office, because a run is regional and hiding
         * it would hide the roster with it — but every head counted on this
         * page is a head in the reader's own jurisdiction. Without this the
         * list told a field office "48 registered" and its roster then showed
         * six, which reads as data missing rather than as a different question
         * being answered.
         *
         * scopedFieldOfficeId() resolves to 0 for a field-office user with no
         * office assigned, so an unassigned account counts nobody rather than
         * everybody — failing closed, exactly as the directory does.
         */
        $officeId = $request->user()->scopedFieldOfficeId();

        $inJurisdiction = fn ($query) => $query->when($officeId !== null, fn ($registration) => $registration
            ->whereHas('user.profile', fn ($profile) => $profile->where('field_office_id', $officeId))
        );

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
                'registrations as paid_count' => fn ($query) => $inJurisdiction($query)
                    ->whereIn('status', RegistrationStatus::occupying())
                    ->whereHas('payments', fn ($payment) => $payment
                        ->where('status', PaymentStatus::Verified)
                        ->whereNot('payment_method', PaymentMethod::Promissory)
                    ),
                'registrations as promissory_count' => fn ($query) => $inJurisdiction($query)
                    ->whereIn('status', RegistrationStatus::occupying())
                    ->whereHas('payments', fn ($payment) => $payment
                        ->where('status', PaymentStatus::Verified)
                        ->where('payment_method', PaymentMethod::Promissory)
                    ),
                'registrations as pending_count' => fn ($query) => $inJurisdiction($query)
                    ->whereIn('status', RegistrationStatus::occupying())
                    ->whereHas('training', fn ($training) => $training->where('payment_required', true))
                    ->whereDoesntHave('payments', fn ($payment) => $payment->where('status', PaymentStatus::Verified)),
                'registrations as free_count' => fn ($query) => $inJurisdiction($query)
                    ->whereIn('status', RegistrationStatus::occupying())
                    ->whereHas('training', fn ($training) => $training->where('payment_required', false)),
                'registrations as cancelled_count' => fn ($query) => $inJurisdiction($query)
                    ->where('status', RegistrationStatus::Cancelled),
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
            /*
             * Whether this viewer holds the pen. Every staff role reads this
             * list — it is the way to a roster — but creating and editing a run
             * is HRD work, and the routes for it are narrowed to admin and
             * superadmin. Without this the page offered a New Training button
             * and an Edit link to a field office, both of which walked into a
             * 403: an affordance that lies about what will happen.
             */
            'can' => [
                'manage' => $request->user()->role->managesTrainings(),
            ],
            /*
             * Named on the page for the same reason the roster names it: a
             * scoped figure that does not say it is scoped is indistinguishable
             * from a wrong one. Null for everyone who sees the region.
             */
            'scopedTo' => $request->user()->fieldOffice?->name,
            // The status tabs, with their live counts. Counts are global (not
            // narrowed by the search box) so the tabs stay a stable map of the
            // catalogue; "All" carries the region-wide total.
            'tabs' => $this->statusTabs(),
            'summary' => $this->summary(),
        ]);
    }

    /**
     * The calendar at a glance — deliberately *not* a second set of status
     * counts.
     *
     * The chips under this row already carry one per status, including the
     * catalogue total on "All", so tiles for "All runs" and "Published" would
     * be the same six numbers printed twice, three centimetres apart. What the
     * chips cannot answer is *when*: a run's status says it is published, not
     * whether it is happening this morning. So every figure here is about the
     * schedule, and the two views compose instead of repeating.
     *
     * `running` is the one this page had no way to show at all. A run that has
     * started and not finished is in neither the upcoming nor the finished
     * bucket, and it is the one whose roster somebody is most likely to want
     * today.
     *
     * Runs, never people: the catalogue is regional while participant figures
     * are scoped to a field office, so a row mixing the two would need half of
     * it to carry a "your office only" caveat — the exact confusion the notice
     * above these tiles exists to prevent. People are counted on the dashboard
     * and in the reports, where the scope is uniform.
     *
     * Not narrowed by the search box or the chips, for the same reason the
     * tabs are not: `useFilters` does not reload it, so a filtered figure would
     * go stale rather than follow.
     *
     * @return array<string, int>
     */
    private function summary(): array
    {
        $now = now();
        $published = TrainingStatus::Published->value;
        // A single-day run has no ends_at, so starts_at is its end.
        $end = 'COALESCE(ends_at, starts_at)';

        $row = (array) Training::query()->toBase()->selectRaw(
            "SUM(CASE WHEN status = ? AND starts_at <= ? AND {$end} >= ? THEN 1 ELSE 0 END) as running,"
            .' SUM(CASE WHEN status = ? AND starts_at > ? AND starts_at <= ? THEN 1 ELSE 0 END) as this_week,'
            .' SUM(CASE WHEN status = ? AND starts_at > ? THEN 1 ELSE 0 END) as upcoming,'
            // Finished but never closed out — the same backlog the dashboard
            // names, surfaced where the rosters that clear it actually are.
            ."SUM(CASE WHEN {$end} < ? AND status NOT IN (?, ?) THEN 1 ELSE 0 END) as ended",
            [
                $published, $now, $now,
                $published, $now, $now->copy()->addWeek(),
                $published, $now,
                $now, TrainingStatus::Cancelled->value, TrainingStatus::Draft->value,
            ],
        )->first();

        return [
            'running' => (int) ($row['running'] ?? 0),
            'this_week' => (int) ($row['this_week'] ?? 0),
            'upcoming' => (int) ($row['upcoming'] ?? 0),
            'ended' => (int) ($row['ended'] ?? 0),
        ];
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
                'ends_at' => $training->ends_at->format('d M Y, g:i A'),
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
                'ends_at' => $target->ends_at->format('d M Y, g:i A'),
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
            'transferTargets' => TransferTargets::for($training),
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
