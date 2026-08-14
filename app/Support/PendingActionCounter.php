<?php

namespace App\Support;

use App\Enums\AgencyRequestStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\RegistrationStatus;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\AgencyRequest;
use App\Models\CancellationRequest;
use App\Models\Payment;
use App\Models\RefundRequest;
use App\Models\Registration;
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
    /**
     * @return array<string, int> Nav item key => items awaiting a decision.
     */
    public static function for(User $user): array
    {
        return $user->role->isStaff()
            ? self::staff($user)
            : self::participant($user);
    }

    /**
     * @return array<string, int>
     */
    private static function participant(User $user): array
    {
        $counts = [];

        // Two kinds of "pending" on the participant's own Payments screen:
        // proof still being checked, and a fee still owed on an active slot.
        $counts['payments'] = Payment::where('user_id', $user->getKey())
            ->where('status', PaymentStatus::Pending)
            ->count();

        $counts['payments'] += Registration::where('user_id', $user->getKey())
            ->whereIn('status', RegistrationStatus::occupying())
            ->whereHas('training', fn ($query) => $query->where('payment_required', true))
            ->whereDoesntHave('payments')
            ->count();

        // Agency requests where the next move is theirs: a confirmation form to
        // return, or post-training documents still outstanding. A request
        // sitting with HRD is not work for the agency and must not be badged.
        $counts['agency-requests'] = AgencyRequest::where('requested_by', $user->getKey())
            ->where(fn ($query) => $query
                ->where('status', AgencyRequestStatus::RequirementsSent)
                ->orWhere(fn ($inner) => $inner
                    ->where('status', AgencyRequestStatus::Confirmed)
                    ->whereNull('completion_submitted_at')
                )
            )
            ->count();

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private static function staff(User $user): array
    {
        $counts = [];

        if ($user->role->handlesPayments()) {
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
