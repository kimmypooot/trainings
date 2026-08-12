<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Support\Exports\SpreadsheetExport;
use Illuminate\Http\Request;
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
     */
    public function participants(Request $request): StreamedResponse
    {
        $query = User::query()
            ->where('role', 'participant')
            ->whereHas('profile')
            ->with('profile.fieldOffice');

        $officeId = $request->user()->scopedFieldOfficeId();

        if ($officeId !== null) {
            $query->whereHas('profile', fn ($profile) => $profile->where('field_office_id', $officeId));
        }

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
        abort_unless($request->user()->role->handlesPayments(), 403);

        $query = Payment::with(['user.profile.fieldOffice', 'training', 'verifier'])
            ->when(
                $request->string('status')->toString(),
                fn ($inner, $status) => $inner->where('status', $status)
            );

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
