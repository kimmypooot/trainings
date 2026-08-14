<?php

namespace App\Support;

use App\Enums\ChargeTo;
use App\Enums\RegistrationStatus;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Notifications\RegistrationReviewed;
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
