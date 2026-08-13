<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RegistrationStatus;
use App\Enums\TrainingStatus;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Registration;
use App\Models\Training;
use App\Support\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The venue scanner: a standalone check-in station for a phone or tablet.
 *
 * The point of this controller is the *offline* case. Training venues are
 * function rooms, gymnasiums and provincial capitol halls where the signal
 * dies at the door, and a queue of forty participants cannot wait for a
 * request to time out per person. So the scanner downloads the roster it needs
 * before the session starts, decides everything locally while scanning, and
 * hands its results back when a connection returns.
 *
 * That shape drives three endpoints rather than one:
 *
 *  - index()  the station page itself, listing what can be scanned;
 *  - roster() the offline bundle, downloaded once per training per day;
 *  - sync()   the batch write-back, idempotent so a retry is always safe.
 *
 * Nothing here is public. A roster resolves participant identities in bulk, so
 * it is staff-only and field-office scoped exactly like TrainingController@roster
 * — see FieldOfficeScopingTest, which is the guard on that.
 */
class ScannerController extends Controller
{
    /**
     * The scanning station.
     *
     * Trainings running today come first because that is what a scanner at a
     * door needs; the rest of the current window is offered too, so a station
     * can be prepared the night before while the office still has wifi.
     */
    public function index(Request $request): Response
    {
        $trainings = Training::query()
            // A window rather than "today": staff set the tablet up in advance,
            // and a multi-day run must stay downloadable on days two and three.
            ->whereBetween('starts_at', [now()->subDays(30), now()->addDays(30)])
            // A draft has no approved participants and a cancelled run has no
            // door to stand at; offering either is just a way to mis-scan.
            ->whereNotIn('status', [TrainingStatus::Draft, TrainingStatus::Cancelled])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Training $training) => [
                'id' => $training->id,
                'title' => $training->title,
                'venue' => $training->venue,
                'starts_at' => $training->starts_at->format('d M Y, g:i A'),
                'date_label' => $training->starts_at->format('D, d M Y'),
                'duration_days' => max(1, $training->duration_days ?? 1),
                'is_today' => $training->isRunningToday(),
                'roster_url' => route('admin.scanner.roster', $training),
            ])
            ->values()
            ->all();

        return Inertia::render('Staff/Scanner', [
            'trainings' => $trainings,
            'syncUrl' => route('admin.scanner.sync'),
            'scopedTo' => $request->user()->fieldOffice?->name,
            'operator' => $request->user()->name,
        ]);
    }

    /**
     * The offline bundle for one training.
     *
     * Deliberately *not* an Inertia response: this is downloaded by fetch() and
     * written straight into IndexedDB, and it has to be re-downloadable without
     * disturbing whatever the station is currently showing.
     *
     * Tokens are sent as SHA-256 digests, never in the clear. The scanner hashes
     * what it reads off the camera and compares digests, which means a tablet
     * left in a function room overnight carries no working check-in codes — only
     * proof of which codes it would have accepted.
     */
    public function roster(Request $request, Training $training): JsonResponse
    {
        $officeId = $request->user()->scopedFieldOfficeId();

        $registrations = Registration::with(['user.profile.fieldOffice', 'attendances'])
            ->where('training_id', $training->getKey())
            // Only a registration that holds a place can be marked, so anything
            // else is dead weight on a device that has to work from memory.
            ->whereIn('status', [RegistrationStatus::Approved, RegistrationStatus::Completed])
            ->when($officeId !== null, fn ($query) => $query->whereHas(
                'user.profile',
                fn ($profile) => $profile->where('field_office_id', $officeId)
            ))
            ->orderBy('registered_at')
            ->get();

        $participants = $registrations->map(function (Registration $registration) {
            $user = $registration->user;

            // Minted here rather than at first scan: a participant who never
            // opened their QR page still has to be recognisable at the door.
            $token = $user->ensureQrToken();

            return [
                'registration_id' => $registration->id,
                'name' => $user->name,
                'organization' => $user->profile?->organization_name,
                'position' => $user->profile?->position_title,
                'field_office' => $user->profile?->fieldOffice?->name,
                'food_restrictions' => $user->profile?->food_restrictions_details,
                'token_hash' => hash('sha256', $token),
                // What the server already knows, so a freshly downloaded roster
                // recognises someone another station checked in this morning.
                'attendance' => $registration->attendances
                    ->filter(fn (Attendance $attendance) => $attendance->time_in !== null)
                    ->mapWithKeys(fn (Attendance $attendance) => [
                        (string) $attendance->training_day => [
                            'status' => $attendance->status->value,
                            'status_label' => $attendance->status->label(),
                            'time_in' => $attendance->time_in,
                        ],
                    ])
                    ->all(),
            ];
        })->values()->all();

        return response()->json([
            'training' => [
                'id' => $training->id,
                'title' => $training->title,
                'venue' => $training->venue,
                'duration_days' => max(1, $training->duration_days ?? 1),
                // The scheduled start time of day, which is what decides Present
                // versus Late — the scanner applies the same rule offline.
                'starts_at_time' => $training->starts_at->format('H:i'),
                'late_after_minutes' => AttendanceService::LATE_AFTER_MINUTES,
                'days' => array_map(fn (array $day) => [
                    'day' => $day['day'],
                    'date' => $day['date']->toDateString(),
                    'label' => $day['date']->format('D, d M Y'),
                ], $training->trainingDays()),
            ],
            'participants' => $participants,
            'scoped_to' => $request->user()->fieldOffice?->name,
            'downloaded_at' => CarbonImmutable::now()->toIso8601String(),
        ]);
    }

    /**
     * Write a batch of offline scans back.
     *
     * Every scan carries the moment it happened at the door, not the moment it
     * reached us — a queue flushed at 5pm must still record the 8am arrival, and
     * must still land on the right training day.
     *
     * Each result is reported separately and the whole batch never fails as a
     * unit: one participant whose registration was cancelled mid-session cannot
     * be allowed to strand thirty-nine other people's attendance on a tablet.
     */
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'training_id' => ['required', 'integer', 'exists:trainings,id'],
            'scans' => ['required', 'array', 'max:500'],
            'scans.*.client_id' => ['required', 'string', 'max:64'],
            'scans.*.registration_id' => ['required', 'integer'],
            'scans.*.scanned_at' => ['required', 'date'],
        ]);

        $officeId = $request->user()->scopedFieldOfficeId();

        // Resolved as one query and then matched in memory: the same registration
        // scoping as the roster download, applied again here so a tampered
        // payload cannot check in someone outside the operator's office.
        $registrations = Registration::with(['training', 'user'])
            ->where('training_id', $validated['training_id'])
            ->whereIn('id', array_column($validated['scans'], 'registration_id'))
            ->when($officeId !== null, fn ($query) => $query->whereHas(
                'user.profile',
                fn ($profile) => $profile->where('field_office_id', $officeId)
            ))
            ->get()
            ->keyBy('id');

        $results = [];

        foreach ($validated['scans'] as $scan) {
            $results[] = $this->applyScan($request, $registrations, $scan);
        }

        return response()->json([
            'results' => $results,
            'synced_at' => CarbonImmutable::now()->toIso8601String(),
        ]);
    }

    /**
     * One queued scan, resolved to a verdict the station can act on.
     *
     * The three outcomes are deliberately distinct. `synced` and `duplicate`
     * both mean the record is safely on the server and the device may drop it;
     * `rejected` means it will never succeed, so retrying forever would only
     * keep a permanent error badge on the screen.
     *
     * @param  Collection<int, Registration>  $registrations
     * @param  array<string, mixed>  $scan
     * @return array<string, mixed>
     */
    private function applyScan(Request $request, $registrations, array $scan): array
    {
        $registration = $registrations->get($scan['registration_id']);

        if ($registration === null) {
            return [
                'client_id' => $scan['client_id'],
                'status' => 'rejected',
                'message' => 'That registration is not on this training’s roster.',
            ];
        }

        $at = CarbonImmutable::parse($scan['scanned_at']);

        // A device whose clock has run ahead would otherwise record attendance
        // for a day that has not happened. Trust the server's clock instead of
        // silently filing the scan under the wrong training day.
        if ($at->isFuture()) {
            $at = CarbonImmutable::now();
        }

        $day = $registration->training->dayNumberFor($at);

        $alreadyPresent = $day !== null && Attendance::where('registration_id', $registration->getKey())
            ->where('training_day', $day)
            ->whereNotNull('time_in')
            ->exists();

        try {
            $attendance = AttendanceService::checkIn($registration, $request->user(), $at);
        } catch (ValidationException $exception) {
            return [
                'client_id' => $scan['client_id'],
                'status' => 'rejected',
                'message' => $exception->validator->errors()->first(),
            ];
        }

        return [
            'client_id' => $scan['client_id'],
            // Reported as a duplicate only when the server already held an
            // arrival time; checkIn() is idempotent, so this is the one place
            // that can still tell the two apart.
            'status' => $alreadyPresent ? 'duplicate' : 'synced',
            'training_day' => $attendance->training_day,
            'attendance_status' => $attendance->status->value,
            'time_in' => $attendance->time_in,
            'name' => $registration->user->name,
        ];
    }
}
