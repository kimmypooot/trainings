<?php

namespace App\Support;

use App\Enums\PhysicalOrRequestStatus;
use App\Models\Payment;
use App\Models\PhysicalOrRequest;
use App\Models\PhysicalOrRequestStatusLog;
use App\Models\PhysicalOrSetting;
use App\Models\User;
use App\Notifications\PhysicalOrRequestReviewed;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The physical-OR delivery pipeline: filing a request and walking it from
 * "please mail me my receipt" to "delivered".
 *
 * A participant outside Region VIII can ask for a physical copy of their
 * official receipt. That is not a payment workflow — the training fee was
 * already settled — so it deliberately lives beside, not inside,
 * PaymentService. The only money involved is a courier fee, paid separately
 * via GCash and verified against a screenshot.
 *
 * Every transition goes through advance() or reject()/cancel(), which are the
 * only places `status` is written. That is what makes the log complete: there
 * is no path to a new stage that does not also record one.
 */
class PhysicalOrRequestService
{
    /**
     * File a request against a verified, receipted payment.
     *
     * The participant is expected to pay the courier fee and attach the GCash
     * screenshot at the same time, so proof is passed straight in — but it is
     * optional here because the participant may also submit first and pay
     * later (uploadProof() then picks it up). Either way the request exists
     * from the moment the participant submits.
     */
    public static function request(
        Payment $payment,
        User $user,
        ?string $proofPath = null,
        ?string $notes = null
    ): PhysicalOrRequest {
        $request = DB::transaction(function () use ($payment, $user, $proofPath, $notes) {
            $locked = Payment::with(['physicalOrRequests'])
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->isRefundable()) {
                throw ValidationException::withMessages([
                    'physical_or' => 'Only a verified payment can be mailed a physical receipt.',
                ]);
            }

            if (blank($locked->or_number)) {
                throw ValidationException::withMessages([
                    'physical_or' => 'This payment has no official receipt to mail.',
                ]);
            }

            if ($locked->hasPendingPhysicalOrRequest()) {
                throw ValidationException::withMessages([
                    'physical_or' => 'A physical receipt request for this payment is already in progress.',
                ]);
            }

            if ($locked->hasDeliveredPhysicalOrRequest()) {
                throw ValidationException::withMessages([
                    'physical_or' => 'A physical copy of this receipt has already been delivered.',
                ]);
            }

            // The service is deliberately gated on the participant's profile:
            // the whole point is that people who can reach the office in person
            // do not pay for postage. Fails open on a missing profile region.
            if (! $user->profile?->isOutsideCscRegion()) {
                throw ValidationException::withMessages([
                    'physical_or' => 'This option is for participants outside Region VIII.',
                ]);
            }

            $created = PhysicalOrRequest::create([
                'request_code' => PhysicalOrRequest::nextRequestCode(),
                'user_id' => $user->getKey(),
                'payment_id' => $locked->getKey(),
                'courier_fee' => PhysicalOrSetting::current()->courier_fee,
                'status' => PhysicalOrRequestStatus::RequestSubmitted,
                'proof_path' => $proofPath,
                'notes' => $notes,
            ]);

            self::log($created, null, PhysicalOrRequestStatus::RequestSubmitted, null, 'Request filed by participant.');

            // The participant paid and attached proof at filing time, so the
            // request lands directly on the verification queue. The two log
            // entries are deliberate — "filed" and "proof uploaded" are
            // different moments, and the trail should say so.
            if ($proofPath !== null) {
                self::log($created, PhysicalOrRequestStatus::RequestSubmitted, PhysicalOrRequestStatus::PaymentVerificationPending, null, 'Courier fee proof uploaded.');
                $created->forceFill(['status' => PhysicalOrRequestStatus::PaymentVerificationPending])->save();
            }

            return $created;
        });

        return $request;
    }

    /**
     * Attach the GCash proof to a request that was filed without it.
     *
     * The participant's only action after filing; everything else is the
     * officer's. Restricted to the one stage that is actually waiting on it —
     * a request already being verified cannot be rewritten from under the
     * officer's nose.
     */
    public static function uploadProof(
        PhysicalOrRequest $request,
        User $user,
        string $proofPath
    ): PhysicalOrRequest {
        $request = DB::transaction(function () use ($request, $user, $proofPath) {
            $locked = PhysicalOrRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== PhysicalOrRequestStatus::RequestSubmitted) {
                throw ValidationException::withMessages([
                    'proof' => 'This request is no longer waiting for its payment proof.',
                ]);
            }

            $locked->forceFill([
                'status' => PhysicalOrRequestStatus::PaymentVerificationPending,
                'proof_path' => $proofPath,
            ])->save();

            self::log($locked, PhysicalOrRequestStatus::RequestSubmitted, PhysicalOrRequestStatus::PaymentVerificationPending, $user, 'Courier fee proof uploaded.');

            return $locked;
        });

        return $request;
    }

    /**
     * Move a request one stage forward.
     *
     * The target is passed explicitly even though PhysicalOrRequestStatus::next()
     * could derive it — the officer's screen sends what it displayed, and
     * checking that against the pipeline is what stops a stale tab from
     * advancing a request someone else already moved.
     *
     * @param  array{courier_name?: string, tracking_number?: string}  $shipping
     */
    public static function advance(
        PhysicalOrRequest $request,
        PhysicalOrRequestStatus $target,
        User $officer,
        ?string $notes = null,
        array $shipping = []
    ): PhysicalOrRequest {
        if ($target === PhysicalOrRequestStatus::Rejected) {
            throw ValidationException::withMessages([
                'status' => 'Declining a request needs a reason — use the decline action.',
            ]);
        }

        $request = DB::transaction(function () use ($request, $target, $officer, $notes, $shipping) {
            $locked = PhysicalOrRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status->isTerminal()) {
                throw ValidationException::withMessages([
                    'status' => "This request is already {$locked->status->label()}.",
                ]);
            }

            if (! $locked->status->canAdvanceTo($target)) {
                throw ValidationException::withMessages([
                    'status' => "A request at {$locked->status->label()} cannot move straight to {$target->label()}.",
                ]);
            }

            // A request is only ever handed to a courier with the courier's
            // name and a tracking number on file — otherwise "shipped" is a
            // promise nobody can check on.
            if ($target === PhysicalOrRequestStatus::Shipped) {
                if (blank($shipping['courier_name'] ?? null) || blank($shipping['tracking_number'] ?? null)) {
                    throw ValidationException::withMessages([
                        'shipping' => 'A courier name and tracking number are required to mark a request shipped.',
                    ]);
                }
            }

            $from = $locked->status;

            $locked->forceFill([
                'status' => $target,
                'reviewed_by' => $officer->getKey(),
                'reviewed_at' => now(),
                'review_remarks' => $notes,
                // Money in — confirmed only when the officer actually sees the
                // screenshot clear the GCash account.
                'verified_by' => $target === PhysicalOrRequestStatus::PaymentVerified ? $officer->getKey() : $locked->verified_by,
                'verified_at' => $target === PhysicalOrRequestStatus::PaymentVerified ? now() : $locked->verified_at,
                'courier_name' => $target === PhysicalOrRequestStatus::Shipped ? $shipping['courier_name'] : $locked->courier_name,
                'tracking_number' => $target === PhysicalOrRequestStatus::Shipped ? $shipping['tracking_number'] : $locked->tracking_number,
                'shipped_at' => $target === PhysicalOrRequestStatus::Shipped ? now() : $locked->shipped_at,
                'delivered_at' => $target === PhysicalOrRequestStatus::Delivered ? now() : $locked->delivered_at,
            ])->save();

            self::log($locked, $from, $target, $officer, $notes);

            return $locked;
        });

        self::notify($request);

        return $request;
    }

    /**
     * Decline a request. Reachable from any open stage — the receipt may prove
     * unmailable late, and pretending otherwise would leave those stuck open
     * forever.
     */
    public static function reject(PhysicalOrRequest $request, User $officer, string $reason): PhysicalOrRequest
    {
        if (blank($reason)) {
            throw ValidationException::withMessages([
                'rejection_reason' => 'Give a reason when declining a request.',
            ]);
        }

        $request = DB::transaction(function () use ($request, $officer, $reason) {
            $locked = PhysicalOrRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status->isTerminal()) {
                throw ValidationException::withMessages([
                    'status' => "This request is already {$locked->status->label()}.",
                ]);
            }

            $from = $locked->status;

            $locked->forceFill([
                'status' => PhysicalOrRequestStatus::Rejected,
                'reviewed_by' => $officer->getKey(),
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            self::log($locked, $from, PhysicalOrRequestStatus::Rejected, $officer, $reason);

            return $locked;
        });

        self::notify($request);

        return $request;
    }

    /**
     * Withdraw a request the participant no longer wants.
     *
     * Reachable only before the courier fee is verified — once the officer has
     * confirmed the GCash payment landed, the money is CSC's and unwinding it
     * is a refund decision, not a cancellation. Mirrors the participant-facing
     * cancel on agency requests.
     */
    public static function cancel(PhysicalOrRequest $request, User $user): PhysicalOrRequest
    {
        $request = DB::transaction(function () use ($request, $user) {
            $locked = PhysicalOrRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            $cancellable = in_array($locked->status, [
                PhysicalOrRequestStatus::RequestSubmitted,
                PhysicalOrRequestStatus::PaymentVerificationPending,
            ], true);

            if (! $cancellable) {
                throw ValidationException::withMessages([
                    'status' => 'This request can no longer be cancelled.',
                ]);
            }

            $from = $locked->status;

            $locked->forceFill([
                'status' => PhysicalOrRequestStatus::Rejected,
                'rejection_reason' => 'Cancelled by the participant.',
            ])->save();

            self::log($locked, $from, PhysicalOrRequestStatus::Rejected, $user, 'Cancelled by the participant.');

            return $locked;
        });

        return $request;
    }

    /**
     * Two records, deliberately.
     *
     * `physical_or_request_status_logs` is what the participant and the
     * officers read on the request itself — it is part of the feature, ordered
     * and rendered as a trail on screen. The activity log is the cross-cutting
     * one, where a delivery request sits alongside the payment it came from.
     * Folding either into the other would cost one of those two readings.
     */
    private static function log(
        PhysicalOrRequest $request,
        ?PhysicalOrRequestStatus $from,
        PhysicalOrRequestStatus $to,
        ?User $actor,
        ?string $notes
    ): void {
        PhysicalOrRequestStatusLog::create([
            'physical_or_request_id' => $request->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'notes' => $notes,
            'changed_by' => $actor?->getKey(),
            'changed_at' => now(),
        ]);

        ActivityLogger::recordTransition(
            "physical_or.{$to->value}",
            $request,
            $from,
            $to,
            sprintf(
                '%s: %s.',
                $request->request_code,
                $from === null ? 'filed' : "moved to {$to->label()}",
            ),
            ['courier_fee' => (float) $request->courier_fee, 'notes' => $notes],
            $actor,
        );
    }

    private static function notify(PhysicalOrRequest $request): void
    {
        $request->loadMissing('payment.user');
        $request->payment->user->notify(new PhysicalOrRequestReviewed($request));
    }
}
