<?php

namespace App\Support;

use App\Enums\PaymentMethod;
use App\Enums\RegistrationStatus;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admitting somebody who turned up at the venue without registering.
 *
 * The office cannot pretend walk-ins do not happen: on a thousand-seat event
 * they are expected, and the system's answer until now was that there was no
 * answer — staff could not enrol anyone at the door and the participant could
 * not enrol themselves, so the only people who could be marked present were
 * the ones who had planned ahead weeks earlier.
 *
 * What happens at the desk is one action, and this class is it:
 *
 *   1. enrol them, over the closed deadline and, if need be, over the cap;
 *   2. settle the fee with a promissory note so the door opens now and the
 *      money is collected when there is not a queue behind them;
 *   3. check them in for today.
 *
 * Steps 2 and 3 are conditional, and the interesting cases are the ones where
 * they do not happen — see admit() for each.
 *
 * Deliberately *not* reachable from the volunteer station. Everything here
 * creates a registration and, on a paid run, a financial obligation in
 * somebody's name; that is a much larger authority than ticking a box beside a
 * name already on a list, and it belongs to a signed-in staff member whose
 * identity ends up on the record. See the scanner route group in routes/web.php.
 */
class WalkInService
{
    /**
     * Admit one walk-in, and report back everything the desk has to act on.
     *
     * @return array{
     *     registration: Registration,
     *     admitted: bool,
     *     checked_in: bool,
     *     over_capacity: bool,
     *     over_by: int,
     *     message: string,
     * }
     */
    public static function admit(
        User $participant,
        Training $training,
        User $admittedBy,
        ?CarbonImmutable $at = null
    ): array {
        $at ??= CarbonImmutable::now();

        if (! $training->accepts_walk_ins) {
            throw ValidationException::withMessages([
                'walk_in' => "“{$training->title}” was not published as accepting walk-ins.",
            ]);
        }

        /*
         * A walk-in is defined by being at the door while the training runs.
         * Without this, the desk becomes a way to backfill somebody onto a
         * course that finished last month — which is a records correction, and
         * corrections belong on the roster where they can be reviewed, not on a
         * scanner where they look like an ordinary check-in.
         */
        if ($training->dayNumberFor($at) === null) {
            throw ValidationException::withMessages([
                'walk_in' => "“{$training->title}” is not running on ".$at->format('d M Y').'.',
            ]);
        }

        // Measured before the registration exists, so the number reported is
        // the overrun this admission caused rather than the one it created.
        $capacity = $training->capacity;
        $occupied = $training->activeRegistrations()->count();

        return DB::transaction(function () use (
            $participant, $training, $admittedBy, $at, $capacity, $occupied
        ) {
            $registration = RegistrationService::register($participant, $training, [], walkIn: true);

            $overBy = $capacity === null ? 0 : max(0, $occupied + 1 - $capacity);

            $settled = self::settleWithNote($registration, $training, $admittedBy, $at);

            $checkedIn = false;

            /*
             * Refreshed before it is read. Settling the note approved the
             * registration through PaymentService, which loaded its own copy of
             * the row — so the instance in hand here is still the Pending one
             * register() returned, and handing that to checkIn() gets it
             * refused for a status the database no longer holds.
             */
            $registration->refresh();

            /*
             * Only an approved registration can be marked, so the check-in is
             * attempted rather than assumed. A free run was approved by
             * register(); a paid one by the note a line ago. What is left is
             * the paid run that takes no notes, where the participant is
             * enrolled and waiting on the cashier — real progress, but not a
             * person who is present yet.
             */
            if ($registration->status === RegistrationStatus::Approved) {
                AttendanceService::checkIn($registration, $admittedBy, $at);
                $checkedIn = true;
            }

            ActivityLogger::record(
                'registration.walk_in',
                $registration,
                "Admitted {$participant->name} as a walk-in to {$training->title}.",
                [
                    'training_id' => $training->getKey(),
                    'settled_with_note' => $settled,
                    'checked_in' => $checkedIn,
                    // Logged even when zero: "we did not go over" is worth as
                    // much to next year's planning as the overrun itself.
                    'capacity' => $capacity,
                    'over_by' => $overBy,
                ],
                $admittedBy,
            );

            return [
                'registration' => $registration,
                'admitted' => true,
                'checked_in' => $checkedIn,
                'over_capacity' => $overBy > 0,
                'over_by' => $overBy,
                'message' => self::message($participant, $checkedIn, $settled),
            ];
        });
    }

    /**
     * Hold the slot with a promissory note so the door opens now.
     *
     * The note is already the office's instrument for exactly this: it settles
     * the registration without the money having arrived, and the certificate
     * stays withheld until a real payment clears it. So a walk-in needs no new
     * financial concept — it needs the note issued by staff instead of filed by
     * the participant, which is what recordAtCounter does.
     *
     * Two runs get no note. A free one has nothing to owe. A paid one that was
     * published as *not* accepting notes has had that decision made about it
     * already, and a busy door is the worst possible place to overturn it — so
     * the participant is enrolled and sent to the cashier, and the fee clears
     * the normal way.
     */
    private static function settleWithNote(
        Registration $registration,
        Training $training,
        User $admittedBy,
        CarbonImmutable $at
    ): bool {
        if (! $training->payment_required || ! $training->accepts_promissory) {
            return false;
        }

        PaymentService::recordAtCounter($registration, $admittedBy, [
            'amount' => $training->payment_amount,
            'payment_method' => PaymentMethod::Promissory->value,
            'payment_date' => $at->toDateString(),
            'remarks' => 'Promissory note issued at the venue for a walk-in participant.',
        ]);

        return true;
    }

    private static function message(User $participant, bool $checkedIn, bool $settled): string
    {
        if (! $checkedIn) {
            return "{$participant->name} is enrolled. Send them to the cashier — "
                .'they can be scanned in once the fee is settled.';
        }

        return $settled
            ? "{$participant->name} is checked in on a promissory note. The fee is still due."
            : "{$participant->name} is checked in.";
    }
}
