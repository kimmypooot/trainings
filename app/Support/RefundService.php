<?php

namespace App\Support;

use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\RefundRequest;
use App\Models\RefundStatusLog;
use App\Models\User;
use App\Notifications\RefundReviewed;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The refund pipeline: filing a claim and walking it out to disbursement.
 *
 * Split out of PaymentService because a refund is a longer-lived thing than a
 * payment — it crosses HRD and MSD, has its own audit trail, and is the only
 * workflow in the app that ends with money leaving the agency. Keeping it
 * beside payment verification made PaymentService the largest service in the
 * codebase for no shared logic.
 *
 * Every transition goes through advance() or reject(), which are the only two
 * places `status` is written. That is what makes the log complete: there is no
 * path to a new stage that does not also record one.
 */
class RefundService
{
    /**
     * File a claim against a verified payment.
     *
     * The payee block is required rather than optional. v1 let a request be
     * filed without one and MSD then had to chase the participant for bank
     * details before anything could move — the request existed but was not
     * actionable, which is the worst of both.
     *
     * @param  array{account_name: string, bank_name: string, account_number: string, proof_path?: ?string}  $payee
     */
    public static function request(
        Payment $payment,
        string $reason,
        array $payee,
        ?float $amount = null
    ): RefundRequest {
        $request = DB::transaction(function () use ($payment, $reason, $payee, $amount) {
            $locked = Payment::with('refundRequests')
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->isRefundable()) {
                throw ValidationException::withMessages([
                    'refund' => 'Only a verified payment can be refunded.',
                ]);
            }

            if ($locked->hasPendingRefund()) {
                throw ValidationException::withMessages([
                    'refund' => 'A refund request for this payment is already in progress.',
                ]);
            }

            if ($locked->hasBeenRefunded()) {
                throw ValidationException::withMessages([
                    'refund' => 'This payment has already been refunded.',
                ]);
            }

            $claimed = $amount ?? (float) $locked->amount;

            if ($claimed <= 0 || $claimed > (float) $locked->amount) {
                throw ValidationException::withMessages([
                    'amount' => 'A refund cannot exceed the amount paid.',
                ]);
            }

            $created = RefundRequest::create([
                'request_code' => RefundRequest::nextRequestCode(),
                'payment_id' => $locked->getKey(),
                'amount' => $claimed,
                'reason' => $reason,
                'account_name' => $payee['account_name'],
                'bank_name' => $payee['bank_name'],
                'account_number' => $payee['account_number'],
                'proof_path' => $payee['proof_path'] ?? null,
                'status' => RefundStatus::ForReview,
            ]);

            self::log($created, null, RefundStatus::ForReview, null, 'Request filed by participant.');

            return $created;
        });

        return $request;
    }

    /**
     * Move a request one stage forward.
     *
     * The target is passed explicitly even though RefundStatus::next() could
     * derive it — the officer's screen sends what it displayed, and checking
     * that against the pipeline is what stops a stale tab from advancing a
     * request someone else already moved.
     */
    public static function advance(
        RefundRequest $request,
        RefundStatus $target,
        User $officer,
        ?string $notes = null
    ): RefundRequest {
        if ($target === RefundStatus::Rejected) {
            throw ValidationException::withMessages([
                'status' => 'Declining a refund needs a reason — use the decline action.',
            ]);
        }

        $request = DB::transaction(function () use ($request, $target, $officer, $notes) {
            $locked = RefundRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status->isTerminal()) {
                throw ValidationException::withMessages([
                    'status' => "This refund is already {$locked->status->label()}.",
                ]);
            }

            if (! $locked->status->canAdvanceTo($target)) {
                throw ValidationException::withMessages([
                    'status' => "A refund at {$locked->status->label()} cannot move straight to {$target->label()}.",
                ]);
            }

            $from = $locked->status;

            $locked->forceFill([
                'status' => $target,
                // The last officer to touch it, for the list view. The full
                // chain of who did what lives in the log.
                'reviewed_by' => $officer->getKey(),
                'reviewed_at' => now(),
                'review_remarks' => $notes,
                // Set only when the money has actually gone out.
                'refunded_at' => $target === RefundStatus::Refunded ? now() : $locked->refunded_at,
            ])->save();

            self::log($locked, $from, $target, $officer, $notes);

            // v1 flipped the registration's payment status here so the roster
            // stopped showing the participant as paid. The same has to happen
            // now that the payment is no longer money CSC holds.
            if ($target === RefundStatus::Refunded) {
                $locked->loadMissing('payment');
                $locked->payment->forceFill(['remarks' => trim(
                    ($locked->payment->remarks ? $locked->payment->remarks.' ' : '')
                    ."Refunded in full under {$locked->request_code}."
                )])->save();
            }

            return $locked;
        });

        self::notify($request);

        return $request;
    }

    /**
     * Decline a claim. Reachable from any open stage — MSD can bounce a
     * request back that HRD already passed, and pretending otherwise would
     * leave those stuck open forever.
     */
    public static function reject(RefundRequest $request, User $officer, string $reason): RefundRequest
    {
        if (blank($reason)) {
            throw ValidationException::withMessages([
                'rejection_reason' => 'Give a reason when declining a refund.',
            ]);
        }

        $request = DB::transaction(function () use ($request, $officer, $reason) {
            $locked = RefundRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status->isTerminal()) {
                throw ValidationException::withMessages([
                    'status' => "This refund is already {$locked->status->label()}.",
                ]);
            }

            $from = $locked->status;

            $locked->forceFill([
                'status' => RefundStatus::Rejected,
                'reviewed_by' => $officer->getKey(),
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            self::log($locked, $from, RefundStatus::Rejected, $officer, $reason);

            return $locked;
        });

        self::notify($request);

        return $request;
    }

    /**
     * Two records, deliberately.
     *
     * `refund_status_logs` is what the participant and the officers read on the
     * refund itself — it is part of the feature, ordered and rendered as a
     * trail on screen. The activity log is the cross-cutting one, where a
     * refund sits alongside the payment it came from and the registration
     * behind that. Folding either into the other would cost one of those two
     * readings.
     */
    private static function log(
        RefundRequest $request,
        ?RefundStatus $from,
        RefundStatus $to,
        ?User $actor,
        ?string $notes
    ): void {
        RefundStatusLog::create([
            'refund_request_id' => $request->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'notes' => $notes,
            'changed_by' => $actor?->getKey(),
            'changed_at' => now(),
        ]);

        ActivityLogger::recordTransition(
            "refund.{$to->value}",
            $request,
            $from,
            $to,
            sprintf(
                '%s: %s.',
                $request->request_code,
                $from === null ? 'filed' : "moved to {$to->label()}",
            ),
            ['amount' => (float) $request->amount, 'notes' => $notes],
            $actor,
        );
    }

    private static function notify(RefundRequest $request): void
    {
        $request->loadMissing('payment.user');
        $request->payment->user->notify(new RefundReviewed($request));
    }
}
