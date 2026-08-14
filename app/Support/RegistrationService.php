<?php

namespace App\Support;

use App\Enums\ChargeTo;
use App\Enums\RegistrationStatus;
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
     */
    /**
     * @param  array{charge_to?: ?ChargeTo, needs_certificate?: bool, supporting_document_path?: ?string}  $details
     */
    public static function register(User $user, Training $training, array $details = []): Registration
    {
        return DB::transaction(function () use ($user, $training, $details) {
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

            $existing = Registration::where('user_id', $user->getKey())
                ->where('training_id', $locked->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing?->isActive()) {
                throw ValidationException::withMessages([
                    'registration' => 'You are already registered for this training.',
                ]);
            }

            if ($locked->isFull()) {
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
            ];

            if ($existing) {
                $existing->forceFill([
                    'status' => RegistrationStatus::Pending,
                    'registered_at' => now(),
                    'cancelled_at' => null,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'review_remarks' => null,
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
                'status' => RegistrationStatus::Pending,
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
     * Withdraw from a training, freeing the slot.
     *
     * Cancellation stays participant-initiated and immediate — v2 routes this
     * through an approval workflow, but that was deliberately kept instant here.
     */
    public static function cancel(Registration $registration): Registration
    {
        if (! $registration->status->isCancellable()) {
            throw ValidationException::withMessages([
                'registration' => "A {$registration->status->label()} registration cannot be cancelled.",
            ]);
        }

        $from = $registration->status;

        $registration->forceFill([
            'status' => RegistrationStatus::Cancelled,
            'cancelled_at' => now(),
        ])->save();

        ActivityLogger::recordTransition(
            'registration.cancelled',
            $registration,
            $from,
            RegistrationStatus::Cancelled,
            'Registration cancelled, slot released.',
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

                if ($registration->training_id === $locked->getKey()) {
                    $skipped[] = "{$name} (already on this training)";

                    continue;
                }

                if (in_array($registration->user_id, $alreadyThere, true)) {
                    $skipped[] = "{$name} (already registered for the target)";

                    continue;
                }

                if (! $registration->status->isCancellable()) {
                    $skipped[] = "{$name} ({$registration->status->label()})";

                    continue;
                }

                // Counted as it goes rather than checked once up front: the
                // batch may be larger than the room left, and stopping at the
                // limit moves whoever fits instead of refusing everyone.
                if ($registration->status->occupiesSlot() && $remaining <= 0) {
                    $skipped[] = "{$name} (target is full)";

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
