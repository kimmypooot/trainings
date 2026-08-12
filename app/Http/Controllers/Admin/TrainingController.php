<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AttendanceStatus;
use App\Enums\RegistrationStatus;
use App\Enums\TrainingMode;
use App\Enums\TrainingStatus;
use App\Http\Controllers\Controller;
use App\Models\Registration;
use App\Models\Training;
use App\Support\RegistrationService;
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
        $trainings = Training::query()
            ->withCount([
                'registrations as active_count' => fn ($query) => $query->whereIn('status', RegistrationStatus::occupying()),
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
                'starts_at' => $training->starts_at->format('d M Y, g:i A'),
                'status' => $training->status->value,
                'status_label' => $training->status->label(),
                'capacity' => $training->capacity,
                'registered' => $training->active_count,
                'roster_url' => route('admin.trainings.roster', $training),
                'edit_url' => route('admin.trainings.edit', $training),
            ]),
            'filters' => [
                'status' => $request->string('status')->toString(),
                'search' => $request->string('search')->toString(),
            ],
            'statuses' => array_map(
                fn (TrainingStatus $status) => ['value' => $status->value, 'label' => $status->label()],
                TrainingStatus::cases()
            ),
        ]);
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
    private function formOptions(): array
    {
        return [
            'statuses' => array_map(
                fn (TrainingStatus $status) => ['value' => $status->value, 'label' => $status->label()],
                TrainingStatus::cases()
            ),
            'modes' => TrainingMode::options(),
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $training = Training::create([
            ...$data,
            'slug' => $this->uniqueSlug($data['title']),
            'created_by' => $request->user()->getKey(),
        ]);

        return redirect()
            ->route('admin.trainings.index')
            ->with('success', "“{$training->title}” has been created.");
    }

    public function edit(Training $training): Response
    {
        return Inertia::render('Admin/Trainings/Form', [
            'training' => [
                'id' => $training->id,
                'title' => $training->title,
                'training_code' => $training->training_code,
                'description' => $training->description,
                'category' => $training->category,
                'venue' => $training->venue,
                'mode' => $training->mode->value,
                'starts_at' => $training->starts_at->format('Y-m-d\TH:i'),
                'ends_at' => $training->ends_at->format('Y-m-d\TH:i'),
                'duration_days' => $training->duration_days,
                'registration_opens_at' => $training->registration_opens_at?->format('Y-m-d\TH:i'),
                'registration_closes_at' => $training->registration_closes_at?->format('Y-m-d\TH:i'),
                'capacity' => $training->capacity,
                'facilitator_name' => $training->facilitator_name,
                'facilitator_contact' => $training->facilitator_contact,
                'objectives' => $training->objectives,
                'prerequisites' => $training->prerequisites,
                'target_participants' => $training->target_participants,
                'payment_required' => $training->payment_required,
                'payment_amount' => $training->payment_amount,
                'status' => $training->status->value,
            ],
            ...$this->formOptions(),
        ]);
    }

    public function update(Request $request, Training $training): RedirectResponse
    {
        $training->update($this->validated($request, $training));

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

        $registrations = Registration::with(['user.profile.fieldOffice', 'attendances', 'certificate'])
            ->where('training_id', $training->getKey())
            // Field-office staff see only their own office's participants on
            // the roster; the training itself stays visible to everyone.
            ->when($officeId !== null, fn ($query) => $query->whereHas(
                'user.profile',
                fn ($profile) => $profile->where('field_office_id', $officeId)
            ))
            ->orderBy('registered_at')
            ->get();

        return Inertia::render('Admin/Trainings/Roster', [
            'training' => [
                'id' => $training->id,
                'title' => $training->title,
                'venue' => $training->venue,
                'starts_at' => $training->starts_at->format('d M Y, g:i A'),
                'capacity' => $training->capacity,
                'status_label' => $training->status->label(),
                'duration_days' => $training->duration_days,
                'days' => array_map(fn (array $day) => [
                    'day' => $day['day'],
                    'label' => $day['date']->format('d M'),
                    'is_today' => $day['date']->isToday(),
                ], $training->trainingDays()),
            ],
            'scopedTo' => $request->user()->fieldOffice?->name,
            'attendanceStatuses' => AttendanceStatus::options(),
            'registrations' => $registrations->map(fn (Registration $registration) => [
                'id' => $registration->id,
                'status' => $registration->status->value,
                'name' => $registration->user->name,
                'email' => $registration->user->email,
                'organization' => $registration->user->profile?->organization_name,
                'position' => $registration->user->profile?->position_title,
                'field_office' => $registration->user->profile?->fieldOffice?->name,
                'food_restrictions' => $registration->user->profile?->food_restrictions_details,
                'registered_at' => $registration->registered_at->format('d M Y'),
                'review_remarks' => $registration->review_remarks,
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
                'can_complete' => $registration->status === RegistrationStatus::Approved
                    && $registration->setRelation('training', $training)->hasSufficientAttendance(),
                'certificate_number' => $registration->certificate?->isReleased()
                    ? $registration->certificate->certificate_number
                    : null,
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
            ],
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

        RegistrationService::review($registration, $decision, $request->user(), $validated['remarks'] ?? null);

        return back()->with(
            'success',
            "{$registration->user->name} — {$decision->label()}."
        );
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

        $registration->forceFill([
            'status' => RegistrationStatus::Completed,
            // Falls back to the training's start only when completion was
            // forced; otherwise attendance has already set this.
            'attended_at' => $registration->attended_at ?? $registration->training->starts_at,
            'review_remarks' => $forced ? $validated['remarks'] : $registration->review_remarks,
        ])->save();

        return back()->with('success', "{$registration->user->name} marked as completed.");
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
            'category' => ['nullable', 'string', 'max:100'],
            'venue' => ['required', 'string', 'max:255'],
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
            'facilitator_name' => ['nullable', 'string', 'max:128'],
            'facilitator_contact' => ['nullable', 'string', 'max:32'],
            'objectives' => ['nullable', 'string', 'max:5000'],
            'prerequisites' => ['nullable', 'string', 'max:5000'],
            'target_participants' => ['nullable', 'string', 'max:5000'],
            'payment_required' => ['boolean'],
            // Only meaningful — and only required — when the training is paid.
            'payment_amount' => [
                'nullable', 'required_if:payment_required,true', 'numeric', 'min:0', 'max:1000000',
            ],
            'status' => ['required', Rule::enum(TrainingStatus::class)],
        ]);

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
