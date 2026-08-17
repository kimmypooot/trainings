<?php

namespace App\Support;

use App\Models\Payment;
use Illuminate\Support\Collection;

/**
 * The revenue rules shared by every money report.
 *
 * Only verified payments count. A promissory note is verified but no money
 * arrived, so it is counted apart rather than folded into what was collected.
 * The PRIME-HRM discount is reported as its own line — gross, discount, net —
 * so an office can answer "what was assessed, what was given away, what came
 * in" without opening each payment.
 *
 * This is the canonical home of those rules. The per-training roster report
 * and the analytics reports all read through here so none of them can drift.
 */
final class RevenueService
{
    /**
     * The headline figures for a set of payments.
     *
     * @param  Collection<int, Payment>  $payments
     * @return array{
     *     gross: float,
     *     discount: float,
     *     collected: float,
     *     promissory: float,
     *     promissory_count: int,
     *     discounted_count: int,
     * }
     */
    public static function summarize(Collection $payments): array
    {
        $settled = $payments->filter(fn (Payment $payment) => $payment->payment_method->isSettlement());
        $promissory = $payments->reject(fn (Payment $payment) => $payment->payment_method->isSettlement());

        // Counted over the settled payments, not all of them, so the count and
        // the money it annotates describe the same set. Counting a promissory
        // note here while its gross and discount are left out of the figures
        // above made "granted to N participants" impossible to reconcile with
        // the discount total printed beside it.
        $discounted = self::discounted($payments);

        return [
            'gross' => round($settled->sum(fn (Payment $payment) => $payment->grossAmount()), 2),
            'discount' => round($settled->sum(fn (Payment $payment) => (float) $payment->discount_amount), 2),
            'collected' => round($settled->sum(fn (Payment $payment) => (float) $payment->amount), 2),
            'promissory' => round($promissory->sum(fn (Payment $payment) => (float) $payment->amount), 2),
            'promissory_count' => $promissory->count(),
            'discounted_count' => $discounted->count(),
        ];
    }

    /**
     * Who got the PRIME-HRM discount, so a report answers "which participant"
     * without anyone opening each payment in turn.
     *
     * @param  Collection<int, Payment>  $payments
     * @return array<int, array{id: int, participant: string, gross: float, discount: float, net: float, or_number: ?string}>
     */
    public static function discountedList(Collection $payments): array
    {
        // Same set summarize() counts — the table is the detail behind that
        // figure, so a row here that is missing from the total, or the other
        // way round, is a report that cannot be added up.
        return self::discounted($payments)
            ->map(fn (Payment $payment) => [
                'id' => $payment->getKey(),
                'participant' => $payment->user->name,
                'gross' => $payment->grossAmount(),
                'discount' => (float) $payment->discount_amount,
                'net' => (float) $payment->amount,
                'or_number' => $payment->or_number,
            ])
            ->values()
            ->all();
    }

    /**
     * The discounted payments among a set, on the one rule both the count and
     * the list use: the discount is only real once the money is settled.
     *
     * @param  Collection<int, Payment>  $payments
     * @return Collection<int, Payment>
     */
    private static function discounted(Collection $payments): Collection
    {
        return $payments
            ->filter(fn (Payment $payment) => $payment->payment_method->isSettlement())
            ->filter(fn (Payment $payment) => $payment->prime_hrm_discount);
    }
}
