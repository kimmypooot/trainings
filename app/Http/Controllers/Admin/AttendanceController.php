<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Models\Registration;
use App\Support\AttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Taking attendance by hand from the roster.
 *
 * Scanning covers the common case, but staff still need a way to record an
 * excused absence, close out a day, or fix a mis-scan — and not every venue
 * will have working phones.
 */
class AttendanceController extends Controller
{
    public function store(Request $request, Registration $registration): RedirectResponse
    {
        $this->authorizeOffice($request, $registration);

        $validated = $request->validate([
            'training_day' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::enum(AttendanceStatus::class)],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $attendance = AttendanceService::mark(
            $registration,
            $validated['training_day'],
            AttendanceStatus::from($validated['status']),
            $request->user(),
            $validated['remarks'] ?? null
        );

        return back()->with('success', sprintf(
            '%s — day %d marked %s.',
            $registration->user->name,
            $attendance->training_day,
            $attendance->status->label()
        ));
    }

    /**
     * Close out a participant's day.
     */
    public function checkOut(Request $request, Registration $registration): RedirectResponse
    {
        $this->authorizeOffice($request, $registration);

        AttendanceService::checkOut($registration, $request->user());

        return back()->with('success', "{$registration->user->name} checked out.");
    }

    /**
     * Field-office staff may only take attendance for their own participants,
     * matching how the roster itself is scoped in TrainingController@roster.
     */
    private function authorizeOffice(Request $request, Registration $registration): void
    {
        $officeId = $request->user()->scopedFieldOfficeId();

        if ($officeId === null) {
            return;
        }

        $registration->loadMissing('user.profile');

        abort_unless($registration->user->profile?->field_office_id === $officeId, 404);
    }
}
