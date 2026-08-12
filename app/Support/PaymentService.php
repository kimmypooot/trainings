<?php

namespace App\Support;

use App\Enums\PaymentStatus;
use App\Enums\RequestStatus;
use App\Models\Payment;
use App\Models\RefundRequest;
use App\Models\User;
use App\Notifications\PaymentReviewed;
use App\Notifications\RefundReviewed;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Verifying payments and settling refunds.
 *
 * Money decisions are the one place where "who did this and when" matters
 * most, so every transition records the officer and the timestamp, and a
 * rejection is required to carry a reason.
 */
class PaymentService
{
    public static function verify(Payment $payment, User $officer, ?string $remarks = null): Payment
    {
        return self::decide($payment, PaymentStatus::Verified, $officer, $remarks);
    }

    public static function reject(Payment $payment, User $officer, string $reason): Payment
    {
        return self::decide($payment, PaymentStatus::Rejected, $officer, $reason);
    }

    private static function decide(
        Payment $payment,
        PaymentStatus $decision,
        User $officer,
        ?string $reason
    ): Payment {
        if ($decision === PaymentStatus::Rejected && blank($reason)) {
            throw ValidationException::withMessages([
                'rejection_reason' => 'Give a reason when rejecting a payment.',
            ]);
        }

        $payment = DB::transaction(function () use ($payment, $decision, $officer, $reason) {
            $locked = Payment::whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isPending()) {
                throw ValidationException::withMessages([
                    'payment' => "This payment has already been {$locked->status->label()}.",
                ]);
            }

            $locked->forceFill([
                'status' => $decision,
                'verified_by' => $officer->getKey(),
                'verified_at' => now(),
                'rejection_reason' => $decision === PaymentStatus::Rejected ? $reason : null,
                'remarks' => $decision === PaymentStatus::Verified ? $reason : $locked->remarks,
            ])->save();

            return $locked;
        });

        $payment->loadMissing(['user', 'training']);
        $payment->user->notify(new PaymentReviewed($payment));

        return $payment;
    }

    /**
     * Open a refund claim against a verified payment.
     */
    public static function requestRefund(Payment $payment, string $reason, ?float $amount = null): RefundRequest
    {
        return DB::transaction(function () use ($payment, $reason, $amount) {
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
                    'refund' => 'A refund request for this payment is already awaiting review.',
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

            return RefundRequest::create([
                'payment_id' => $locked->getKey(),
                'amount' => $claimed,
                'reason' => $reason,
                'status' => RequestStatus::Pending,
            ]);
        });
    }

    public static function reviewRefund(
        RefundRequest $request,
        RequestStatus $decision,
        User $officer,
        ?string $remarks = null
    ): RefundRequest {
        if ($decision === RequestStatus::Pending) {
            throw ValidationException::withMessages([
                'decision' => 'That is not a valid review decision.',
            ]);
        }

        if ($decision === RequestStatus::Rejected && blank($remarks)) {
            throw ValidationException::withMessages([
                'remarks' => 'Give a reason when declining a refund.',
            ]);
        }

        $request = DB::transaction(function () use ($request, $decision, $officer, $remarks) {
            $locked = RefundRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isPending()) {
                throw ValidationException::withMessages([
                    'decision' => 'This refund request has already been reviewed.',
                ]);
            }

            $locked->forceFill([
                'status' => $decision,
                'reviewed_by' => $officer->getKey(),
                'reviewed_at' => now(),
                'review_remarks' => $remarks,
                // Marks the money as actually returned, not merely approved.
                'refunded_at' => $decision === RequestStatus::Approved ? now() : null,
            ])->save();

            return $locked;
        });

        $request->loadMissing('payment.user');
        $request->payment->user->notify(new RefundReviewed($request));

        return $request;
    }
}
