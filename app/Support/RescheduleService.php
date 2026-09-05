<?php

namespace App\Support;

use App\Enums\PaymentStatus;
use App\Enums\RegistrationStatus;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\Training;
use Illuminate\Support\Collection;

/**
 * Who a rescheduled run leaves stranded, and whether they can be moved.
 *
 * The office does not edit a run's dates when it reschedules. The original
 * record stands — its registration dates, its attendance, the fees collected
 * against it are all history — and the new schedule is published as a separate
 * training that the participants are then moved onto.
 *
 * Moving them was never the hard part; RegistrationService::transfer has done
 * that since the port from v1. Finding them was. The question the office
 * actually asks is a money question — who has already paid, or signed a
 * promissory note, against a run that is no longer happening — and until this
 * class existed the only way to answer it was to read a roster and cross-check
 * each name against the payment queue by hand. Somebody is missed that way, and
 * the person who is missed is the one whose money is sitting against a training
 * they will never attend.
 *
 * This class only reads. Every decision it reports is deferred to the service
 * that will carry it out, so the list cannot promise a move that the transfer
 * then refuses.
 */
class RescheduleService
{
    /**
     * The roster of a run that has been rescheduled, annotated with what the
     * office needs in order to decide about each person.
     *
     * Waitlisted registrants are included alongside the slot-holders. They hold
     * no slot and so are invisible to most of the app, but they arranged leave
     * around the old dates like everyone else, and a reschedule is frequently
     * the moment a waitlist finally fits — the new run is often emptier than
     * the one that was called off for low turnout.
     *
     * Cancelled and rejected registrations are not affected by anything. They
     * are excluded rather than shown greyed out; a list of people to act on
     * that is padded with people to ignore is a list nobody trusts.
     *
     * $target is optional because the list is worth reading before the
     * replacement run exists — that is often what tells the office how big to
     * make it. Supply one and every row also carries whether it can actually be
     * moved there, and why not when it cannot.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function affected(
        Training $from,
        ?Training $target = null,
        ?int $fieldOfficeId = null,
    ): Collection {
        $registrations = Registration::with(['user.profile.fieldOffice', 'payments'])
            ->where('training_id', $from->getKey())
            ->whereIn('status', self::affectedStatuses())
            /*
             * Scoped exactly like the roster this list is derived from. A field
             * office reschedules its own sessions and must see its own people;
             * it must not learn the region's roster by way of a screen that
             * happens to be new.
             */
            ->when($fieldOfficeId !== null, fn ($query) => $query->whereHas(
                'user.profile',
                fn ($profile) => $profile->where('field_office_id', $fieldOfficeId)
            ))
            // `id` as the tiebreak: `registered_at` is second-resolution, so a
            // sort on it alone is not a total order, and the seat allocation
            // below — and the transfer that has to match it — would differ
            // between two runs over identical data.
            ->orderBy('registered_at')
            ->orderBy('id')
            ->get();

        // Handed over rather than re-queried: the fee predicates below reach
        // for the training on every row, and they all belong to this one.
        $registrations->each(fn (Registration $registration) => $registration->setRelation('training', $from));

        $alreadyThere = $target === null ? [] : Registration::where('training_id', $target->getKey())
            ->whereIn('user_id', $registrations->pluck('user_id'))
            ->pluck('user_id')
            ->all();

        $remaining = $target === null ? 0 : self::slotsLeftOn($target);

        return $registrations->map(function (Registration $registration) use ($target, $alreadyThere, &$remaining) {
            $blocker = null;

            if ($target !== null) {
                $blocker = RegistrationService::transferBlocker(
                    $registration,
                    $target,
                    $alreadyThere,
                    $remaining,
                );

                /*
                 * Counted down as the preview walks the list, mirroring what
                 * transfer() does as it walks the same list. Previewing every
                 * row against the target's *initial* free space would report a
                 * batch of thirty as movable into ten seats.
                 *
                 * This makes the order significant — the first rows to be shown
                 * are the first to be offered a seat — which is why both this
                 * and the roster order by registered_at. First registered, first
                 * seated is the rule the office would apply anyway.
                 */
                if ($blocker === null && $registration->status->occupiesSlot()) {
                    $remaining--;
                }
            }

            $profile = $registration->user->profile;

            return [
                'id' => $registration->id,
                'name' => $registration->user->name,
                'email' => $registration->user->email,
                'office' => $profile?->fieldOffice?->name,
                'status' => $registration->status->value,
                'status_label' => $registration->status->label(),
                'registered_at' => $registration->registered_at->format('d M Y'),
                ...self::feeStateFor($registration),
                // Null means movable. The screen shows the phrase as-is, which
                // is the same phrase the transfer will report if it is run.
                'blocker' => $blocker,
                'movable' => $target !== null && $blocker === null,
            ];
        });
    }

    /**
     * Totals for the header of the affected list.
     *
     * The counts are what turns the screen into a decision. "Fourteen affected"
     * is a number; "fourteen affected, nine of whom the office is holding
     * ₱13,500 for and three more on unpaid promissory notes" is the reason the
     * run cannot simply be called off and forgotten.
     *
     * @param  Collection<int, array<string, mixed>>  $affected
     * @return array<string, mixed>
     */
    public static function summarise(Collection $affected): array
    {
        return [
            'total' => $affected->count(),
            'paid' => $affected->where('fee_state', 'paid')->count(),
            'promissory' => $affected->where('fee_state', 'promissory')->count(),
            'unpaid' => $affected->where('fee_state', 'unpaid')->count(),
            // Money actually received, so promissory notes are excluded — a
            // note is a promise, and adding it here would report cash the
            // office does not hold.
            'collected' => round((float) $affected->where('fee_state', 'paid')->sum('amount'), 2),
            // What is owed on notes, which is what has to be chased or written
            // off if these people do not move to the new run.
            'promised' => round((float) $affected->where('fee_state', 'promissory')->sum('amount'), 2),
            'movable' => $affected->where('movable', true)->count(),
            'blocked' => $affected->where('movable', false)->count(),
        ];
    }

    /**
     * The registration statuses a reschedule disturbs.
     *
     * @return array<int, RegistrationStatus>
     */
    public static function affectedStatuses(): array
    {
        return [...RegistrationStatus::occupying(), RegistrationStatus::Waitlisted];
    }

    /**
     * How one participant stands with the fee on the run being abandoned.
     *
     * Three states, and the middle one is the whole reason this feature exists.
     * They are derived from the model's own predicates rather than from a fresh
     * reading of the payments table, so that "paid" here means exactly what it
     * means to the certificate run and to the venue door.
     *
     * @return array<string, mixed>
     */
    private static function feeStateFor(Registration $registration): array
    {
        $verified = $registration->payments
            ->first(fn (Payment $payment) => $payment->status === PaymentStatus::Verified);

        $state = match (true) {
            /*
             * Named before the predicates below are consulted, because both of
             * them answer "yes, nothing is owed" on a free run — correctly, for
             * their own purposes, since a free training must not block a
             * certificate. Here that answer would report a room full of people
             * as having paid and invent money the office never took.
             */
            ! $registration->training->payment_required => 'free',
            // Money arrived. Follows the participant to the new run untouched.
            $registration->hasClearedFee() => 'paid',
            // Verified, but not settlement — a promissory note. The office is
            // holding a signed promise against a run that will not happen, and
            // the note has to follow the participant or be cancelled.
            $registration->hasSettledFee() => 'promissory',
            default => 'unpaid',
        };

        return [
            'fee_state' => $state,
            'amount' => $verified === null ? null : (float) $verified->amount,
            'payment_method' => $verified?->payment_method->label(),
            'or_number' => $verified?->or_number,
        ];
    }

    /**
     * Free seats on the replacement run, counted the way transfer() counts them.
     */
    private static function slotsLeftOn(Training $target): int
    {
        if ($target->capacity === null) {
            return PHP_INT_MAX;
        }

        return max(0, $target->capacity - Registration::where('training_id', $target->getKey())
            ->whereIn('status', RegistrationStatus::occupying())
            ->count());
    }
}
