<?php

namespace App\Support;

use App\Enums\PaymentStatus;
use App\Enums\RegistrationStatus;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\Training;
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
     * The PRIME-HRM incentive, as a fraction of the course fee.
     *
     * A constant rather than a row in payment_settings alongside the bank
     * details: those are clerical and change when an account changes, while a
     * discount rate is policy. Changing it should take a deploy and a review,
     * not a form submission.
     */
    public const PRIME_HRM_RATE = 0.20;

    /**
     * What a PRIME-HRM participant pays for this training, and what CSC forgoes.
     *
     * The single place the arithmetic happens, so no caller can compute its own
     * and disagree. The net is derived by *subtraction* rather than by a second
     * multiplication, which is what makes `gross − discount = net` true by
     * construction instead of true by luck.
     *
     * No rounding rule: course fees are always round figures that divide by 5,
     * so 20% of one is exact to the centavo. Inventing a rounding policy for a
     * case that cannot arise would be inventing behaviour nobody can check.
     *
     * @return array{gross: float, discount: float, net: float}
     */
    public static function primeHrmBreakdown(Training $training): array
    {
        $gross = round((float) $training->payment_amount, 2);
        $discount = round($gross * self::PRIME_HRM_RATE, 2);

        return [
            'gross' => $gross,
            'discount' => $discount,
            'net' => round($gross - $discount, 2),
        ];
    }

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
        array $receipt = [],
        bool $primeHrmDiscount = false
    ): Payment {
        if ($primeHrmDiscount) {
            self::applyPrimeHrmDiscount($payment);
        }

        $payment = self::decide($payment, PaymentStatus::Verified, $officer, $remarks, $receipt);

        self::confirmSlotOnSettlement($payment, $officer);

        return $payment;
    }

    /**
     * A settled fee confirms the slot.
     *
     * The office's rule: once a field office or HRD has accepted the money —
     * or the promissory note standing in for it — the participant is on the
     * final roster, and nobody should have to approve them a second time. The
     * human decision is still there; it has just moved to the point where the
     * payment is verified, which is where a person actually looks at the case.
     *
     * Deliberately narrow:
     *
     * - Pending only. A waitlisted registration holds no slot, so approving it
     *   here would put the training over capacity; the office has to decide
     *   whether to make room. Rejected and cancelled are decisions someone
     *   made on purpose, and paying must not quietly reverse them — which
     *   matters because the participant's payment screen still lists those
     *   registrations as owing.
     * - Runs after the payment is committed, and never throws. The money is a
     *   fact once verified; a registration that cannot be approved leaves the
     *   payment standing and waits for a human, rather than failing the
     *   verification the officer just made.
     * - Free trainings have no payment to verify, so they are still approved
     *   by hand. That is not an oversight — there is nothing to settle.
     */
    private static function confirmSlotOnSettlement(Payment $payment, User $officer): void
    {
        $registration = $payment->loadMissing('registration')->registration;

        if ($registration?->status !== RegistrationStatus::Pending) {
            return;
        }

        RegistrationService::review(
            $registration,
            RegistrationStatus::Approved,
            $officer,
            'Approved automatically when the fee was settled.',
        );
    }

    /**
     * Record that an already-tendered payment was made under the discount.
     *
     * Unlike a counter payment, the amount here is a fact — the money has
     * already moved — so ticking the box does not change it. It records *why*
     * the payment is short of the full fee, and refuses when the two do not
     * reconcile: a participant who paid something other than the discounted
     * price has a discrepancy the officer needs to see, not a note to paper
     * over it with.
     */
    private static function applyPrimeHrmDiscount(Payment $payment): void
    {
        $payment->loadMissing('training');

        if (! $payment->training->payment_required) {
            throw ValidationException::withMessages([
                'prime_hrm_discount' => 'This training has no fee, so there is nothing to discount.',
            ]);
        }

        $breakdown = self::primeHrmBreakdown($payment->training);

        if (round((float) $payment->amount, 2) !== $breakdown['net']) {
            throw ValidationException::withMessages([
                'prime_hrm_discount' => sprintf(
                    'A PRIME-HRM payment for this training should be PHP %s, but this one is for PHP %s. Resolve the difference before applying the discount.',
                    number_format($breakdown['net'], 2),
                    number_format((float) $payment->amount, 2),
                ),
            ]);
        }

        $payment->forceFill([
            'prime_hrm_discount' => true,
            'discount_amount' => $breakdown['discount'],
        ])->save();
    }

    public static function reject(Payment $payment, User $officer, string $reason): Payment
    {
        return self::decide($payment, PaymentStatus::Rejected, $officer, $reason);
    }

    /**
     * A participant submitting proof of payment (or a promissory note) online.
     *
     * Locked and guarded the same way RefundService and PhysicalOrRequestService
     * guard their own "one open claim at a time" rule: without it, a double
     * form submission (double-click, slow response resubmitted, two tabs)
     * lands two pending payments on the same registration, and nothing stops
     * a collecting officer from verifying both — double-counting the fee in
     * RevenueService's sum and satisfying hasClearedFee() twice over for no
     * reason.
     *
     * @param  array{amount: mixed, payment_method: string, payment_date: mixed,
     *               reference_number?: ?string, proof_path?: ?string}  $data
     */
    public static function submit(Registration $registration, array $data): Payment
    {
        return DB::transaction(function () use ($registration, $data) {
            $locked = Registration::whereKey($registration->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->loadMissing(['training', 'payments']);

            // hasClearedFee(), not hasSettledFee(): a verified promissory note
            // must still be payable online, the same way recordAtCounter()
            // lets staff clear one at the counter. Only an actually-cleared
            // fee is a reason to refuse a second payment.
            if ($locked->hasClearedFee()) {
                throw ValidationException::withMessages([
                    'payment' => 'This registration has already been paid.',
                ]);
            }

            $hasPending = $locked->payments->contains(
                fn (Payment $payment) => $payment->status === PaymentStatus::Pending
            );

            if ($hasPending) {
                throw ValidationException::withMessages([
                    'payment' => 'You already have a payment awaiting verification for this registration.',
                ]);
            }

            return Payment::create([
                'registration_id' => $locked->getKey(),
                'user_id' => $locked->user_id,
                'training_id' => $locked->training_id,
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'],
                'reference_number' => $data['reference_number'] ?? null,
                'payment_date' => $data['payment_date'],
                'proof_path' => $data['proof_path'] ?? null,
            ]);
        });
    }

    /**
     * Record a payment taken at the counter, already verified.
     *
     * v1's `payment-actions.php` let staff set a registration's payment status
     * to "paid" outright, typing in the OR number, its date, and the collecting
     * officer. That is not an edge case: the participant paid cash at the desk
     * and walked away with the receipt, so there is nothing for them to upload
     * and nothing to review. v2 had no way to express it — the whole payments
     * screen only reviews what a participant submitted — which left the office
     * unable to record over-the-counter money at all.
     *
     * The payment is created pending and then goes through decide() like any
     * other, deliberately: this adds a way to *reach* verified, not a second
     * definition of it, so the activity trail, the participant's notification,
     * and the OR stamp are identical to a counter-verified upload.
     *
     * @param  array{amount: mixed, payment_method: string, payment_date: mixed,
     *               reference_number?: ?string, or_number?: ?string, or_date?: ?string,
     *               collecting_officer_id?: int|null, remarks?: ?string,
     *               prime_hrm_discount?: bool}  $data
     */
    public static function recordAtCounter(Registration $registration, User $officer, array $data): Payment
    {
        $registration->loadMissing(['training', 'payments']);

        if (! $registration->training->payment_required) {
            throw ValidationException::withMessages([
                'payment' => 'This training has no fee to collect.',
            ]);
        }

        /*
         * Cleared, not merely settled.
         *
         * A promissory note settles the registration — it holds the slot — but
         * the money has not arrived, and the commonest way it arrives is the
         * participant walking up to the counter with cash. Refusing that
         * because the note "already settled" would leave the office unable to
         * record the very payment the note was written against, and the
         * certificate withheld forever. Only an actually-paid fee is a reason
         * to turn a second payment away.
         */
        if ($registration->hasClearedFee()) {
            throw ValidationException::withMessages([
                'payment' => 'This registration has already been paid.',
            ]);
        }

        /*
         * The PRIME-HRM discount overrides whatever amount was posted rather
         * than trusting it. The officer ticks a box; the figures are the
         * office's to compute, and an amount that arrived over the wire is one
         * that could have been edited on the way.
         */
        $discounted = (bool) ($data['prime_hrm_discount'] ?? false);
        $breakdown = self::primeHrmBreakdown($registration->training);

        $payment = Payment::create([
            'registration_id' => $registration->getKey(),
            'user_id' => $registration->user_id,
            'training_id' => $registration->training_id,
            'amount' => $discounted ? $breakdown['net'] : $data['amount'],
            'prime_hrm_discount' => $discounted,
            'discount_amount' => $discounted ? $breakdown['discount'] : 0,
            'payment_method' => $data['payment_method'],
            'reference_number' => $data['reference_number'] ?? null,
            'payment_date' => $data['payment_date'],
        ]);

        return self::verify($payment, $officer, $data['remarks'] ?? null, [
            'or_number' => $data['or_number'] ?? null,
            'or_date' => $data['or_date'] ?? null,
            'collecting_officer_id' => $data['collecting_officer_id'] ?? null,
        ]);
    }

    /**
     * @param  array{or_number?: ?string, or_date?: ?string, collecting_officer_id?: int|null}  $receipt
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
                // The officer who verified is the one who issued it — unless
                // the caller names someone else, which is how HRD records money
                // a field office actually collected.
                'collecting_officer_id' => $issued
                    ? ($receipt['collecting_officer_id'] ?? $officer->getKey())
                    : $locked->collecting_officer_id,
            ])->save();

            ActivityLogger::recordTransition(
                "payment.{$decision->value}",
                $locked,
                PaymentStatus::Pending,
                $decision,
                sprintf(
                    'PHP %s %s by %s.%s',
                    number_format((float) $locked->amount, 2),
                    $decision->label(),
                    $officer->name,
                    $locked->prime_hrm_discount
                        ? sprintf(' PRIME-HRM discount of PHP %s applied.', number_format((float) $locked->discount_amount, 2))
                        : '',
                ),
                [
                    'amount' => (float) $locked->amount,
                    'reason' => $reason,
                    // The OR is the number finance reconciles on, so it belongs
                    // in the trail rather than only on the row it stamped.
                    'or_number' => $issued ? $receipt['or_number'] : null,
                    // A discount is revenue the office chose to forgo. Who
                    // granted it, and how much, belongs in the trail rather
                    // than only in a column somebody has to go looking for.
                    'prime_hrm_discount' => (bool) $locked->prime_hrm_discount,
                    'discount_amount' => (float) $locked->discount_amount,
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
