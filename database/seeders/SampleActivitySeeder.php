<?php

namespace Database\Seeders;

use App\Enums\AgencyDocumentKind;
use App\Enums\AgencyRequestStatus;
use App\Enums\AttendanceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\RegistrationStatus;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Enums\TrainingMode;
use App\Enums\TrainingStatus;
use App\Models\AgencyRequest;
use App\Models\Attendance;
use App\Models\CancellationRequest;
use App\Models\Certificate;
use App\Models\Payment;
use App\Models\RefundRequest;
use App\Models\Registration;
use App\Models\SmeEvaluation;
use App\Models\SubjectMatterExpert;
use App\Models\Training;
use App\Models\TrainingDayEvaluation;
use App\Models\TrainingRequest;
use App\Models\User;
use Database\Factories\RegistrationFactory;
use Database\Seeders\Concerns\SeedsRandomly;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A working dataset: trainings across their whole lifecycle, and the
 * registrations, attendance, certificates, payments and requests that hang off
 * them.
 *
 * Written directly against the models rather than through the services. The
 * services fire notifications, which would mean a few hundred queued mails per
 * run, and they enforce "today" rules that make it impossible to backdate a
 * training that finished last month. Seeding needs history, so the invariants
 * that matter — one registration per person per training, capacity, attendance
 * only on days a training actually runs — are upheld here explicitly instead.
 *
 * Certificates are data only: a row, a number and a verification code, but no
 * rendered PDF. The verification page works; the download does not, because
 * there is no file behind it. Issue one through the roster to get a real PDF.
 *
 * Re-running tops the dataset up rather than duplicating it. The catalogue is
 * matched on slug so it stays put, while each pass registers a fresh slice of
 * the participant pool — self-limiting, since a pair that already exists is
 * skipped and capacity still applies.
 */
class SampleActivitySeeder extends Seeder
{
    use SeedsRandomly;

    public const SEED_ENV = 'SAMPLE_DATA_SEED';

    /** Reused so registrations, attendance and payments all agree on who exists. */
    private Collection $participants;

    private Collection $staff;

    /** The pool of resource persons runs are staffed from. */
    private Collection $experts;

    /** @var array<string, int> */
    private array $tally = [];

    public function run(): void
    {
        if ($this->blockedInProduction('SampleActivitySeeder')) {
            return;
        }

        $seed = $this->applySeed(self::SEED_ENV);

        $this->participants = User::where('role', Role::Participant)
            ->whereHas('profile')
            ->get();

        $this->staff = User::whereIn('role', array_map(
            fn (Role $role) => $role->value,
            [Role::Admin, Role::SuperAdmin]
        ))->get();

        if ($this->participants->count() < 5 || $this->staff->isEmpty()) {
            $this->command->warn('Not enough accounts to work with — running SampleUsersSeeder first.');
            $this->call(SampleUsersSeeder::class);

            $this->participants = User::where('role', Role::Participant)->whereHas('profile')->get();
            $this->staff = User::whereIn('role', [Role::Admin->value, Role::SuperAdmin->value])->get();
        }

        $this->experts = $this->subjectMatterExperts();

        foreach ($this->blueprint() as $spec) {
            $this->training($spec);
        }

        $this->trainingRequests();
        $this->agencyRequests();

        $this->report($seed);
    }

    /**
     * The spread of trainings to create.
     *
     * Deliberately covers the whole lifecycle rather than a pile of identical
     * upcoming ones: the dashboard, roster, certificate and payment screens each
     * only come alive for a particular stage, and a dataset of nothing but
     * future trainings leaves half the system looking empty.
     *
     * @return array<int, array<string, mixed>>
     */
    private function blueprint(): array
    {
        // [title, category, start offset in days, length in days, status,
        //  capacity, charges a fee, is a Supervisory Development Course]
        return [
            // Finished — the source of attendance and certificates.
            ['Records Management Seminar', 'Technical', -14, 2, TrainingStatus::Completed, 30, false, false],
            ['Public Service Ethics Workshop', 'Foundation', -35, 1, TrainingStatus::Completed, 40, false, false],
            ['Gender and Development Orientation', 'Foundation', -60, 1, TrainingStatus::Completed, 50, false, false],
            ['Strategic Performance Management System', 'Technical', -21, 3, TrainingStatus::Completed, 25, true, false],

            // Running right now — the QR scanner has something to check in.
            ['Basic Computer Literacy', 'Technical', 0, 3, TrainingStatus::Published, 25, false, false],

            // Upcoming — registrations awaiting review.
            ['Leadership Development Program', 'Leadership and Management', 21, 5, TrainingStatus::Published, 20, true, false],
            ['Frontline Service Excellence', 'Foundation', 35, 2, TrainingStatus::Published, 45, false, false],
            ['Data Privacy for Public Servants', 'Technical', 49, 1, TrainingStatus::Published, null, false, false],
            /*
             * The one supervisory run, and the only reason the flag column
             * exists in this blueprint. Nothing seeded used to set
             * is_supervisory, so SupervisoryEligibility — the salary-grade bar
             * and the designation-document upload it gates — never fired on
             * demo data at all, and the badge never appeared. Kept upcoming and
             * open so a demo can actually walk the registration path.
             */
            ['Supervisory Development Course', 'Leadership and Management', 70, 5, TrainingStatus::Published, 30, true, true],

            // Not yet announced.
            ['Project Management Fundamentals', 'Technical', 90, 4, TrainingStatus::Draft, 30, false, false],
            ['Records Disposal and Archiving', 'Technical', 105, 2, TrainingStatus::Draft, 25, false, false],

            // Called off — participants keep a cancelled registration.
            ['Disaster Preparedness Briefing', 'Foundation', 28, 1, TrainingStatus::Cancelled, 60, false, false],
        ];
    }

    /**
     * @param  array<int, mixed>  $spec
     */
    private function training(array $spec): void
    {
        [$title, $category, $offsetDays, $days, $status, $capacity, $paid, $supervisory] = $spec;

        $starts = Carbon::today()->addDays($offsetDays)->setTime(8, 30);
        $ends = $starts->copy()->addDays($days - 1)->setTime(16, 30);
        $creator = $this->staff->random();

        $training = Training::updateOrCreate(
            ['slug' => Str::slug($title)],
            [
                'title' => $title,
                'training_code' => sprintf('TRN-%s-%04d', $starts->format('Y'), fake()->unique()->numberBetween(1, 9999)),
                'description' => "A {$days}-day {$category} programme run by CSC Regional Office VIII.",
                'category' => $category,
                'venue' => fake()->randomElement([
                    'CSC Regional Office VIII, Palo, Leyte',
                    'Leyte Provincial Capitol, Tacloban City',
                    'Ormoc City Convention Center',
                ]),
                'mode' => fake()->randomElement(TrainingMode::cases()),
                'starts_at' => $starts,
                'ends_at' => $ends,
                'duration_days' => $days,
                'registration_opens_at' => $starts->copy()->subDays(30),
                'registration_closes_at' => $starts->copy()->subDays(3),
                'capacity' => $capacity,
                // Who signs the certificate, which is not the panel below.
                'signatory_name' => mb_strtoupper(fake()->name()),
                'prerequisites' => fake()->boolean(40) ? 'None.' : 'Participants must have completed the orientation course.',
                'target_participants' => 'Second-level personnel of national and local government agencies.',
                'payment_required' => $paid,
                'payment_amount' => $paid ? fake()->randomElement([850, 1200, 1500, 2500]) : null,
                'is_supervisory' => $supervisory,
                'status' => $status,
                'created_by' => $creator->getKey(),
            ]
        );

        $this->count('trainings');

        $this->assignExperts($training, $days);

        // A draft has not been announced, so nobody could have registered.
        if ($status === TrainingStatus::Draft) {
            return;
        }

        $this->registrations($training, $status);
    }

    /**
     * Register a slice of the participant pool, with a status mix that suits
     * where the training is in its life.
     */
    private function registrations(Training $training, TrainingStatus $status): void
    {
        $pool = $this->participants->shuffle();
        $wanted = $training->capacity === null
            ? fake()->numberBetween(6, 12)
            : min($training->capacity, fake()->numberBetween(5, 14));

        foreach ($pool->take($wanted) as $participant) {
            if (Registration::where('user_id', $participant->getKey())
                ->where('training_id', $training->getKey())
                ->exists()) {
                continue;
            }

            $decision = $this->decisionFor($status);
            $registeredAt = $training->starts_at->copy()->subDays(fake()->numberBetween(5, 25));

            $registration = Registration::create([
                'user_id' => $participant->getKey(),
                'training_id' => $training->getKey(),
                'status' => $decision,
                'registered_at' => $registeredAt,
                // Every real registration carries this — the form requires it.
                // Drawn from the factory so the seeded mix and the factory mix
                // cannot drift apart into two different ideas of "typical".
                'charge_to' => RegistrationFactory::chargeTo(),
            ]);

            // Anything past pending has been looked at by someone.
            if ($decision !== RegistrationStatus::Pending) {
                $registration->forceFill([
                    'reviewed_by' => $this->staff->random()->getKey(),
                    'reviewed_at' => $registeredAt->copy()->addDays(2),
                    'review_remarks' => $decision === RegistrationStatus::Rejected
                        ? fake()->randomElement([
                            'Slots reserved for frontline personnel this cycle.',
                            'Agency has already sent its maximum nominees.',
                            'Prerequisite course not yet completed.',
                        ])
                        : null,
                    'cancelled_at' => $decision === RegistrationStatus::Cancelled
                        ? $registeredAt->copy()->addDays(4)
                        : null,
                ])->save();
            }

            $this->count('registrations');

            $this->attendance($registration, $training);
            $this->evaluations($registration, $training);
            $this->certificate($registration, $training);
            $this->payment($registration, $training);
            $this->cancellationRequest($registration, $training);
        }
    }

    /**
     * The status a registration would plausibly be in.
     */
    private function decisionFor(TrainingStatus $status): RegistrationStatus
    {
        return match (true) {
            // Everyone on a cancelled training loses their slot.
            $status === TrainingStatus::Cancelled => RegistrationStatus::Cancelled,

            $status === TrainingStatus::Completed => fake()->randomElement([
                ...array_fill(0, 7, RegistrationStatus::Completed),
                RegistrationStatus::Cancelled,
                RegistrationStatus::Rejected,
            ]),

            default => fake()->randomElement([
                ...array_fill(0, 5, RegistrationStatus::Approved),
                ...array_fill(0, 3, RegistrationStatus::Pending),
                RegistrationStatus::Waitlisted,
                RegistrationStatus::Rejected,
            ]),
        };
    }

    /**
     * Per-day attendance for trainings that have actually started.
     */
    private function attendance(Registration $registration, Training $training): void
    {
        $holdsPlace = in_array($registration->status, [
            RegistrationStatus::Approved,
            RegistrationStatus::Completed,
        ], true);

        if (! $holdsPlace || $training->starts_at->isFuture()) {
            return;
        }

        $recorder = $this->staff->random();
        $firstCredited = null;

        foreach ($training->trainingDays() as $day) {
            // A training running right now has no record for days still ahead.
            if ($day['date']->isFuture()) {
                continue;
            }

            $status = fake()->randomElement([
                ...array_fill(0, 12, AttendanceStatus::Present),
                ...array_fill(0, 3, AttendanceStatus::Late),
                AttendanceStatus::Absent,
                AttendanceStatus::Excused,
            ]);

            $present = $status === AttendanceStatus::Present || $status === AttendanceStatus::Late;

            Attendance::updateOrCreate(
                [
                    'registration_id' => $registration->getKey(),
                    'training_day' => $day['day'],
                ],
                [
                    'attendance_date' => $day['date']->toDateString(),
                    'status' => $status,
                    'time_in' => $present
                        ? ($status === AttendanceStatus::Late ? '09:'.fake()->numberBetween(10, 55) : '08:'.fake()->numberBetween(10, 29)).':00'
                        : null,
                    'time_out' => $present ? '16:'.fake()->numberBetween(30, 59).':00' : null,
                    'remarks' => $status === AttendanceStatus::Excused
                        ? fake()->randomElement(['Bereavement leave.', 'Official travel.', 'Medical certificate submitted.'])
                        : null,
                    'recorded_by' => $recorder->getKey(),
                ]
            );

            if ($status->credits() && $firstCredited === null) {
                $firstCredited = $day['date'];
            }

            $this->count('attendance records');
        }

        // Mirrors what AttendanceService writes: the first day that counted.
        $registration->forceFill(['attended_at' => $firstCredited])->save();
    }

    /**
     * A certificate record for a completed registration — data only.
     *
     * file_path names where a PDF would live but nothing is written to disk, so
     * these read as released and verify correctly while the download 404s.
     */
    private function certificate(Registration $registration, Training $training): void
    {
        if ($registration->status !== RegistrationStatus::Completed || ! fake()->boolean(85)) {
            return;
        }

        $code = Str::random(32);
        $issuedAt = $training->ends_at->copy()->addDays(fake()->numberBetween(3, 21));

        $certificate = Certificate::updateOrCreate(
            ['registration_id' => $registration->getKey()],
            [
                'user_id' => $registration->user_id,
                'training_id' => $training->getKey(),
                'certificate_number' => sprintf(
                    'CSC8-%s-%06d',
                    $training->starts_at->format('Y'),
                    fake()->unique()->numberBetween(1, 999999)
                ),
                'verification_code' => $code,
                'file_path' => "certificates/{$code}.pdf",
                'generated_at' => $issuedAt,
                'generated_by' => $this->staff->random()->getKey(),
                'email_sent_at' => $issuedAt,
            ]
        );

        /*
         * Verification history, and the counter derived from it.
         *
         * Seeding the count on its own used to leave the certificate page
         * contradicting itself — "3 public verifications" above a panel saying
         * nobody had ever looked it up. CertificateService::recordVerification()
         * writes the row and bumps the counter together, so the only faithful
         * way to seed it is to write the rows and count them.
         */
        // times() rather than range(): range(1, 0) counts *down* and yields
        // [1, 0], so a certificate meant to have no lookups would quietly get
        // one and never appear as un-verified in the data.
        $lookups = Collection::times(
            fake()->numberBetween(0, 6),
            fn () => $issuedAt->copy()->addDays(fake()->numberBetween(1, 40))
        )->sort()->values();

        $lookups->each(fn ($at) => $certificate->verifications()->create([
            'verified_at' => $at,
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ]));

        // Downloads have no history table — the counter is all there is.
        $downloads = fake()->numberBetween(0, 4);

        $certificate->forceFill([
            'verification_count' => $lookups->count(),
            'download_count' => $downloads,
            'last_verified_at' => $lookups->last(),
            'last_downloaded_at' => $downloads ? $issuedAt->copy()->addDays(fake()->numberBetween(1, 30)) : null,
        ])->save();

        $this->count('certificates');
    }

    /**
     * Payment against a paid training, and occasionally a refund claim.
     */
    private function payment(Registration $registration, Training $training): void
    {
        if (! $training->payment_required) {
            return;
        }

        // Not everyone has paid yet — an empty queue teaches nothing.
        if (in_array($registration->status, [RegistrationStatus::Rejected, RegistrationStatus::Waitlisted], true)
            || ! fake()->boolean(80)) {
            return;
        }

        /*
         * The first three payments take one status each, and only then does it
         * go random. The dataset exists so every screen has something on it,
         * and leaving that to a weighted draw meant a run could — rarely, but
         * it happened — produce no pending payment at all and leave the
         * collecting officer's queue empty. Guaranteeing the coverage is the
         * seeder's job; the tests should not be asserting a dice roll.
         */
        $status = match ($this->tally['payments'] ?? 0) {
            0 => PaymentStatus::Verified,
            1 => PaymentStatus::Pending,
            2 => PaymentStatus::Rejected,
            default => fake()->randomElement([
                ...array_fill(0, 6, PaymentStatus::Verified),
                ...array_fill(0, 3, PaymentStatus::Pending),
                PaymentStatus::Rejected,
            ]),
        };

        $method = fake()->randomElement(PaymentMethod::cases());
        $paidOn = $registration->registered_at->copy()->addDays(fake()->numberBetween(1, 6));

        $payment = Payment::updateOrCreate(
            ['registration_id' => $registration->getKey()],
            [
                'user_id' => $registration->user_id,
                'training_id' => $training->getKey(),
                'amount' => $training->payment_amount,
                'payment_method' => $method,
                'reference_number' => $method->requiresReference() ? fake()->numerify('REF#########') : null,
                'payment_date' => $paidOn->toDateString(),
                // No attachment: proof is uploaded by the participant, and
                // there is no file to point at here.
                'proof_path' => null,
                'status' => $status,
                'verified_by' => $status->isPending() ? null : $this->staff->random()->getKey(),
                'verified_at' => $status->isPending() ? null : $paidOn->copy()->addDays(2),
                'rejection_reason' => $status === PaymentStatus::Rejected
                    ? 'Reference number does not match the collection report.'
                    : null,
            ]
        );

        $this->count('payments');

        /*
         * The first refundable payment always draws a claim, and only then
         * does it go random — the same reasoning as the payment statuses
         * above. On a pure 20% draw a run could produce no refund at all,
         * leaving the refund queue empty and
         * SampleActivitySeederTest::test_refunds_only_claim_against_verified_payments
         * looping over nothing, which PHPUnit rightly calls risky: the test
         * passed without checking anything. Reproduce the old behaviour with
         * SAMPLE_DATA_SEED=4.
         */
        $noRefundYet = ($this->tally['refund requests'] ?? 0) === 0;

        if ($status->isRefundable() && ($noRefundYet || fake()->boolean(20))) {
            $this->refund($payment);
        }
    }

    private function refund(Payment $payment): void
    {
        // Spread the demo data across the whole pipeline, not just its ends —
        // the officer's queue is only worth looking at if something is sitting
        // in the middle of it.
        $stage = fake()->randomElement([
            RefundStatus::ForReview,
            RefundStatus::Processing,
            RefundStatus::ForwardedToMsd,
            RefundStatus::ForRelease,
            RefundStatus::Refunded,
            RefundStatus::Rejected,
        ]);

        $filedAt = $payment->verified_at?->copy()->addDays(2) ?? now();
        $decidedAt = $filedAt->copy()->addDays(3);
        $touched = $stage !== RefundStatus::ForReview;

        $refund = RefundRequest::updateOrCreate(
            ['payment_id' => $payment->getKey()],
            [
                'request_code' => RefundRequest::nextRequestCode(),
                'amount' => $payment->amount,
                'reason' => fake()->randomElement([
                    'The training was rescheduled and I can no longer attend.',
                    'Duplicate payment made for the same registration.',
                    'Agency withdrew the nomination after payment.',
                ]),
                'account_name' => $payment->user->name,
                'bank_name' => fake()->randomElement([
                    'Land Bank of the Philippines',
                    'Development Bank of the Philippines',
                    'Bank of the Philippine Islands',
                ]),
                'account_number' => (string) fake()->numerify('##########'),
                'status' => $stage,
                'reviewed_by' => $touched ? $this->staff->random()->getKey() : null,
                'reviewed_at' => $touched ? $decidedAt : null,
                'rejection_reason' => $stage === RefundStatus::Rejected
                    ? 'Request received after the refund window closed.'
                    : null,
                'refunded_at' => $stage === RefundStatus::Refunded ? $decidedAt : null,
            ]
        );

        $this->seedRefundTrail($refund, $stage, $filedAt);

        $this->count('refund requests');
    }

    /**
     * Walk the log forward through every stage the request passed on its way
     * to where it sits now, so the trail on screen matches the status.
     */
    private function seedRefundTrail(RefundRequest $refund, RefundStatus $stage, Carbon $filedAt): void
    {
        $refund->statusLogs()->delete();

        $reached = [RefundStatus::ForReview];

        if ($stage === RefundStatus::Rejected) {
            // A decline can land at any point; keep the demo simple and have
            // it happen straight out of review.
            $reached[] = RefundStatus::Rejected;
        } else {
            foreach (RefundStatus::pipeline() as $step) {
                if ($step === RefundStatus::ForReview) {
                    continue;
                }

                $reached[] = $step;

                if ($step === $stage) {
                    break;
                }
            }
        }

        $at = $filedAt->copy();
        $previous = null;

        foreach ($reached as $step) {
            $refund->statusLogs()->create([
                'from_status' => $previous,
                'to_status' => $step,
                'notes' => $previous === null ? 'Request filed by participant.' : null,
                'changed_by' => $previous === null ? null : $this->staff->random()->getKey(),
                'changed_at' => $at->copy(),
            ]);

            $previous = $step;
            $at->addDays(2);
        }
    }

    /**
     * A few open withdrawal requests on upcoming trainings.
     */
    private function cancellationRequest(Registration $registration, Training $training): void
    {
        if ($registration->status !== RegistrationStatus::Approved
            || $training->starts_at->isPast()
            || ! fake()->boolean(12)) {
            return;
        }

        CancellationRequest::updateOrCreate(
            ['registration_id' => $registration->getKey()],
            [
                'reason' => fake()->randomElement([
                    'Assigned to field work covering the same dates.',
                    'Medical procedure scheduled that week.',
                    'Office cannot spare staff during the audit period.',
                ]),
                'status' => RequestStatus::Pending,
            ]
        );

        $this->count('withdrawal requests');
    }

    /**
     * Agency-requested trainings, at every stage of review.
     */
    private function trainingRequests(): void
    {
        $titles = [
            ['Basic Occupational Safety and Health', 'Technical', RequestStatus::Pending],
            ['Customer Service for Frontline Staff', 'Foundation', RequestStatus::Pending],
            ['Advanced Spreadsheet Skills', 'Technical', RequestStatus::Approved],
            ['Conflict Resolution in the Workplace', 'Leadership and Management', RequestStatus::Rejected],
            ['Freedom of Information Orientation', 'Foundation', RequestStatus::Pending],
        ];

        foreach ($titles as [$title, $category, $status]) {
            $requester = $this->participants->random();
            $reviewed = $status !== RequestStatus::Pending;
            $submittedAt = now()->subDays(fake()->numberBetween(5, 60));

            $request = TrainingRequest::updateOrCreate(
                ['title' => $title, 'requested_by' => $requester->getKey()],
                [
                    'justification' => "Our office has personnel with no formal training in {$category}, and the "
                        .'competency gap is showing in day-to-day work. We are requesting CSC to run a session locally.',
                    'category' => $category,
                    'expected_participants' => fake()->numberBetween(12, 40),
                    'preferred_start' => now()->addMonths(fake()->numberBetween(2, 5))->toDateString(),
                    'preferred_end' => now()->addMonths(fake()->numberBetween(2, 5))->addDays(2)->toDateString(),
                    'status' => $status,
                    'reviewed_by' => $reviewed ? $this->staff->random()->getKey() : null,
                    'reviewed_at' => $reviewed ? $submittedAt->copy()->addDays(7) : null,
                    'review_remarks' => $status === RequestStatus::Rejected
                        ? 'No facilitator available this semester — resubmit for the next planning cycle.'
                        : null,
                ]
            );

            $request->forceFill(['created_at' => $submittedAt])->save();

            $this->count('training requests');
        }
    }

    /**
     * Agency requests spread across the correspondence.
     *
     * Written directly rather than through AgencyRequestService for the same
     * reason as everything else here: the service uploads real files and fires
     * notifications, and seeding needs neither. The documents are therefore
     * metadata only — the rows exist and the screens render, but the downloads
     * have no file behind them, exactly as with the seeded certificates.
     */
    private function agencyRequests(): void
    {
        $blueprint = [
            ['Records Management for LGU Personnel', AgencyRequestStatus::Pending],
            ['Basic Customer Service', AgencyRequestStatus::UnderReview],
            ['Occupational Safety Orientation', AgencyRequestStatus::RequirementsSent],
            ['Supervisory Skills for Middle Managers', AgencyRequestStatus::Confirmed],
            ['Public Financial Management', AgencyRequestStatus::Completed],
            ['Team Building Workshop', AgencyRequestStatus::Rejected],
        ];

        foreach ($blueprint as [$title, $status]) {
            $requester = $this->participants->random();
            $filedAt = now()->subDays(fake()->numberBetween(10, 90));
            $officer = $this->staff->random();

            $request = AgencyRequest::updateOrCreate(
                ['training_title' => $title, 'requested_by' => $requester->getKey()],
                [
                    'request_code' => AgencyRequest::nextRequestCode(),
                    'agency_name' => $requester->profile?->organization_name ?? 'Local Government Unit',
                    'proposed_start' => $filedAt->copy()->addMonths(2)->toDateString(),
                    'proposed_end' => $filedAt->copy()->addMonths(2)->addDays(2)->toDateString(),
                    'proposed_venue' => fake()->randomElement([
                        'Municipal Hall, Palo, Leyte',
                        'Provincial Capitol, Tacloban City',
                        'Agency Training Room',
                    ]),
                    'expected_participants' => fake()->numberBetween(15, 45),
                    'status' => $status,
                ]
            );

            $this->dressAgencyRequest($request, $status, $filedAt, $officer, $requester);

            $this->count('agency requests');
        }
    }

    /**
     * Fill in the fields each stage implies, so no seeded request contradicts
     * itself — a `confirmed` row with no confirmed dates would make the screens
     * look broken rather than populated.
     */
    private function dressAgencyRequest(
        AgencyRequest $request,
        AgencyRequestStatus $status,
        Carbon $filedAt,
        User $officer,
        User $requester,
    ): void {
        $attributes = ['created_at' => $filedAt];
        $documents = [[AgencyDocumentKind::RequestLetter, $requester, $filedAt]];

        if ($status !== AgencyRequestStatus::Pending) {
            $attributes['assigned_to'] = $officer->getKey();
            $attributes['assigned_at'] = $filedAt->copy()->addDays(2);
            $attributes['ord_notified_at'] = $filedAt->copy()->addDay();
        }

        if ($status->hasReached(AgencyRequestStatus::RequirementsSent)) {
            $attributes['requirements_text'] = 'Please return the signed confirmation form and confirm '
                .'your final dates and venue. Payment is settled after the training has run.';
            $attributes['requirements_sent_at'] = $filedAt->copy()->addDays(5);
            $documents[] = [AgencyDocumentKind::ResponseLetter, $officer, $filedAt->copy()->addDays(5)];
            $documents[] = [AgencyDocumentKind::BlankConfirmationForm, $officer, $filedAt->copy()->addDays(5)];
        }

        if ($status->hasReached(AgencyRequestStatus::Confirmed)) {
            $attributes['confirmed_start'] = $request->proposed_start;
            $attributes['confirmed_end'] = $request->proposed_end;
            $attributes['confirmed_venue'] = $request->proposed_venue;
            $attributes['confirmed_at'] = $filedAt->copy()->addDays(12);
            $documents[] = [AgencyDocumentKind::SignedConfirmationForm, $requester, $filedAt->copy()->addDays(12)];
        }

        if ($status === AgencyRequestStatus::Completed) {
            $attributes['completion_submitted_at'] = $filedAt->copy()->addDays(70);
            $attributes['payment_amount'] = fake()->randomElement([12000, 18000, 25000]);
            $attributes['payment_verified_by'] = $officer->getKey();
            $attributes['payment_verified_at'] = $filedAt->copy()->addDays(75);
            $attributes['closed_at'] = $filedAt->copy()->addDays(75);

            foreach (AgencyDocumentKind::requiredForCompletion() as $kind) {
                $documents[] = [$kind, $requester, $filedAt->copy()->addDays(70)];
            }
        }

        if ($status === AgencyRequestStatus::Rejected) {
            $attributes['rejection_reason'] = 'No facilitator is available for the proposed dates. '
                .'Please resubmit for the next quarter.';
            $attributes['closed_at'] = $filedAt->copy()->addDays(8);
        }

        $request->forceFill($attributes)->save();

        $request->documents()->delete();

        foreach ($documents as [$kind, $uploader, $at]) {
            $request->documents()->create([
                'kind' => $kind,
                'file_path' => "agency-requests/{$request->getKey()}/seeded-{$kind->value}.pdf",
                'original_filename' => Str::slug($kind->label()).'.pdf',
                'file_size' => fake()->numberBetween(40_000, 900_000),
                'mime_type' => 'application/pdf',
                'uploaded_by' => $uploader->getKey(),
                'created_at' => $at,
            ]);
        }
    }

    /**
     * The standing panel of resource persons.
     *
     * Named rather than faked, and matched on name so re-running keeps the same
     * people: their ratings accumulate across runs of the seeder, and a fresh
     * set of strangers each pass would leave every expert with one evaluation
     * and the SME screens looking like they do not work.
     */
    private function subjectMatterExperts(): Collection
    {
        $roster = [
            ['Leilani C. Parel', 'Chief HR Specialist', 'Supervisory development, coaching, performance management.'],
            ['Ramon T. Villanueva', 'Supervising HR Specialist', 'Records management, ISO documentation.'],
            ['Cristina M. Dagohoy', 'Director II', 'Public service ethics, accountability, RA 6713.'],
            ['Arnel B. Sabalza', 'HR Specialist II', 'Data privacy, cybersecurity awareness, digital tools.'],
            ['Ma. Teresa L. Ocampo', 'Chief HR Specialist', 'Gender and development, workplace inclusion.'],
            ['Joel P. Bacareza', 'Supervising HR Specialist', 'Frontline service, ARTA compliance.'],
        ];

        return collect($roster)->map(function (array $expert) {
            [$name, $position, $expertise] = $expert;

            $record = SubjectMatterExpert::updateOrCreate(
                ['name' => $name],
                [
                    'position' => $position,
                    'organization' => 'Civil Service Commission RO VIII',
                    'email' => Str::slug($name, '.').'@csc.gov.ph',
                    'contact_number' => '09'.fake()->numerify('#########'),
                    'expertise' => $expertise,
                    'is_active' => true,
                    'created_by' => $this->staff->random()->getKey(),
                ]
            );

            if ($record->wasRecentlyCreated) {
                $this->count('subject matter experts');
            }

            return $record;
        });
    }

    /**
     * Staff a run with one to three experts.
     *
     * A multi-day programme gets its panel split across the days — which is
     * the case the `days` pivot column exists for, and the one worth having in
     * demo data, because it is where an evaluation form showing the wrong
     * expert would be visible.
     */
    private function assignExperts(Training $training, int $days): void
    {
        if ($training->subjectMatterExperts()->exists()) {
            return;
        }

        $panel = $this->experts->shuffle()->take(fake()->numberBetween(1, min(3, $days + 1)));

        $payload = [];

        foreach ($panel->values() as $index => $expert) {
            // One expert covers the whole run; a panel divides it, each taking
            // roughly consecutive days.
            $assigned = $panel->count() === 1
                ? null
                : json_encode(
                    collect(range(1, $days))
                        ->filter(fn (int $day) => $day % $panel->count() === $index % $panel->count())
                        ->values()
                        ->all()
                );

            $payload[$expert->getKey()] = [
                'topic' => fake()->randomElement([
                    'Session proper', 'Plenary and workshop', 'Lecture and case study', null,
                ]),
                'days' => $assigned === '[]' ? null : $assigned,
                'sort_order' => $index,
            ];
        }

        $training->subjectMatterExperts()->sync($payload);
    }

    /**
     * Evaluations for days the participant actually attended.
     *
     * Deliberately not everyone: a response rate of 100% is the one number the
     * screens will never show in real use, and demo data that implies it hides
     * the very column an office reads first.
     */
    private function evaluations(Registration $registration, Training $training): void
    {
        if (! $registration->status->occupiesSlot() || $training->starts_at->isFuture()) {
            return;
        }

        $training->loadMissing('subjectMatterExperts');

        $credited = $registration->attendances()
            ->get()
            ->filter(fn (Attendance $attendance) => $attendance->credits());

        foreach ($credited as $attendance) {
            if (fake()->boolean(35)) {
                continue;
            }

            $experts = $training->expertsForDay($attendance->training_day);

            if ($experts->isEmpty()) {
                continue;
            }

            $evaluation = TrainingDayEvaluation::updateOrCreate(
                [
                    'registration_id' => $registration->getKey(),
                    'day_number' => $attendance->training_day,
                ],
                [
                    'training_id' => $training->getKey(),
                    'learned' => fake()->boolean(70)
                        ? fake()->randomElement([
                            'The documentation requirements I can apply to our own filing system.',
                            'How to run a coaching conversation without it becoming a reprimand.',
                            'The reporting timelines — I had been computing them wrongly.',
                        ])
                        : null,
                    'liked_most' => fake()->boolean(60)
                        ? fake()->randomElement([
                            'The workshop portion. Actual documents, not slides.',
                            'The open forum in the afternoon.',
                            'The examples drawn from LGU practice.',
                        ])
                        : null,
                    'needs_improvement' => fake()->boolean(45)
                        ? fake()->randomElement([
                            'The venue was cold and the audio cut out after lunch.',
                            'More time for the group activity.',
                            'Handouts arrived only at the end of the session.',
                        ])
                        : null,
                    'suggestions' => fake()->boolean(30)
                        ? 'Please run this again next semester for the rest of our office.'
                        : null,
                    'submitted_at' => $attendance->attendance_date->copy()->setTime(17, fake()->numberBetween(5, 55)),
                ]
            );

            foreach ($experts as $expert) {
                // Skewed high, the way voluntary feedback actually arrives —
                // a uniform 1-to-5 spread would make every average land on 3
                // and the results screens meaningless.
                $draw = fn () => fake()->randomElement([5, 5, 5, 4, 4, 4, 4, 3, 3, 2]);

                SmeEvaluation::updateOrCreate(
                    [
                        'training_day_evaluation_id' => $evaluation->getKey(),
                        'subject_matter_expert_id' => $expert->getKey(),
                    ],
                    [
                        'knowledge_rating' => $draw(),
                        'interaction_rating' => $draw(),
                        'engagement_rating' => $draw(),
                        'pace_rating' => $draw(),
                        'comments' => fake()->boolean(35)
                            ? fake()->randomElement([
                                'Very clear and patient with questions.',
                                'Knows the material but spoke too quickly after lunch.',
                                'Best resource person we have had this year.',
                            ])
                            : null,
                    ]
                );
            }

            $this->count('evaluations');
        }
    }

    private function count(string $key): void
    {
        $this->tally[$key] = ($this->tally[$key] ?? 0) + 1;
    }

    private function report(int $seed): void
    {
        foreach ($this->tally as $label => $count) {
            $this->command->line(sprintf('  %-20s %d', $label, $count));
        }

        $this->command->warn('Certificates are data only — no PDF exists, so downloads will fail.');
        $this->command->info('Verification pages do work: /verify/{code} for any seeded certificate.');
        $this->reportSeed($seed, self::SEED_ENV);
    }
}
