<?php

namespace App\Support;

use App\Enums\PaymentStatus;
use App\Enums\PhysicalOrRequestStatus;
use App\Enums\RefundStatus;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\AgencyRequest;
use App\Models\CancellationRequest;
use App\Models\Payment;
use App\Models\PhysicalOrRequest;
use App\Models\RefundRequest;
use App\Models\RegistrationOutput;
use App\Models\TrainingRequest;
use App\Models\User;

/**
 * The sidebar's pending-action badges.
 *
 * Every badge must mean "the queue this nav item leads to has work in it" —
 * a count that includes items nobody at the current role can act on is a badge
 * that lies. The field-office scoping applied here is the same rule the queue
 * screens themselves apply, so the number on the sidebar matches the number
 * on the page.
 */
class PendingActionCounter
{
    /** Where the per-request memo lives on the Request. */
    private const MEMO_KEY = 'pending_action_counts';

    /**
     * @return array<string, int> Nav item key => items awaiting a decision.
     */
    public static function for(User $user): array
    {
        /*
         * Computed at most once per user per request.
         *
         * Two callers ask for the same numbers on a single page load: the
         * sidebar badges in HandleInertiaRequests::share(), and any screen that
         * repeats those queues in its own body — the participant dashboard's
         * "needs your attention" block, the admin dashboard's requests tile.
         * The second call is a whole second round of queries plus another
         * SmeEvaluationService::pendingFor() pass, for an answer that cannot
         * have changed since the first.
         *
         * The memo hangs off the Request rather than a static because a static
         * outlives the request in the two places that matter: a test method
         * making several requests through one application instance, and Octane.
         * Both would then serve counts from before the state change under test.
         * Request attributes die with the request, so the cache can never be
         * older than the page it is printed on.
         */
        $request = request();
        $key = (int) $user->getKey();
        $memo = $request->attributes->get(self::MEMO_KEY, []);

        if (isset($memo[$key])) {
            return $memo[$key];
        }

        $counts = $user->role->isStaff()
            ? self::staff($user)
            : self::participant($user);

        $memo[$key] = $counts;
        $request->attributes->set(self::MEMO_KEY, $memo);

        return $counts;
    }

    /**
     * The participant's own queues.
     *
     * Counted off ParticipantAttention's rows rather than re-derived here.
     * The dashboard's "needs your attention" block and this badge used to be
     * two statements of the same rules, and two statements of one rule drift.
     * The failure mode is specific and nasty: a badge showing work the screen
     * behind it does not list, which the participant cannot clear and so learns
     * to ignore. There is one statement now, in ParticipantAttention, and this
     * counts it — including the evaluations queue, whose "is this day open?"
     * question remains SmeEvaluationService's to answer.
     *
     * @return array<string, int>
     */
    private static function participant(User $user): array
    {
        return array_map(
            fn (array $items) => count($items),
            ParticipantAttention::for($user),
        );
    }

    /**
     * @return array<string, int>
     */
    private static function staff(User $user): array
    {
        $counts = [];

        if ($user->collectsPayments()) {
            // The payments screen leads with the two queues that are still work:
            // proof awaiting verification, and refunds still mid-pipeline.
            $counts['admin-payments'] = Payment::where('status', PaymentStatus::Pending)->count()
                + RefundRequest::whereNotIn('status', [
                    RefundStatus::Refunded->value, RefundStatus::Rejected->value,
                ])->count();
        }

        // The agency-request queue is admin-only, and its badge counts exactly
        // what its default tab shows: requests where HRD is the one holding
        // things up. Not office-scoped, because the queue is not either — an
        // agency writes to the region, not to a field office.
        if (in_array($user->role, [Role::Admin, Role::SuperAdmin], true)) {
            $counts['admin-agency-requests'] = AgencyRequest::awaitingStaff()->count();
        }

        // The physical-OR queue is admin-only and not office-scoped (a receipt
        // is mailed from the regional office, wherever the participant is).
        if ($user->role->handlesPhysicalOrRequests()) {
            $counts['admin-physical-or'] = PhysicalOrRequest::whereNotIn('status', [
                PhysicalOrRequestStatus::Delivered->value, PhysicalOrRequestStatus::Rejected->value,
            ])->count();
        }

        $officeId = $user->scopedFieldOfficeId();

        $scopeCancellations = fn ($query) => $query->when(
            $officeId !== null,
            fn ($inner) => $inner->whereHas(
                'registration.user.profile',
                fn ($profile) => $profile->where('field_office_id', $officeId)
            )
        );

        $scopeOutputs = fn ($query) => $query->when(
            $officeId !== null,
            fn ($inner) => $inner->whereHas(
                'registration.user.profile',
                fn ($profile) => $profile->where('field_office_id', $officeId)
            )
        );

        $scopeTrainingRequests = fn ($query) => $query->when(
            $officeId !== null,
            fn ($inner) => $inner->whereHas(
                'requester.profile',
                fn ($profile) => $profile->where('field_office_id', $officeId)
            )
        );

        $counts['admin-requests'] = CancellationRequest::tap($scopeCancellations)
            ->where('status', RequestStatus::Pending)
            ->count()
            + RegistrationOutput::tap($scopeOutputs)
                ->where('status', RequestStatus::Pending)
                ->count()
            + TrainingRequest::tap($scopeTrainingRequests)
                ->where('status', RequestStatus::Pending)
                ->count();

        return $counts;
    }
}
