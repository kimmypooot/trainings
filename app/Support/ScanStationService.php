<?php

namespace App\Support;

use App\Enums\RegistrationStatus;
use App\Models\Attendance;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
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
    public static function roster(Training $training, ?int $officeId, ?CarbonImmutable $since = null): array
    {
        /*
         * The watermark is taken before the query, not after.
         *
         * It is what the device sends back as `since` next time, so anything
         * written while this query runs has to fall on the *later* side of it
         * or it would be missed forever. Stamping the response at the end
         * would silently swallow exactly the rows a busy door is producing.
         */
        $watermark = CarbonImmutable::now();

        $scoped = fn ($query) => $query
            ->where('training_id', $training->getKey())
            ->when($officeId !== null, fn ($inner) => $inner->whereHas(
                'user.profile',
                fn ($profile) => $profile->where('field_office_id', $officeId)
            ));

        $registrations = Registration::with(['user.profile.fieldOffice', 'attendances'])
            ->where($scoped)
            // Only a registration that holds a place can be marked, so anything
            // else is dead weight on a device that has to work from memory.
            ->whereIn('status', [RegistrationStatus::Approved, RegistrationStatus::Completed])
            /*
             * A delta carries what changed, which is two different things.
             *
             * The registration row moves when somebody is admitted, approved or
             * completed. The attendance rows move when another station marks
             * them, and that leaves the registration untouched — so a delta
             * that only watched registrations would keep re-serving a
             * participant as unmarked long after the door across the room had
             * marked them.
             *
             * Compared with >= rather than >, because MySQL stores these to the
             * second: a row written in the same second as the watermark would
             * otherwise fall through the gap between two deltas. The cost is
             * occasionally re-sending a row the device already has, and the
             * merge is by registration id, so that costs nothing.
             */
            ->when($since !== null, fn ($query) => $query->where(fn ($inner) => $inner
                ->where('registrations.updated_at', '>=', $since)
                ->orWhereHas('attendances', fn ($attendance) => $attendance
                    ->where('attendances.updated_at', '>=', $since))
            ))
            ->orderBy('registered_at')
            ->get();

        $participants = $registrations
            ->map(fn (Registration $registration) => self::participantRow($registration))
            ->values()
            ->all();

        return [
            'training' => [
                'id' => $training->id,
                'title' => $training->title,
                'venue' => $training->venue,
                'duration_days' => max(1, $training->duration_days ?? 1),
                // Whether the station may offer "admit as walk-in" when a valid
                // code turns out not to be on this roster. Shipped in the
                // bundle so the offer is simply absent on a run that was not
                // published for it, rather than present and refused by the
                // server after the operator has already promised somebody a
                // seat. The server checks it again regardless — see
                // WalkInService::admit.
                'accepts_walk_ins' => (bool) $training->accepts_walk_ins,
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
            /*
             * Who stopped counting.
             *
             * A registration cancelled mid-session disappears from the query
             * above — it no longer holds a place — and on a full download that
             * is the whole story, because the device replaces its list. A delta
             * merges instead, so absence says nothing: without this the device
             * would happily go on admitting somebody whose registration was
             * pulled an hour ago. Only meaningful alongside `since`, so it is
             * omitted entirely from a full bundle rather than sent empty.
             */
            ...($since === null ? [] : [
                'removed' => Registration::where($scoped)
                    ->whereNotIn('status', [RegistrationStatus::Approved, RegistrationStatus::Completed])
                    ->where('registrations.updated_at', '>=', $since)
                    ->pluck('id')
                    ->all(),
                // Tells the station to merge rather than replace. Sent as a
                // flag rather than inferred from the request, so a bundle read
                // back out of IndexedDB hours later still knows what it is.
                'partial' => true,
            ]),
            'downloaded_at' => $watermark->toIso8601String(),
        ];
    }

    /**
     * The `since` watermark a station sends to ask for a delta.
     *
     * Lives here rather than on either controller because both doors parse it
     * identically, and this class exists precisely to stop the public station
     * drifting from the staff one.
     *
     * Unparseable input falls back to null — a full bundle — rather than
     * erroring. The station is the party a 422 would strand, and a full
     * download is always a correct answer to "what has changed"; it is merely
     * a more expensive one.
     */
    public static function since(Request $request): ?CarbonImmutable
    {
        $raw = $request->string('since')->toString();

        if ($raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * One participant, in the shape a station recognises.
     *
     * Extracted because a walk-in admitted mid-session has to be added to the
     * device's roster, and the only safe row to add is the one the roster
     * download would itself have produced. Building a second, nearly-identical
     * shape at the walk-in endpoint is how a device ends up with a participant
     * it can display but not match — the digest is the part that has to agree,
     * and agreement is not something to leave to two copies of a hash call.
     *
     * @return array<string, mixed>
     */
    public static function participantRow(Registration $registration): array
    {
        $registration->loadMissing(['user.profile.fieldOffice', 'attendances']);

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

        /*
         * A rehearsal held off the training's calendar still has to say
         * something useful.
         *
         * Proving the phones on a Tuesday for a course that runs next Monday is
         * the normal case, and "not running today" for every scan tests nothing
         * but the off-day guard itself. So a dry run borrows the training's
         * first day, keeping the clock so Present-versus-Late is still
         * exercised. Only ever for a dry run: the guard is the whole point on a
         * live door, where landing a mis-scanned code on day 1 is the failure.
         */
        if ($dryRun && $registration->training->dayNumberFor($at) === null) {
            $first = $registration->training->trainingDays()[0]['date'] ?? null;

            if ($first !== null) {
                $at = $first->setTime((int) $at->format('H'), (int) $at->format('i'), (int) $at->format('s'));
            }
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
