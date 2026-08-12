<?php

namespace App\Support;

use App\Enums\RequestStatus;
use App\Models\CancellationRequest;
use App\Models\Registration;
use App\Models\User;
use App\Notifications\CancellationReviewed;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Withdrawing from a training now goes through review.
 *
 * v3 previously cancelled on the spot. That lost the audit trail and freed the
 * slot silently, which matters when CSC has already catered and printed for a
 * confirmed head count — so the slot is held until staff decide.
 */
class CancellationRequestService
{
    public static function open(Registration $registration, string $reason): CancellationRequest
    {
        return DB::transaction(function () use ($registration, $reason) {
            if (! $registration->status->isCancellable()) {
                throw ValidationException::withMessages([
                    'registration' => "A {$registration->status->label()} registration cannot be withdrawn.",
                ]);
            }

            $open = CancellationRequest::where('registration_id', $registration->getKey())
                ->pending()
                ->lockForUpdate()
                ->exists();

            if ($open) {
                throw ValidationException::withMessages([
                    'registration' => 'You already have a withdrawal request awaiting review.',
                ]);
            }

            return CancellationRequest::create([
                'registration_id' => $registration->getKey(),
                'reason' => $reason,
                'status' => RequestStatus::Pending,
            ]);
        });
    }

    /**
     * Staff decision. Approving performs the actual cancellation, which is what
     * frees the slot.
     */
    public static function review(
        CancellationRequest $request,
        RequestStatus $decision,
        User $reviewer,
        ?string $remarks = null
    ): CancellationRequest {
        if ($decision === RequestStatus::Pending) {
            throw ValidationException::withMessages([
                'decision' => 'That is not a valid review decision.',
            ]);
        }

        $request = DB::transaction(function () use ($request, $decision, $reviewer, $remarks) {
            $locked = CancellationRequest::whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->isPending()) {
                throw ValidationException::withMessages([
                    'decision' => 'This request has already been reviewed.',
                ]);
            }

            $locked->forceFill([
                'status' => $decision,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'review_remarks' => $remarks,
            ])->save();

            if ($decision === RequestStatus::Approved) {
                RegistrationService::cancel($locked->registration);
            }

            return $locked;
        });

        $request->loadMissing('registration.user', 'registration.training');
        $request->registration->user->notify(new CancellationReviewed($request));

        return $request;
    }
}
