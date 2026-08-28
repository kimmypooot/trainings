<?php

namespace App\Support;

use App\Enums\RequestStatus;
use App\Models\RegistrationOutput;
use App\Models\User;
use App\Notifications\OutputReviewed;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff review of a submitted post-training output.
 *
 * Mirrors CancellationRequestService::review: locked, re-review is rejected,
 * and the decision is audited. Before this existed the decision was written
 * straight from the controller, so a double-click (or two admins reviewing
 * the same submission at once) could silently overwrite an earlier decision
 * with no trace of it — the same failure this pattern already prevents for
 * cancellations, training requests, agency requests and physical-OR requests.
 */
class RegistrationOutputService
{
    public static function review(
        RegistrationOutput $output,
        RequestStatus $decision,
        User $reviewer,
        ?string $remarks = null
    ): RegistrationOutput {
        if ($decision === RequestStatus::Pending) {
            throw ValidationException::withMessages([
                'decision' => 'That is not a valid review decision.',
            ]);
        }

        $output = DB::transaction(function () use ($output, $decision, $reviewer, $remarks) {
            $locked = RegistrationOutput::whereKey($output->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->isPending()) {
                throw ValidationException::withMessages([
                    'decision' => 'This output has already been reviewed.',
                ]);
            }

            $locked->forceFill([
                'status' => $decision,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'review_remarks' => $remarks,
            ])->save();

            ActivityLogger::recordTransition(
                "output.{$decision->value}",
                $locked,
                RequestStatus::Pending,
                $decision,
                "Output \"{$locked->title}\" {$decision->label()} by {$reviewer->name}.",
                ['registration_id' => $locked->registration_id, 'remarks' => $remarks],
                $reviewer,
            );

            return $locked;
        });

        $output->loadMissing('registration.user', 'registration.training');
        $output->registration->user->notify(new OutputReviewed($output));

        return $output;
    }
}
