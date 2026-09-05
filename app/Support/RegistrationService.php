<?php

namespace App\Support;

use App\Enums\ChargeTo;
use App\Enums\RegistrationStatus;
use App\Enums\SupervisoryDocumentStatus;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Notifications\RegistrationReviewed;
use App\Notifications\RegistrationTransferred;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegistrationService
{
    /**
     * Register a participant, enforcing capacity and the duplicate guard in one
     * transaction.
     *
     * The training row is locked first so two people claiming the last slot at
     * the same moment cannot both pass the capacity check — without the lock,
     * both read "1 slot left" before either writes.
     *
     * $walkIn admits somebody who is already at the venue.
     *
     * It relaxes exactly two guards — the registration window and the capacity
     * cap — and nothing else. In particular a walk-in is still refused when the
     * training is not published, when the participant is barred from a
     * supervisory course, when that course needs a supporting document they
     * cannot produce at a door, and when they are already registered. Those are
     * statements about whether this person may attend at all, and standing in
     * the room is not an argument against any of them.
     *
     * @param  array{charge_to?: ?ChargeTo, needs_certificate?: bool, supporting_document_path?: ?string}  $details
     */
    public static function register(
        User $user,
        Training $training,
        array $details = [],
        bool $walkIn = false
    ): Registration {
        return DB::transaction(function () use ($user, $training, $details, $walkIn) {
            $locked = Training::whereKey($training->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isOpenToParticipants()) {
                throw ValidationException::withMessages([
                    'registration' => 'This training is not open for registration.',
                ]);
            }

            // Checked here rather than only in the controller: a supervisory
            // course is the one training where being registered at all is a
            // claim about the participant's job, and the service is the only
            // path every caller shares.
            if (SupervisoryEligibility::isBarred($locked, $user)) {
                throw ValidationException::withMessages([
                    'registration' => SupervisoryEligibility::barredMessage(),
                ]);
            }

            if (SupervisoryEligibility::requiresSupportingDocument($locked, $user)
                && blank($details['supporting_document_path'] ?? null)) {
                throw ValidationException::withMessages([
                    'supporting_document' => 'Proof of your supervisory function is required for this course.',
                ]);
            }

            /*
             * The registration window is the first thing a walk-in is, by
             * definition, outside of — the deadline has passed, because the
             * training is already running. Skipping the check is the whole
             * point of admitting one, so it is skipped here rather than by
             * loosening registrationHasClosed(), which would also reopen
             * self-service registration to everybody on the internet.
             */
            if (! $walkIn) {
                if (! $locked->registrationHasOpened()) {
                    throw ValidationException::withMessages([
                        'registration' => 'Registration for this training has not opened yet.',
                    ]);
                }

                if ($locked->registrationHasClosed()) {
                    throw ValidationException::withMessages([
                        'registration' => 'Registration for this training has closed.',
                    ]);
                }
            }

            $existing = Registration::where('user_id', $user->getKey())
                ->where('training_id', $locked->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing?->isActive()) {
                throw ValidationException::withMessages([
                    'registration' => 'You are already registered for this training.',
                ]);
            }

            /*
             * Capacity stops a registration, but it does not stop a walk-in.
             *
             * The office's call, and it is the right one: the person is
             * physically standing in the room. Refusing the record would not
             * send them home, it would only mean the room holds someone the
             * system cannot see — no attendance, no certificate, and a seat
             * count that reads as correct while being wrong. Admitting them
             * over the cap keeps the register honest about who is present.
             *
             * The cost is that the organiser has to *know*, immediately, so
             * they can call for more chairs and meals. WalkInService measures
             * the overrun before this runs and reports it back to the desk;
             * this is also written to the activity log, so "we went twelve
             * over on day one" survives past the event.
             */
            if (! $walkIn && $locked->isFull()) {
                throw ValidationException::withMessages([
                    'registration' => 'This training is already full.',
                ]);
            }

            // Re-registering after a cancellation reuses the same row, which is
            // what keeps the unique constraint usable as the duplicate guard.
            // A fresh submission goes back to pending review.
            $submitted = [
                'charge_to' => $details['charge_to'] ?? null,
                'needs_certificate' => $details['needs_certificate'] ?? true,
                // Keep whatever was attached this time; a re-registration that
                // omits the file falls back to the one already on record
                // rather than silently dropping it.
                'supporting_document_path' => $details['supporting_document_path']
                    ?? $existing?->supporting_document_path,
                'supervisory_document_status' => self::documentStatusFor(
                    $locked,
                    $user,
                    $details['supporting_document_path'] ?? $existing?->supporting_document_path,
                ),
                'is_walk_in' => $walkIn,
            ];

            /*
             * A free training is first come, first served.
             *
             * On a paid run the slot is confirmed when the fee is settled — see
             * PaymentService::confirmSlotOnSettlement(). A free run has no fee
             * to settle, so there is nothing left to wait for: capacity has
             * been dealt with above — checked, or deliberately waived for a
             * walk-in — and holding the registration at pending would only
             * mean somebody rubber-stamping a queue.
             *
             * Staff keep the last word either way. A registration approved this
             * way can still be cancelled from the roster, with a reason, which
             * is the honest way to remove someone rather than never letting
             * them in.
             */
            $initial = $locked->payment_required
                ? RegistrationStatus::Pending
                : RegistrationStatus::Approved;

            if ($existing) {
                $existing->forceFill([
                    'status' => $initial,
                    'registered_at' => now(),
                    'cancelled_at' => null,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'review_remarks' => null,
                    'supervisory_document_reviewed_by' => null,
                    'supervisory_document_reviewed_at' => null,
                    'supervisory_document_remarks' => null,
                    ...$submitted,
                ])->save();

                ActivityLogger::record(
                    'registration.reopened',
                    $existing,
                    "Re-registered for {$locked->title}.",
                    ['training_id' => $locked->getKey()],
                    $user,
                );

                return $existing;
            }

            $registration = Registration::create([
                'user_id' => $user->getKey(),
                'training_id' => $locked->getKey(),
                'status' => $initial,
                'registered_at' => now(),
                ...$submitted,
            ]);

            ActivityLogger::record(
                'registration.created',
                $registration,
                "Registered for {$locked->title}.",
                [
                    'training_id' => $locked->getKey(),
                    'charge_to' => $submitted['charge_to'] instanceof ChargeTo
                        ? $submitted['charge_to']->value
                        : $submitted['charge_to'],
                ],
                $user,
            );

            return $registration;
        });
    }

    /**
     * The document's starting status for a (re-)registration.
     *
     * Only the middle grade band is ever asked to attach anything, so everyone
     * else gets null — no document, no status. A band participant who attached
     * the file goes straight to Submitted; one who somehow reached the roster
     * without it is flagged as Document Required.
     */
    private static function documentStatusFor(
        Training $training,
        User $user,
        ?string $documentPath,
    ): ?SupervisoryDocumentStatus {
        if (! SupervisoryEligibility::requiresSupportingDocument($training, $user)) {
            return null;
        }

        return blank($documentPath)
            ? SupervisoryDocumentStatus::Required
            : SupervisoryDocumentStatus::Submitted;
    }

    /**
     * Withdraw from a training, freeing the slot.
     *
     * Cancellation stays participant-initiated and immediate — v2 routes this
     * through an approval workflow, but that was deliberately kept instant here.
     *
     * `$staff` is the other caller: v1's registration-details page let HRD
     * cancel a registration outright, which is what the office does when a
     * participant phones in, or when a duplicate or a no-show has to be cleared
     * so the slot can go to someone else. v2 could only *review* a
     * participant-filed cancellation request, leaving no way to start one. The
     * reason lands in `review_remarks` rather than a column of its own: it is
     * the same field the roster already shows against a decision, and the one
     * UndoService captures, so a taken-back cancellation restores it too.
     */
    public static function cancel(
        Registration $registration,
        ?User $staff = null,
        ?string $reason = null
    ): Registration {
        if (! $registration->status->isCancellable()) {
            throw ValidationException::withMessages([
                'registration' => "A {$registration->status->label()} registration cannot be cancelled.",
            ]);
        }

        $from = $registration->status;

        $registration->forceFill([
            'status' => RegistrationStatus::Cancelled,
            'cancelled_at' => now(),
            ...($staff ? [
                'reviewed_by' => $staff->getKey(),
                'reviewed_at' => now(),
                'review_remarks' => $reason,
            ] : []),
        ])->save();

        ActivityLogger::recordTransition(
            'registration.cancelled',
            $registration,
            $from,
            RegistrationStatus::Cancelled,
            $staff
                ? "Registration cancelled by {$staff->name}, slot released."
                : 'Registration cancelled, slot released.',
            $staff ? ['reason' => $reason] : [],
            $staff,
        );

        return $registration;
    }

    /**
     * Move registrations to another training, ported from v1's
     * `transfer-participants.php`.
     *
     * The commonest real cause is a run being rescheduled or split, and doing
     * it by hand means cancelling and re-registering everyone — which loses the
     * original registration date, the attendance already recorded against it,
     * and any payment attached.
     *
     * The whole batch is checked against the target's capacity before anything
     * moves, and each row is checked individually: a participant already
     * registered for the target is skipped rather than failing the batch, since
     * the point is usually to move whoever can be moved.
     *
     * Two of v1's behaviours are deliberately not carried over. It appended a
     * transfer note to `remarks`, which in v2 would overwrite a reviewer's own
     * remarks — activity_logs records the move properly now. And it offered a
     * "reset payment" option that cleared `or_number` and `or_date`, destroying
     * the receipt record for money that had actually been received; payments
     * follow the registration instead.
     *
     * @param  array<int, int>  $registrationIds
     * @return array{moved: int, skipped: array<int, string>}
     */
    public static function transfer(
        array $registrationIds,
        Training $target,
        User $staff,
        string $reason,
        ?int $fieldOfficeId = null,
    ): array {
        return DB::transaction(function () use ($registrationIds, $target, $staff, $reason, $fieldOfficeId) {
            $locked = Training::whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isOpenToParticipants()) {
                throw ValidationException::withMessages([
                    'target' => 'Participants can only be moved into a training that is open.',
                ]);
            }

            /*
             * Re-resolved rather than taken on trust, and scoped the same way
             * the roster is: a selection posted from a page must not become a
             * way to move a registration the staff member cannot even see.
             */
            $registrations = Registration::with(['user', 'training'])
                ->whereIn('id', $registrationIds)
                ->when($fieldOfficeId !== null, fn ($query) => $query->whereHas(
                    'user.profile',
                    fn ($profile) => $profile->where('field_office_id', $fieldOfficeId)
                ))
                /*
                 * The same order the preview walks, and for the same reason.
                 *
                 * Seats in the target are handed out as this loop advances, so
                 * the order *is* the allocation rule. This had none at all —
                 * it took whatever the engine returned — while
                 * RescheduleService::affected() sorted by registered_at to
                 * promise "first registered, first seated". Against a target
                 * with fewer seats than the batch, the two disagreed about who
                 * got them: the screen named one set of people as movable and
                 * the transfer moved another, reporting the difference as
                 * skipped. Somebody then stays on a run that is not happening,
                 * having been shown a list that said otherwise.
                 *
                 * `id` breaks the tie because `registered_at` is only
                 * second-resolution: two people registering in the same second
                 * is ordinary, and without a tiebreak the sort is not a total
                 * order and the two sides can still diverge.
                 */
                ->orderBy('registered_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // Everyone already holding a slot in the target, so a participant
            // is never moved into a training they are already on.
            $alreadyThere = Registration::where('training_id', $locked->getKey())
                ->whereIn('user_id', $registrations->pluck('user_id'))
                ->pluck('user_id')
                ->all();

            $remaining = $locked->capacity === null
                ? PHP_INT_MAX
                : max(0, $locked->capacity - Registration::where('training_id', $locked->getKey())
                    ->whereIn('status', RegistrationStatus::occupying())
                    ->count());

            $moved = 0;
            $skipped = [];

            foreach ($registrations as $registration) {
                $name = $registration->user->name;

                // Counted as it goes rather than checked once up front: the
                // batch may be larger than the room left, and stopping at the
                // limit moves whoever fits instead of refusing everyone.
                $blocker = self::transferBlocker($registration, $locked, $alreadyThere, $remaining);

                if ($blocker !== null) {
                    $skipped[] = "{$name} ({$blocker})";

                    continue;
                }

                $from = $registration->training;

                $registration->forceFill(['training_id' => $locked->getKey()])->save();

                // Payments belong to the registration, so they travel with it.
                // The fee difference is recorded rather than silently absorbed
                // — finance reconciles against it.
                $registration->payments()->update(['training_id' => $locked->getKey()]);

                if ($registration->status->occupiesSlot()) {
                    $remaining--;
                }

                ActivityLogger::record(
                    'registration.transferred',
                    $registration,
                    "{$name} moved from “{$from->title}” to “{$locked->title}”. Reason: {$reason}",
                    [
                        'from_training_id' => $from->getKey(),
                        'to_training_id' => $locked->getKey(),
                        'reason' => $reason,
                        // Null when neither run charges; a non-zero value is
                        // what finance needs to chase.
                        'fee_difference' => (float) ($locked->payment_amount ?? 0)
                            - (float) ($from->payment_amount ?? 0),
                    ],
                    $staff,
                );

                $registration->user->notify(new RegistrationTransferred($registration, $from, $reason));

                $moved++;
            }

            return ['moved' => $moved, 'skipped' => $skipped];
        });
    }

    /**
     * Why this registration cannot be moved onto the target, or null if it can.
     *
     * Pulled out of transfer()'s loop so that the affected-participants list can
     * ask the same question *before* anything moves and get the same answer.
     * The alternative — a screen with its own idea of who is movable — is worse
     * than no screen at all: it would show a clean list of twenty names, move
     * fourteen, and report the other six as skipped in a flash message nobody
     * reads, which is exactly how somebody stays on a run that will not happen.
     *
     * The reason is returned as the phrase that goes in the parentheses, so the
     * preview and the outcome are worded identically too.
     *
     * $remaining is passed in rather than derived because the caller is
     * counting it down across a batch — see transfer(). A preview showing every
     * row against the *same* remaining count would call the whole batch movable
     * when only the first few fit, so a caller previewing a list has to decrement
     * it the same way.
     *
     * @param  array<int, int>  $alreadyThere  user ids already holding a slot on the target
     */
    public static function transferBlocker(
        Registration $registration,
        Training $target,
        array $alreadyThere,
        int $remaining,
    ): ?string {
        if ($registration->training_id === $target->getKey()) {
            return 'already on this training';
        }

        if (in_array($registration->user_id, $alreadyThere, true)) {
            return 'already registered for the target';
        }

        if (! $registration->status->isCancellable()) {
            return $registration->status->label();
        }

        if ($registration->status->occupiesSlot() && $remaining <= 0) {
            return 'target is full';
        }

        return null;
    }

    /**
     * HRD decision on a pending registration.
     *
     * Approving is checked against capacity again: slots can fill between
     * submission and review, and an approval must not overshoot the limit.
     */
    public static function review(
        Registration $registration,
        RegistrationStatus $decision,
        User $reviewer,
        ?string $remarks = null
    ): Registration {
        $allowed = [
            RegistrationStatus::Approved,
            RegistrationStatus::Waitlisted,
            RegistrationStatus::Rejected,
        ];

        if (! in_array($decision, $allowed, true)) {
            throw ValidationException::withMessages([
                'registration' => 'That is not a valid review decision.',
            ]);
        }

        $registration = DB::transaction(function () use ($registration, $decision, $reviewer, $remarks) {
            $training = Training::whereKey($registration->training_id)->lockForUpdate()->firstOrFail();

            if ($decision === RegistrationStatus::Approved
                && ! $registration->status->occupiesSlot()
                && $training->isFull()) {
                throw ValidationException::withMessages([
                    'registration' => 'This training is full — waitlist the participant instead.',
                ]);
            }

            $from = $registration->status;

            $registration->forceFill([
                'status' => $decision,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'review_remarks' => $remarks,
            ])->save();

            ActivityLogger::recordTransition(
                "registration.{$decision->value}",
                $registration,
                $from,
                $decision,
                "Registration {$decision->label()} by {$reviewer->name}.",
                ['remarks' => $remarks, 'training_id' => $training->getKey()],
                $reviewer,
            );

            return $registration;
        });

        /*
         * Sent after the transaction commits, so a participant can never be
         * told they are confirmed for a decision that then rolls back.
         *
         * Held back for the length of the undo window on top of that: a
         * decision the reviewer takes back within seconds should never have
         * reached the participant at all. The notification re-checks the
         * decision before it delivers, so an undo leaves nothing to read.
         */
        $registration->loadMissing(['user', 'training']);
        $registration->user->notify(
            (new RegistrationReviewed($registration))->delay(now()->addSeconds(UndoService::WINDOW_SECONDS))
        );

        return $registration;
    }
}
