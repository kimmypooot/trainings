import { ref, watch } from 'vue';
import type { WatchSource } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * One filter set, as it goes onto the query string.
 *
 * `undefined` is meaningful here rather than sloppy: a filter sitting at its
 * default is omitted from the URL entirely, so a page at rest has a clean
 * address and a shared link carries only what was actually chosen.
 */
export type FilterQuery = Record<string, string | number | boolean | undefined>;

export interface UseFiltersOptions {
    /**
     * The path to visit. A function is evaluated per visit, for a page whose
     * URL is not fixed for the life of the component — the training roster
     * keeps its open tab in the fragment, and a filtered visit to the bare path
     * would drop it, so a reload would land the operator back on Participants.
     */
    url: string | (() => string);
    /** The current filter values, read fresh on every visit. */
    query: () => FilterQuery;
    /** The props the filters actually move. See the note above on `only`. */
    only?: string[];
    /** Refs to watch; every change queues a debounced visit. */
    watch?: WatchSource[] | null;
    resetPage?: boolean;
    preserveScroll?: boolean;
    delay?: number;
}

/**
 * Server-side filtering for the admin list screens, in one place.
 *
 * Every index page had grown its own copy of the same twelve lines — a `let
 * debounce`, a `watch` over the filter refs, a `setTimeout`, a `router.get`
 * with `preserveState` — and each copy had drifted a little: 300ms here, 350ms
 * there, `replace` on some and `preserveScroll` on others, `page: 1` remembered
 * on most and forgotten on a couple. None of them told the user anything was
 * happening, and none of them narrowed what came back.
 *
 * Two things this fixes that the copies could not:
 *
 * **The wait is visible.** `filtering` goes true the moment a filter changes —
 * not when the request leaves — and stays true until the response lands. That
 * matters because the debounce is the longest part of the wait: on the old
 * pages you typed, and for 300ms plus a round trip the table sat there looking
 * like a finished result that simply did not match what you had typed. Pages
 * bind it to `aria-busy` and dim the rows.
 *
 * There is no flash guard, deliberately. The debounce is the flash guard: the
 * dim can never be on for less than `delay`, which is well past the ~100ms
 * where a flicker reads as a glitch. An undebounced `apply()` — a dropdown, a
 * chip — is a click, where feedback within the frame is the point.
 *
 * **Only the list comes back.** `only` is a partial reload. Filtering used to
 * re-render the whole page payload: the stat tiles, the filter dropdown
 * options, the shell's badge counts, all recomputed and all thrown away,
 * because the only thing that had changed was the paginator. Pass the props the
 * filters actually move — the paginator, `filters` itself, and anything derived
 * from the query like an `exportUrl` — and leave the rest.
 *
 * Getting `only` wrong is a silent staleness bug, not an error: an omitted prop
 * keeps its previous value. The rule is to ask whether the controller computes
 * it from the request's filters. `stats` on the participants register does not
 * (it is the whole office, whatever is being searched); `exportUrl` on that
 * same page does, because the download is meant to match the rows on screen.
 *
 * Usage — the refs stay in the page, since that is where the inputs bind:
 *
 *     const search = ref(props.filters.search ?? '');
 *     const status = ref(props.filters.status ?? '');
 *
 *     const { filtering } = useFilters({
 *         url: '/admin/participants',
 *         only: ['participants', 'filters', 'exportUrl'],
 *         watch: [search, status],
 *         query: () => ({ search: search.value || undefined, status: status.value || undefined }),
 *     });
 *
 * Pages that treat their controls differently — a debounced text box beside
 * dropdowns that should act on the click — omit `watch` and drive `apply`
 * themselves.
 */
export function useFilters({
    url,
    query,
    only = [],
    watch: sources = null,
    /*
     * A filter change starts from the first page. Staying on page 4 of a
     * freshly narrowed result set reads as "nothing found" — every page that
     * pages was already doing this, and the ones that were not had the bug.
     */
    resetPage = true,
    /*
     * The filter controls sit at the top of these pages, so the default is to
     * let the scroll reset. Pages whose controls sit alongside a long queue
     * (payments, physical-OR) pass true and keep their place.
     */
    preserveScroll = false,
    delay = 300,
}: UseFiltersOptions) {
    const filtering = ref(false);

    let timer: ReturnType<typeof setTimeout> | undefined;

    /*
     * Visits overlap: Inertia cancels an in-flight visit when the next one
     * starts, and fires `onFinish` for the cancelled one too. Clearing the flag
     * there would drop the dim while the newer request is still out, so the
     * flag tracks a count of live visits rather than the last event seen.
     */
    let inflight = 0;

    const run = () => {
        timer = undefined;

        router.get(
            typeof url === 'function' ? url() : url,
            { ...query(), ...(resetPage ? { page: 1 } : {}) },
            {
                only: only.length ? only : undefined,
                preserveState: true,
                preserveScroll,
                // `replace` so a search does not write a history entry per
                // keystroke-batch; Back should leave the list, not walk back
                // through every narrowing that got you here.
                replace: true,
                onStart: () => { inflight += 1; },
                onFinish: () => {
                    inflight = Math.max(0, inflight - 1);
                    if (inflight === 0) filtering.value = false;
                },
            }
        );
    };

    /**
     * Queue a filtered visit. `apply()` debounces — for a text box, where a
     * request per keystroke is waste. `apply({ immediate: true })` goes at
     * once, for a dropdown or a chip, where a 300ms lag reads as a missed tap.
     */
    const apply = ({ immediate = false } = {}) => {
        clearTimeout(timer);
        filtering.value = true;

        if (immediate) {
            run();
        } else {
            timer = setTimeout(run, delay);
        }
    };

    if (sources) watch(sources, () => apply());

    return { filtering, apply };
}

/**
 * The class list for the region a filter narrows.
 *
 * Dimmed rather than replaced by a skeleton, because these are re-queries of
 * something already on screen: the previous rows are the best available
 * approximation of the next ones, and blanking them out loses the reader's
 * place for no gain. Pointer events go with it so a row cannot be actioned in
 * the moment between it being superseded and it being redrawn.
 */
export const filteringClass = (filtering: boolean): string =>
    filtering ? 'opacity-50 transition-opacity pointer-events-none' : 'transition-opacity';
