<?php

namespace App\Support;

use App\Enums\AttendanceStatus;
use App\Enums\RegistrationStatus;
use App\Models\Attendance;
use App\Models\Registration;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recording attendance, whether it arrives from a QR scan at the door or from
 * a staff member working down the roster by hand.
 *
 * Both paths land here so the rules — which registrations may be marked, which
 * day a date belongs to, and what a repeat scan does — are stated once.
 */
class AttendanceService
{
    /**
     * How late someone may arrive before a scan records Late rather than Present.
     *
     * Public because the offline scanner has to apply the same rule on the
     * device, hours before its scans reach this class — see ScannerController.
     * Both sides must read the number from here or a queued scan would show one
     * status at the door and land as another on the server.
     */
    public const LATE_AFTER_MINUTES = 30;

    /**
     * Check a participant in from a scan.
     *
     * Idempotent by design: scanning the same code twice on the same day is the
     * normal case at a busy door, and it must not produce a second row or move
     * the recorded arrival time later.
     */
    public static function checkIn(
        Registration $registration,
        User $recordedBy,
        ?CarbonImmutable $at = null
    ): Attendance {
        $at ??= CarbonImmutable::now();

        return DB::transaction(function () use ($registration, $recordedBy, $at) {
            $registration->loadMissing('training');
            $training = $registration->training;

            self::assertMarkable($registration);

            $day = $training->dayNumberFor($at);

            if ($day === null) {
                throw ValidationException::withMessages([
                    'attendance' => "“{$training->title}” is not running on ".$at->format('d M Y').'.',
                ]);
            }

            $existing = Attendance::where('registration_id', $registration->getKey())
                ->where('training_day', $day)
                ->lockForUpdate()
                ->first();

            // Already checked in today — hand back the original row untouched so
            // the first arrival time is what stands.
            if ($existing !== null && $existing->time_in !== null) {
                return $existing;
            }

            $attendance = self::write($registration, $day, $at, [
                'status' => self::statusForArrival($registration, $at),
                'time_in' => $at->format('H:i:s'),
                'recorded_by' => $recordedBy->getKey(),
            ]);

            self::syncRegistrationAttendance($registration);

            return $attendance;
        });
    }

    /**
     * Record a departure for a day already checked in.
     */
    public static function checkOut(
        Registration $registration,
        User $recordedBy,
        ?CarbonImmutable $at = null
    ): Attendance {
        $at ??= CarbonImmutable::now();

        return DB::transaction(function () use ($registration, $recordedBy, $at) {
            $registration->loadMissing('training');

            $day = $registration->training->dayNumberFor($at);

            $attendance = $day === null ? null : Attendance::where('registration_id', $registration->getKey())
                ->where('training_day', $day)
                ->lockForUpdate()
                ->first();

            if ($attendance === null) {
                throw ValidationException::withMessages([
                    'attendance' => 'There is no check-in to close out for today.',
                ]);
            }

            $attendance->forceFill([
                'time_out' => $at->format('H:i:s'),
                'recorded_by' => $recordedBy->getKey(),
            ])->save();

            return $attendance;
        });
    }

    /**
     * Set a day's status by hand, from the roster.
     *
     * This is how Absent and Excused get recorded — a scan can never produce
     * them — and how staff correct a mis-scan.
     */
    public static function mark(
        Registration $registration,
        int $day,
        AttendanceStatus $status,
        User $recordedBy,
        ?string $remarks = null
    ): Attendance {
        return DB::transaction(function () use ($registration, $day, $status, $recordedBy, $remarks) {
            $registration->loadMissing('training');
            $training = $registration->training;

            self::assertMarkable($registration);

            $days = $training->trainingDays();

            if ($day < 1 || $day > count($days)) {
                throw ValidationException::withMessages([
                    'attendance' => "“{$training->title}” has no day {$day}.",
                ]);
            }

            $date = $days[$day - 1]['date'];

            $previous = Attendance::where('registration_id', $registration->getKey())
                ->where('training_day', $day)
                ->value('status');

            $attendance = self::write($registration, $day, $date, [
                'status' => $status,
                'remarks' => $remarks,
                'recorded_by' => $recordedBy->getKey(),
                // Marking someone absent after a mis-scan has to clear the
                // arrival time, or the roster would show a contradiction.
                ...($status === AttendanceStatus::Absent ? ['time_in' => null, 'time_out' => null] : []),
            ]);

            self::syncRegistrationAttendance($registration);

            /*
             * The hand-marked path is logged; the scanned one is not, and the
             * asymmetry is deliberate.
             *
             * Attendance rows already answer "who recorded this and when" —
             * `recorded_by`, `time_in` and the timestamps are on every row — so
             * a scan is not an unrecorded event. What the rows cannot show is a
             * *decision*: a Present that a member of staff turned into Absent,
             * or an Excused granted after the fact. That is what a dispute is
             * ever about, and it is what this captures.
             *
             * Logging every check-in instead would add a row per scan per
             * participant per day — thousands over one large event — and bury
             * the handful of overrides inside them. That is precisely the
             * volume problem LoginController's comment describes for v1's
             * login rows, where the decisions worth auditing were lost in the
             * noise of the ones that were not.
             */
            ActivityLogger::recordTransition(
                'attendance.marked',
                $registration,
                $previous instanceof AttendanceStatus ? $previous : ($previous ? AttendanceStatus::tryFrom($previous) : null),
                $status,
                sprintf(
                    '%s — day %d marked %s by hand.',
                    $registration->user->name,
                    $day,
                    $status->label(),
                ),
                [
                    'training_id' => $training->getKey(),
                    'training_day' => $day,
                    'remarks' => $remarks,
                ],
                $recordedBy,
            );

            return $attendance;
        });
    }

    /**
     * Only a registration that actually holds a place can be marked.
     *
     * Without this a scan would happily record attendance for someone who
     * cancelled or was rejected, and that record would then feed completion.
     */
    private static function assertMarkable(Registration $registration): void
    {
        $allowed = [RegistrationStatus::Approved, RegistrationStatus::Completed];

        if (! in_array($registration->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'attendance' => "This registration is {$registration->status->label()} — only an approved participant can be marked.",
            ]);
        }
    }

    /**
     * Present unless the participant arrived more than the grace period after
     * the day's scheduled start.
     */
    private static function statusForArrival(Registration $registration, CarbonImmutable $at): AttendanceStatus
    {
        $startsAt = CarbonImmutable::parse($registration->training->starts_at);

        // The scheduled time of day applies to every day of a multi-day run.
        $expected = $at->setTime((int) $startsAt->format('H'), (int) $startsAt->format('i'));

        return $at->greaterThan($expected->addMinutes(self::LATE_AFTER_MINUTES))
            ? AttendanceStatus::Late
            : AttendanceStatus::Present;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function write(
        Registration $registration,
        int $day,
        CarbonImmutable $date,
        array $attributes
    ): Attendance {
        return Attendance::updateOrCreate(
            [
                'registration_id' => $registration->getKey(),
                'training_day' => $day,
            ],
            [
                'attendance_date' => $date->toDateString(),
                ...$attributes,
            ]
        );
    }

    /**
     * Keep `registrations.attended_at` in step with the attendance rows.
     *
     * It is denormalised — the dashboard, roster and certificate release all
     * read it — so it is written here rather than left to drift.
     */
    private static function syncRegistrationAttendance(Registration $registration): void
    {
        $first = $registration->attendances()
            ->whereIn('status', AttendanceStatus::crediting())
            ->orderBy('attendance_date')
            ->first();

        $registration->forceFill([
            'attended_at' => $first?->attendance_date,
        ])->save();

        // The in-memory relation is stale now that rows have changed, and the
        // caller commonly asks for creditedDays() straight after.
        $registration->unsetRelation('attendances');
    }
}
