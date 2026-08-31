import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, ref } from 'vue';

/*
 * The composable's whole job is to talk to Inertia's router, so the router is
 * the thing to stand in for. `visits` records what each call was asked to do,
 * and the options object it captures is what the assertions read — including
 * the `onStart`/`onFinish` hooks, which the tests call by hand to play out a
 * response landing.
 */
const visits = [];

vi.mock('@inertiajs/vue3', () => ({
    router: {
        get: (url, query, options) => {
            visits.push({ url, query, options });
        },
    },
}));

const { useFilters, filteringClass } = await import('./useFilters');

/** The most recent visit, and a way to play its response back. */
const last = () => visits[visits.length - 1];
const land = (visit) => visit.options.onFinish();
const start = (visit) => visit.options.onStart();

beforeEach(() => {
    visits.length = 0;
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('useFilters', () => {
    it('debounces a watched change into a single visit', async () => {
        const search = ref('');
        useFilters({
            url: '/admin/participants',
            watch: [search],
            query: () => ({ search: search.value || undefined }),
        });

        search.value = 'a';
        await nextTick();
        search.value = 'ab';
        await nextTick();
        search.value = 'abc';
        await nextTick();

        // Still inside the debounce window: nothing has gone out yet.
        expect(visits).toHaveLength(0);

        vi.advanceTimersByTime(300);

        expect(visits).toHaveLength(1);
        expect(last().url).toBe('/admin/participants');
        expect(last().query.search).toBe('abc');
    });

    it('is filtering from the keystroke, not from the request', async () => {
        const search = ref('');
        const { filtering } = useFilters({
            url: '/admin/users',
            watch: [search],
            query: () => ({ search: search.value || undefined }),
        });

        expect(filtering.value).toBe(false);

        search.value = 'x';
        await nextTick();

        // The point of the whole exercise: the wait is visible during the
        // debounce, which is the longest part of it, not only once the
        // request is actually in flight.
        expect(filtering.value).toBe(true);
        expect(visits).toHaveLength(0);

        vi.advanceTimersByTime(300);
        start(last());
        expect(filtering.value).toBe(true);

        land(last());
        expect(filtering.value).toBe(false);
    });

    it('stays filtering until the last of several overlapping visits lands', async () => {
        const search = ref('');
        const { filtering, apply } = useFilters({
            url: '/admin/users',
            query: () => ({ search: search.value || undefined }),
        });

        apply({ immediate: true });
        apply({ immediate: true });
        expect(visits).toHaveLength(2);

        start(visits[0]);
        start(visits[1]);

        // Inertia cancels the superseded visit and fires its onFinish. Clearing
        // the flag on the first finish seen would drop the dim while the newer
        // request is still out.
        land(visits[0]);
        expect(filtering.value).toBe(true);

        land(visits[1]);
        expect(filtering.value).toBe(false);
    });

    it('sends an immediate apply without waiting for the debounce', () => {
        const status = ref('');
        const { apply } = useFilters({
            url: '/admin/trainings',
            query: () => ({ status: status.value || undefined }),
        });

        status.value = 'published';
        apply({ immediate: true });

        expect(visits).toHaveLength(1);
        expect(last().query.status).toBe('published');
    });

    it('resets to the first page by default, and not when told not to', () => {
        const { apply } = useFilters({ url: '/a', query: () => ({}) });
        apply({ immediate: true });
        expect(last().query.page).toBe(1);

        const { apply: applyKeeping } = useFilters({
            url: '/b',
            resetPage: false,
            query: () => ({}),
        });
        applyKeeping({ immediate: true });
        expect(last().query).not.toHaveProperty('page');
    });

    it('passes the partial-reload keys through, and omits `only` when empty', () => {
        const { apply } = useFilters({
            url: '/admin/certificates',
            only: ['certificates', 'filters', 'exportUrl'],
            query: () => ({}),
        });
        apply({ immediate: true });
        expect(last().options.only).toEqual(['certificates', 'filters', 'exportUrl']);

        const { apply: applyAll } = useFilters({ url: '/x', query: () => ({}) });
        applyAll({ immediate: true });
        // Not an empty array: Inertia reads `only: []` as a partial reload
        // asking for no props at all.
        expect(last().options.only).toBeUndefined();
    });

    it('preserves state and replaces history so typing writes no back entries', () => {
        const { apply } = useFilters({ url: '/x', query: () => ({}) });
        apply({ immediate: true });

        expect(last().options.preserveState).toBe(true);
        expect(last().options.replace).toBe(true);
        expect(last().options.preserveScroll).toBe(false);
    });

    it('honours preserveScroll for the queues that ask for it', () => {
        const { apply } = useFilters({ url: '/admin/payments', preserveScroll: true, query: () => ({}) });
        apply({ immediate: true });

        expect(last().options.preserveScroll).toBe(true);
    });

    it('drops a queued visit when a newer one supersedes it', async () => {
        const search = ref('');
        const { apply } = useFilters({
            url: '/x',
            watch: [search],
            query: () => ({ search: search.value || undefined }),
        });

        search.value = 'first';
        await nextTick();
        vi.advanceTimersByTime(200);

        // Re-armed before the first timer fired, so only the later value goes.
        apply();
        search.value = 'second';
        vi.advanceTimersByTime(300);

        expect(visits).toHaveLength(1);
        expect(last().query.search).toBe('second');
    });
});

describe('filteringClass', () => {
    it('dims and stops taking clicks while a visit is out', () => {
        expect(filteringClass(true)).toContain('opacity-50');
        expect(filteringClass(true)).toContain('pointer-events-none');
    });

    it('keeps the transition on the way back so the dim fades rather than snaps', () => {
        expect(filteringClass(false)).toContain('transition-opacity');
        expect(filteringClass(false)).not.toContain('opacity-50');
        expect(filteringClass(false)).not.toContain('pointer-events-none');
    });
});
