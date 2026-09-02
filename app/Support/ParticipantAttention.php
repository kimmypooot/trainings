<?php

namespace App\Support;

use App\Enums\AgencyRequestStatus;
use App\Enums\PaymentStatus;
use App\Enums\PhysicalOrRequestStatus;
use App\Enums\RegistrationStatus;
use App\Models\AgencyRequest;
use App\Models\Payment;
use App\Models\PhysicalOrRequest;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;

/**
 * What a participant still owes, item by item.
 *
 * This is the single statement of "what is outstanding for this person". The
 * dashboard's "needs your attention" block renders these rows; the sidebar
 * badge counts them, through PendingActionCounter, rather than re-deriving the
 * same rules a second way. That direction matters: a counter that counts its
 * own idea of pending and a list that lists a different one will disagree
 * eventually, and the disagreement shows up as a badge that never clears.
 *
 * Every row answers three questions, because a count answers none of them:
 * what is needed, which training it is about, and where to go. A participant
 * reading "2 training fees need settling" still has to open the payments screen
 * to find out which two — which is the trip the block existed to save.
 *
 * Hydrating rows costs more than counting them did, and is worth it here: the
 * common case is a participant who owes nothing, where every query below comes
 * back empty and there is nothing to hydrate. Somebody who does owe something
 * owes a handful of things, not a page of them. PendingActionCounter memoises
 * per request, so this runs once per page load however many callers ask.
 */
class ParticipantAttention
{
    /**
     * Outstanding items, keyed by queue, in the order a participant should
     * deal with them: an unpaid fee can cost them their slot, an unfilled
     * evaluation costs them nothing yet.
     *
     * A queue with nothing in it is still present, as an empty array — the
     * counter maps over these keys, and a missing one would read as a queue
     * that no longer exists rather than one that is clear.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function for(User $user): array
    {
        return [
            'payments' => self::payments($user),
            'agency-requests' => self::agencyRequests($user),
            'physical-or' => self::physicalOrRequests($user),
            'evaluations' => self::evaluations($user),
        ];
    }

    /**
     * The two kinds of pending on the participant's own payments screen.
     *
     * They are not the same errand and no longer read as one. A fee with no
     * payment against it is the participant's move; proof already submitted is
     * the collecting officer's, and saying so is the difference between a
     * prompt and a nag — a participant who has already paid and is told a fee
     * "needs settling" will reasonably think their payment was lost.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function payments(User $user): array
    {
        $unpaid = Registration::with('training')
            ->where('user_id', $user->getKey())
            ->whereIn('status', RegistrationStatus::occupying())
            ->whereHas('training', fn ($query) => $query->where('payment_required', true))
            ->whereDoesntHave('payments')
            ->get()
            ->map(fn (Registration $registration) => [
                'key' => "payments-registration-{$registration->getKey()}",
                'queue' => 'payments',
                'label' => 'Training fee not yet settled',
                'subject' => $registration->training->title,
                'amount' => $registration->training->payment_amount,
                // A fee owed on a training that has already begun is a
                // different situation from one owed on a booking months out,
                // and the dates alone do not say which — the reader would have
                // to know today's date and do the comparison themselves.
                'detail' => $registration->training->starts_at->isFuture()
                    ? null
                    : 'Already under way',
                'href' => route('payments.index'),
            ] + self::schedule($registration->training));

        $awaitingVerification = Payment::with('training')
            ->where('user_id', $user->getKey())
            ->where('status', PaymentStatus::Pending)
            ->get()
            ->map(fn (Payment $payment) => [
                'key' => "payments-payment-{$payment->getKey()}",
                'queue' => 'payments',
                'label' => 'Payment is being verified',
                'subject' => $payment->training?->title,
                'amount' => $payment->amount,
                'detail' => 'Submitted '.$payment->created_at->format('d M Y').' — with the collecting officer',
                'href' => route('payments.index'),
            ] + self::schedule($payment->training));

        return $unpaid->concat($awaitingVerification)->values()->all();
    }

    /**
     * Agency requests where the next move is the requester's.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function agencyRequests(User $user): array
    {
        // documents rides along because missingCompletionDocuments() reads the
        // relation, and it is asked of every row below.
        return AgencyRequest::with('documents')
            ->where('requested_by', $user->getKey())
            ->where(fn ($query) => $query
                ->where('status', AgencyRequestStatus::RequirementsSent)
                ->orWhere(fn ($inner) => $inner
                    ->where('status', AgencyRequestStatus::Confirmed)
                    ->whereNull('completion_submitted_at')
                )
            )
            ->get()
            ->map(function (AgencyRequest $request) {
                $confirming = $request->status === AgencyRequestStatus::RequirementsSent;

                // Naming what is missing, not just that something is. The
                // completion stage asks for several documents and a request
                // sits open until the last one lands, so "documents are
                // outstanding" without saying which sends the requester to the
                // screen to work out what they already sent.
                $missing = $confirming ? [] : $request->missingCompletionDocuments();

                /*
                 * The confirmed dates once there are any, the proposed ones
                 * until then. An agency request has both, and after HRD has
                 * agreed a schedule the proposed dates are a stale offer — an
                 * agency reading them would be checking their diary against a
                 * plan that has already moved.
                 */
                $start = $request->confirmed_start ?? $request->proposed_start;
                $end = $request->confirmed_end ?? $request->proposed_end;

                return [
                    'key' => "agency-request-{$request->getKey()}",
                    'queue' => 'agency-requests',
                    'label' => $confirming
                        ? 'Requirements sent — your confirmation is needed'
                        : 'Post-training documents are outstanding',
                    'subject' => $request->training_title,
                    'amount' => null,
                    'detail' => $confirming || $missing === []
                        ? $request->request_code
                        : $request->request_code.' — still to send: '.self::sentence(
                            array_map(fn ($kind) => strtolower($kind->label()), $missing)
                        ),
                    'starts_at' => $start?->format('d M Y'),
                    'ends_at' => $end?->format('d M Y'),
                    'href' => route('agency-requests.index'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Receipt requests waiting on the participant's proof of payment.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function physicalOrRequests(User $user): array
    {
        return PhysicalOrRequest::with('payment.training')
            ->where('user_id', $user->getKey())
            ->where('status', PhysicalOrRequestStatus::RequestSubmitted)
            ->get()
            ->map(fn (PhysicalOrRequest $request) => [
                'key' => "physical-or-{$request->getKey()}",
                'queue' => 'physical-or',
                'label' => 'Receipt request needs your proof of payment',
                'subject' => $request->payment?->training?->title,
                'amount' => null,
                'detail' => $request->request_code,
                'href' => route('physical-or.index'),
            ] + self::schedule($request->payment?->training))
            ->values()
            ->all();
    }

    /**
     * Training days attended but not yet evaluated.
     *
     * The one queue whose rows can be linked precisely: an evaluation form is
     * addressed by registration and day number, so the row lands on the form
     * itself rather than on the list the participant would then have to search.
     *
     * SmeEvaluationService owns which days are open — asked here rather than
     * reimplemented, so a row can never point at a form that will refuse it.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function evaluations(User $user): array
    {
        return SmeEvaluationService::pendingFor($user)
            ->map(function (array $pending) {
                $registration = $pending['registration'];
                $schedule = self::schedule($registration->training);

                /*
                 * The day's own date, but only when the run is longer than a
                 * day. On a single-day training the run and the day are the
                 * same date, and printing it twice on one row reads as two
                 * facts that happen to match rather than one stated once.
                 */
                $day = $pending['date']->format('d M Y');

                return [
                    'key' => "evaluation-{$registration->getKey()}-{$pending['day']}",
                    'queue' => 'evaluations',
                    'label' => "Day {$pending['day']} is waiting to be evaluated",
                    'subject' => $registration->training->title,
                    'amount' => null,
                    'detail' => $schedule['ends_at'] === null || $schedule['ends_at'] === $schedule['starts_at']
                        ? null
                        : "Day {$pending['day']} was {$day}",
                    'href' => route('evaluations.show', [$registration, $pending['day']]),
                ] + $schedule;
            })
            ->values()
            ->all();
    }

    /**
     * A training's dates, for the row to identify it by.
     *
     * The title alone is not enough to tell one run from another: this office
     * puts the same programme on several times a year, so "Records Management
     * Seminar" names four things and the dates are what pick one out. They are
     * formatted here rather than in the browser, per the house rule that
     * controllers format with Carbon — the page pairs them through
     * resources/js/dateRange.ts, which is what every other screen showing a
     * training's schedule uses, so a multi-day run reads the same here as it
     * does in the catalogue.
     *
     * Null start and end for a row whose training cannot be resolved, rather
     * than a guess: a receipt request whose payment was deleted still has a
     * proof to send, and should say so without inventing a schedule.
     *
     * @return array{starts_at: string|null, ends_at: string|null}
     */
    private static function schedule(?Training $training): array
    {
        return [
            'starts_at' => $training?->starts_at?->format('d M Y'),
            'ends_at' => $training?->ends_at?->format('d M Y'),
        ];
    }

    /**
     * "a, b and c" — an English list, because this one is read as a sentence
     * rather than scanned as a column.
     *
     * @param  array<int, string>  $items
     */
    private static function sentence(array $items): string
    {
        if (count($items) < 2) {
            return implode('', $items);
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}
