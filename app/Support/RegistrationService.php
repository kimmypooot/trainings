<?php

namespace App\Support;

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
    public static function register(User $user, Training $training): Registration
    {
        return DB::transaction(function () use ($user, $training) {
            $locked = Training::whereKey($training->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isOpenToParticipants()) {
                throw ValidationException::withMessages([
                    'registration' => 'This training is not open for registration.',
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
            if ($existing) {
                $existing->forceFill([
                    'status' => RegistrationStatus::Pending,
                    'registered_at' => now(),
                    'cancelled_at' => null,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'review_remarks' => null,
                ])->save();

                return $existing;
            }

            return Registration::create([
                'user_id' => $user->getKey(),
                'training_id' => $locked->getKey(),
                'status' => RegistrationStatus::Pending,
                'registered_at' => now(),
            ]);
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

        $registration->forceFill([
            'status' => RegistrationStatus::Cancelled,
            'cancelled_at' => now(),
        ])->save();

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

            $registration->forceFill([
                'status' => $decision,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'review_remarks' => $remarks,
            ])->save();

            return $registration;
        });

        // Sent after the transaction commits, so a participant can never be
        // told they are confirmed for a decision that then rolls back.
        $registration->loadMissing(['user', 'training']);
        $registration->user->notify(new RegistrationReviewed($registration));

        return $registration;
    }
}
