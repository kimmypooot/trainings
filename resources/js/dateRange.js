/**
 * A training's date, formatted as a range.
 *
 * Every screen that shows a training's schedule — the catalogue, the roster
 * (on screen and on the printed attendance sheet), the reschedule-impact
 * page, a participant's registration history, an expert's assignment list,
 * the admin dashboard's upcoming list, a certificate's verification page —
 * needs the same answer to "is this actually more than one day, and if so
 * what do I show instead of just the start". That check used to be copied
 * into each page individually, which is how one of them (the catalogue
 * modal) ended up asking the question slightly differently from the rest.
 * One place for it means a multi-day run reads the same way everywhere it
 * appears, and a training whose ends_at happens to equal its starts_at never
 * grows a pointless "– same date" suffix.
 *
 * Dates arrive from the server pre-formatted (see CLAUDE.md: controllers
 * format with Carbon, not the browser) — these helpers work on those
 * strings, not Date objects, so they stay this simple.
 */

/** Whether a start/end pair is actually worth showing as a range. */
export const spansMultipleDays = (startsAt, endsAt) => Boolean(endsAt) && endsAt !== startsAt;

/** "12 Sep 2026" or "12 Sep 2026 – 16 Sep 2026", matching every plain-text usage. */
export const formatDateRange = (startsAt, endsAt) =>
    spansMultipleDays(startsAt, endsAt) ? `${startsAt} – ${endsAt}` : startsAt;
