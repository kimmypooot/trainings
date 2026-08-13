<?php

namespace App\Support;

use App\Enums\RegistrationStatus;
use App\Models\Attendance;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What an attendance station is served and what it may write back.
 *
 * There are two doors into the same station now — the signed-in staff scanner
 * at /admin/scanner and the shareable link at /scan/{token} — and they differ
 * only in how the operator is identified. Everything after that point must be
 * identical: the same roster shape, the same digest-not-token rule, the same
 * idempotent write-back. Stating it once here is what keeps the public door
 * from quietly drifting into being more permissive than the staff one.
 *
 * Both entry points pass in an *actor* and a *scope* rather than a request. For
 * the staff scanner those come from the signed-in user; for a scan link they
 * come from whoever issued the link. That is the whole security argument for
 * the public station: a link can never read or write more than the person who
 * created it could.
 */
class ScanStationService
{
    /**
     * The offline bundle for one training.
     *
     * Everything the device needs to decide a scan with no network: who is on
     * the roster, which codes to accept, which calendar days exist and where
     * the line between Present and Late falls.
     *
     * @return array<string, mixed>
     */
    public static function roster(Training $training, ?int $officeId): array
    {
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
                // Digests, never the codes themselves. A device left in a
                // function room overnight carries proof of which codes it would
                // have accepted, but no working check-in codes.
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

        return [
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
            'downloaded_at' => CarbonImmutable::now()->toIso8601String(),
        ];
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
     * be allowed to strand thirty-nine other people's attendance on a device.
     *
     * @param  array<int, array<string, mixed>>  $scans
     * @return array<int, array<string, mixed>>
     */
    public static function sync(
        int $trainingId,
        array $scans,
        User $actor,
        ?int $officeId,
        bool $dryRun = false
    ): array {
        // Resolved as one query and then matched in memory: the same scoping as
        // the roster download, applied again here so a tampered payload cannot
        // check in someone outside the actor's office.
        $registrations = Registration::with(['training', 'user'])
            ->where('training_id', $trainingId)
            ->whereIn('id', array_column($scans, 'registration_id'))
            ->when($officeId !== null, fn ($query) => $query->whereHas(
                'user.profile',
                fn ($profile) => $profile->where('field_office_id', $officeId)
            ))
            ->get()
            ->keyBy('id');

        return array_map(
            fn (array $scan) => self::applyScan($registrations, $scan, $actor, $dryRun),
            $scans
        );
    }

    /**
     * A scan played out in full and then thrown away.
     *
     * The obvious implementation — re-derive the verdict without calling
     * AttendanceService — is the wrong one. It would mean two copies of rules
     * like "only an approved participant can be marked" and "this training is
     * not running today", and the copy nobody exercises is the one that drifts,
     * so the rehearsal would eventually reassure an operator about a scan that
     * would actually fail.
     *
     * So the real code runs, inside a transaction that is always rolled back.
     * The verdict is exact by construction, and nothing survives. checkIn()
     * opens its own transaction, which nests as a savepoint inside this one and
     * is discarded with it.
     *
     * @param  array<string, mixed>  $scan
     * @return array<string, mixed>
     */
    private static function rehearseScan(
        Registration $registration,
        array $scan,
        User $actor,
        CarbonImmutable $at,
        bool $alreadyPresent
    ): array {
        DB::beginTransaction();

        try {
            $attendance = AttendanceService::checkIn($registration, $actor, $at);

            return [
                'client_id' => $scan['client_id'],
                'status' => $alreadyPresent ? 'duplicate' : 'synced',
                'dry_run' => true,
                'training_day' => $attendance->training_day,
                'attendance_status' => $attendance->status->value,
                'time_in' => $attendance->time_in,
                'name' => $registration->user->name,
            ];
        } catch (ValidationException $exception) {
            return [
                'client_id' => $scan['client_id'],
                'status' => 'rejected',
                'dry_run' => true,
                'message' => $exception->validator->errors()->first(),
            ];
        } finally {
            // Unconditional: a rehearsal that committed even once would be
            // worse than having no test mode at all.
            DB::rollBack();
        }
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
    private static function applyScan(
        Collection $registrations,
        array $scan,
        User $actor,
        bool $dryRun = false
    ): array {
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

        if ($dryRun) {
            return self::rehearseScan($registration, $scan, $actor, $at, $alreadyPresent);
        }

        try {
            $attendance = AttendanceService::checkIn($registration, $actor, $at);
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
