<?php

namespace App\Support;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\PaymentReviewed;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Verifying payments.
 *
 * Money decisions are the one place where "who did this and when" matters
 * most, so every transition records the officer and the timestamp, and a
 * rejection is required to carry a reason.
 *
 * Refunds used to live here too; they outgrew it and moved to RefundService.
 */
class PaymentService
{
    /**
     * Verify a payment, recording the official receipt it was issued against.
     *
     * The OR block is what finance reconciles on. It is optional here because
     * a promissory note is verified without one — there is no receipt until
     * the money actually arrives.
     *
     * @param  array{or_number?: ?string, or_date?: ?string}  $receipt
     */
    public static function verify(
        Payment $payment,
        User $officer,
        ?string $remarks = null,
        array $receipt = []
    ): Payment {
        return self::decide($payment, PaymentStatus::Verified, $officer, $remarks, $receipt);
    }

    public static function reject(Payment $payment, User $officer, string $reason): Payment
    {
        return self::decide($payment, PaymentStatus::Rejected, $officer, $reason);
    }

    /**
     * @param  array{or_number?: ?string, or_date?: ?string}  $receipt
     */
    private static function decide(
        Payment $payment,
        PaymentStatus $decision,
        User $officer,
        ?string $reason,
        array $receipt = []
    ): Payment {
        if ($decision === PaymentStatus::Rejected && blank($reason)) {
            throw ValidationException::withMessages([
                'rejection_reason' => 'Give a reason when rejecting a payment.',
            ]);
        }

        $payment = DB::transaction(function () use ($payment, $decision, $officer, $reason, $receipt) {
            $locked = Payment::whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isPending()) {
                throw ValidationException::withMessages([
                    'payment' => "This payment has already been {$locked->status->label()}.",
                ]);
            }

            $issued = $decision === PaymentStatus::Verified && filled($receipt['or_number'] ?? null);

            $locked->forceFill([
                'status' => $decision,
                'verified_by' => $officer->getKey(),
                'verified_at' => now(),
                'rejection_reason' => $decision === PaymentStatus::Rejected ? $reason : null,
                'remarks' => $decision === PaymentStatus::Verified ? $reason : $locked->remarks,
                'or_number' => $issued ? $receipt['or_number'] : $locked->or_number,
                // Defaults to today rather than left null: an OR always has a
                // date, and the officer entering the number is issuing it now.
                'or_date' => $issued ? ($receipt['or_date'] ?? now()->toDateString()) : $locked->or_date,
                // The officer who verified is the one who issued it.
                'collecting_officer_id' => $issued ? $officer->getKey() : $locked->collecting_officer_id,
            ])->save();

            ActivityLogger::recordTransition(
                "payment.{$decision->value}",
                $locked,
                PaymentStatus::Pending,
                $decision,
                sprintf(
                    'PHP %s %s by %s.',
                    number_format((float) $locked->amount, 2),
                    $decision->label(),
                    $officer->name,
                ),
                [
                    'amount' => (float) $locked->amount,
                    'reason' => $reason,
                    // The OR is the number finance reconciles on, so it belongs
                    // in the trail rather than only on the row it stamped.
                    'or_number' => $issued ? $receipt['or_number'] : null,
                ],
                $officer,
            );

            return $locked;
        });

        $payment->loadMissing(['user', 'training']);
        $payment->user->notify(new PaymentReviewed($payment));

        return $payment;
    }
}
