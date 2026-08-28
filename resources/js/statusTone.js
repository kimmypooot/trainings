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
 */
export const registrationCardTone = {
    pending: 'border-warning/30 bg-warning-soft',
    approved: 'border-success/30 bg-success-soft',
    waitlisted: 'border-warning/30 bg-warning-soft',
    rejected: 'border-danger/30 bg-danger-soft',
    cancelled: 'border-danger/30 bg-danger-soft',
    completed: 'border-success/30 bg-success-soft',
};

const unregisteredTone = 'border-csc-line bg-white';

/** The tone classes for a card, given whether it's registered and at what status. */
export const registrationCardToneFor = (isRegistered, status) =>
    isRegistered ? (registrationCardTone[status] ?? unregisteredTone) : unregisteredTone;
