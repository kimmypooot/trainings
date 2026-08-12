<?php

namespace App\Http\Controllers;

use App\Enums\RegistrationStatus;
use App\Models\Registration;
use App\Models\User;
use App\Support\AttendanceService;
use App\Support\ParticipantQrCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class QrCodeController extends Controller
{
    /**
     * The participant's own code.
     */
    public function show(Request $request): Response
    {
        $user = $request->user()->loadMissing('profile');

        return Inertia::render('My/QrCode', [
            'qr' => ParticipantQrCode::dataUri($user),
            'participant' => [
                'name' => $user->name,
                'organization' => $user->profile?->organization_name,
                'position' => $user->profile?->position_title,
            ],
        ]);
    }

    /**
     * The same code as a plain PNG, for saving or printing — and a way to view
     * it outside the page's CSS when something looks wrong on screen.
     */
    public function image(Request $request): HttpResponse
    {
        $dataUri = ParticipantQrCode::dataUri($request->user());
        $binary = base64_decode(explode(',', $dataUri)[1]);

        return response($binary, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="csc-tims-qr-code.png"',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * Issue a fresh code, invalidating the old one.
     */
    public function regenerate(Request $request): RedirectResponse
    {
        $user = $request->user();

        ParticipantQrCode::forget($user->qr_token ?? '');
        $user->regenerateQrToken();

        return back()->with('success', 'A new QR code has been issued. Your previous code no longer works.');
    }

    /**
     * Where a scanned code lands. Staff-only: it resolves a participant's
     * identity, so it must never be readable by whoever points a camera at it.
     */
    public function scan(Request $request, string $token): Response
    {
        $participant = $this->resolveParticipant($request, $token);

        return Inertia::render('Staff/ScanResult', [
            'participant' => [
                'name' => $participant->name,
                'email' => $participant->email,
                'organization' => $participant->profile?->organization_name,
                'position' => $participant->profile?->position_title,
                'field_office' => $participant->profile?->fieldOffice?->name,
                'food_restrictions' => $participant->profile?->food_restrictions_details,
            ],
            'sessions' => $this->sessionsToday($participant),
            'checkInUrl' => route('scan.check-in', ['token' => $token]),
        ]);
    }

    /**
     * Record the participant's arrival at one of today's trainings.
     */
    public function checkIn(Request $request, string $token): RedirectResponse
    {
        $participant = $this->resolveParticipant($request, $token);

        $validated = $request->validate([
            'registration_id' => ['required', 'integer'],
        ]);

        // Scoped to the participant so a staff member cannot check in an
        // arbitrary registration by editing the posted id.
        $registration = Registration::with('training')
            ->where('user_id', $participant->getKey())
            ->whereKey($validated['registration_id'])
            ->first();

        if (! $registration) {
            throw new NotFoundHttpException('That registration does not belong to this participant.');
        }

        $attendance = AttendanceService::checkIn($registration, $request->user());

        return back()->with('success', sprintf(
            '%s checked in for day %d of “%s” — %s.',
            $participant->name,
            $attendance->training_day,
            $registration->training->title,
            $attendance->status->label()
        ));
    }

    /**
     * Staff-only: a scan resolves a participant's identity, so it must never be
     * readable by whoever points a camera at it.
     */
    private function resolveParticipant(Request $request, string $token): User
    {
        if (! $request->user()?->role->isStaff()) {
            abort(403, 'Only CSC staff can look up a participant code.');
        }

        $participant = User::where('qr_token', $token)->with('profile.fieldOffice')->first();

        if (! $participant) {
            throw new NotFoundHttpException('That code does not match any participant.');
        }

        return $participant;
    }

    /**
     * The trainings this participant could be checked in to right now.
     *
     * Resolved per scan rather than held in the session: a venue can be running
     * two trainings at once, and stale scanner context would silently record
     * attendance against the wrong one.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sessionsToday(User $participant): array
    {
        $registrations = Registration::with(['training', 'attendances'])
            ->where('user_id', $participant->getKey())
            ->whereIn('status', [RegistrationStatus::Approved, RegistrationStatus::Completed])
            ->get()
            ->filter(fn (Registration $registration) => $registration->training->isRunningToday());

        return $registrations->map(function (Registration $registration) {
            $day = $registration->training->dayNumberFor(now());
            $today = $registration->attendances->firstWhere('training_day', $day);

            return [
                'registration_id' => $registration->id,
                'training' => $registration->training->title,
                'venue' => $registration->training->venue,
                'day' => $day,
                'of_days' => $registration->training->duration_days,
                'already_checked_in' => $today?->time_in !== null,
                'status' => $today?->status->value,
                'status_label' => $today?->status->label(),
                'time_in' => $today?->time_in,
            ];
        })->values()->all();
    }
}
