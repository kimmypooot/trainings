import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { iconNames } from '@/icons';
import { verdictStyles } from './station';

/**
 * The scan verdict vocabulary, held to the two things that fail silently.
 *
 * Both failures render. A verdict with no entry in `verdictStyles` throws on
 * `skin.fill` and blanks the viewfinder mid-queue; an entry naming an icon the
 * registry does not hold draws *nothing at all* — AppIcon has no fallback
 * glyph — leaving a coloured card with a written title and an empty 24px hole
 * where the tick or the cross should be. Neither is caught by the build, and
 * the second is not caught by looking at the screen either unless you happen to
 * scan the one badge that produces it.
 *
 * The verdicts are read out of the *source* of resolve.js and station.js rather
 * than by calling them, for the reason NotificationKindTest gives on the PHP
 * side: exercising the functions only ever proves whichever branch a fixture
 * happens to land on, and it is the rare branch — a refused walk-in, an
 * unreadable code — that nobody notices is broken.
 */
const sourceOf = (file) =>
    readFileSync(fileURLToPath(new URL(file, import.meta.url)), 'utf8');

/** Every `verdict: 'x'` the station can produce, from both files that build one. */
const produced = [...sourceOf('./resolve.js').matchAll(/verdict: '([a-z-]+)'/g)]
    .concat([...sourceOf('./station.js').matchAll(/verdict: '([a-z-]+)'/g)])
    .map((match) => match[1]);

describe('scan verdict styles', () => {
    it('reads the verdicts out of the source rather than trusting a fixture', () => {
        // Guards the regexes themselves. Without this, a rename that stops them
        // matching turns every assertion below into a loop over nothing that
        // passes triumphantly.
        expect(new Set(produced).size).toBeGreaterThanOrEqual(6);
    });

    it('has an entry for every verdict the station can produce', () => {
        for (const verdict of new Set(produced)) {
            expect(Object.keys(verdictStyles)).toContain(verdict);
        }
    });

    it('names only icons the registry actually holds', () => {
        // AppIcon draws nothing for a name it does not know, so a typo here is
        // an empty chip on the one card an operator has to read at a glance.
        for (const [verdict, skin] of Object.entries(verdictStyles)) {
            expect(iconNames, `${verdict} names a missing icon`).toContain(skin.icon);
        }
    });

    it('gives every verdict a written title, so colour is never the only signal', () => {
        for (const [verdict, skin] of Object.entries(verdictStyles)) {
            expect(skin.title, `${verdict} has no title`).toBeTruthy();
            expect(skin.fill, `${verdict} has no fill`).toMatch(/^bg-/);
        }
    });
});
