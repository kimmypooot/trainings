import { registrationStatusValues, type RegistrationStatus } from '@/types/enums';

/**
 * Card-background tone for a registration's lifecycle status.
 *
 * Deliberately separate from AppBadge's own colouring, not a duplicate of
 * it: AppBadge answers "what stage is this, precisely" and treats
 * `approved` as still in-flight (its info tone), while this answers a
 * coarser question — "does this card read as good news at a glance" — and
 * treats `approved` as the settled, green outcome it feels like to a
 * participant scanning a grid of cards. The two are allowed to disagree on
 * `approved` on purpose.
 *
 * What must not happen is two *cards* disagreeing with each other. This is
 * the one place the mapping is written down, so a second screen that wants
 * the same "tint the card by registration status" treatment imports this
 * instead of copying the object literal and drifting from it.
 *
 * `Record<RegistrationStatus, string>` is the point of this file being .ts.
 * The union comes from App\Enums\RegistrationStatus by way of
 * `php artisan tims:types`, so the record is exhaustive against the PHP: add
 * a case to the enum and this stops compiling until it has a tone, and
 * misspell one and it stops compiling immediately. Before, a status added
 * server-side simply fell through to the neutral white card — a silent
 * wrong answer on a screen nobody would think to re-check.
 */
export const registrationCardTone: Record<RegistrationStatus, string> = {
    pending: 'border-warning/30 bg-warning-soft',
    approved: 'border-success/30 bg-success-soft',
    waitlisted: 'border-warning/30 bg-warning-soft',
    rejected: 'border-danger/30 bg-danger-soft',
    cancelled: 'border-danger/30 bg-danger-soft',
    completed: 'border-success/30 bg-success-soft',
};

const unregisteredTone = 'border-csc-line bg-white';

/**
 * Whether a string off the wire is one of the statuses the server can send.
 *
 * A runtime check, not a cast, and it reads its answer from the same
 * generated array the type is derived from — so it cannot fall out of step
 * with the union the way a hand-written list would. This is what lets the
 * function below keep taking a plain `string`: props arrive as JSON and are
 * not validated by the type system, so pretending the parameter is already a
 * RegistrationStatus would be a lie the compiler happily believes.
 */
const isRegistrationStatus = (value: string): value is RegistrationStatus =>
    (registrationStatusValues as readonly string[]).includes(value);

/** The tone classes for a card, given whether it's registered and at what status. */
export const registrationCardToneFor = (
    isRegistered: boolean,
    status: string | null | undefined,
): string => {
    if (! isRegistered || ! status || ! isRegistrationStatus(status)) {
        return unregisteredTone;
    }

    return registrationCardTone[status];
};
