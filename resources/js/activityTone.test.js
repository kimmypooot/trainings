import { describe, expect, it } from 'vitest';
import { activityTones, fallbackTone, tone } from './activityTone';
import { iconNames } from './icons';

/**
 * The map itself needs no test — it is a table of constants. What needs one is
 * the *resolver*, because the thing it guards against is the failure the map
 * used to have when it lived in Dashboard.vue: a kind minted server-side with
 * no entry here read `.icon` off `undefined` and took the whole page down with
 * a TypeError, thrown nowhere near the change that caused it.
 *
 * Notification kinds now come from PHP (ParticipantNotification::kind), so the
 * unknown-kind case is not hypothetical: it is what every notification row
 * written before kinds existed does, on every participant's list, today.
 */
describe('tone()', () => {
    it('resolves a known kind', () => {
        expect(tone('certificate')).toBe(activityTones.certificate);
    });

    // The three shapes an absent kind actually arrives in: a kind the map does
    // not have, and the two the JSON payload produces for a row without one.
    it.each([['a-kind-nobody-added'], [null], [undefined]])('falls back for %s', (kind) => {
        expect(tone(kind)).toBe(fallbackTone);
    });

    /*
     * AppIcon renders nothing at all for a name it does not hold — no warning,
     * no placeholder — so a typo is an empty 40px square that reads as a
     * rendering glitch, on whichever kind of event happens to be rare.
     *
     * activityTone.ts throws on import in development for this, which is the
     * fast feedback; this is the half that holds in CI, where the production
     * build strips that check out.
     */
    it('names only icons the registry holds', () => {
        const named = [...Object.values(activityTones), fallbackTone].map((entry) => entry.icon);

        expect(named.filter((name) => !iconNames.includes(name))).toEqual([]);
    });
});
