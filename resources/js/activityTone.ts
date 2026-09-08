import { iconNames } from '@/icons';

/**
 * How a thing that happened to a participant reads: one glyph and one tone per
 * kind of event.
 *
 * This was an object literal inside Dashboard.vue, which was the right place
 * for it while the activity feed was the only thing drawing events. The
 * notifications page draws the same events — a registration approved, a payment
 * verified, a certificate released — and had been rendering all fifty of them
 * as identical bordered boxes, so the one screen a participant opens *because*
 * something happened was the screen that would not say what.
 *
 * Copying the map over would have been the `statusTone` mistake: two screens
 * showing the same event in two colours, drifting apart the first time a kind
 * is added to one of them. So it lives here, and both import it.
 *
 * The tones borrow the semantic palette the badges already use, so green means
 * the same thing here as it does anywhere else in the app, and every entry also
 * carries a distinct glyph — the feed has to survive greyscale print and colour
 * blindness on its own, exactly as AppBadge does.
 */
export type ActivityTone = {
    /** A name from the icon registry — checked at module load, see below. */
    icon: string;
    /** Background and foreground for the icon chip. */
    node: string;
};

/*
 * Kinds are minted server-side (DashboardController::event, and the notification
 * classes), so this map is not exhaustive over a union the compiler can see —
 * there is no PHP enum behind it to generate one from. `tone()` below is what
 * makes that safe.
 */
export const activityTones: Record<string, ActivityTone> = {
    // Registrations
    registered: { icon: 'bookmark', node: 'bg-csc-blue-tint text-csc-blue' },
    approved: { icon: 'check', node: 'bg-info-soft text-info' },
    waitlisted: { icon: 'clock', node: 'bg-warning-soft text-warning' },
    rejected: { icon: 'close', node: 'bg-danger-soft text-danger' },
    withdrawn: { icon: 'close', node: 'bg-csc-line/60 text-csc-ink-subtle' },
    completed: { icon: 'check', node: 'bg-success-soft text-success' },
    transferred: { icon: 'arrow-forward', node: 'bg-info-soft text-info' },

    // Documents and money
    certificate: { icon: 'certificate', node: 'bg-success-soft text-success' },
    payment: { icon: 'card', node: 'bg-info-soft text-info' },
    refund: { icon: 'card', node: 'bg-warning-soft text-warning' },
    receipt: { icon: 'document', node: 'bg-info-soft text-info' },
    document: { icon: 'document', node: 'bg-csc-blue-tint text-csc-blue' },
    agency: { icon: 'building', node: 'bg-csc-blue-tint text-csc-blue' },

    // Asked of the participant, rather than done to them
    reminder: { icon: 'calendar', node: 'bg-warning-soft text-warning' },
    evaluation: { icon: 'clipboard', node: 'bg-warning-soft text-warning' },
    announcement: { icon: 'bell', node: 'bg-csc-blue-tint text-csc-blue' },
    account: { icon: 'shield', node: 'bg-csc-blue-tint text-csc-blue' },
};

/**
 * The tile for an event of unknown kind.
 *
 * This matters more than it looks, and it is the reason `tone()` exists rather
 * than a bare index. Kinds arrive off the wire, and one with no entry here used
 * to take the whole dashboard down with a TypeError — thrown while reading
 * `.icon` of undefined, nowhere near the server-side change that caused it. An
 * unstyled-but-rendered tile is the better failure: the event is still legible,
 * and nothing else on the page is lost with it.
 */
export const fallbackTone: ActivityTone = {
    icon: 'clock',
    node: 'bg-csc-line/60 text-csc-ink-subtle',
};

/** The icon and chip classes for an event kind, whatever the server sent. */
export const tone = (kind: string | null | undefined): ActivityTone =>
    (kind ? activityTones[kind] : undefined) ?? fallbackTone;

/*
 * Every glyph named above is checked against the registry once, when the module
 * loads, rather than being trusted.
 *
 * AppIcon renders nothing at all for a name it does not hold — no warning, no
 * placeholder — so a typo here is an event tile with an empty 40px square in
 * it, which reads as a rendering glitch rather than as a mistake in a map, and
 * only on the kind of event that happens to be rare. Failing at import time
 * puts it in front of whoever made the typo, in the file they made it in.
 *
 * Development only: this is a check on this file's own contents, so it cannot
 * come true in production without having been true in the build, and a blank
 * chip is not worth a white page in front of a participant.
 */
if (import.meta.env.DEV) {
    const unknown = [...Object.values(activityTones), fallbackTone]
        .map((entry) => entry.icon)
        .filter((name) => !iconNames.includes(name));

    if (unknown.length) {
        throw new Error(`activityTone.ts names icons that are not in the registry: ${unknown.join(', ')}`);
    }
}
