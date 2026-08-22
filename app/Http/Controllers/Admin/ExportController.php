<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Support\Exports\SpreadsheetExport;
use App\Support\ParticipantFilter;
use App\Support\ReportScope;
use App\Support\RescheduleService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ports v1's four export pages: participants, training records, per-training
 * breakdown, and payments.
 *
 * Every query here goes through scoped(), which applies the same field-office
 * restriction the on-screen listings use. An export that leaked another
 * office's participants would be a data-protection incident, not a bug — so
 * the scoping lives in one place and is covered by dedicated tests.
 */
class ExportController extends Controller
{
    /**
     * Restrict a query to the requesting staff member's field office.
     *
     * Fails closed: an office-scoped user with no office assigned sees nothing,
     * matching the behaviour asserted in FieldOfficeScopingTest.
     */
    private function scoped(Request $request, $query, string $profilePath = 'user.profile')
    {
        $officeId = $request->user()->scopedFieldOfficeId();

        if ($officeId === null) {
            return $query;
        }

        return $query->whereHas(
            $profilePath,
            fn ($profile) => $profile->where('field_office_id', $officeId)
        );
    }

    /**
     * Every participant, with their profile. v1's `export-participants.php`.
     *
     * The directory's filters ride along in the query string, as they did on
     * v1's Export All button: what the administrator downloads is what they
     * were looking at. Both surfaces narrow the query through
     * ParticipantFilter, which is also where the field-office scoping lives —
     * so a filtered export cannot lose it.
     */
    public function participants(Request $request): StreamedResponse
    {
        $officeId = $request->user()->scopedFieldOfficeId();

        $query = ParticipantFilter::apply(
            ParticipantFilter::base($officeId),
            ParticipantFilter::fromRequest($request)
        )
            // A participant who never filled in a profile has nothing to put in
            // any of these columns.
            ->whereHas('profile')
            ->with('profile.fieldOffice');

        return SpreadsheetExport::download(
            'csc-tims-participants',
            [
                'Name', 'Email', 'Sex', 'Date of Birth', 'Mobile', 'Position',
                'Salary Grade', 'Employment Status', 'Organization', 'Sector',
                'Region', 'Province', 'City/Municipality', 'Field Office',
                'Food Restrictions', 'Registered On',
            ],
            fn () => $this->rows($query->orderBy('name'), fn (User $user) => [
                $user->name,
                $user->email,
                $user->profile?->sex,
                $user->profile?->date_of_birth,
                $user->profile?->mobile_number,
                $user->profile?->position_title,
                $user->profile?->salary_grade,
                $user->profile?->employment_status,
                $user->profile?->organization_name,
                $user->profile?->sector,
                $user->profile?->region,
                $user->profile?->province,
                $user->profile?->city_municipality,
                $user->profile?->fieldOffice?->name,
                $user->profile?->food_restrictions_details,
                $user->created_at,
            ]),
            $request->string('format')->toString()
        );
    }

    /**
     * The roster for one training, including attendance. v1's
     * `export-training-breakdown.php`.
     */
    public function roster(Request $request, Training $training): StreamedResponse
    {
        $query = Registration::with(['user.profile.fieldOffice', 'attendances', 'certificate'])
            ->where('training_id', $training->getKey());

        $query = $this->scoped($request, $query);

        $days = $training->trainingDays();

        return SpreadsheetExport::download(
            "roster-{$training->slug}",
            [
                'Name', 'Email', 'Organization', 'Position', 'Field Office',
                'Status', 'Registered On', 'Food Restrictions',
                ...array_map(fn (array $day) => 'Day '.$day['day'].' ('.$day['date']->format('d M').')', $days),
                'Days Credited', 'Certificate No.',
            ],
            fn () => $this->rows($query->orderBy('registered_at'), function (Registration $registration) use ($days) {
                $byDay = $registration->attendances->keyBy('training_day');

                return [
                    $registration->user->name,
                    $registration->user->email,
                    $registration->user->profile?->organization_name,
                    $registration->user->profile?->position_title,
                    $registration->user->profile?->fieldOffice?->name,
                    $registration->status->label(),
                    $registration->registered_at,
                    $registration->user->profile?->food_restrictions_details,
                    ...array_map(
                        fn (array $day) => $byDay->get($day['day'])?->status->label() ?? '',
                        $days
                    ),
                    $registration->creditedDays(),
                    $registration->certificate?->certificate_number,
                ];
            }),
            $request->string('format')->toString()
        );
    }

    /**
     * Who a rescheduled run stranded, and what the office holds for them.
     *
     * The paper counterpart of the affected-participants screen. It exists
     * because the decision it supports is rarely made at a desk: the list gets
     * printed or mailed to a collecting officer, who reconciles the promissory
     * notes against it before anyone is moved.
     *
     * Built through RescheduleService rather than from its own query, so the
     * spreadsheet and the screen cannot disagree about who is affected or
     * whether a given person can be moved.
     */
    public function affected(Request $request, Training $training): StreamedResponse
    {
        $target = $request->integer('target') > 0
            ? Training::whereKey($request->integer('target'))->whereKeyNot($training->getKey())->first()
            : $training->reschedules()->latest('id')->first();

        $rows = RescheduleService::affected(
            $training,
            $target,
            $request->user()->scopedFieldOfficeId(),
        );

        return SpreadsheetExport::download(
            "affected-{$training->slug}",
            [
                'Name', 'Email', 'Field Office', 'Registration Status', 'Registered On',
                'Fee State', 'Payment Method', 'Amount', 'OR No.',
                'Can Be Moved', 'Reason',
            ],
            fn () => $this->affectedRows($rows),
            $request->string('format')->toString()
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function affectedRows(Collection $rows): \Generator
    {
        foreach ($rows as $row) {
            yield [
                $row['name'],
                $row['email'],
                $row['office'],
                $row['status_label'],
                $row['registered_at'],
                // Spelled out rather than shipped as the internal key: this
                // sheet is read by people reconciling receipts, and
                // "promissory" on its own has been mistaken for money in.
                match ($row['fee_state']) {
                    'paid' => 'Paid',
                    'promissory' => 'Promissory note (unpaid)',
                    'free' => 'No fee',
                    default => 'Unpaid',
                },
                $row['payment_method'],
                $row['amount'],
                $row['or_number'],
                $row['movable'],
                $row['blocker'],
            ];
        }
    }

    /**
     * One participant's whole training record.
     *
     * v1 had an "Export History" button on its field-office participant page,
     * but it was never wired up: `export.php` reads the `type` parameter and
     * then ignores it, dying on the missing `training_id` that a history export
     * never sends. Clicking it produced a plain-text error. So this is the
     * report that button implied rather than a port of one.
     *
     * The office needs it when a participant asks what they have attended, or
     * when an agency asks what CSC has delivered to its staff — questions the
     * roster and the registrations export answer per *training*, never per
     * person.
     *
     * One row per registration, carrying the training, what was decided, what
     * was attended, what was paid and what was issued — so the row stands on
     * its own without a second lookup.
     */
    public function participantHistory(Request $request, User $user): StreamedResponse
    {
        abort_unless($user->role === Role::Participant, 404);

        // The same office guard the participant directory applies. 404 rather
        // than 403: a scoped officer has no business knowing the record exists.
        $officeId = $request->user()->scopedFieldOfficeId();

        abort_if(
            $officeId !== null && $user->profile?->field_office_id !== $officeId,
            404
        );

        $query = Registration::with(['training', 'attendances', 'certificate', 'payments'])
            ->where('user_id', $user->getKey());

        return SpreadsheetExport::download(
            'training-history-'.$user->name,
            [
                'Participant', 'Training', 'Training Code', 'Starts', 'Ends', 'Mode',
                'Registration Status', 'Registered On', 'Charged To', 'Days Credited',
                'Amount Paid', 'PRIME-HRM Discount', 'Payment Method', 'OR No.',
                'Payment Status', 'Certificate No.', 'Certificate Issued',
            ],
            fn () => $this->rows(
                $query->join('trainings', 'trainings.id', '=', 'registrations.training_id')
                    ->orderByDesc('trainings.starts_at')
                    ->select('registrations.*'),
                function (Registration $registration) use ($user) {
                    // The payment that settled it, if any. A registration can
                    // carry a rejected attempt as well, and reporting that one
                    // would understate what the participant actually paid.
                    $payment = $registration->payments
                        ->firstWhere('status', PaymentStatus::Verified);

                    return [
                        $user->name,
                        $registration->training->title,
                        $registration->training->training_code,
                        $registration->training->starts_at,
                        $registration->training->ends_at,
                        $registration->training->mode->label(),
                        $registration->status->label(),
                        $registration->registered_at,
                        $registration->charge_to?->label(),
                        $registration->creditedDays(),
                        $payment?->amount,
                        $payment?->prime_hrm_discount ? 'Yes (20%)' : ($payment ? 'No' : ''),
                        $payment?->payment_method->label(),
                        $payment?->or_number,
                        $payment?->status->label() ?? 'No payment recorded',
                        $registration->certificate?->certificate_number,
                        $registration->certificate?->generated_at,
                    ];
                }
            ),
            $request->string('format')->toString()
        );
    }

    /**
     * What one training earned, participant by participant.
     *
     * The PRIME-HRM discount is the reason this exists as its own report: the
     * office is asked both what a run brought in and which participants were
     * given the incentive, and neither the roster nor the payments queue
     * answers the pair together.
     *
     * Figures come off the payment rows, which froze their own gross and
     * discount when taken — so a later repricing of the course cannot restate a
     * closed run's revenue.
     */
    public function revenue(Request $request, Training $training): StreamedResponse
    {
        abort_unless($request->user()->collectsPayments(), 403);

        $query = Payment::with(['user.profile.fieldOffice', 'collectingOfficer'])
            ->where('training_id', $training->getKey())
            ->where('status', PaymentStatus::Verified);

        $query = $this->scoped($request, $query);

        return SpreadsheetExport::download(
            "revenue-{$training->slug}",
            [
                'Participant', 'Organization', 'Field Office', 'Full Fee',
                'PRIME-HRM Discount', 'Discount Amount', 'Amount Paid', 'Method',
                'OR No.', 'OR Date', 'Collecting Officer', 'Payment Date',
            ],
            fn () => $this->rows($query->orderBy('or_number'), fn (Payment $payment) => [
                $payment->user->name,
                $payment->user->profile?->organization_name,
                $payment->user->profile?->fieldOffice?->name,
                $payment->grossAmount(),
                // Spelled out rather than a bare 1/0: this column is read by a
                // person reconciling a spreadsheet, not by a machine.
                $payment->prime_hrm_discount ? 'Yes (20%)' : 'No',
                $payment->discount_amount,
                $payment->amount,
                $payment->payment_method->label(),
                $payment->or_number,
                $payment->or_date,
                $payment->collectingOfficer?->name,
                $payment->payment_date,
            ]),
            $request->string('format')->toString()
        );
    }

    /**
     * Every registration across every training. v1's `export-records.php`.
     */
    public function registrations(Request $request): StreamedResponse
    {
        $query = Registration::with(['user.profile.fieldOffice', 'training'])
            ->when(
                $request->string('status')->toString(),
                fn ($inner, $status) => $inner->where('status', $status)
            );

        $query = $this->scoped($request, $query);

        return SpreadsheetExport::download(
            'csc-tims-registrations',
            [
                'Training', 'Training Code', 'Starts', 'Participant', 'Email',
                'Organization', 'Field Office', 'Status', 'Registered On',
                'Days Credited', 'Attended On',
            ],
            fn () => $this->rows($query->orderByDesc('registered_at'), fn (Registration $registration) => [
                $registration->training->title,
                $registration->training->training_code,
                $registration->training->starts_at,
                $registration->user->name,
                $registration->user->email,
                $registration->user->profile?->organization_name,
                $registration->user->profile?->fieldOffice?->name,
                $registration->status->label(),
                $registration->registered_at,
                $registration->attendances->filter(fn ($a) => $a->credits())->count(),
                $registration->attended_at,
            ]),
            $request->string('format')->toString()
        );
    }

    /**
     * Payments and their verification state. v1's collecting-officer reports.
     */
    public function payments(Request $request): StreamedResponse
    {
        abort_unless($request->user()->collectsPayments(), 403);

        $status = $request->string('status')->toString();
        $method = $request->string('method')->toString();
        $search = $request->string('search')->toString();

        $query = Payment::with(['user.profile.fieldOffice', 'training', 'verifier'])
            ->when($status, fn ($inner, $s) => $inner->where('status', $s))
            ->when($method, fn ($inner, $m) => $inner->where('payment_method', $m))
            ->when($search, fn ($inner, $s) => $inner->where(function ($q) use ($s) {
                $q->whereHas('user', fn ($user) => $user->where('name', 'like', "%{$s}%"))
                    ->orWhere('or_number', 'like', "%{$s}%")
                    ->orWhere('reference_number', 'like', "%{$s}%")
                    ->orWhereHas('training', fn ($training) => $training->where('title', 'like', "%{$s}%"));
            }));

        return SpreadsheetExport::download(
            'csc-tims-payments',
            [
                'Participant', 'Training', 'Amount', 'Method', 'Reference',
                'Paid On', 'Status', 'Verified By', 'Verified On', 'Remarks',
            ],
            fn () => $this->rows($query->orderByDesc('created_at'), fn (Payment $payment) => [
                $payment->user->name,
                $payment->training->title,
                $payment->amount,
                $payment->payment_method->label(),
                $payment->reference_number,
                $payment->payment_date,
                $payment->status->label(),
                $payment->verifier?->name,
                $payment->verified_at,
                $payment->rejection_reason ?: $payment->remarks,
            ]),
            $request->string('format')->toString()
        );
    }

    /**
     * The certificate register, honouring the same filters the on-screen list
     * shows. No v1 ancestor — the register itself is new to v2.
     */
    public function certificates(Request $request): StreamedResponse
    {
        $query = Certificate::query()
            ->with(['user.profile.fieldOffice', 'training'])
            // A row with no file is a half-finished release, not a certificate.
            ->whereNotNull('generated_at')
            ->when(
                $request->string('search')->toString(),
                fn ($inner, $search) => $inner->where(fn ($q) => $q
                    ->where('certificate_number', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($user) => $user
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                    )
                    ->orWhereHas('training', fn ($training) => $training->where('title', 'like', "%{$search}%"))
                )
            )
            ->when(
                $request->string('training')->toString(),
                fn ($inner, $id) => $inner->where('training_id', $id)
            )
            ->when(
                $request->string('emailed')->toString() === '1',
                fn ($inner) => $inner->whereNotNull('email_sent_at')
            )
            ->when(
                $request->string('emailed')->toString() === '0',
                fn ($inner) => $inner->whereNull('email_sent_at')
            )
            ->when(
                $request->string('year')->toString(),
                fn ($inner, $year) => $inner->whereYear('generated_at', $year)
            );

        $query = $this->scoped($request, $query);

        return SpreadsheetExport::download(
            'csc-tims-certificates',
            [
                'Certificate No.', 'Participant', 'Email', 'Training', 'Issued On',
                'Emailed On', 'Verifications', 'Downloads', 'Verify URL',
            ],
            fn () => $this->rows($query->orderByDesc('generated_at'), fn (Certificate $certificate) => [
                $certificate->certificate_number,
                $certificate->user->name,
                $certificate->user->email,
                $certificate->training->title,
                $certificate->generated_at,
                $certificate->email_sent_at,
                $certificate->verification_count,
                $certificate->download_count,
                $certificate->verificationUrl(),
            ]),
            $request->string('format')->toString()
        );
    }

    /**
     * The revenue report behind the analytics page: either one training or all
     * trainings conducted in a period, with the PRIME-HRM discount on its own
     * line so the assessed and the collected never blur.
     *
     * The scope comes through ReportScope — the same parser the analytics page
     * uses — so a downloaded report and the screen it was downloaded from can
     * never answer differently.
     */
    public function revenueReport(Request $request): StreamedResponse
    {
        abort_unless($request->user()->collectsPayments(), 403);

        $scope = ReportScope::fromRequest($request);

        abort_if($scope->view === 'training' && $scope->trainingId === null, 404);

        $query = Payment::with(['user.profile.fieldOffice', 'training'])
            ->whereIn('training_id', $scope->trainingsQuery()->pluck('id'))
            ->where('status', PaymentStatus::Verified);

        $query = $this->scoped($request, $query, 'user.profile');

        return SpreadsheetExport::download(
            'revenue-report-'.$scope->exportSlug(),
            [
                'Participant', 'Organization', 'Field Office', 'Training', 'Training Date',
                'Full Fee', 'PRIME-HRM Discount', 'Discount Amount',
                // Two money columns rather than one "Amount Paid", because a
                // promissory note is verified but nothing was received. Folded
                // together, summing the column overstated the takings and
                // disagreed with the Collected figure on the screen this claims
                // to mirror. Split, each column totals to its own headline and
                // the row is still there to show who owes.
                'Amount Collected', 'On Promissory Note',
                'Method', 'OR No.',
            ],
            fn () => $this->rows($query->orderBy('or_number'), fn (Payment $payment) => [
                $payment->user->name,
                $payment->user->profile?->organization_name,
                $payment->user->profile?->fieldOffice?->name,
                $payment->training->title,
                $payment->training->starts_at,
                $payment->grossAmount(),
                // Spelled out rather than a bare 1/0: this column is read by a
                // person reconciling a spreadsheet, not by a machine.
                $payment->prime_hrm_discount ? 'Yes (20%)' : 'No',
                $payment->discount_amount,
                $payment->payment_method->isSettlement() ? $payment->amount : null,
                $payment->payment_method->isSettlement() ? null : $payment->amount,
                $payment->payment_method->label(),
                $payment->or_number,
            ]),
            $request->string('format')->toString()
        );
    }

    /**
     * The demographic report behind the analytics page: one row per
     * registration of the selected training or the period's trainings, with
     * every breakdown cut on the row so it can be pivoted anywhere.
     */
    public function breakdownReport(Request $request): StreamedResponse
    {
        $scope = ReportScope::fromRequest($request);

        abort_if($scope->view === 'training' && $scope->trainingId === null, 404);

        $query = Registration::with(['user.profile', 'training'])
            ->whereIn('training_id', $scope->trainingsQuery()->pluck('id'));

        $query = $this->scoped($request, $query);

        return SpreadsheetExport::download(
            'breakdown-report-'.$scope->exportSlug(),
            [
                'Training', 'Training Date', 'Participant', 'Email', 'Organization',
                'Sector', 'Sex', 'PWD', 'Position Level', 'Employment Status', 'Age',
            ],
            fn () => $this->rows($query->orderBy('registered_at'), fn (Registration $registration) => [
                $registration->training->title,
                $registration->training->starts_at,
                $registration->user->name,
                $registration->user->email,
                $registration->user->profile?->organization_name,
                $registration->user->profile?->sector,
                $registration->user->profile?->sex,
                $registration->user->profile?->is_pwd === null
                    ? ''
                    : ($registration->user->profile->is_pwd ? 'Yes' : 'No'),
                $registration->user->profile?->position_level,
                $registration->user->profile?->employment_status,
                $registration->user->profile?->date_of_birth?->age,
            ]),
            $request->string('format')->toString()
        );
    }

    /**
     * Walk a query in chunks, yielding one mapped row at a time.
     *
     * lazy() rather than get(): the whole point of streaming is that the result
     * set never lands in memory at once. It is offset-based, which keeps the
     * caller's ordering intact — lazyById() would silently override it.
     *
     * @return \Generator<int, array<int, mixed>>
     */
    private function rows($query, callable $map): \Generator
    {
        foreach ($query->lazy(500) as $model) {
            yield $map($model);
        }
    }
}
